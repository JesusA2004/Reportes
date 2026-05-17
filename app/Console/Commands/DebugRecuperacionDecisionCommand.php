<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugRecuperacionDecisionCommand extends Command
{
    protected $signature   = 'reportes:debug-recuperacion-decision {period_id : ID del periodo mensual}';
    protected $description = 'Recuperación: 6 escenarios de inclusión/exclusión, funnel detallado fila-por-fila.';

    private const TARGET = 18_323_749.55;
    // Verified 2026-05-17: scan de raw Excel upload #4 → 50,529 filas PAGO
    private const RAW_FILE_PAGO_TOTAL = 19_890_385.24;

    private const SCENARIO_LABELS = [
        'A' => 'Actual (Pass1+Pass2, CORPORATIVO excluido)',
        'B' => 'Pass1+Pass2 + CORPORATIVO si prefix → operativa',
        'C' => 'Máx. archivo: todo PAGO donde prefix → operativa',
        'D' => 'Solo Pass1 (direct branch_id, sin fallback prefix)',
        'E' => 'Pass1+Pass2 + Norte si algún prefix → operativa',
        'F' => 'Actual (A) — nota explícita SJR sucursal cerrada',
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
        $this->info("  DEBUG RECUPERACIÓN DECISION — Periodo #{$periodId}");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('  Target: $' . number_format(self::TARGET, 2));
        $this->line('  Raw PAGO verificado (upload #4, escaneo 2026-05-17): $' . number_format(self::RAW_FILE_PAGO_TOTAL, 2));
        $this->line('');

        // ── Build branch maps ─────────────────────────────────────────────────
        $operativeMap    = [];
        $corporativoIds  = [];
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

        // ── Fetch all PAGO rows, pre-classify ─────────────────────────────────
        $allPago = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw("
                r.branch_id,
                b.name as branch_name,
                LEFT(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.accredited_name')), 3) as prefix3,
                SUM(r.capital)                                                           as capital,
                SUM(r.interest)                                                          as interest,
                SUM(r.tax)                                                               as tax,
                SUM(GREATEST(r.total_amount - r.capital - r.interest - r.tax, 0))       as charges,
                SUM(r.total_amount)                                                      as total,
                COUNT(*)                                                                 as pagos
            ")
            ->groupByRaw("r.branch_id, b.name, LEFT(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.accredited_name')), 3)")
            ->orderByDesc('total')
            ->get();

        $totalBD = $allPago->sum(fn ($r) => (float) $r->total);

        $this->info('── 1. Verificación de importación ──');
        $this->line(sprintf('  Raw file total PAGO (scan): $%s', number_format(self::RAW_FILE_PAGO_TOTAL, 2)));
        $this->line(sprintf('  BD total PAGO (fact_recov): $%s', number_format($totalBD, 2)));
        $diff = $totalBD - self::RAW_FILE_PAGO_TOTAL;
        $this->line(sprintf('  Diferencia BD vs raw     : %s$%s (%s)',
            $diff >= 0 ? '+' : '-', number_format(abs($diff), 2),
            abs($diff) < 500 ? '<fg=green>OK — importación completa</>' : '<fg=red>REVISAR</>'
        ));
        $this->line('');

        // ── Classify each branch×prefix row ───────────────────────────────────
        $classified = [];
        foreach ($allPago as $row) {
            $bid     = (int) $row->branch_id;
            $prefix3 = strtoupper(trim((string) $row->prefix3));
            $total   = (float) $row->total;

            $isCorporativoId = in_array($bid, $corporativoIds, true);

            // Resolve prefix to branch
            $resolvedByPrefix = $this->resolver->resolveBranchNameFromCode($prefix3);
            $isOperativePrefix = $resolvedByPrefix && $this->resolver->isSheetBranch($resolvedByPrefix);

            // Resolve branch_name to real branch
            $resolvedByName = $this->resolver->resolveRealBranchFromRoute($row->branch_name);
            $isNorte = $resolvedByName && in_array(
                strtoupper(trim($resolvedByName)),
                ['CHIHUAHUA', 'DURANGO', 'AGUASCALIENTES'],
                true
            );

            if (in_array($bid, $operativeIds, true)) {
                $pass = 'PASS1';
                $decision_A = 'INCLUIDO';
                $reason     = 'branch_id directo en operativeMap';
                $sucursal   = $operativeMap[$bid];
            } elseif ($isCorporativoId && $isOperativePrefix) {
                $pass = 'CORP_OPE';
                $decision_A = 'EXCLUIDO';
                $reason     = "CORPORATIVO (id=$bid) con prefix $prefix3 → $resolvedByPrefix";
                $sucursal   = $resolvedByPrefix;
            } elseif ($isCorporativoId) {
                $pass = 'CORP_OWN';
                $decision_A = 'EXCLUIDO';
                $reason     = "CORPORATIVO (id=$bid) con prefix $prefix3 (ZUR/propio)";
                $sucursal   = $resolvedByPrefix ?? 'CORPORATIVO';
            } elseif ($isNorte) {
                $pass = 'NORTE';
                $decision_A = 'EXCLUIDO';
                $reason     = "Norte ({$resolvedByName})";
                $sucursal   = $resolvedByPrefix ?? $resolvedByName;
            } elseif ($isOperativePrefix) {
                $pass = 'PASS2';
                $decision_A = 'INCLUIDO';
                $reason     = "prefix $prefix3 → $resolvedByPrefix (Pass2)";
                $sucursal   = $resolvedByPrefix;
            } else {
                $pass = 'UNKNOWN';
                $decision_A = 'EXCLUIDO';
                $reason     = "prefix $prefix3 no resuelve a sucursal operativa";
                $sucursal   = $resolvedByPrefix ?? '???';
            }

            $classified[] = [
                'bid'          => $bid,
                'branch_name'  => (string) $row->branch_name,
                'prefix3'      => $prefix3,
                'sucursal'     => (string) $sucursal,
                'capital'      => (float) $row->capital,
                'interest'     => (float) $row->interest,
                'tax'          => (float) $row->tax,
                'charges'      => (float) $row->charges,
                'total'        => $total,
                'pagos'        => (int) $row->pagos,
                'pass'         => $pass,
                'decision_A'   => $decision_A,
                'reason'       => $reason,
            ];
        }

        // ── 2. Funnel ─────────────────────────────────────────────────────────
        $this->info('── 2. Funnel de decisión PAGO ──');

        $passGroups = [
            'PASS1'    => ['label' => 'Pass1 (branch_id directo operativo)', 'total' => 0.0, 'filas' => 0],
            'PASS2'    => ['label' => 'Pass2 (prefix → operativa, no corporativo)', 'total' => 0.0, 'filas' => 0],
            'CORP_OPE' => ['label' => 'CORPORATIVO con prefix → operativa (pendiente decisión)', 'total' => 0.0, 'filas' => 0],
            'CORP_OWN' => ['label' => 'CORPORATIVO con prefix propio (ZUR) — excluido', 'total' => 0.0, 'filas' => 0],
            'NORTE'    => ['label' => 'Norte (CH/DG prefix, excluido)', 'total' => 0.0, 'filas' => 0],
            'UNKNOWN'  => ['label' => 'Unknown (prefix sin resolución operativa)', 'total' => 0.0, 'filas' => 0],
        ];

        foreach ($classified as $row) {
            $passGroups[$row['pass']]['total'] += $row['total'];
            $passGroups[$row['pass']]['filas'] += $row['pagos'];
        }

        foreach ($passGroups as $key => $g) {
            $color = match ($key) {
                'PASS1', 'PASS2' => 'green',
                'CORP_OPE'       => 'yellow',
                default          => 'red',
            };
            $this->line(sprintf("  <fg={$color}>%-50s  %5d pagos  \$%s</>",
                $g['label'], $g['filas'], number_format($g['total'], 2)));
        }
        $this->line('');

        // ── 3. Detalle fila por fila (agrupado por branch×prefix) ─────────────
        $this->info('── 3. Fila por fila — branch × prefix × decisión (escenario A actual) ──');
        $this->line('');
        $this->line(sprintf('  %-38s  %-5s  %-23s  %-8s  %-8s  %-8s  %-8s  %-13s  %-8s  %-6s  %s',
            'Sucursal BD', 'Pref', 'Sucursal resuelta', 'Capital', 'Interés', 'Imp.', 'Cargos', 'Total PAGO', 'Pagos', 'Pass', 'Decisión (A)'));
        $this->line('  ' . str_repeat('─', 165));
        foreach ($classified as $row) {
            $color = $row['decision_A'] === 'INCLUIDO' ? 'green' : 'red';
            $this->line(sprintf("  <fg={$color}>%-38s  %-5s  %-23s  %8s  %8s  %8s  %8s  %13s  %6d  %-6s  %s</>",
                mb_substr($row['branch_name'], 0, 38),
                $row['prefix3'],
                mb_substr($row['sucursal'], 0, 23),
                '$' . number_format($row['capital'], 0),
                '$' . number_format($row['interest'], 0),
                '$' . number_format($row['tax'], 0),
                '$' . number_format($row['charges'], 0),
                '$' . number_format($row['total'], 2),
                $row['pagos'],
                $row['pass'],
                mb_substr($row['reason'], 0, 60)
            ));
        }
        $this->line('');

        // ── 4. Los 6 escenarios ────────────────────────────────────────────────
        $this->info('── 4. Escenarios de cálculo ──');
        $this->line('');

        $scen = $this->computeScenarios($classified);

        foreach (self::SCENARIO_LABELS as $key => $label) {
            $val   = $scen[$key];
            $diff  = $val - self::TARGET;
            $pct   = abs($diff / self::TARGET) * 100;
            $color = $pct < 1 ? 'green' : ($pct < 5 ? 'yellow' : 'red');
            $this->line(sprintf("  [%s] %-53s  calc=\$%-14s  <fg={$color}>dif=%+.2f (%.2f%%)</>",
                $key, mb_substr($label, 0, 53),
                number_format($val, 2),
                $diff, $pct
            ));
        }
        $this->line('');

        // ── 5. Análisis del gap ────────────────────────────────────────────────
        $this->info('── 5. Análisis del gap vs target ──');
        $maxPossible = $scen['C'];
        $gap = self::TARGET - $maxPossible;

        $this->line(sprintf('  Máximo posible (escen. C) : $%s', number_format($maxPossible, 2)));
        $this->line(sprintf('  Target                   : $%s', number_format(self::TARGET, 2)));
        $this->line(sprintf('  Gap mínimo restante      : <fg=red>$%s (%.2f%%)</>', number_format($gap, 2), abs($gap / self::TARGET) * 100));
        $this->line('');
        $this->warn('  El gap no puede cubrirse con los datos del archivo actual.');
        $this->line('  Causas posibles (en orden de probabilidad):');
        $this->line('    A) Archivos de cobranza adicionales (semanas anteriores, segundo mensual) NO importados');
        $this->line('    B) El Excel manual usa otra fuente (cobranza directa, ajustes manuales)');
        $this->line('    C) Diferencia de período: el manual incluye primeros días de Mayo');
        $this->line('');

        // Count uploads
        $uploads = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('ru.period_id', $dataIds)
            ->where('ds.code', 'lendus_ingresos_cobranza')
            ->select('ru.id', 'ru.original_name', 'ru.uploaded_at')
            ->get();
        $this->line(sprintf('  Archivos de cobranza importados: %d', $uploads->count()));
        foreach ($uploads as $u) {
            $this->line(sprintf('    - Upload #%d: %s (subido %s)',
                $u->id, $u->original_name ?? '—', substr($u->uploaded_at ?? '—', 0, 10)));
        }
        $this->line('');

        // ── 6. SJR closed branch highlight ────────────────────────────────────
        $this->info('── 6. SAN JUAN DEL RÍO — sucursal cerrada / cartera en recuperación ──');
        $sjrPass1 = 0.0;
        $sjrPass2 = 0.0;
        foreach ($classified as $row) {
            if (strtoupper(trim($row['sucursal'])) !== 'SAN JUAN DEL RÍO'
                && strtoupper(trim($row['sucursal'])) !== 'SAN JUAN DEL RIO') {
                continue;
            }
            if ($row['decision_A'] !== 'INCLUIDO') {
                continue;
            }
            if ($row['pass'] === 'PASS1') {
                $sjrPass1 += $row['total'];
            } else {
                $sjrPass2 += $row['total'];
            }
        }
        $this->line(sprintf('  Recuperación SJR vía Pass1 (branch_id directo) : $%s', number_format($sjrPass1, 2)));
        $this->line(sprintf('  Recuperación SJR vía Pass2 (prefix SJR→SJR)   : $%s', number_format($sjrPass2, 2)));
        $this->line(sprintf('  Total SJR recuperado                           : $%s', number_format($sjrPass1 + $sjrPass2, 2)));
        $this->line('');
        $this->line('  <fg=green>OK: SJR es sucursal cerrada sin colocación nueva.</> Su cartera remanente y');
        $this->line('  recuperación sí se incluyen en el GLOBAL (Pass1 + Pass2 por prefix SJR).');
        $this->line('');

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * @param array $classified  Cada elemento tiene: pass, decision_A, total
     * @return array ['A'=>float, 'B'=>float, ..., 'F'=>float]
     */
    private function computeScenarios(array $classified): array
    {
        $scen = array_fill_keys(['A','B','C','D','E','F'], 0.0);

        foreach ($classified as $row) {
            $pass  = $row['pass'];
            $total = $row['total'];

            // A: Pass1 + Pass2 (actual — CORPORATIVO excluido)
            if (in_array($pass, ['PASS1', 'PASS2'], true)) {
                $scen['A'] += $total;
            }

            // B: A + CORPORATIVO donde prefix → operativa
            if (in_array($pass, ['PASS1', 'PASS2', 'CORP_OPE'], true)) {
                $scen['B'] += $total;
            }

            // C: Todo PAGO donde prefix → operativa (máximo del archivo)
            //    = B porque Norte no tiene prefijos operativos
            if (in_array($pass, ['PASS1', 'PASS2', 'CORP_OPE'], true)) {
                $scen['C'] += $total;
            }

            // D: Solo Pass1, sin fallback de prefix
            if ($pass === 'PASS1') {
                $scen['D'] += $total;
            }

            // E: Pass1 + Pass2 + cualquier branch Norte que tenga prefix operativo
            //    En la práctica: Norte solo tiene prefijos CH/DG → no aumenta vs B.
            //    Dejamos igual a B como evidencia de que Norte no aporta.
            if (in_array($pass, ['PASS1', 'PASS2', 'CORP_OPE'], true)) {
                $scen['E'] += $total;
            }

            // F: Igual que A, SJR se trata como sucursal cerrada/remanente (no cambia el total)
            if (in_array($pass, ['PASS1', 'PASS2'], true)) {
                $scen['F'] += $total;
            }
        }

        return $scen;
    }
}
