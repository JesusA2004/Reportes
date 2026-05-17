<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugSourceFilesCommand extends Command
{
    protected $signature   = 'reportes:debug-source-files {period_id : ID del periodo mensual}';
    protected $description = 'Resumen de todos los archivos fuente: filas en BD, filtro a 13 sucursales, diferencia vs targets Abril 2026.';

    private const TARGETS_ABRIL = [
        'Cartera total'       => 39_130_054.53,
        'Colocación'          => 14_538_964.00,
        'Recuperación (PAGO)' => 18_323_749.55,
        'Mora total'          => 13_973_658.37,  // sum of all buckets
        'Gastos operativos'   => 837_384.28,
        'Excedentes'          => 3_076_800.00,
        'Fondeo'              => 449_425.00,
        'Nómina (P001)'       => 1_075_509.70,
        'Comisiones (P002)'   => 716_885.13,
        'Bonos (P1xx)'        => 291_655.85,
    ];

    public function __construct(private readonly BranchResolverService $resolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG SOURCE FILES — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // ── Uploads para este periodo ──────────────────────────────────────────
        $this->info('── Archivos subidos ──');
        $uploads = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('ru.period_id', $dataIds)
            ->select('ru.id', 'ru.period_id', 'ds.code', 'ds.name', 'ru.original_name', 'ru.uploaded_at', 'ru.status')
            ->orderBy('ds.code')
            ->get();

        $this->table(
            ['Upload ID', 'Fuente', 'Archivo', 'Subido', 'Estado'],
            $uploads->map(fn ($u) => [
                $u->id,
                $u->code,
                mb_substr($u->original_name ?? '—', 0, 35),
                substr($u->uploaded_at ?? '—', 0, 16),
                $u->status,
            ])->all()
        );
        $this->line('');

        // ── Build operative map (includes route branches) ─────────────────────
        $operativeMap   = [];
        $corporativoIds = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if (!$real) {
                continue;
            }
            if ($this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            } elseif (strtoupper(trim($real)) === 'CORPORATIVO') {
                $corporativoIds[] = (int) $b->id;
            }
        }
        $operativeIds = array_keys($operativeMap);

        // Operative branch IDs (only the 13 real sucursales, not routes)
        $realOperativeIds = DB::table('branches')->whereIn(
            DB::raw('LOWER(normalized_name)'),
            ['atlacomulco','atlixco','cordoba','cuernavaca','huamantla','ixtlahuaca','miacatlan','orizaba','san juan del rio','san luis potosi','tenango del valle','tlaxcala','tula']
        )->pluck('id')->all();

        // ── fact_recoveries (Ingresos cobranza) ───────────────────────────────
        $this->info('── fact_recoveries (Ingresos cobranza) ──');
        $recTotal = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as filas, SUM(total_amount) as total')->first();
        $recPago = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('COUNT(*) as filas, SUM(total_amount) as total')->first();

        // Pass 1: branch_id in full operative map (includes route branches)
        $recPass1 = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('COUNT(*) as filas, SUM(total_amount) as total')->first();

        // Pass 2: non-operative, non-corporativo branches
        $recPass2 = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $operativeIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('COUNT(*) as filas, SUM(total_amount) as total')->first();

        $recPass2Operativo = 0.0;
        $recPass2Rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $operativeIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw("LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3) as prefix3, SUM(total_amount) as total")
            ->groupByRaw("LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3)")
            ->get();
        foreach ($recPass2Rows as $row) {
            $prefix3  = strtoupper(trim((string) $row->prefix3));
            $resolved = $this->resolver->resolveBranchNameFromCode($prefix3);
            if ($resolved && $this->resolver->isSheetBranch($resolved)) {
                $recPass2Operativo += (float) $row->total;
            }
        }

        $calcRec = (float)($recPass1->total ?? 0) + $recPass2Operativo;

        $this->line(sprintf('  Total en BD              : %5d filas  $%s', (int)($recTotal->filas ?? 0), number_format((float)($recTotal->total ?? 0), 2)));
        $this->line(sprintf('  Solo PAGO                : %5d filas  $%s', (int)($recPago->filas ?? 0), number_format((float)($recPago->total ?? 0), 2)));
        $this->line(sprintf('  Pass1 (branch op.)       : %5d filas  $%s', (int)($recPass1->filas ?? 0), number_format((float)($recPass1->total ?? 0), 2)));
        $this->line(sprintf('  Pass2 (prefix resolv.)   :           $%s', number_format($recPass2Operativo, 2)));
        $this->printDiff('  PAGO Pass1+Pass2 vs target', $calcRec, 18_323_749.55);
        $this->line('  (Ver debug-recuperacion-raw para fila a fila detallado)');
        $this->line('');

        // ── fact_portfolios (Saldos Por Cliente) ───────────────────────────────
        $this->info('── fact_portfolios (Saldos Por Cliente) ──');
        $portTotal = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as filas, SUM(balance) as total')->first();
        $portOp = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $realOperativeIds)
            ->selectRaw('COUNT(*) as filas, SUM(balance) as total')->first();

        $this->line(sprintf('  Total en BD              : %5d filas  $%s', (int)($portTotal->filas ?? 0), number_format((float)($portTotal->total ?? 0), 2)));
        $this->line(sprintf('  Solo sucursales operativas: %5d filas  $%s', (int)($portOp->filas ?? 0), number_format((float)($portOp->total ?? 0), 2)));
        $this->printDiff('  Cartera operativa vs target', (float)($portOp->total ?? 0), 39_130_054.53);
        $this->line('');

        // ── fact_placements (Ministraciones) ──────────────────────────────────
        $this->info('── fact_placements (Ministraciones) ──');
        $placTotal = DB::table('fact_placements')->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as filas, SUM(amount) as total')->first();
        $placNoRestr = DB::table('fact_placements')->whereIn('period_id', $dataIds)
            ->where(function ($q) {
                $q->whereNull('product_name')
                  ->orWhereRaw("product_name NOT REGEXP 'REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS'");
            })
            ->selectRaw('COUNT(*) as filas, SUM(amount) as total')->first();
        $placOp = DB::table('fact_placements')->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $realOperativeIds)
            ->where(function ($q) {
                $q->whereNull('product_name')
                  ->orWhereRaw("product_name NOT REGEXP 'REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS'");
            })
            ->selectRaw('COUNT(*) as filas, SUM(amount) as total')->first();

        $this->line(sprintf('  Total en BD              : %5d filas  $%s', (int)($placTotal->filas ?? 0), number_format((float)($placTotal->total ?? 0), 2)));
        $this->line(sprintf('  Sin reestructuras        : %5d filas  $%s', (int)($placNoRestr->filas ?? 0), number_format((float)($placNoRestr->total ?? 0), 2)));
        $this->line(sprintf('  Operativo sin restr.     : %5d filas  $%s', (int)($placOp->filas ?? 0), number_format((float)($placOp->total ?? 0), 2)));
        $this->printDiff('  Colocación operativa vs target', (float)($placOp->total ?? 0), 14_538_964.00);
        $this->line('');

        // ── fact_expenses (Gastos Lendus + ERP) ──────────────────────────────
        $this->info('── fact_expenses (Gastos Lendus + Gastos ERP) ──');
        $bySource = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->selectRaw("ds.code, COUNT(*) as filas, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get();

        foreach ($bySource as $s) {
            $this->line(sprintf('  %-25s : %5d filas  $%s', $s->code, $s->filas, number_format((float)$s->total, 2)));
        }

        // gastos_lendus_excel is the same data as gastos_lendus (PDF) — use only PDF to avoid double-count
        $gastosLendus = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ds.code', 'gastos_lendus')
            ->whereNotIn('e.category', ['Envío de utilidad a corporativo', 'Préstamos Intersucursales', 'Nómina y Capital Humano'])
            ->selectRaw("SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->value('total');

        $gastosErp = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ds.code', 'gastos_erp')
            ->whereNotIn('e.category', ['Gastos Operativos', 'Pólizas', 'Renta Oficina', 'Recargas Telefónicas', 'Gasolina', 'Agua', 'Envío de utilidad a corporativo', 'Préstamos Intersucursales', 'Nómina y Capital Humano'])
            ->selectRaw("SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->value('total');

        $gastosTotal = (float)($gastosLendus ?? 0) + (float)($gastosErp ?? 0);
        $this->line(sprintf('  Gastos op (Lendus+ERP deduplicado) : $%s', number_format($gastosTotal, 2)));
        $this->printDiff('  Gastos operativos vs target', $gastosTotal, 837_384.28);
        $this->line('  (Ver debug-gastos-sources para detalle categoría por categoría)');
        $this->line('');

        // ── fact_noi_movements (NOMINANUEVA + NOMINAF) ────────────────────────
        $this->info('── fact_noi_movements (NOI: NOMINANUEVA + NOMINAF) ──');
        $noiBySource = DB::table('fact_noi_movements as n')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->selectRaw("ds.code, ru.original_name, COUNT(DISTINCT n.employee_id) as empleados, SUM(n.amount) as total")
            ->groupBy('ds.id', 'ds.code', 'ru.id', 'ru.original_name')
            ->orderByDesc('total')
            ->get();

        foreach ($noiBySource as $s) {
            $this->line(sprintf('  %-25s %-20s : %3d emps  $%s',
                $s->code, mb_substr($s->original_name ?? '—', 0, 20), $s->empleados, number_format((float)$s->total, 2)));
        }

        $nomina = (float) DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)
            ->where('concept', 'P001 SUELDO')->where('concept_type', 'percepcion')->sum('amount');
        $comisiones = (float) DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)
            ->whereRaw("concept LIKE 'P002%'")->sum('amount');
        $bonos = (float) DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)
            ->whereRaw("concept REGEXP '^P(108|109|120|123)'")->sum('amount');

        $this->line('');
        $this->printDiff('  Nómina P001 vs target', $nomina, 1_075_509.70);
        $this->printDiff('  Comisiones P002 vs target', $comisiones, 716_885.13);
        $this->printDiff('  Bonos P1xx vs target', $bonos, 291_655.85);
        $this->line('  (Ver debug-nomina-sources para detalle por archivo y empleado)');
        $this->line('');

        // ── Mora (ajuste DPD) ─────────────────────────────────────────────────
        $this->info('── Mora por bucket (fact_portfolios, sucursales operativas) ──');
        $moraRows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $realOperativeIds)
            ->where('days_past_due', '>', 0)
            ->selectRaw('days_past_due, SUM(COALESCE(NULLIF(past_due_balance,0), balance)) as mora')
            ->groupBy('days_past_due')
            ->get();

        $buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '120+' => 0.0];
        $adj     = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '120+' => 0.0];
        $targets = ['0-30' => 1_057_765.00, '31-60' => 926_001.80, '61-90' => 913_414.71, '91-120' => 816_584.91, '120+' => 10_259_891.95];

        foreach ($moraRows as $row) {
            $dpd  = (int) $row->days_past_due;
            $mora = (float) $row->mora;
            $b    = match (true) { $dpd <= 30 => '0-30', $dpd <= 60 => '31-60', $dpd <= 90 => '61-90', $dpd <= 120 => '91-120', default => '120+' };
            $buckets[$b] += $mora;
            $d16 = max(0, $dpd - 16);
            if ($d16 > 0) {
                $ba = match (true) { $d16 <= 30 => '0-30', $d16 <= 60 => '31-60', $d16 <= 90 => '61-90', $d16 <= 120 => '91-120', default => '120+' };
                $adj[$ba] += $mora;
            }
        }

        $this->line(sprintf('  %-10s  %-14s  %-14s  %-14s  %s', 'Bucket', 'Archivo (raw)', 'Ajust(-16d)', 'Target', 'Dif adj'));
        foreach ($buckets as $b => $val) {
            $a    = $adj[$b];
            $tgt  = $targets[$b];
            $diff = $a - $tgt;
            $pct  = $tgt > 0 ? abs($diff / $tgt) * 100 : 0;
            $color = $pct < 2 ? 'green' : ($pct < 10 ? 'yellow' : 'red');
            $this->line(sprintf("  %-10s  \$%-13s  \$%-13s  \$%-13s  <fg={$color}>%+.2f (%.1f%%)</>",
                $b,
                number_format($val, 2),
                number_format($a, 2),
                number_format($tgt, 2),
                $diff, $pct));
        }
        $this->line('  * Ajust(-16d): DPD reducido 16 días → DPD al 2026-04-30 (fecha fin período)');
        $this->line('  * Ver debug-mora-raw para análisis completo.');
        $this->line('');

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function printDiff(string $label, float $calc, float $target): void
    {
        $diff  = $calc - $target;
        $pct   = $target > 0 ? abs($diff / $target) * 100 : 0;
        $color = $pct < 0.5 ? 'green' : ($pct < 5.0 ? 'yellow' : 'red');
        $this->line(sprintf("  %s: calc=\$%s  tgt=\$%s  <fg={$color}>dif=%+.2f (%.2f%%)</>",
            $label,
            number_format($calc, 2),
            number_format($target, 2),
            $diff, $pct));
    }
}
