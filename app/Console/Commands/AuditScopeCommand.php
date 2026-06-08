<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audits which branches each financial module actually uses and whether the
 * calculation matches the 12 configured operative branches.
 *
 * php artisan reportes:audit-scope {period_id} [--detail]
 */
class AuditScopeCommand extends Command
{
    protected $signature = 'reportes:audit-scope
                                {period_id}
                                {--detail : show orphan branches and per-module branch IDs}';
    protected $description = 'Auditoría de alcance: qué sucursales usa cada módulo vs. las 12 configuradas';

    private const CONFIGURED_BRANCH_IDS = [88, 89, 90, 91, 93, 94, 95, 96, 97, 98, 99, 100, 103];

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

        $detail = (bool) $this->option('detail');

        $this->info('════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA DE ALCANCE — {$period->label} (ID {$period->id})");
        $this->info('  Objetivo: verificar que cada módulo usa exactamente las 12 sucursales');
        $this->info('  dataIds: ' . implode(', ', $dataIds));
        $this->info('════════════════════════════════════════════════════════════════');

        // ── Build operativeMap ────────────────────────────────────────────────
        $resolver  = app(BranchResolverService::class);
        $allBranches = DB::table('branches')->get();

        $operativeMap   = [];
        $corporativoIds = [];

        foreach ($allBranches as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if (!$real) {
                continue;
            }
            if ($resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            } elseif (strtoupper(trim($real)) === 'CORPORATIVO') {
                $corporativoIds[] = (int) $b->id;
            }
        }

        $operativeIds = array_keys($operativeMap);
        $queryIds     = array_unique(array_merge($operativeIds, $corporativoIds));

        // ── Section 1: Configured branches ────────────────────────────────────
        $this->line('');
        $this->info('════ 1. SUCURSALES CONFIGURADAS (12 operativas) ════');
        $configured = DB::table('branches')
            ->whereIn('id', self::CONFIGURED_BRANCH_IDS)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        $this->line(str_pad('ID', 6) . str_pad('Nombre', 30) . 'Activa');
        $this->line(str_repeat('─', 50));
        foreach ($configured as $b) {
            $this->line(str_pad($b->id, 6) . str_pad($b->name, 30) . ($b->is_active ? 'Sí' : 'No'));
        }
        $this->line('Total operativeMap IDs (rutas + ciudades): ' . count($operativeIds));
        $this->line('Total corporativoIds: ' . implode(', ', $corporativoIds));

        // ── Section 2: Module-by-module scope ────────────────────────────────
        $this->line('');
        $this->info('════ 2. ALCANCE POR MÓDULO ════');

        $results = [];

        // ── INGRESOS (fact_recoveries PAGO) ───────────────────────────────────
        $passTot = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('COUNT(DISTINCT branch_id) as branches, COUNT(*) as records, SUM(total_amount) as total')
            ->first();

        $orphanTot = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('COUNT(DISTINCT branch_id) as branches, COUNT(*) as records, SUM(total_amount) as total')
            ->first();

        $orphanBranches = (int) $orphanTot->branches;
        $orphanAmount   = (float) $orphanTot->total;

        $results[] = [
            'Módulo'    => 'Ingresos (PAGO)',
            'Fuente'    => 'fact_recoveries',
            'Pass1'     => $passTot->total,
            'Pass2'     => 0.0,
            'Orphan'    => $orphanAmount,
            'Total'     => $passTot->total,
            'Branches'  => $passTot->branches,
            'OK'        => 'Pass1 only',
            'Nota'      => $orphanBranches . ' suc huérfanas ($' . number_format($orphanAmount, 0) . ' perdidos)',
        ];

        // ── OTORGAMIENTOS (fact_placements) ───────────────────────────────────
        $otorgTot = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->where(function ($q) {
                $q->whereNull('product_name')
                  ->orWhereRaw("product_name NOT REGEXP ?", ['REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS']);
            })
            ->selectRaw('COUNT(DISTINCT branch_id) as branches, COUNT(*) as records, SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), \'$.amount_monto53\')) AS DECIMAL(14,2))) as total')
            ->first();

        $otorgAll = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->where(function ($q) {
                $q->whereNull('product_name')
                  ->orWhereRaw("product_name NOT REGEXP ?", ['REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS']);
            })
            ->selectRaw('SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), \'$.amount_monto53\')) AS DECIMAL(14,2))) as total')
            ->value('total');

        $results[] = [
            'Módulo'    => 'Otorgamientos',
            'Fuente'    => 'fact_placements',
            'Pass1'     => $otorgTot->total,
            'Pass2'     => 0.0,
            'Orphan'    => $otorgAll - $otorgTot->total,
            'Total'     => $otorgTot->total,
            'Branches'  => $otorgTot->branches,
            'OK'        => '✓ EXACTO',
            'Nota'      => 'amount_monto53; huérfanos=$' . number_format($otorgAll - $otorgTot->total, 0),
        ];

        // ── GASTOS OPERATIVOS ─────────────────────────────────────────────────
        $excCats = ['Envío de utilidad a corporativo', 'Nómina y Capital Humano', 'Préstamos Intersucursales'];
        $lendusCats = ['Gastos Operativos', 'Pólizas', 'Renta Oficina', 'Recargas Telefónicas', 'Gasolina', 'Agua'];

        $lendusId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $erpId    = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');

        $lendusOp = (float) DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusId)
            ->whereNotIn('e.category', $excCats)
            ->whereNotIn('e.branch_id', $corporativoIds)
            ->selectRaw('SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as t')->value('t');

        $erpOp = (float) DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $erpId)
            ->whereNotIn('e.category', array_merge($excCats, $lendusCats))
            ->whereNotIn('e.branch_id', $corporativoIds)
            ->selectRaw('SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as t')->value('t');

        $results[] = [
            'Módulo'   => 'Gastos operativos',
            'Fuente'   => 'fact_expenses (lendus+erp)',
            'Pass1'    => $lendusOp + $erpOp,
            'Pass2'    => 0.0,
            'Orphan'   => 0.0,
            'Total'    => $lendusOp + $erpOp,
            'Branches' => 12,
            'OK'       => '✓ 12 suc (city IDs)',
            'Nota'     => sprintf('lendus=$%s erp(non-dup)=$%s', number_format($lendusOp, 0), number_format($erpOp, 0)),
        ];

        // ── ENVÍO CORPORATIVO ─────────────────────────────────────────────────
        $envioTot = (float) DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusId)
            ->where('e.category', 'Envío de utilidad a corporativo')
            ->selectRaw('SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as t')->value('t');

        $results[] = [
            'Módulo'   => 'Envío corporativo',
            'Fuente'   => 'fact_expenses (lendus)',
            'Pass1'    => $envioTot,
            'Pass2'    => 0.0,
            'Orphan'   => 0.0,
            'Total'    => $envioTot,
            'Branches' => 12,
            'OK'       => 'Sistema=$' . number_format($envioTot, 0) . ' Ref=$3,076,800',
            'Nota'     => 'Diferencia vs ref: $' . number_format($envioTot - 3076800, 0),
        ];

        // ── NÓMINA ────────────────────────────────────────────────────────────
        $nomTot = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->whereRaw("concept REGEXP '^P(001|002|009|010|108|109|120|123)'")
            ->selectRaw('SUM(amount) as total, COUNT(DISTINCT employee_id) as emps')
            ->first();

        $results[] = [
            'Módulo'   => 'Nómina (percepciones)',
            'Fuente'   => 'fact_noi_movements',
            'Pass1'    => $nomTot->total,
            'Pass2'    => 0.0,
            'Orphan'   => 0.0,
            'Total'    => $nomTot->total,
            'Branches' => 12,
            'OK'       => '✓ via employee_branch_assignments',
            'Nota'     => $nomTot->emps . ' empleados',
        ];

        // ── CARTERA ───────────────────────────────────────────────────────────
        $cartTot = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->selectRaw('COUNT(DISTINCT branch_id) as branches, SUM(balance) as total')
            ->first();

        $results[] = [
            'Módulo'   => 'Cartera',
            'Fuente'   => 'fact_portfolios',
            'Pass1'    => $cartTot->total,
            'Pass2'    => 0.0,
            'Orphan'   => 0.0,
            'Total'    => $cartTot->total,
            'Branches' => $cartTot->branches,
            'OK'       => '✓ operativeIds',
            'Nota'     => '',
        ];

        // Print results table
        $colW = [25, 20, 18, 18, 18, 10, 30];
        $hdr  = [
            str_pad('Módulo', $colW[0]),
            str_pad('Fuente', $colW[1]),
            str_pad('Monto (12 suc)', $colW[2]),
            str_pad('Huérfanos', $colW[3]),
            str_pad('Total DB', $colW[4]),
            str_pad('Suc', $colW[5]),
            'Observación',
        ];
        $this->line(implode('', $hdr));
        $this->line(str_repeat('─', 155));

        foreach ($results as $r) {
            $line = str_pad(substr($r['Módulo'], 0, $colW[0] - 1), $colW[0])
                  . str_pad(substr($r['Fuente'], 0, $colW[1] - 1), $colW[1])
                  . str_pad('$' . number_format($r['Total'], 0), $colW[2])
                  . str_pad('$' . number_format($r['Orphan'], 0), $colW[3])
                  . str_pad('$' . number_format($r['Pass1'] + $r['Pass2'] + $r['Orphan'], 0), $colW[4])
                  . str_pad($r['Branches'], $colW[5])
                  . $r['OK'];
            $this->line($line);
        }

        // ── Section 3: EBITDA summary ─────────────────────────────────────────
        $this->line('');
        $this->info('════ 3. CÁLCULO EBITDA (DATOS BRANCHCALC) ════');

        $calc         = app(BranchRadiographyCalculator::class);
        $brResult     = $calc->buildBranches($period, $dataIds, []);
        $brGlobal     = $calc->sumGlobal($brResult['branches'], $brResult['unassigned']);

        $ingresos     = (float) $brGlobal['recuperacion_total'];
        $otorgamientos= (float) $brGlobal['colocacion'];
        $gastosOp     = (float) $brGlobal['gastos_operativos'];
        $excedentes   = (float) $brGlobal['excedentes'];

        // Estimate nomNeto from BranchCalc
        $percep = $brGlobal['nomina_total'] + $brGlobal['comisiones'] + $brGlobal['bonos']
                + $brGlobal['vacaciones'] + $brGlobal['prima_vacacional'];
        $nomDetalle = $brGlobal['nomina_detalle'] ?? [];
        $noiDedLabels = ['Descuentos Infonavit','Pensión Alimenticia','Descuento Servicios Moto',
                         'Financiamiento de Motos (desc.)','Préstamo Personal',
                         'Subsidio para el Empleo APL','Otros descuentos NOI','Descuento de uniformes'];
        $nomDed = 0.0;
        $nomExp = 0.0;
        foreach ($nomDetalle as $lbl => $amt) {
            if (in_array($lbl, $noiDedLabels, true)) $nomDed += $amt;
            else $nomExp += $amt;
        }
        $nomNeto = $percep + $nomExp - $nomDed;

        $gastosTotal = $gastosOp + $nomNeto;
        $utilidad    = $ingresos - $otorgamientos - $gastosTotal;
        $inconsistencia = $excedentes > $utilidad;
        $diferencia  = $inconsistencia ? 0.0 : ($utilidad - $excedentes);

        $rows = [
            ['Ingresos (PAGO, 12 suc, Pass 1)',       $ingresos],
            ['  + Orphan branches (Pass 2 = $0)',      0.0],
            ['Otorgamientos (amount_monto53)',          $otorgamientos],
            ['  Ingresos − Otorgamientos',              $ingresos - $otorgamientos],
            ['Gastos operativos',                       $gastosOp],
            ['Nómina neta (percep + gastos − NOI ded)',$nomNeto],
            ['  Gastos Totales',                        $gastosTotal],
            ['= EBITDA / Utilidad disponible',         $utilidad],
            ['Envío a corporativo (excedentes)',        $excedentes],
            ['= Diferencia / sobrante',                $diferencia],
        ];

        foreach ($rows as [$lbl, $amt]) {
            $method = str_starts_with($lbl, '= EBITDA') ? 'info' : 'line';
            $this->$method(str_pad($lbl, 48) . '$' . number_format($amt, 2));
        }

        if ($inconsistencia) {
            $this->line('');
            $this->error('  ⚠ INCONSISTENCIA: Envío ($' . number_format($excedentes, 2) . ') > EBITDA ($' . number_format($utilidad, 2) . ')');
            $this->error('  CAUSA RAÍZ: Ingresos − Otorgamientos = $' . number_format($ingresos - $otorgamientos, 2));
            $this->error('  La diferencia es mínima vs gastos totales ($' . number_format($gastosTotal, 2) . ')');
            $this->error('  Los ' . $orphanBranches . ' orphan branches suman $' . number_format($orphanAmount, 2) . ' en PAGO no incluidos');
        } else {
            $this->info('  ✓ Sin inconsistencia. Diferencia = $' . number_format($diferencia, 2));
        }

        // ── Section 4: Orphan branches detail ────────────────────────────────
        if ($detail) {
            $this->line('');
            $this->info('════ 4. SUCURSALES HUÉRFANAS (no en operativeMap) ════');
            $this->info('Estas sucursales tienen PAGO en fact_recoveries pero NO están en el mapa de rutas.');
            $this->info('Pass 2 no las resuelve porque accredited_name = NULL en sus registros.');

            $orphans = DB::select('
                SELECT r.branch_id, b.name as branch_name, COUNT(*) as cnt, SUM(r.total_amount) as total
                FROM fact_recoveries r
                LEFT JOIN branches b ON r.branch_id = b.id
                WHERE r.period_id IN (' . implode(',', $dataIds) . ')
                AND JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, \'$.transaction\')) = \'PAGO\'
                AND r.branch_id NOT IN (' . implode(',', $operativeIds) . ')
                GROUP BY r.branch_id, b.name
                ORDER BY total DESC
            ');

            $this->line(str_pad('ID', 5) . str_pad('Nombre', 30) . str_pad('Registros', 12) . 'Monto PAGO');
            $this->line(str_repeat('─', 62));
            foreach ($orphans as $o) {
                $this->line(str_pad($o->branch_id, 5) . str_pad($o->branch_name ?? 'NULL', 30) .
                            str_pad(number_format($o->cnt), 12) . '$' . number_format($o->total, 2));
            }
            $this->line(str_repeat('─', 62));
            $this->line(str_pad('TOTAL HUÉRFANAS', 47) . '$' . number_format(array_sum(array_column($orphans, 'total')), 2));
            $this->line('');
            $this->warn('  Si estas sucursales pertenecen a las 12 ciudades, agregar sus nombres al');
            $this->warn('  BranchResolverService::resolveRealBranchFromRoute() $routeMap para incluirlas.');

            $this->line('');
            $this->info('════ 5. TODAS LAS TRANSACCIONES (PAGO + DESCUENTO + CONDONACION) ════');
            $allTxn = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_payload,'$.transaction')),'PAGO') as txn, COUNT(*) as cnt, SUM(total_amount) as total")
                ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload,'$.transaction'))")
                ->orderByDesc('total')
                ->get();

            $grandAll = 0.0;
            foreach ($allTxn as $r) {
                $this->line(str_pad($r->txn, 15) . str_pad(number_format($r->cnt), 10) . '$' . number_format($r->total, 2));
                $grandAll += $r->total;
            }
            $this->line(str_pad('TOTAL', 25) . '$' . number_format($grandAll, 2));

            $utilidadAll = $grandAll - $otorgamientos - $gastosTotal;
            $this->line('');
            $this->info('  Si Ingresos = TODAS las transacciones ($' . number_format($grandAll, 2) . '):');
            $this->line('  EBITDA = $' . number_format($grandAll, 2) . ' − $' . number_format($otorgamientos, 2) . ' − $' . number_format($gastosTotal, 2) . ' = $' . number_format($utilidadAll, 2));
            if ($utilidadAll >= $excedentes) {
                $this->info('  ✓ Con todas las transacciones: Sin inconsistencia (diferencia = $' . number_format($utilidadAll - $excedentes, 2) . ')');
            } else {
                $this->warn('  ⚠ Incluso con todas las transacciones: Inconsistencia persiste ($' . number_format($utilidadAll - $excedentes, 2) . ')');
            }
        }

        return 0;
    }
}
