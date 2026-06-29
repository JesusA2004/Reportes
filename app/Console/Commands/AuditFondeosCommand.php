<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditFondeosCommand extends Command
{
    protected $signature = 'reportes:audit-fondeos
                                {period_id}
                                {--detail : mostrar cada movimiento de fondeo}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría fondeos entre sucursales: origen, destino, responsable y monto';

    // Official branch names for normalization
    private const BRANCH_ALIASES = [
        'SAN JUAN DEL RIO'  => 'SAN JUAN DEL RÍO',
        'SAN JUAN'          => 'SAN JUAN DEL RÍO',
        'TENANGO'           => 'TENANGO DEL VALLE',
        'CORDOBA'           => 'CÓRDOBA',
        'SAN LUIS POTOSI'   => 'SAN LUIS POTOSÍ',
        'MIACATLAN'         => 'MIACATLÁN',
    ];

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA FONDEOS LENDUS — {$period->label} (ID {$period->id})");
        $this->info('  Categorías: Préstamos Intersucursales + Envío de utilidad a corporativo');
        $this->info('  Nota: NO afectan EBITDA — solo rastreo de flujo');
        $this->info('════════════════════════════════════════════════════════════');

        // Use PDF only (gastos_lendus) — Excel records have branch_id=NULL and are used only for
        // destination cross-reference in buildInterbranchLoans(). Showing them as separate rows
        // would produce 132 "sin origen" false positives.
        $lendusPdfId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');

        $fondeos = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('e.category', ['Préstamos Intersucursales', 'Envío de utilidad a corporativo'])
            ->when($lendusPdfId, fn ($q) => $q->where('ru.data_source_id', $lendusPdfId))
            ->select(
                'e.id',
                'b.name as sucursal_origen',
                'e.category as categoria',
                'e.concept as concepto',
                'e.observations',
                'e.expense_date as fecha',
                'e.amount',
                'e.paid_amount',
                'ds.code as fuente',
                'e.raw_payload',
            )
            ->orderBy('e.category')
            ->orderBy('b.name')
            ->orderByDesc('e.amount')
            ->get();

        if ($fondeos->isEmpty()) {
            $this->info('  No se encontraron movimientos de fondeo en el período.');
            return 0;
        }

        // Pre-load Excel records indexed by (amount rounded to int) for cross-reference
        $lendusExcelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        $excelByAmount = [];
        if ($lendusExcelId) {
            $excelRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $lendusExcelId)
                ->whereIn('e.category', ['Préstamos Intersucursales', 'Envío de utilidad a corporativo'])
                ->select('e.id', 'e.amount', 'e.paid_amount', 'e.observations', 'e.raw_payload')
                ->get();
            foreach ($excelRows as $ex) {
                $amt = (int) round((float) ($ex->paid_amount ?: $ex->amount));
                $excelByAmount[$amt][] = $ex;
            }
        }

        $usedExcelIds = [];

        $totalFondeo     = 0.0;
        $totalCorporativo = 0.0;
        $sinOrigen       = 0.0;
        $sinDestino      = 0.0;
        $porOrigen       = [];
        $porDestino      = [];
        $estadoCounts    = [];
        $detailRows      = [];

        foreach ($fondeos as $row) {
            $monto    = (float) ($row->paid_amount ?: $row->amount);
            $origen   = (string) ($row->sucursal_origen ?? '');
            $cat      = (string) ($row->categoria ?? '');
            $concepto = (string) ($row->concepto ?? '');
            $payload  = json_decode((string) ($row->raw_payload ?? 'null'), true) ?? [];

            // Destination: prefer parsed raw_payload keys, then cross-reference with Excel by amount
            $destinoSuc = $payload['fondeo_destino_sucursal'] ?? null;
            if (!$destinoSuc || $destinoSuc === 'No detectado' || $destinoSuc === 'null') {
                $destinoSuc = null;
            }
            $destinoTexto = $payload['fondeo_destino_texto'] ?? $concepto;
            $fondeoTipo   = $payload['fondeo_tipo'] ?? null;
            $excelObs     = '';

            // Cross-reference with Excel by amount ±1 peso
            if (!$destinoSuc && !empty($excelByAmount)) {
                $montoInt = (int) round($monto);
                $candidates = array_merge(
                    $excelByAmount[$montoInt]     ?? [],
                    $excelByAmount[$montoInt + 1] ?? [],
                    $excelByAmount[$montoInt - 1] ?? [],
                );
                foreach ($candidates as $ex) {
                    if (in_array($ex->id, $usedExcelIds, true)) continue;
                    $exPayload = json_decode((string) ($ex->raw_payload ?? 'null'), true) ?? [];
                    $candidate = $exPayload['branch_to_detected'] ?? null;
                    if (!$candidate || $candidate === 'No detectado' || $candidate === 'null') {
                        // Try to detect from observations
                        $exText = mb_strtoupper(trim((string) ($ex->observations ?? '')));
                        if ($exText) {
                            $candidate = $this->detectFromExcelText($exText);
                        }
                    }
                    if ($candidate) {
                        $destinoSuc   = $candidate;
                        $excelObs     = (string) ($ex->observations ?? '');
                        $fondeoTipo   = 'fondeo_sucursal';
                        $usedExcelIds[] = $ex->id;
                        break;
                    }
                }
            }

            if (!$destinoSuc) {
                $parsed     = $this->parseDestinoFromConcept($concepto);
                $destinoSuc = $parsed['sucursal'];
                $fondeoTipo = $parsed['tipo'];
            }

            // Normalize destination name (strip noise, apply aliases)
            if ($destinoSuc && $destinoSuc !== 'CORPORATIVO') {
                $destinoSuc = $this->stripNoise($destinoSuc);
                $normKey    = strtr(mb_strtoupper(trim($destinoSuc)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
                $destinoSuc = self::BRANCH_ALIASES[$normKey] ?? self::BRANCH_ALIASES[$destinoSuc] ?? $destinoSuc;
            }

            $tipo = $cat === 'Envío de utilidad a corporativo'
                ? 'Envío a corporativo / excedente'
                : ($fondeoTipo === 'fondeo_sucursal' ? 'Fondeo entre sucursales' : 'No clasificado');

            $estado = 'OK';
            $motivo = '';

            if (!$origen) {
                $estado = 'Origen no detectado';
                $motivo = 'branch_id NULL — reimportar PDF para recuperar sucursal';
                $sinOrigen += $monto;
            } elseif ($cat === 'Envío de utilidad a corporativo' || $destinoSuc === 'CORPORATIVO') {
                $estado = 'OK';
                $motivo = "Origen: {$origen} → Corporativo / excedente";
                $totalCorporativo += $monto;
            } elseif (!$destinoSuc || $destinoSuc === 'No detectado') {
                $estado = 'Destino no detectado';
                $motivo = "Texto sin sucursal reconocida: «{$concepto}»";
                $sinDestino  += $monto;
                $totalFondeo += $monto;
            } else {
                $motivo = "Origen: {$origen} → Destino: {$destinoSuc}";
                $totalFondeo += $monto;
                $porDestino[$destinoSuc] = ($porDestino[$destinoSuc] ?? 0.0) + $monto;
            }

            $estadoCounts[$estado] = ($estadoCounts[$estado] ?? 0) + 1;
            $porOrigen[$origen ?: '(sin sucursal)'] = ($porOrigen[$origen ?: '(sin sucursal)'] ?? 0.0) + $monto;

            $detailRows[] = [
                'id'           => $row->id,
                'origen'       => $origen ?: '(sin sucursal)',
                'destino_suc'  => $destinoSuc ?? 'No detectado',
                'destino_texto'=> $destinoTexto,
                'tipo'         => $tipo,
                'empleado'     => (string) ($row->observations ?? ''),
                'concepto'     => $concepto,
                'fecha'        => $row->fecha,
                'monto'        => $monto,
                'fuente'       => (string) ($row->fuente ?? ''),
                'estado'       => $estado,
                'motivo'       => $motivo,
            ];
        }

        $totalTotal = $totalFondeo + $totalCorporativo + $sinOrigen;

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->line(str_pad('Total registros', 44) . count($detailRows));
        $this->line(str_pad('Total monto',     44) . '$' . number_format($totalTotal, 2));
        $this->line('');
        $this->info(str_pad('Fondeos entre sucursales',     44) . '$' . number_format($totalFondeo, 2));
        $this->info(str_pad('Envíos a corporativo/excedente', 44) . '$' . number_format($totalCorporativo, 2));
        if ($sinOrigen > 0)  $this->warn(str_pad('Sin origen detectado',     44) . '$' . number_format($sinOrigen, 2));
        if ($sinDestino > 0) $this->warn(str_pad('Sin destino detectado',    44) . '$' . number_format($sinDestino, 2));
        $this->line('');
        foreach ($estadoCounts as $est => $cnt) {
            $this->line(str_pad("  Estado [{$est}]", 44) . $cnt . ' registros');
        }

        // ── Por sucursal destino ──────────────────────────────────────────────
        if (!empty($porDestino)) {
            $this->line('');
            $this->info('════ FONDEOS POR DESTINO ════');
            arsort($porDestino);
            $this->line(str_pad('Sucursal destino', 30) . 'Total recibido');
            $this->line(str_repeat('─', 50));
            foreach ($porDestino as $dest => $amt) {
                $this->line(str_pad($dest, 30) . '$' . number_format($amt, 2));
            }
        }

        // ── Por sucursal origen ──────────────────────────────────────────────
        $this->line('');
        $this->info('════ POR SUCURSAL ORIGEN ════');
        arsort($porOrigen);
        $this->line(str_pad('Sucursal Origen', 30) . 'Total fondeado');
        $this->line(str_repeat('─', 50));
        foreach ($porOrigen as $suc => $amt) {
            $this->line(str_pad($suc, 30) . '$' . number_format($amt, 2));
        }

        // ── Tabla detalle ────────────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ TABLA DETALLE DE FONDEOS ════');
            $this->line(
                str_pad('ID', 8) .
                str_pad('Origen', 20) .
                str_pad('Destino', 22) .
                str_pad('Monto', 16) .
                str_pad('Estado', 26) .
                'Concepto'
            );
            $this->line(str_repeat('─', 140));
            foreach ($detailRows as $r) {
                $line = str_pad($r['id'], 8) .
                        str_pad(mb_substr($r['origen'], 0, 18), 20) .
                        str_pad(mb_substr($r['destino_suc'], 0, 20), 22) .
                        str_pad('$' . number_format($r['monto'], 2), 16) .
                        str_pad(mb_substr($r['estado'], 0, 24), 26) .
                        mb_substr($r['concepto'], 0, 50);
                match ($r['estado']) {
                    'OK'  => $this->line($line),
                    default => $this->warn($line),
                };
            }
        }

        // ── Export CSV ────────────────────────────────────────────────────────
        if ($this->option('export')) {
            $this->exportCsv($period->id, $detailRows);
        }

        return 0;
    }

    private function parseDestinoFromConcept(string $concept): array
    {
        $cu = strtr(mb_strtoupper(trim($concept)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $cu = preg_replace('/\s+/', ' ', $cu) ?? $cu;

        if (str_contains($cu, 'CORPORATIVO') || str_contains($cu, 'EXCEDENTE')) {
            return ['sucursal' => 'CORPORATIVO', 'tipo' => 'excedente_corporativo'];
        }

        $noise = [
            '/^FONDEO\s+(A\s+)?SUC\.?\s*/i', '/^FONDEO\s+(A\s+)?SUCURSAL\s*/i',
            '/^FONDEO\s+A\s*/i', '/^FONDEO\s*/i', '/^A\s+SUC\.?\s*/i',
            '/^A\s+SUCURSAL\s*/i', '/^PRESTAMO\s+INTERSUCURSAL\s*/i',
            '/^PRESTAMO\s+A\s*/i', '/\s*FONDEO\s*/i', '/\s*DEPOSITO\s*/i',
        ];
        $clean = $cu;
        foreach ($noise as $p) { $clean = preg_replace($p, ' ', $clean) ?? $clean; }
        $clean = trim($clean);

        $suc = $this->matchBranch($clean);
        return ['sucursal' => $suc ?? 'No detectado', 'tipo' => $suc ? 'fondeo_sucursal' : 'no_clasificado'];
    }

    private function matchBranch(string $text): ?string
    {
        $map = [
            'ATLACOMULCO'=>'ATLACOMULCO','ATLIXCO'=>'ATLIXCO','CORDOBA'=>'CÓRDOBA',
            'CUERNAVACA'=>'CUERNAVACA','HUAMANTLA'=>'HUAMANTLA','IXTLAHUACA'=>'IXTLAHUACA',
            'MIACATLAN'=>'MIACATLÁN','ORIZABA'=>'ORIZABA','SAN JUAN DEL RIO'=>'SAN JUAN DEL RÍO',
            'SAN JUAN'=>'SAN JUAN DEL RÍO','SAN LUIS POTOSI'=>'SAN LUIS POTOSÍ',
            'TENANGO DEL VALLE'=>'TENANGO DEL VALLE','TENANGO'=>'TENANGO DEL VALLE',
            'TLAXCALA'=>'TLAXCALA','TULA'=>'TULA','CHIHUAHUA'=>'CHIHUAHUA',
            'DURANGO'=>'DURANGO','YAUTEPEC'=>'YAUTEPEC',
        ];
        $norm = strtr(mb_strtoupper(trim($text)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        foreach ($map as $key => $official) {
            if (str_contains($norm, $key)) return $official;
        }
        return null;
    }

    private function detectFromExcelText(string $textUpper): ?string
    {
        static $noiseLeading = ['FONDEO', 'FONDEA', 'DEPOSITO', 'DEPÓSITO', 'A', 'PARA', 'DE', 'SUC', 'SUCURSAL'];

        $patterns = [
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/FOND(?:EA|EO)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/FOND(?:EA|EO)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/DEP[OÓ]SITO\s+(?:A|PARA)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $textUpper, $m)) {
                $candidate = trim($m[1]);
                // Strip noise words
                $changed = true;
                while ($changed) {
                    $changed = false;
                    foreach ($noiseLeading as $noise) {
                        if (str_starts_with($candidate, $noise . ' ')) {
                            $candidate = trim(mb_substr($candidate, mb_strlen($noise)));
                            $changed = true;
                            break;
                        }
                    }
                }
                if (strlen($candidate) >= 3) {
                    $matched = $this->matchBranch($candidate);
                    if ($matched) return $matched;
                }
            }
        }

        return null;
    }

    private function stripNoise(string $candidate): string
    {
        static $noiseLeading = ['FONDEO', 'FONDEA', 'DEPOSITO', 'DEPÓSITO', 'A', 'PARA', 'DE', 'SUC', 'SUCURSAL'];
        $c = mb_strtoupper(trim($candidate));
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($noiseLeading as $noise) {
                if (str_starts_with($c, $noise . ' ')) {
                    $c = trim(mb_substr($c, mb_strlen($noise)));
                    $changed = true;
                    break;
                }
            }
        }
        return $c;
    }

    private function exportCsv(int $periodId, array $rows): void
    {
        $dir  = 'auditorias';
        $file = "fondeos_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $headers = ['ID', 'Sucursal Origen', 'Sucursal Destino', 'Destino Texto', 'Tipo', 'Empleado', 'Concepto', 'Fecha', 'Monto', 'Fuente', 'Estado', 'Motivo'];
        $lines   = [implode(',', $headers)];

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $r['id'],
                '"' . str_replace('"', '""', $r['origen']) . '"',
                '"' . str_replace('"', '""', $r['destino_suc']) . '"',
                '"' . str_replace('"', '""', $r['destino_texto']) . '"',
                '"' . str_replace('"', '""', $r['tipo']) . '"',
                '"' . str_replace('"', '""', $r['empleado']) . '"',
                '"' . str_replace('"', '""', $r['concepto']) . '"',
                $r['fecha'] ?? '',
                number_format($r['monto'], 2, '.', ''),
                '"' . str_replace('"', '""', $r['fuente']) . '"',
                '"' . str_replace('"', '""', $r['estado']) . '"',
                '"' . str_replace('"', '""', $r['motivo']) . '"',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
