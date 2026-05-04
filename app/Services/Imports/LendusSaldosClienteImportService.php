<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Portfolio;
use App\Models\ReportUpload;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class LendusSaldosClienteImportService
{
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        $rows = Excel::toArray([], Storage::disk('public')->path($upload->stored_path))[0] ?? [];
        if (empty($rows)) throw new \RuntimeException('El archivo de saldos está vacío o no se pudo leer.');
        $headerIndex = $this->detectHeaderRowIndex($rows);
        $map = $this->buildHeaderMap($rows[$headerIndex] ?? []);

        Portfolio::query()->where('report_upload_id', $upload->id)->delete();
        $stats = ['rows_read'=>0,'rows_inserted'=>0,'rows_skipped'=>0,'rows_with_errors'=>0];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if (!is_array($row) || $this->isEmptyRow($row)) { $stats['rows_skipped']++; continue; }
            $stats['rows_read']++;
            $balance = $this->toDecimal($this->valueFromRow($row,$map,'balance'));
            $pastDue = $this->toDecimal($this->valueFromRow($row,$map,'past_due_balance')) ?? 0;
            if (($balance ?? 0) <= 0 && $pastDue <= 0) { $stats['rows_skipped']++; continue; }
            $branchName = $this->clean($this->valueFromRow($row,$map,'branch_name'));
            $branch = $this->resolveBranch($branchName);

            Portfolio::query()->create([
                'period_id'=>$upload->period_id,
                'report_upload_id'=>$upload->id,
                'branch_id'=>$branch?->id,
                'client_name'=>$this->clean($this->valueFromRow($row,$map,'client_name')),
                'normalized_client_name'=>$this->normalize($this->clean($this->valueFromRow($row,$map,'client_name'))),
                'balance'=>$balance ?? 0,
                'past_due_balance'=>$pastDue,
                'days_past_due'=>(int)($this->toDecimal($this->valueFromRow($row,$map,'days_past_due')) ?? 0),
                'portfolio_date'=>$this->toDate($this->valueFromRow($row,$map,'portfolio_date')),
                'raw_payload'=>null,
            ]);
            $stats['rows_inserted']++;
            if ($progress && $stats['rows_read'] % 250 === 0) { $progress($stats + ['log' => 'Integrando cartera...']); }
        }

        return $stats + ['log'=>'Importación de saldos por cliente finalizada.'];
    }

    private function detectHeaderRowIndex(array $rows): int { foreach(array_slice($rows,0,25,true) as $i=>$r){$t=mb_strtolower(implode(' ',array_map('strval',$r))); if(str_contains($t,'saldo')||str_contains($t,'cartera')||str_contains($t,'cliente')) return (int)$i;} return 0; }
    private function buildHeaderMap(array $header): array { $m=[]; foreach($header as $i=>$h){$n=$this->normalizeHeader((string)$h); if(in_array($n,['sucursal','oficina'],true))$m['branch_name']=$i; if(in_array($n,['cliente','nombre_cliente'],true))$m['client_name']=$i; if(in_array($n,['saldo','saldo_actual','valor_cartera'],true))$m['balance']=$i; if(in_array($n,['saldo_vencido','cartera_vencida'],true))$m['past_due_balance']=$i; if(in_array($n,['dias_mora','dias_vencido'],true))$m['days_past_due']=$i; if(in_array($n,['fecha','fecha_corte'],true))$m['portfolio_date']=$i;} return $m; }
    private function resolveBranch(?string $name): ?Branch { if(!$name) return null; $n=$this->normalize($name); return Branch::query()->where('normalized_name',$n)->orWhereRaw('LOWER(name)=?',[mb_strtolower($name)])->first(); }
    private function valueFromRow(array $r,array $m,string $f): mixed { return isset($m[$f]) ? ($r[$m[$f]] ?? null) : null; }
    private function normalizeHeader(string $v): string { return trim((string)preg_replace('/[^a-z0-9]+/','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',mb_strtolower(trim($v)))),'_'); }
    private function normalize(?string $v): ?string { if(!$v) return null; $v=mb_strtolower(trim($v)); $v=str_replace(['á','é','í','ó','ú','ñ'],['a','e','i','o','u','n'],$v); return preg_replace('/\s+/',' ',$v); }
    private function clean(mixed $v): ?string { $v=trim((string)$v); return $v===''?null:$v; }
    private function toDecimal(mixed $v): ?float { $v=str_replace(['$',',',' '],'',(string)$v); return is_numeric($v)?round((float)$v,2):null; }
    private function toDate(mixed $v): ?string { try { return $v ? Carbon::parse((string)$v)->toDateString() : null; } catch (\Throwable) { return null; } }
    private function isEmptyRow(array $r): bool { foreach($r as $v){ if(trim((string)$v)!=='') return false; } return true; }
}
