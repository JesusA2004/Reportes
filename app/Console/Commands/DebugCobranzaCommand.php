<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Support\OperationalExclusion;
use App\Support\RegionNorteFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugCobranzaCommand extends Command
{
    protected $signature   = 'reportes:debug-cobranza {period_id : ID del periodo mensual}';
    protected $description = 'Diagnóstico de cobranza en BD: totales por Transacción/Estatus, desglose capital/interés/impuesto/cargos, diferencia contra target.';

    // Referencia manual Abril 2026
    private const TARGET_TOTAL_INGRESOS  = 18_332_149.55;
    private const TARGET_RECUPERACION    = 18_323_749.55;

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
        $this->info("══════════════════════════════════════════════════════════════");
        $this->info("  DEBUG COBRANZA — {$period->label} (ID #{$periodId})");
        $this->info("══════════════════════════════════════════════════════════════");
        $this->line("  Data IDs: [" . implode(', ', $dataIds) . "]");
        $this->line('');

        // ── 1. Totales globales en fact_recoveries ─────────────────────
        $this->info('── 1. Totales globales en fact_recoveries ──');
        $glob = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as filas, SUM(capital) as capital, SUM(interest) as interes, SUM(tax) as impuesto, SUM(charges) as cargos, SUM(total_amount) as total')
            ->first();

        $totalBD = (float)($glob->total ?? 0);
        $this->line(sprintf('  Filas            : %d', $glob->filas ?? 0));
        $this->line(sprintf('  Capital          : $%s  (manual ref: $12,883,858.49)', number_format((float)($glob->capital ?? 0), 2)));
        $this->line(sprintf('  Interés          : $%s  (manual ref: $4,545,211.24)', number_format((float)($glob->interes ?? 0), 2)));
        $this->line(sprintf('  Impuesto         : $%s  (manual ref: $727,231.40)', number_format((float)($glob->impuesto ?? 0), 2)));
        $this->line(sprintf('  Cargos/Multas    : $%s  (manual ref: $149,384.52 + $18,063.90)', number_format((float)($glob->cargos ?? 0), 2)));
        $this->line(sprintf('  Total importado  : $%s', number_format($totalBD, 2)));
        $this->line('');

        // ── 2. Totales por Transacción (desde raw_payload) ────────────
        $this->info('── 2. Totales por Transacción (raw_payload.transaction) ──');
        $rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) as transaccion, COUNT(*) as filas, SUM(total_amount) as total")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction'))")
            ->orderByDesc('total')
            ->get();

        $totalByTrans = 0.0;
        foreach ($rows as $r) {
            $transLabel = $r->transaccion ?: '(NULL / vacío)';
            $this->line(sprintf('  %-30s filas=%-6d total=$%s', mb_substr($transLabel, 0, 30), $r->filas, number_format((float)$r->total, 2)));
            $totalByTrans += (float)$r->total;
        }
        $this->line(sprintf('  %-30s SUMA:  $%s', '', number_format($totalByTrans, 2)));
        $this->line('');

        // ── 3. Totales por Estatus pago (desde raw_payload) ───────────
        $this->info('── 3. Totales por Estatus pago (raw_payload.concept / estatus derivado) ──');
        $byConcepto = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.concept')) as concepto, COUNT(*) as filas, SUM(total_amount) as total")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.concept'))")
            ->orderByDesc('total')
            ->get();

        foreach ($byConcepto as $r) {
            $label = $r->concepto ?: '(NULL)';
            $this->line(sprintf('  %-35s filas=%-6d total=$%s', mb_substr($label, 0, 35), $r->filas, number_format((float)$r->total, 2)));
        }
        $this->line('');

        // ── 4. Transacción × Concepto (combinado) ─────────────────────
        $this->info('── 4. Transacción × Concepto (top 20 combinaciones) ──');
        $combined = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) as transaccion,
                JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.concept')) as concepto,
                COUNT(*) as filas,
                SUM(total_amount) as total
            ")
            ->groupByRaw("
                JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')),
                JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.concept'))
            ")
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        foreach ($combined as $r) {
            $this->line(sprintf('  %-20s | %-25s | filas=%-5d | $%s',
                mb_substr($r->transaccion ?? '(null)', 0, 20),
                mb_substr($r->concepto ?? '(null)', 0, 25),
                $r->filas,
                number_format((float)$r->total, 2)));
        }
        $this->line('');

        // ── 5. Filtro Norte preciso sobre PAGO ────────────────────────
        $this->info('── 5. Filtro Norte preciso sobre PAGO (raw_payload + sucursal inequívoca) ──');
        $this->line('  Regla: excluir PAGO solo con evidencia Norte clara:');
        $this->line('    a) Sucursal inequívoca Norte (CHIHUAHUA, DURANGO, VIS-NOR, CER-GRAN…)');
        $this->line('    b) Prefijo acreditado CH/DG + dígito en raw_payload.accredited_name');
        $this->line('    c) Zona Norte en raw_payload.zone_name');
        $this->line('  Nombres genéricos (CENTRO, VILLAS, FACTOR…) NO se excluyen sin evidencia.');
        $this->line('');

        $norteExpr = RegionNorteFilter::norteDetectionExpr('r', 'b');

        // Total PAGO bruto
        $pagoBruto = (float) DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->sum('r.total_amount');

        // PAGO excluido Norte (cualquier evidencia)
        $pagoExcluidoNorte = (float) DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->whereRaw($norteExpr)
            ->sum('r.total_amount');

        // Desglose: excluido solo por sucursal inequívoca
        $unambig = RegionNorteFilter::names();
        $pagoExclSucursal = (float) DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->whereNotNull('b.name')
            ->whereIn(DB::raw('LOWER(b.name)'), $unambig)
            ->sum('r.total_amount');

        // Desglose: excluido por prefijo acreditado CH/DG
        $pagoExclPrefijo = (float) DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            // exclude what's already caught by unambiguous branch to avoid double count
            ->where(function ($q) use ($unambig) {
                $q->whereNull('b.name')->orWhereNotIn(DB::raw('LOWER(b.name)'), $unambig);
            })
            ->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload,'$.accredited_name')),'') REGEXP ?",
                ['^(CH|DG)[0-9]']
            )
            ->sum('r.total_amount');

        // Desglose: excluido por zone_name Norte
        $pagoExclZona = (float) DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->where(function ($q) use ($unambig) {
                $q->whereNull('b.name')->orWhereNotIn(DB::raw('LOWER(b.name)'), $unambig);
            })
            ->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload,'$.accredited_name')),'') NOT REGEXP ?",
                ['^(CH|DG)[0-9]']
            )
            ->whereRaw(
                "UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload,'$.zone_name')),'')) REGEXP ?",
                ['CHIHUAHUA|DURANGO|AGUASCALIENTES|MATRIZ NORTE|REGION NORTE']
            )
            ->sum('r.total_amount');

        // PAGO excluido por Corporativo / Falso / Inactiva (NOT Norte)
        $pagoExclCorporativo = (float) DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            // Must NOT be Norte already
            ->whereRaw("NOT ({$norteExpr})")
            ->whereNotNull('b.name')
            ->whereIn(DB::raw('LOWER(b.name)'), OperationalExclusion::names())
            ->sum('r.total_amount');

        // Operative PAGO final: Norte out + Corporativo/Falso out
        $pagoOperativo = (float) OperationalExclusion::applyTo(
            RegionNorteFilter::applyRecoveryFilter(
                DB::table('fact_recoveries as r')
                    ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
                    ->whereIn('r.period_id', $dataIds)
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            )
        )->sum('r.total_amount');

        $targetRec = self::TARGET_RECUPERACION;
        $diffOp    = $pagoOperativo - $targetRec;

        $this->line(sprintf('  PAGO bruto total              : $%s', number_format($pagoBruto, 2)));
        $this->line(sprintf('  ── Excluido por Norte ─────────────────────────────────────────'));
        $this->line(sprintf('     Sucursal Norte inequívoca   : -$%s', number_format($pagoExclSucursal, 2)));
        $this->line(sprintf('     Prefijo acreditado CH/DG    : -$%s', number_format($pagoExclPrefijo, 2)));
        $this->line(sprintf('     Zona Norte                  : -$%s', number_format($pagoExclZona, 2)));
        $this->line(sprintf('     Total excluido Norte        : -$%s', number_format($pagoExcluidoNorte, 2)));
        $this->line(sprintf('  ── Excluido por Corporativo/Falso ────────────────────────────'));
        $this->line(sprintf('     Corporativo / Falso         : -$%s', number_format($pagoExclCorporativo, 2)));
        $this->line(sprintf('  ──────────────────────────────────────────────────────────────'));
        $this->line(sprintf('  PAGO operativo final          :  $%s', number_format($pagoOperativo, 2)));
        $this->line(sprintf('  Target recuperación           :  $%s', number_format($targetRec, 2)));
        $diffColor = abs($diffOp) < 1000 ? 'green' : (abs($diffOp) < 50000 ? 'yellow' : 'red');
        $this->line(sprintf("  Diferencia                    :  <fg={$diffColor}>%s%s</>",
            $diffOp >= 0 ? '+' : '', number_format($diffOp, 2)));
        $this->line('');

        // Top registros excluidos por Norte (PAGO)
        $this->info('── 5b. Top registros PAGO excluidos por Norte ──');
        $topExcluidos = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->whereRaw($norteExpr)
            ->selectRaw("
                COALESCE(b.name,'(sin sucursal)') as sucursal,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload,'$.accredited_name'))) as acreditado_muestra,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload,'$.zone_name'))) as zona_muestra,
                COUNT(*) as filas,
                SUM(r.total_amount) as total
            ")
            ->groupByRaw("b.id, b.name")
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        foreach ($topExcluidos as $row) {
            $branchLower = strtolower($row->sucursal ?? '');
            $isNorte     = in_array($branchLower, $unambig, true);
            $acr         = substr($row->acreditado_muestra ?? '', 0, 8);
            $acr2        = substr($acr, 0, 2);
            $reason = $isNorte ? 'Norte inequívoca' :
                (preg_match('/^(CH|DG)\d/', $acr) ? "prefijo {$acr2} (Norte)" :
                    "zona Norte:{$row->zona_muestra}");
            $this->line(sprintf('  ✗ %-22s %-10s %-8s filas=%-4d $%s  [%s]',
                mb_substr($row->sucursal, 0, 22),
                mb_substr($row->acreditado_muestra ?? '(null)', 0, 10),
                mb_substr($row->zona_muestra ?? '(null)', 0, 8),
                $row->filas,
                number_format((float)$row->total, 2),
                $reason));
        }
        $this->line('');

        // Rutas genéricas detectadas pero conservadas (no Norte según evidencia)
        $this->info('── 5c. Rutas genéricas conservadas (no excluidas por falta de evidencia Norte) ──');
        $genericNames = ['centro', 'villas', 'manantial', 'factor', 'universal', 'supervisor', 'pri', 'industrial', 'santa fe'];
        $genericRows = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->whereIn(DB::raw('LOWER(b.name)'), $genericNames)
            ->whereRaw("NOT ({$norteExpr})")
            ->selectRaw("COALESCE(b.name,'?') as sucursal, COUNT(*) as filas, SUM(r.total_amount) as total")
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('total')
            ->get();

        if ($genericRows->isEmpty()) {
            $this->line('  Ninguna ruta genérica conservada.');
        } else {
            foreach ($genericRows as $row) {
                $this->line(sprintf('  ~ %-22s filas=%-4d $%s  [conservada — no es Norte según payload]',
                    mb_substr($row->sucursal, 0, 22), $row->filas, number_format((float)$row->total, 2)));
            }
        }
        $this->line('');

        // ── 6. Diferencia contra targets ──────────────────────────────
        $this->info('── 6. Diferencia contra targets ──');
        $targetIng    = self::TARGET_TOTAL_INGRESOS;
        $diffRec      = $totalBD - $targetRec;
        $diffIng      = $totalBD - $targetIng;

        $this->line(sprintf('  Total importado BD (bruto) : $%s', number_format($totalBD, 2)));
        $this->line(sprintf('  PAGO operativo (Norte out) : $%s   dif vs target=%s',
            number_format($pagoOperativo, 2),
            number_format($diffOp, 2)));
        $this->line(sprintf('  Target recuperación        : $%s', number_format($targetRec, 2)));
        $this->line(sprintf('  Target total ingresos      : $%s', number_format($targetIng, 2)));
        $this->line('');

        $this->info("══════════════════════════════════════════════════════════════");
        $this->line('');

        return self::SUCCESS;
    }
}
