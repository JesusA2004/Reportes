<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodSummary;
use App\Services\ImssFromNoiFiscalService;
use App\Services\PeriodEmployeeRosterService;
use App\Services\RadiografiaExportService;
use App\Services\RotacionDerivedFromNoiService;
use Illuminate\Console\Command;

/**
 * Corrección 2026-07-23: IMSS y Rotación de Personal ya NO se suben manualmente.
 * Este comando reconstruye SOLO lo derivado de NOI (roster, IMSS, rotación) y
 * regenera Excel/PDF con los KPIs ya actualizados (Nómina, Gastos Totales, EBITDA,
 * Margen EBITDA dependen de imss_patronal — ver BranchRadiographyCalculator).
 *
 * A propósito NO llama a PeriodRadiographyService::generate() ni a ninguna otra
 * ruta que reimporte archivos (ReportAnalysisService): eso reprocesaría TODAS las
 * fuentes del periodo, incluida "Lendus Ingresos Cobranza", dejándola en estado
 * "processing" sin necesidad. RadiografiaExportService::export()/exportPdf() ya
 * recalculan Nómina/EBITDA en vivo desde BranchRadiographyCalculator a partir del
 * PeriodSummary existente — no requieren reimportar nada.
 */
class RebuildDerivedNoiCommand extends Command
{
    protected $signature   = 'reportes:rebuild-derived-noi {period_id : ID del periodo mensual}';
    protected $description = 'Reconstruye roster/IMSS/Rotación derivados de NOI y regenera Excel/PDF, SIN reimportar Cobranza ni ninguna otra fuente.';

    public function __construct(
        private readonly PeriodEmployeeRosterService  $rosterService,
        private readonly ImssFromNoiFiscalService     $imssService,
        private readonly RotacionDerivedFromNoiService $rotacionService,
        private readonly RadiografiaExportService      $exportService,
    ) {
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

        $summary = PeriodSummary::query()->where('period_id', $period->id)->where('status', 'generated')->first();
        if (!$summary) {
            $this->error("No existe una radiografía generada para {$period->label}. Corre primero reportes:generar-radiografia {$periodId}.");
            return self::FAILURE;
        }

        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $this->line('');
        $this->info("══════ REBUILD DERIVADO NOI (IMSS + ROTACIÓN) — {$period->label} (ID #{$periodId}) ══════");
        $this->warn('  Este comando NO reimporta Cobranza ni ninguna otra fuente — solo recalcula IMSS/Rotación desde NOI y regenera Excel/PDF.');
        $this->line('');

        $this->line('  1. Reconstruyendo roster de empleados desde NOI (normal + fiscal)...');
        $roster = $this->rosterService->buildForPeriod($period);
        $this->line(sprintf(
            '  <fg=green>✓ Roster: %d activos, %d altas, %d bajas, %d sin sucursal, %d duplicados fusionados.</>',
            $roster['active_count'], $roster['altas'], $roster['bajas'], $roster['sin_sucursal'], $roster['duplicados_fusionados'],
        ));

        $this->line('  2. Recalculando IMSS desde NOI fiscal ($3,500 por colaborador)...');
        $imss = $this->imssService->deriveForPeriod($period);
        $this->line(sprintf(
            '  <fg=green>✓ IMSS: %d colaboradores incluidos, $%s (%d excluidos/sin sucursal).</>',
            $imss['collaborators_included'], number_format($imss['amount_included'], 2), $imss['sin_sucursal'],
        ));
        if ($imss['legacy_exists']) {
            $this->line($imss['matches_legacy']
                ? '     Coincide con el archivo manual legado.'
                : sprintf('     ⚠ Difiere del archivo manual legado ($%s) — el archivo queda solo como referencia histórica, el derivado es la fuente oficial.', number_format((float) $imss['legacy_amount'], 2)));
        }

        $this->line('  3. Recalculando Rotación desde NOI (normal + fiscal)...');
        $rotacion = $this->rotacionService->deriveForPeriod($period);
        $this->line(sprintf(
            '  <fg=green>✓ Rotación: %d altas / %d bajas / %d plantilla (índice %.2f%%).</>',
            $rotacion['altas'], $rotacion['bajas'], $rotacion['plantilla'], $rotacion['indice'],
        ));
        if ($rotacion['legacy_exists']) {
            $this->line($rotacion['matches_legacy']
                ? '     Coincide con el archivo manual legado.'
                : '     ⚠ Difiere del archivo manual legado — el archivo queda solo como referencia histórica, el derivado es la fuente oficial.');
        }

        $this->line('  4. Regenerando radiografía (Excel)...');
        $excelPath = $this->exportService->export($period);
        $this->line("  <fg=green>✓ Excel: {$excelPath}</>");

        $this->line('  5. Regenerando radiografía (PDF)...');
        try {
            $pdfPath = $this->exportService->exportPdf($period);
            $this->line("  <fg=green>✓ PDF: {$pdfPath}</>");
        } catch (\Throwable $e) {
            $this->warn('  ⚠ PDF falló (no crítico): ' . mb_strimwidth($e->getMessage(), 0, 120));
            $pdfPath = null;
        }

        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path'       => $excelPath,
            'file_type'         => 'excel',
            'template_version'  => config('app.version'),
            'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label, 'source' => 'rebuild-derived-noi'],
            'exported_at'       => now(),
        ]);
        if ($pdfPath !== null) {
            PeriodRadiographyExport::query()->create([
                'period_summary_id' => $summary->id,
                'export_path'       => $pdfPath,
                'file_type'         => 'pdf',
                'template_version'  => config('app.version'),
                'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label, 'source' => 'rebuild-derived-noi'],
                'exported_at'       => now(),
            ]);
        }

        $this->line('');
        $this->info('══════ REBUILD COMPLETO ══════');
        $this->line('  Cobranza, cartera, mora, colocación y recuperación NO fueron tocadas.');
        $this->line('  Valida con:');
        $this->line("    php artisan reportes:audit-imss-derivado {$periodId} --detail");
        $this->line("    php artisan reportes:audit-rotacion-derivada {$periodId} --detail");
        $this->line("    php artisan reportes:audit-roster-periodo {$periodId} --detail");
        $this->line('');

        return self::SUCCESS;
    }
}
