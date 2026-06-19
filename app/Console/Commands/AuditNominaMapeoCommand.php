<?php

namespace App\Console\Commands;

use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de mapeo de nómina: para cada código NOI (P.../D...) y cada concepto de
 * fact_expenses bajo "Nómina y Capital Humano", muestra a qué línea del reporte se
 * destina (o por qué se excluye), para que nunca quede un monto bajo una etiqueta
 * genérica "Otros" sin saber exactamente de dónde viene.
 */
class AuditNominaMapeoCommand extends Command
{
    protected $signature = 'reportes:audit-nomina-mapeo {period_id}';

    protected $description = 'Audita el mapeo de cada código NOI y concepto de gasto de nómina: Concepto | Código | Monto | Estado.';

    // Mismo whitelist que BranchRadiographyCalculator::accumulateNomina()
    private const NOI_PERCEPCION_CODES = ['P001', 'P002', 'P009', 'P010', 'P108', 'P109', 'P120', 'P123'];
    private const NOI_DEDUCCION_CODES  = ['D094', 'D010', 'D113', 'D123', 'D111', 'D137', 'D004'];

    // Concepts de fact_expenses (Nómina y Capital Humano) que se excluyen a propósito
    // porque ya están cubiertos por otra fuente (NOI o el detalle por sucursal de gastos_lendus).
    private const EXPENSE_EXCLUDED_CONCEPTS = [
        'NOMINA', 'DEDUCCIONES', 'DEDUCCIONES GENERALES',
        'PAGO DE IMSS', 'GASTOS EMERGENTES', 'GASTOS POR TRANSPORTE',
    ];

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');

        $this->line('');
        $this->info("══════ AUDITORÍA DE MAPEO DE NÓMINA — Periodo #{$periodId} ══════");
        $this->line('');

        $this->auditNoiCodes($periodId);
        $this->line('');
        $this->auditExpenseConcepts($periodId);

        return self::SUCCESS;
    }

    private function auditNoiCodes(int $periodId): void
    {
        $this->info('── A. Códigos NOI (fact_noi_movements) ──');

        $rows = DB::table('fact_noi_movements')
            ->where('period_id', $periodId)
            ->selectRaw('LEFT(concept, 4) as code, concept_type, SUM(amount) as total, COUNT(*) as cnt')
            ->groupBy('code', 'concept_type')
            ->orderByDesc('total')
            ->get();

        $whitelist = array_merge(self::NOI_PERCEPCION_CODES, self::NOI_DEDUCCION_CODES);
        $table = [];
        foreach ($rows as $r) {
            $estado = in_array($r->code, $whitelist, true) ? 'Mapeado' : 'Pendiente de clasificar';
            $table[] = [$r->code, $r->concept_type, '$' . number_format((float) $r->total, 2), $r->cnt, $estado];
        }

        $this->table(['Código', 'Tipo', 'Monto', '# Movs', 'Estado'], $table);

        $pendientes = array_filter($table, fn ($row) => $row[4] === 'Pendiente de clasificar');
        if (!empty($pendientes)) {
            $this->warn('⚠ Hay códigos NOI sin clasificar — revisar BranchRadiographyCalculator::accumulateNomina().');
        } else {
            $this->line('✓ Todos los códigos NOI de este periodo están mapeados.');
        }
    }

    private function auditExpenseConcepts(int $periodId): void
    {
        $this->info('── B. Conceptos de gasto bajo "Nómina y Capital Humano" (fact_expenses) ──');

        $calc = app(BranchRadiographyCalculator::class);
        $ref  = new \ReflectionClass($calc);
        $canonicalMethod = $ref->getMethod('canonicalNominaExpense');
        $canonicalMethod->setAccessible(true);

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('e.period_id', $periodId)
            ->where(function ($q) {
                $q->whereIn('e.category', ['Gasolina', 'Financiamiento Celular', 'Nómina y Capital Humano', 'Nomina y Capital Humano']);
            })
            ->selectRaw("ds.code as fuente, e.category, COALESCE(e.concept,'') as concept,
                SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total, COUNT(*) as cnt")
            ->groupBy('ds.code', 'e.category', 'e.concept')
            ->orderByDesc('total')
            ->get();

        $table = [];
        foreach ($rows as $r) {
            $conceptUpper = mb_strtoupper(trim((string) $r->concept));

            if ($r->fuente === 'gastos_lendus_excel') {
                // Esta fuente solo aporta detalle por sucursal de Financiamiento de Motos y
                // Cascos; sus filas sin sucursal son resúmenes globales ya cubiertos por NOI
                // o por gastos_lendus/gastos_erp — se excluyen a propósito (no son una pérdida).
                $estado = in_array($conceptUpper, ['PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS', 'PAGO FINANCIAMIENTO CELULAR'], true)
                    ? 'Mapeado (detalle por sucursal)'
                    : 'Excluido correctamente (resumen duplicado de otra fuente)';
            } elseif (in_array($conceptUpper, self::EXPENSE_EXCLUDED_CONCEPTS, true)) {
                $estado = 'Excluido correctamente (cubierto por NOI)';
            } else {
                $label  = $canonicalMethod->invoke($calc, $r->category, $r->concept);
                $estado = str_starts_with($label, 'Sin clasificar:') ? "Pendiente de clasificar → {$label}" : "Mapeado → {$label}";
            }

            $table[] = [$r->fuente, $r->category, $r->concept, '$' . number_format((float) $r->total, 2), $r->cnt, $estado];
        }

        $this->table(['Fuente', 'Categoría', 'Concepto', 'Monto', '# Regs', 'Estado'], $table);

        $pendientes = array_filter($table, fn ($row) => str_starts_with($row[5], 'Pendiente de clasificar'));
        if (!empty($pendientes)) {
            $this->warn('⚠ Hay conceptos de gasto de nómina sin clasificar — revisar canonicalNominaExpense().');
        } else {
            $this->line('✓ Todos los conceptos de gasto de nómina de este periodo están mapeados o excluidos a propósito.');
        }
    }
}
