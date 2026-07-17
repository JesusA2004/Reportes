<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditNominaPeriodoCommand extends Command
{
    protected $signature = 'reportes:audit-nomina-periodo
                                {period_id}
                                {--detail : Muestra el detalle por concepto/empleado/sucursal}
                                {--export : Exporta CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría de Nómina (KPI = NOI neto): valida uploads vigentes y explica cada peso incluido/excluido del total.';

    private const NOI_SOURCES = ['noi_nomina', 'noi_nomina_fiscal'];

    public function handle(BranchRadiographyCalculator $calc): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA NÓMINA — {$period->label} (ID {$periodId})");
        $this->info('  Regla: KPI Nómina = NOI normal + NOI fiscal, percepciones − deducciones.');
        $this->info('  dataIds: ' . implode(', ', $dataIds));
        $this->info('════════════════════════════════════════════════════════════');

        // ── 1. Uploads vigentes por fuente ──────────────────────────────────
        $this->line('');
        $this->info('════ 1. UPLOADS VIGENTES ════');
        $sourceCodes = ['noi_nomina', 'noi_nomina_fiscal', 'imss', 'gastos_erp', 'gastos_lendus', 'gastos_lendus_excel'];
        foreach ($sourceCodes as $code) {
            $uploads = ReportUpload::query()
                ->whereHas('dataSource', fn ($q) => $q->where('code', $code))
                ->where('period_id', $periodId)
                ->whereIn('status', ['processed', 'failed', 'processing'])
                ->orderByDesc('id')
                ->get();

            if ($uploads->isEmpty()) {
                $this->line("  [{$code}] sin upload en este periodo.");
                continue;
            }

            $vigente = $uploads->first();
            $statusLabel = $vigente->status?->value ?? (string) $vigente->status;
            $this->line(sprintf(
                '  [%s] vigente=#%d (%s, status=%s)%s',
                $code, $vigente->id, $vigente->original_name, $statusLabel,
                $uploads->count() > 1 ? ' — ' . ($uploads->count() - 1) . ' upload(s) anterior(es) ignorado(s)' : '',
            ));
        }

        // ── 2. Totales de control NOI normal / fiscal (raw, sin clasificar) ────
        $this->line('');
        $this->info('════ 2. TOTALES DE CONTROL NOI (raw, por fuente) ════');
        foreach (self::NOI_SOURCES as $code) {
            $sourceId = DB::table('data_sources')->where('code', $code)->value('id');
            $perc = $ded = 0.0;
            if ($sourceId) {
                $perc = (float) DB::table('fact_noi_movements as n')
                    ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
                    ->whereIn('n.period_id', $dataIds)->where('ru.data_source_id', $sourceId)
                    ->where('n.concept_type', 'percepcion')->sum('n.amount');
                $ded  = (float) DB::table('fact_noi_movements as n')
                    ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
                    ->whereIn('n.period_id', $dataIds)->where('ru.data_source_id', $sourceId)
                    ->where('n.concept_type', 'deduccion')->sum('n.amount');
            }
            $this->line(sprintf('  [%s] Percepciones: $%s | Deducciones: $%s', $code, number_format($perc, 2), number_format($ded, 2)));
        }

        // ── 3. Fuente única canónica: BranchRadiographyCalculator ──────────────
        $branchResult = $calc->buildBranches($period, $dataIds);
        $branches     = $branchResult['branches'];
        $unassigned   = $branchResult['unassigned'];
        $global       = $calc->sumGlobal($branches, $unassigned);

        // nomina_total + comisiones + bonos + ... + otros_percepciones ya trae TODAS las
        // deducciones NOI restadas (regla vigente 2026-07: ninguna excepción, ver
        // accumulateNomina()) — para mostrar "percepciones brutas" por separado, se suma de
        // vuelta el total de deducciones (nomina_detalle).
        $percepciones = (float) $global['nomina_total']
            + (float) $global['comisiones'] + (float) $global['bonos']
            + (float) $global['bonos_aceleradores'] + (float) $global['vacaciones']
            + (float) $global['prima_vacacional'] + (float) ($global['otros_percepciones'] ?? 0);
        $deduccionesTotal = array_sum(array_values((array) ($global['nomina_detalle'] ?? [])));
        $percepcionesBrutas = $percepciones + $deduccionesTotal;
        $imssPatronal = (float) ($global['imss_patronal'] ?? 0);
        $gastosEmpleados = (float) ($global['gastos_empleados_nomina'] ?? 0);
        $nominaNeta = BranchRadiographyCalculator::nominaTotalFor($global);

        $this->line('');
        $this->info('════ 3. CLASIFICACIÓN NOI (fuente única BranchRadiographyCalculator) ════');
        $this->line(sprintf('  Percepciones NOI (sueldo+comisiones+bonos+vacaciones+prima+otras): $%s', number_format($percepcionesBrutas, 2)));
        $this->line(sprintf('  Deducciones NOI (restadas del total, todas, sin excepción):        $%s', number_format($deduccionesTotal, 2)));
        $this->line(sprintf('  NOI neto (percepciones − deducciones):                             $%s', number_format($percepcionesBrutas - $deduccionesTotal, 2)));
        $this->line(sprintf('  (+) IMSS patronal operativo (excl. Corporativo/Tulancingo):        $%s', number_format($imssPatronal, 2)));
        $this->line(sprintf('  (+) Gastos empleados Lendus (Motos/Enganche/Cascos/Finiquito/Médicos): $%s', number_format($gastosEmpleados, 2)));
        $this->info(sprintf('  NÓMINA Y CAPITAL HUMANO (KPI): $%s', number_format($nominaNeta, 2)));

        if ($this->option('detail')) {
            $this->line('');
            $this->info('  ── Deducciones NOI por concepto (afectan_total = sí) ──');
            foreach ((array) ($global['nomina_detalle'] ?? []) as $label => $amt) {
                if ((float) $amt == 0.0) continue;
                $this->line(sprintf('    %-45s $%s  (signed: -%s)', $label, number_format($amt, 2), number_format($amt, 2)));
            }
        }

        // ── 4. Desglose de IMSS patronal + gastos empleados — SÍ se suman a Nómina ─────
        $this->line('');
        $this->info('════ 4. DESGLOSE IMSS + GASTOS EMPLEADOS (afectan_total = SÍ) ════');
        $this->line('  (regla vigente 2026-07: IMSS patronal operativo y los 5 gastos de empleados');
        $this->line('   Lendus [Financiamiento de Motos, Enganche, Cascos, Finiquito, Gastos médicos]');
        $this->line('   YA están incluidos en el KPI Nómina — este desglose es el detalle, no algo aparte)');
        foreach ((array) ($global['nomina_informativo'] ?? []) as $label => $amt) {
            if ((float) $amt == 0.0) continue;
            $this->line(sprintf('    %-45s $%s', $label, number_format($amt, 2)));
        }
        $this->info(sprintf('  IMSS patronal:              $%s', number_format($imssPatronal, 2)));
        $this->info(sprintf('  Gastos empleados Lendus:    $%s', number_format($gastosEmpleados, 2)));

        // ── 5. IMSS por separado ────────────────────────────────────────────
        $this->line('');
        $this->info('════ 5. IMSS (auditado aparte — reportes:audit-imss) ════');
        $imssExcluido  = (array) ($unassigned['imss_excluido'] ?? []);
        $this->line(sprintf('  IMSS operativo (13 sucursales, SÍ se suma a Nómina): $%s', number_format($imssPatronal, 2)));
        foreach ($imssExcluido as $branchName => $amt) {
            $this->line(sprintf('  IMSS excluido — %-20s $%s (no operativa, fuera del reporte)', $branchName, number_format($amt, 2)));
        }

        // ── 6. Detalle por sucursal ─────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ 6. DETALLE POR SUCURSAL ════');
            foreach ($branches as $b) {
                $brNeto = BranchRadiographyCalculator::nominaTotalFor($b);
                if ($brNeto == 0.0 && empty($b['nomina_detalle']) && empty($b['nomina_informativo'])) continue;
                $this->line(sprintf('  %-15s Neto: $%s', $b['sucursal'], number_format($brNeto, 2)));
            }
            $unassignedNeto = BranchRadiographyCalculator::nominaTotalFor($unassigned);
            if ($unassignedNeto != 0.0) {
                $this->warn(sprintf('  SIN ASIGNAR      Neto: $%s — empleados sin sucursal asignada, revisar', number_format($unassignedNeto, 2)));
            }
        }

        // ── 7. Validación final ─────────────────────────────────────────────
        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info('  RESUMEN FINAL');
        $this->info('════════════════════════════════════════════════════════════');
        $this->line(sprintf('  Percepciones NOI:                                    $%s', number_format($percepcionesBrutas, 2)));
        $this->line(sprintf('  Deducciones NOI:                                     $%s', number_format($deduccionesTotal, 2)));
        $this->line(sprintf('  (+) IMSS patronal operativo:                         $%s', number_format($imssPatronal, 2)));
        $this->line(sprintf('  (+) Gastos empleados Lendus:                         $%s', number_format($gastosEmpleados, 2)));
        $this->info(sprintf('  NÓMINA Y CAPITAL HUMANO (KPI):                       $%s', number_format($nominaNeta, 2)));

        // Target validado por el usuario contra los archivos reales de Junio 2026 (period 21) —
        // NOI neto ($2,007,149.81) + IMSS operativo ($237,000.00) + gastos empleados Lendus
        // ($238,328.74). Sirve de referencia de regresión para ese periodo específico; en
        // periodos distintos la diferencia contra este número no implica error.
        $target = 2482478.55;
        $diff = $nominaNeta - $target;
        $this->line('');
        $this->line(sprintf('  Target validado (Junio 2026 / period 21):            $%s', number_format($target, 2)));
        $this->line(sprintf('  Diferencia:                                          $%s', number_format($diff, 2)));

        if ($this->option('export')) {
            $this->exportCsv($periodId, $global, $percepcionesBrutas, $deduccionesTotal, $nominaNeta, $imssPatronal + $gastosEmpleados);
        }

        $this->line('');
        $ok = abs($diff) < 0.01;
        $this->info($ok ? '  ✓ Nómina neta coincide con el target.' : '  ✗ Nómina neta NO coincide con el target — revisar arriba (normal si el periodo no es Junio 2026).');
        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function exportCsv(
        int $periodId,
        array $global,
        float $percepciones,
        float $deducciones,
        float $nominaNeta,
        float $imssMasGastosEmpleados,
    ): void {
        $dir  = 'auditorias';
        $file = "nomina_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";
        $csv  = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';

        $lines = [implode(',', ['Sección', 'Concepto', 'Display Amount', 'Signed Amount', 'Afecta Total', 'Motivo'])];

        $lines[] = implode(',', ['Percepciones', 'Total percepciones NOI', number_format($percepciones, 2, '.', ''), number_format($percepciones, 2, '.', ''), 'Sí', '']);

        foreach ((array) ($global['nomina_detalle'] ?? []) as $label => $amt) {
            $lines[] = implode(',', [
                'Deducción NOI', $csv($label), number_format((float) $amt, 2, '.', ''),
                number_format(-(float) $amt, 2, '.', ''), 'Sí', $csv('Deducción NOI, resta del neto'),
            ]);
        }

        foreach ((array) ($global['nomina_informativo'] ?? []) as $label => $amt) {
            $lines[] = implode(',', [
                'IMSS / Gastos empleados', $csv($label), number_format((float) $amt, 2, '.', ''),
                number_format((float) $amt, 2, '.', ''), 'Sí', $csv('IMSS patronal o gasto de empleado Lendus — SÍ suma a Nómina'),
            ]);
        }

        $lines[] = implode(',', ['TOTAL', 'Nómina neta (KPI)', number_format($nominaNeta, 2, '.', ''), number_format($nominaNeta, 2, '.', ''), 'Sí', '']);
        $lines[] = implode(',', ['TOTAL', 'IMSS + Gastos empleados (incluido arriba)', number_format($imssMasGastosEmpleados, 2, '.', ''), number_format($imssMasGastosEmpleados, 2, '.', ''), 'Sí', '']);

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
