<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FinancialProduct;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\Placement;
use App\Models\Portfolio;
use App\Models\Recovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugPeriodoCommand extends Command
{
    protected $signature   = 'reportes:debug-periodo {periodId : ID del periodo a diagnosticar}';
    protected $description = 'Diagnóstico de datos en BD para un periodo. Solo lectura.';

    public function handle(): int
    {
        $id     = (int) $this->argument('periodId');
        $period = Period::query()->find($id);

        if (!$period) {
            $this->error("No existe periodo con id={$id}.");
            return 1;
        }

        $this->info("═══════════════════════════════════════════════════════");
        $this->info("  DIAGNÓSTICO PERIODO  #{$id}  —  {$period->label}");
        $this->info("  {$period->start_date} → {$period->end_date}");
        $this->info("═══════════════════════════════════════════════════════");

        // ── 1. CONTEOS POR TABLA ─────────────────────────────────────────
        $this->line('');
        $this->info('── 1. CONTEOS POR TABLA ────────────────────────────────');
        $this->table(
            ['Tabla', 'Count'],
            [
                ['employees (total global)',              Employee::query()->count()],
                ['employees activos',                     Employee::query()->where('is_active', true)->count()],
                ['fact_noi_movements (periodo)',          NoiMovement::query()->where('period_id', $id)->count()],
                ['fact_noi_movements employee_id NULL',  NoiMovement::query()->where('period_id', $id)->whereNull('employee_id')->count()],
                ['fact_noi_movements concept_type NULL', NoiMovement::query()->where('period_id', $id)->whereNull('concept_type')->count()],
                ['fact_expenses (periodo)',               Expense::query()->where('period_id', $id)->count()],
                ['fact_placements (periodo)',             Placement::query()->where('period_id', $id)->count()],
                ['fact_recoveries (periodo)',             Recovery::query()->where('period_id', $id)->count()],
                ['fact_portfolios (periodo)',             Portfolio::query()->where('period_id', $id)->count()],
                ['fact_portfolios past_due > 0',         Portfolio::query()->where('period_id', $id)->where('past_due_balance', '>', 0)->count()],
                ['fact_portfolios days_past_due > 0',    Portfolio::query()->where('period_id', $id)->where('days_past_due', '>', 0)->count()],
                ['fact_portfolios con contrato',         Portfolio::query()->where('period_id', $id)->whereNotNull('contract')->count()],
                ['fact_portfolios con producto',         Portfolio::query()->where('period_id', $id)->whereNotNull('product_name')->count()],
                ['fact_portfolios con promotor',         Portfolio::query()->where('period_id', $id)->whereNotNull('promoter_name')->count()],
                ['fact_portfolios con ruta',             Portfolio::query()->where('period_id', $id)->whereNotNull('route_name')->count()],
                ['fact_period_employee_summary',        MonthlyEmployeeSummary::query()->where('period_id', $id)->count()],
                ['branches activas (total)',             Branch::query()->where('is_active', true)->count()],
            ]
        );

        // ── 2. SUMATORIAS FINANCIERAS ────────────────────────────────────
        $this->line('');
        $this->info('── 2. SUMATORIAS FINANCIERAS ───────────────────────────');
        $recuperacion = (float) Recovery::query()->where('period_id', $id)->sum('total_amount');
        $colocacion   = (float) Placement::query()->where('period_id', $id)->sum('amount');
        $cartera      = (float) Portfolio::query()->where('period_id', $id)->sum('balance');
        $vencidaPD    = (float) Portfolio::query()->where('period_id', $id)->sum('past_due_balance');
        $vencidaDays  = (float) Portfolio::query()->where('period_id', $id)->where('days_past_due', '>', 0)->sum('balance');
        $capitalDue   = (float) Portfolio::query()->where('period_id', $id)->whereNotNull('capital_due')->sum('capital_due');
        $gastos       = (float) Expense::query()->where('period_id', $id)->sum('amount');

        $vencidaEfectiva = $capitalDue > 0 ? $capitalDue : ($vencidaPD > 0 ? $vencidaPD : $vencidaDays);
        $mora = $cartera > 0 ? round(($vencidaEfectiva / $cartera) * 100, 2) : 0;

        $this->table(
            ['Métrica', 'Valor', 'Nota'],
            [
                ['Recuperación total',            '$' . number_format($recuperacion, 2), ''],
                ['Colocación total',              '$' . number_format($colocacion, 2), ''],
                ['Cartera total',                 '$' . number_format($cartera, 2), ''],
                ['Cartera vencida (past_due_balance)', '$' . number_format($vencidaPD, 2), ''],
                ['Cartera vencida (days>0 * balance)', '$' . number_format($vencidaDays, 2), 'proxy'],
                ['Capital atrasado (capital_due)',   '$' . number_format($capitalDue, 2), 'preferido'],
                ['Mora % (con capital_due)',         $mora . '%', ''],
                ['Gastos totales',                '$' . number_format($gastos, 2), ''],
            ]
        );

        // ── 3. NÓMINA ────────────────────────────────────────────────────
        $this->line('');
        $this->info('── 3. NÓMINA DESDE fact_noi_movements ──────────────────');
        $noiConceptTypes = DB::table('fact_noi_movements')
            ->where('period_id', $id)
            ->selectRaw('COALESCE(concept_type, "(null)") as tipo, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('concept_type')
            ->orderByDesc('cnt')
            ->get();

        if ($noiConceptTypes->isEmpty()) {
            $this->warn('  ⚠ fact_noi_movements vacío para este periodo.');
        } else {
            $this->table(
                ['concept_type', 'filas', 'SUM(amount)'],
                $noiConceptTypes->map(fn ($r) => [
                    $r->tipo,
                    $r->cnt,
                    '$' . number_format($r->total, 2),
                ])->toArray()
            );
        }

        $pagos  = (float) DB::table('fact_noi_movements')->where('period_id', $id)->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")->sum('amount');
        $deduc  = (float) DB::table('fact_noi_movements')->where('period_id', $id)->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')")->sum('amount');
        $neto   = $pagos - $deduc;
        $this->line("  Percepciones: \${$neto} | Deducciones: \${$deduc} | Neto nómina: \${$neto}");

        // ── 4. CARTERA — BUCKETS POR DÍAS VENCIDOS ────────────────────────
        $this->line('');
        $this->info('── 4. CARTERA — BUCKETS POR DÍAS VENCIDOS ──────────────');
        $buckets = [
            ['label' => 'Al corriente (0)',  'min' => 0,   'max' => 0  ],
            ['label' => 'Mora 1-30',         'min' => 1,   'max' => 30 ],
            ['label' => 'Mora 31-60',        'min' => 31,  'max' => 60 ],
            ['label' => 'Mora 61-90',        'min' => 61,  'max' => 90 ],
            ['label' => 'Mora 91-120',       'min' => 91,  'max' => 120],
            ['label' => 'Mora 120+',         'min' => 121, 'max' => 99999],
        ];
        $bucketRows = [];
        foreach ($buckets as $b) {
            $data = DB::table('fact_portfolios')
                ->where('period_id', $id)
                ->where('days_past_due', '>=', $b['min'])
                ->where('days_past_due', '<=', $b['max'])
                ->selectRaw('COUNT(*) as cnt, SUM(balance) as saldo, SUM(past_due_balance) as venc_pd, SUM(COALESCE(capital_due, 0)) as capital_due')
                ->first();

            $cnt = (int)($data?->cnt ?? 0);
            if ($cnt === 0) continue;

            $saldo   = (float)($data?->saldo ?? 0);
            $vencida = (float)($data?->capital_due ?? 0) > 0
                ? (float)($data?->capital_due ?? 0)
                : ((float)($data?->venc_pd ?? 0) > 0 ? (float)($data?->venc_pd ?? 0) : ($b['min'] > 0 ? $saldo : 0));

            $bucketRows[] = [
                $b['label'],
                $cnt,
                '$' . number_format($saldo, 2),
                '$' . number_format($vencida, 2),
            ];
        }
        if (empty($bucketRows)) {
            $this->warn('  ⚠ fact_portfolios vacío o days_past_due = 0 para todo.');
        } else {
            $this->table(['Bucket', 'Contratos', 'Balance', 'Vencido'], $bucketRows);
        }

        // ── 5. SUCURSALES REALES (por prefijo de contrato) ───────────────
        $this->line('');
        $this->info('── 5. SUCURSALES REALES ────────────────────────────────');
        $sucursalesReales = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->where('po.period_id', $id)
            ->selectRaw('b.name as sucursal, COUNT(*) as contratos, SUM(po.balance) as cartera, SUM(CASE WHEN po.days_past_due > 0 THEN COALESCE(po.capital_due, po.past_due_balance, po.balance) ELSE 0 END) as vencida')
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('cartera')
            ->limit(15)
            ->get();

        if ($sucursalesReales->isEmpty()) {
            $this->warn('  ⚠ Sin sucursales en portfolios. Revisa que branch_id esté resuelto.');
        } else {
            $this->table(
                ['Sucursal', 'Contratos', 'Cartera', 'Vencida'],
                $sucursalesReales->map(fn ($r) => [
                    $r->sucursal, $r->contratos,
                    '$' . number_format($r->cartera, 2),
                    '$' . number_format($r->vencida, 2),
                ])->toArray()
            );
        }

        // También mostrar las rutas/oficinas detectadas (para distinguir)
        $rutasDetectadas = DB::table('fact_portfolios')
            ->where('period_id', $id)
            ->whereNotNull('route_name')
            ->selectRaw('route_name, COUNT(*) as cnt')
            ->groupBy('route_name')
            ->orderByDesc('cnt')
            ->limit(10)
            ->pluck('cnt', 'route_name');

        if ($rutasDetectadas->isNotEmpty()) {
            $this->line('  → Rutas/oficinas detectadas (distintas de sucursal real):');
            foreach ($rutasDetectadas as $ruta => $cnt) {
                $this->line("    {$ruta}: {$cnt} contratos");
            }
        }

        // ── 6. PRODUCTOS NORMALIZADOS ────────────────────────────────────
        $this->line('');
        $this->info('── 6. PRODUCTOS (placements + portfolios) ──────────────');
        $prodsPla = DB::table('fact_placements')
            ->where('period_id', $id)
            ->selectRaw('COALESCE(NULLIF(product_name, ""), "(sin producto)") as producto, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();

        if ($prodsPla->isEmpty()) {
            $this->warn('  ⚠ Sin placements para este periodo.');
        } else {
            $this->line('  → Productos en Colocación (Ministraciones):');
            $this->table(
                ['Producto', 'Operaciones', 'Total'],
                $prodsPla->map(fn ($r) => [$r->producto, $r->cnt, '$' . number_format($r->total, 2)])->toArray()
            );
        }

        $prodsPor = DB::table('fact_portfolios')
            ->where('period_id', $id)
            ->whereNotNull('product_name')
            ->selectRaw('product_name as producto, COUNT(*) as contratos, SUM(balance) as cartera')
            ->groupBy('product_name')
            ->orderByDesc('cartera')
            ->limit(20)
            ->get();

        if ($prodsPor->isNotEmpty()) {
            $this->line('  → Productos en Cartera (Saldos):');
            $this->table(
                ['Producto', 'Contratos', 'Cartera'],
                $prodsPor->map(fn ($r) => [$r->producto, $r->contratos, '$' . number_format($r->cartera, 2)])->toArray()
            );
        }

        // ── 7. PROMOTORES / GESTORES CON NOMBRE ────────────────────────
        $this->line('');
        $this->info('── 7. TOP GESTORES (colocación + cartera) ──────────────');

        $promosPla = DB::table('fact_placements')
            ->where('period_id', $id)
            ->where(function ($q) {
                $q->whereNotNull('promoter_name')->orWhereNotNull('promoter_code');
            })
            ->selectRaw('COALESCE(promoter_name, promoter_code) as gestor, COUNT(*) as ops, SUM(amount) as total')
            ->groupBy('normalized_promoter_name', 'promoter_name', 'promoter_code')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        if ($promosPla->isNotEmpty()) {
            $this->table(
                ['Gestor', 'Ops', 'Total colocado'],
                $promosPla->map(fn ($r) => [
                    mb_strimwidth($r->gestor ?? '(sin nombre)', 0, 35),
                    $r->ops,
                    '$' . number_format($r->total, 2),
                ])->toArray()
            );
        } else {
            $this->warn('  ⚠ Sin promotores en placements.');
        }

        // Promotores desde saldos (con nombre real si existe)
        $promosSaldos = DB::table('fact_portfolios')
            ->where('period_id', $id)
            ->where(function ($q) {
                $q->whereNotNull('promoter_name')->orWhereNotNull('promoter_code');
            })
            ->selectRaw('COALESCE(promoter_name, promoter_code) as gestor, promoter_code as codigo, COUNT(*) as contratos, SUM(balance) as cartera')
            ->groupBy('promoter_name', 'promoter_code')
            ->orderByDesc('cartera')
            ->limit(15)
            ->get();

        if ($promosSaldos->isNotEmpty()) {
            $this->line('  → Gestores detectados en Saldos por Cliente:');
            $this->table(
                ['Gestor/Nombre', 'Código', 'Contratos', 'Cartera'],
                $promosSaldos->map(fn ($r) => [
                    mb_strimwidth($r->gestor ?? '(sin nombre)', 0, 35),
                    $r->codigo ?? '—',
                    $r->contratos,
                    '$' . number_format($r->cartera, 2),
                ])->toArray()
            );
        }

        // ── 8. GASTOS ────────────────────────────────────────────────────
        $this->line('');
        $this->info('── 8. GASTOS POR CATEGORÍA Y SUCURSAL ──────────────────');
        $gastosSucursal = DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->where('e.period_id', $id)
            ->selectRaw('COALESCE(e.category, "Sin categoría") as cat, COALESCE(b.name, "Sin sucursal") as suc, COUNT(*) as cnt, SUM(e.amount) as total')
            ->groupBy('e.category', 'b.name')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        if ($gastosSucursal->isEmpty()) {
            $this->warn('  ⚠ Sin gastos para este periodo.');
        } else {
            $this->table(
                ['Categoría', 'Sucursal', 'Registros', 'Total'],
                $gastosSucursal->map(fn ($r) => [
                    mb_strimwidth($r->cat, 0, 30),
                    mb_strimwidth($r->suc, 0, 25),
                    $r->cnt,
                    '$' . number_format($r->total, 2),
                ])->toArray()
            );
        }

        // ── 9. EJEMPLOS fact_portfolios (primeros 5) ─────────────────────
        $this->line('');
        $this->info('── 9. EJEMPLOS fact_portfolios ─────────────────────────');
        $portSamples = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->where('po.period_id', $id)
            ->limit(5)
            ->get([
                'po.id', 'b.name as sucursal', 'po.route_name', 'po.contract',
                'po.promoter_code', 'po.promoter_name', 'po.product_name',
                'po.balance', 'po.capital_due', 'po.days_past_due',
            ]);

        if ($portSamples->isEmpty()) {
            $this->warn('  ⚠ fact_portfolios vacío.');
        } else {
            $this->table(
                ['id', 'sucursal', 'ruta', 'contrato', 'promotor', 'producto', 'balance', 'capital_due', 'días'],
                $portSamples->map(fn ($r) => [
                    $r->id,
                    mb_strimwidth($r->sucursal ?? '—', 0, 20),
                    mb_strimwidth($r->route_name ?? '—', 0, 15),
                    $r->contract ?? '—',
                    mb_strimwidth($r->promoter_name ?? $r->promoter_code ?? '—', 0, 20),
                    mb_strimwidth($r->product_name ?? '—', 0, 15),
                    number_format((float)$r->balance, 2),
                    number_format((float)$r->capital_due, 2),
                    $r->days_past_due,
                ])->toArray()
            );
        }

        $this->line('');
        $this->info("══ FIN DIAGNÓSTICO PERIODO #{$id} ══");

        return 0;
    }
}
