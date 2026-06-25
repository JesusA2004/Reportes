<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Audit all SIN ASIGNAR records across every section:
 * nómina, OPEX, ingresos, colocación, cartera, fondeos.
 * Outputs reason + dato faltante + acción sugerida for each.
 */
class AuditUnassignedCommand extends Command
{
    protected $signature = 'reportes:audit-unassigned
                                {period_id}
                                {--detail : listar registro a registro}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría SIN ASIGNAR — muestra todos los registros sin sucursal y por qué';

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
        $this->info('════════════════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA SIN ASIGNAR — {$period->label} (ID {$period->id})");
        $this->info('════════════════════════════════════════════════════════════════════════════');

        $rows = [];

        $rows = array_merge($rows, $this->auditNomina($dataIds, $period));
        $rows = array_merge($rows, $this->auditOpex($dataIds));
        $rows = array_merge($rows, $this->auditColocacion($dataIds));
        $rows = array_merge($rows, $this->auditCartera($dataIds));
        $rows = array_merge($rows, $this->auditFondeos($dataIds));

        // Summary
        $byFuente = [];
        foreach ($rows as $r) {
            $byFuente[$r['fuente']] = ($byFuente[$r['fuente']] ?? 0) + 1;
        }

        $this->line('');
        $this->info('── RESUMEN POR FUENTE ──');
        $totalMonto = 0.0;
        foreach ($byFuente as $fuente => $cnt) {
            $montoFuente = array_sum(array_column(array_filter($rows, fn ($r) => $r['fuente'] === $fuente), 'monto'));
            $totalMonto += $montoFuente;
            $this->line(sprintf("  %-38s %4d registros   \$%s", $fuente, $cnt, number_format($montoFuente, 2)));
        }
        $this->line('');
        $this->info(sprintf("  TOTAL SIN ASIGNAR: %d registros   \$%s", count($rows), number_format($totalMonto, 2)));

        if ($this->option('detail') && !empty($rows)) {
            $this->line('');
            $this->info('── DETALLE REGISTRO A REGISTRO ──');
            $this->line(
                str_pad('Fuente', 22)
                . str_pad('ID', 8)
                . str_pad('Folio/Nombre', 28)
                . str_pad('Sucursal original', 20)
                . str_pad('Monto', 16)
                . str_pad('Motivo', 30)
                . 'Dato faltante'
            );
            $this->line(str_repeat('─', 145));
            foreach ($rows as $r) {
                $this->line(
                    str_pad(mb_substr($r['fuente'], 0, 20), 22)
                    . str_pad($r['record_id'], 8)
                    . str_pad(mb_substr($r['folio_nombre'], 0, 26), 28)
                    . str_pad(mb_substr($r['sucursal_original'] ?? '-', 0, 18), 20)
                    . str_pad('$' . number_format($r['monto'], 2), 16)
                    . str_pad(mb_substr($r['motivo'], 0, 28), 30)
                    . $r['dato_faltante']
                );
                if (!empty($r['accion_sugerida'])) {
                    $this->line('    → ' . $r['accion_sugerida']);
                }
            }
        }

        if ($this->option('export')) {
            $this->exportCsv($period->id, $rows);
        }

        return 0;
    }

    // ── NÓMINA: empleados sin branch_assignment ──────────────────────────────
    private function auditNomina(array $dataIds, Period $period): array
    {
        $rows = DB::table('fact_noi_movements as n')
            ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->where('eba.period_id', '=', $period->id);
            })
            ->whereIn('n.period_id', $dataIds)
            ->whereNull('eba.branch_id')
            ->whereNotNull('n.employee_id')
            ->selectRaw("
                n.id as record_id,
                COALESCE(e.full_name, 'Sin empleado') as nombre,
                COALESCE(n.concept, '') as concepto,
                n.amount as monto
            ")
            ->orderByDesc('n.amount')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'fuente'            => 'NOI / Nómina',
                'tabla'             => 'fact_noi_movements',
                'record_id'         => $r->record_id,
                'folio_nombre'      => $r->nombre,
                'sucursal_original' => null,
                'concepto'          => $r->concepto,
                'monto'             => (float) $r->monto,
                'motivo'            => 'Sin asignación de sucursal',
                'dato_faltante'     => 'employee_branch_assignments.branch_id',
                'accion_sugerida'   => 'Asignar sucursal al empleado en el período ' . $period->id,
            ];
        }

        $this->printSection('NÓMINA sin sucursal asignada', count($result), array_sum(array_column($result, 'monto')));
        return $result;
    }

    // ── OPEX: gastos ERP/Lendus sin branch_id ───────────────────────────────
    private function auditOpex(array $dataIds): array
    {
        $resolver = app(BranchResolverService::class);
        $calc     = app(BranchRadiographyCalculator::class);
        $maps     = $calc->buildBranchMap();
        $operativeIds = array_keys($maps['operative']);

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->where(function ($q) use ($operativeIds) {
                $q->whereNull('e.branch_id')
                  ->orWhereNotIn('e.branch_id', $operativeIds);
            })
            ->whereIn('ds.code', ['gastos_erp', 'gastos_lendus'])
            ->selectRaw("
                e.id as record_id,
                ds.code as fuente_src,
                COALESCE(b.name, 'NULL') as sucursal_original,
                COALESCE(e.category, '') as concepto,
                COALESCE(e.observations, '') as observaciones,
                COALESCE(NULLIF(e.paid_amount, 0), e.amount) as monto
            ")
            ->orderByDesc('monto')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $motivo = is_null($r->sucursal_original) || $r->sucursal_original === 'NULL'
                ? 'branch_id NULL'
                : 'Alias no reconocido como sucursal operativa';
            $result[] = [
                'fuente'            => 'OPEX / ' . strtoupper($r->fuente_src),
                'tabla'             => 'fact_expenses',
                'record_id'         => $r->record_id,
                'folio_nombre'      => $r->concepto,
                'sucursal_original' => $r->sucursal_original,
                'concepto'          => $r->concepto,
                'monto'             => (float) $r->monto,
                'motivo'            => $motivo,
                'dato_faltante'     => 'branch_id o alias en catálogo',
                'accion_sugerida'   => 'Revisar sucursal en fuente original y actualizar alias',
            ];
        }

        $this->printSection('OPEX sin sucursal operativa', count($result), array_sum(array_column($result, 'monto')));
        return $result;
    }

    // ── COLOCACIÓN: placements sin branch_id operativo ───────────────────────
    private function auditColocacion(array $dataIds): array
    {
        $calc         = app(BranchRadiographyCalculator::class);
        $maps         = $calc->buildBranchMap();
        $operativeIds = array_keys($maps['operative']);

        $rows = DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $dataIds)
            ->where(function ($q) use ($operativeIds) {
                $q->whereNull('p.branch_id')
                  ->orWhereNotIn('p.branch_id', $operativeIds);
            })
            ->selectRaw("
                p.id as record_id,
                COALESCE(b.name, 'NULL') as sucursal_original,
                COALESCE(p.client_name, '') as cliente,
                COALESCE(p.product_name, '') as concepto,
                p.amount as monto
            ")
            ->orderByDesc('p.amount')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'fuente'            => 'Colocación',
                'tabla'             => 'fact_placements',
                'record_id'         => $r->record_id,
                'folio_nombre'      => $r->cliente,
                'sucursal_original' => $r->sucursal_original,
                'concepto'          => $r->concepto,
                'monto'             => (float) $r->monto,
                'motivo'            => 'Sucursal no operativa o branch_id nulo',
                'dato_faltante'     => 'branch_id operativo',
                'accion_sugerida'   => 'Revisar placement ID ' . $r->record_id . ' y asignar sucursal',
            ];
        }

        $this->printSection('Colocación sin sucursal operativa', count($result), array_sum(array_column($result, 'monto')));
        return $result;
    }

    // ── CARTERA: contratos en branch_id fuera de operativas ──────────────────
    private function auditCartera(array $dataIds): array
    {
        $calc         = app(BranchRadiographyCalculator::class);
        $maps         = $calc->buildBranchMap();
        $operativeIds = array_keys($maps['operative']);

        // AGS branch IDs — show separately
        $agsRows = DB::table('branches')->get();
        $agsIds  = [];
        $resolver = app(BranchResolverService::class);
        foreach ($agsRows as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && strtoupper(trim($real)) === 'AGUASCALIENTES') {
                $agsIds[] = (int) $b->id;
            }
        }

        $rows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->whereNotIn('po.branch_id', $operativeIds)
            ->whereNotIn('po.branch_id', empty($agsIds) ? [0] : $agsIds)
            ->selectRaw("
                po.id as record_id,
                COALESCE(b.name, 'NULL') as sucursal_original,
                COALESCE(po.contract, '') as folio,
                COALESCE(po.product_name, '') as concepto,
                po.balance as monto
            ")
            ->orderByDesc('po.balance')
            ->limit(500)
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'fuente'            => 'Cartera (Saldos)',
                'tabla'             => 'fact_portfolios',
                'record_id'         => $r->record_id,
                'folio_nombre'      => $r->folio,
                'sucursal_original' => $r->sucursal_original,
                'concepto'          => $r->concepto,
                'monto'             => (float) $r->monto,
                'motivo'            => 'Contrato en sucursal fuera de operativas (excl. AGS)',
                'dato_faltante'     => 'branch_id operativo',
                'accion_sugerida'   => 'Verificar contrato ' . $r->folio . ' si pertenece a alguna sucursal operativa',
            ];
        }

        $this->printSection('Cartera fuera de sucursales operativas (excl. AGS)', count($result), array_sum(array_column($result, 'monto')));
        return $result;
    }

    // ── FONDEOS: préstamos intersucursales sin destino legible ───────────────
    private function auditFondeos(array $dataIds): array
    {
        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('e.category', 'Préstamos Intersucursales')
            ->whereRaw("COALESCE(e.observations, '') = ''")
            ->selectRaw("
                e.id as record_id,
                COALESCE(b.name, 'NULL') as sucursal_origen,
                COALESCE(e.concept, '') as concepto,
                COALESCE(e.observations, '') as observaciones,
                COALESCE(NULLIF(e.paid_amount, 0), e.amount) as monto
            ")
            ->orderByDesc('monto')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'fuente'            => 'Fondeo / Intersucursal',
                'tabla'             => 'fact_expenses',
                'record_id'         => $r->record_id,
                'folio_nombre'      => $r->concepto,
                'sucursal_original' => $r->sucursal_origen,
                'concepto'          => $r->concepto,
                'monto'             => (float) $r->monto,
                'motivo'            => 'Observación/destino vacío',
                'dato_faltante'     => 'observations (destino del fondeo)',
                'accion_sugerida'   => 'Completar Observación con sucursal destino en el PDF Lendus',
            ];
        }

        $this->printSection('Fondeos sin destino (observación vacía)', count($result), array_sum(array_column($result, 'monto')));
        return $result;
    }

    private function printSection(string $label, int $count, float $monto): void
    {
        $this->line('');
        $status = $count === 0 ? '✓' : '⚠';
        $this->line("  {$status}  {$label}: {$count} registros   \$" . number_format($monto, 2));
    }

    private function exportCsv(int $periodId, array $rows): void
    {
        $dir  = 'auditorias';
        $ts   = now()->format('Ymd_His');
        $file = "unassigned_periodo_{$periodId}_{$ts}.csv";

        $headers = ['Fuente', 'Tabla', 'ID', 'Folio/Nombre', 'Sucursal original', 'Concepto', 'Monto', 'Motivo', 'Dato faltante', 'Acción sugerida'];
        $lines   = [implode(',', $headers)];

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['fuente']) . '"',
                '"' . str_replace('"', '""', $r['tabla']) . '"',
                $r['record_id'],
                '"' . str_replace('"', '""', $r['folio_nombre']) . '"',
                '"' . str_replace('"', '""', $r['sucursal_original'] ?? '') . '"',
                '"' . str_replace('"', '""', $r['concepto']) . '"',
                number_format($r['monto'], 2, '.', ''),
                '"' . str_replace('"', '""', $r['motivo']) . '"',
                '"' . str_replace('"', '""', $r['dato_faltante']) . '"',
                '"' . str_replace('"', '""', $r['accion_sugerida']) . '"',
            ]);
        }

        Storage::disk('local')->put("{$dir}/{$file}", implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$dir}/{$file}");
    }
}
