<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribución de OPEX a colaboradores vía Observación/Justificación (auditoría
 * 27-ago-2026). fact_expenses.employee_id/branch_id YA significan "beneficiario
 * del gasto"/"sucursal del beneficiario" en todo el pipeline existente (import
 * PDF, GastosExcelBranchResolverService, FinanciamientoMotosAssignmentService) —
 * se reutilizan tal cual, NO se duplican. fact_expenses.observations YA contiene
 * Observación+Justificación concatenadas (' | ') — tampoco se duplica.
 *
 * Lo único que falta y no tiene dónde vivir hoy es la METADATA de CÓMO se llegó
 * a esa atribución (para trazabilidad/auditoría y para no re-interpretar texto
 * en cada preview — ver Services/ExpenseObservationAttributionService.php):
 *   - attribution_method:      'alias' | 'exact_name' | 'fuzzy_name' | 'conflict' | 'ambiguous' | null
 *   - attribution_confidence:  0.00–1.00
 *   - attribution_source:      'observation' | 'justification' | null
 *   - attribution_needs_review: true para conflicto/ambiguo (el gasto NO se toca,
 *     solo se marca para revisión humana — nunca bloquea nada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_expenses', function (Blueprint $table) {
            $table->string('attribution_method', 20)->nullable()->after('employee_id');
            $table->decimal('attribution_confidence', 3, 2)->nullable()->after('attribution_method');
            $table->string('attribution_source', 20)->nullable()->after('attribution_confidence');
            $table->boolean('attribution_needs_review')->default(false)->after('attribution_source');
            $table->index('attribution_needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('fact_expenses', function (Blueprint $table) {
            $table->dropIndex(['attribution_needs_review']);
            $table->dropColumn([
                'attribution_method',
                'attribution_confidence',
                'attribution_source',
                'attribution_needs_review',
            ]);
        });
    }
};
