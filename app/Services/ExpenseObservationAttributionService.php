<?php

namespace App\Services;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Atribuye gastos OPEX del Excel de Gastos Lendus (gastos_lendus_excel) al
 * colaborador BENEFICIARIO real, usando las columnas Observación/Justificación
 * — que ya llegan concatenadas en fact_expenses.observations (' | ') — en vez de
 * confiar en la columna "Empleado" del archivo (que representa a quien
 * captura/administra el gasto desde la sucursal, NO siempre al beneficiario).
 *
 * PROBLEMA REAL (auditoría 27-ago-2026): GastosExcelBranchResolverService copia
 * employee_id/branch_id desde la fila espejo del PDF cuando existe (mismo monto +
 * fecha) — y ese employee_id del PDF viene, a su vez, de la MISMA columna
 * "Empleado" cruda (GastosLendusPdfImportService::resolveEmployee(), match
 * EXACTO sin verificar semántica de beneficiario). Para conceptos como RECARGAS
 * TELEFONICAS/GASTOS EMERGENTES, eso deja el gasto atribuido al administrador de
 * sucursal, no al colaborador real — y por eso el OPEX por colaborador salía en
 * $0/incorrecto en Web/Excel/PDF (ver RadiographySnapshotBuilder::
 * buildEmployeesGestores()::$expensesByNorm, que SÍ suma cualquier fact_expenses
 * con employee_id poblado, sin importar de dónde salió ese employee_id).
 *
 * ESTE SERVICIO NO CAMBIA QUÉ ES OPEX, NO TOCA amount/category/concept, NO borra
 * ni duplica gastos — solo re-resuelve employee_id/branch_id (las mismas
 * columnas que ya representan "beneficiario"/"su sucursal" en todo el pipeline)
 * cuando Observación/Justificación dan evidencia MÁS confiable que la propagada
 * desde el PDF. Deja intacto el tratamiento especializado de PAGO FINANCIAMIENTO
 * MOTO/COMPRA DE CASCOS (excluidos aquí — ya los resuelve
 * FinanciamientoMotosAssignmentService con su propio motor idéntico).
 *
 * Nunca inventa persona: solo atribuye con evidencia fuerte (alias confirmado,
 * nombre exacto, o fuzzy con margen amplio) contra el ROSTER VÁLIDO del periodo
 * (PeriodEmployeeRosterService) — nunca contra Employee::all(). Ambigüedad o
 * conflicto entre Observación y Justificación → NEEDS_REVIEW, nunca auto-asigna.
 */
class ExpenseObservationAttributionService
{
    /** Umbral fuzzy alto — esto corre sobre TODO el universo de OPEX (no un
     *  concepto acotado como motos/finiquito), así que exige más margen que el
     *  fuzzy_name (≥80%) usado en FinanciamientoMotosAssignmentService. */
    private const FUZZY_ACCEPT_THRESHOLD = 92.0;
    private const FUZZY_ACCEPT_MARGIN    = 8.0;
    /** Por debajo de esto ni se reporta como "ambiguo" — es simplemente texto no-persona. */
    private const AMBIGUOUS_FLOOR        = 75.0;

    public function __construct(
        private readonly PersonIdentityResolverService $personResolver,
        private readonly PeriodEmployeeRosterService $rosterService,
        private readonly BranchRadiographyCalculator $branchCalculator,
    ) {
    }

    /**
     * @return array<int, array{
     *   fact_expense_id:int, period_id:int, report_upload_id:int, category:string, concept:string,
     *   amount:float, raw_employee_name:?string, observation:?string, justification:?string,
     *   previous_employee_id:?int, previous_branch_id:?int,
     *   employee_id:?int, employee_name:?string, branch_id:?int, branch_name:?string,
     *   metodo:?string, confianza:float, fuente:?string, estado:string, changed:bool,
     * }>
     */
    public function attributeForPeriod(Period $period, array $dataIds, bool $dryRun = false): array
    {
        $lendusExcelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        if (!$lendusExcelId) {
            return [];
        }

        $roster = $this->rosterService->rosterRowsForSelector($period);
        $rosterByEmployeeId = [];
        $rosterNormalizedIndex = []; // normalized_name => employee_id (roster ya deduplicado por identidad)
        foreach ($roster['rows'] as $row) {
            $rosterByEmployeeId[$row['employee_id']] = $row;
            $norm = $this->personResolver->normalizePersonName($row['name']);
            if ($norm !== '') {
                $rosterNormalizedIndex[$norm] = $row['employee_id'];
            }
        }

        if (empty($rosterByEmployeeId)) {
            return [];
        }

        $aliasIndex = DB::table('employee_aliases')
            ->whereIn('employee_id', array_keys($rosterByEmployeeId))
            ->pluck('employee_id', 'normalized_alias')
            ->all();

        $delegatedConcepts = array_map('mb_strtoupper', FinanciamientoMotosAssignmentService::CONCEPTS);

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusExcelId)
            ->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), $delegatedConcepts)
            ->whereNotNull('e.observations')
            ->select(
                'e.id', 'e.period_id', 'e.report_upload_id', 'e.category', 'e.concept',
                'e.employee_id', 'e.branch_id', 'e.observations', 'e.raw_payload',
                DB::raw("COALESCE(NULLIF(e.paid_amount,0), e.amount) as amount")
            )
            ->get()
            // PROBLEMA REAL detectado corriendo esto contra datos reales (Junio 2026):
            // sin este filtro, conceptos como NOMINA/PAGO DE IMSS/DEDUCCIONES/ANTICIPO DE
            // NOMINA (categoría 'Nómina y Capital Humano') se atribuían igual que un gasto
            // OPEX — pero BranchRadiographyCalculator::accumulateGastos() NUNCA los suma a
            // ningún KPI a propósito ("ya están cubiertos por NOI y por el archivo IMSS
            // oficial — sumarlos aquí también duplicaría el gasto", ver su comentario
            // inline). buildEmployeesGestores() NO filtra por categoría al sumar gastos por
            // employee_id — así que atribuir esas filas habría duplicado, a nivel
            // colaborador, dinero que el EBITDA de empleado ya cuenta vía $neto (NOI). Este
            // filtro replica EXACTAMENTE la misma exclusión que accumulateGastos() aplica a
            // nivel sucursal/general — nunca "qué es OPEX", solo A QUIÉN se atribuye.
            ->filter(fn ($row) => $this->isEligibleForAttribution((string) $row->category, (string) $row->concept))
            ->values();

        $operativeMap = $this->branchCalculator->buildBranchMap()['operative'];
        $results = [];

        foreach ($rows as $row) {
            $result = $this->resolveRow($row, $rosterByEmployeeId, $rosterNormalizedIndex, $aliasIndex, $operativeMap);

            if ($result['changed'] && !$dryRun) {
                DB::table('fact_expenses')->where('id', $row->id)->update([
                    'employee_id'               => $result['employee_id'],
                    'branch_id'                 => $result['branch_id'],
                    'attribution_method'        => $result['metodo'],
                    'attribution_confidence'    => $result['confianza'] ?: null,
                    'attribution_source'        => $result['fuente'],
                    'attribution_needs_review'  => $result['estado'] === 'conflicto' || $result['estado'] === 'ambiguo',
                    'updated_at'                => now(),
                ]);
            } elseif (!$dryRun && in_array($result['estado'], ['conflicto', 'ambiguo'], true)) {
                // No toca employee_id/branch_id — solo deja constancia de la revisión pendiente.
                DB::table('fact_expenses')->where('id', $row->id)->update([
                    'attribution_method'       => $result['metodo'],
                    'attribution_confidence'   => $result['confianza'] ?: null,
                    'attribution_source'       => $result['fuente'],
                    'attribution_needs_review' => true,
                    'updated_at'               => now(),
                ]);
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Espejo DELIBERADO (no reutilizable directamente — la clasificación real vive
     * dentro del bucle privado de BranchRadiographyCalculator::accumulateGastos(),
     * que es código de cálculo financiero que este trabajo tiene prohibido tocar) de
     * qué categorías/conceptos cuentan como OPEX ahí. Si esa clasificación cambia,
     * esta lista debe actualizarse a mano — están unidas por comentario, no por código
     * compartido, a propósito (evita acoplar un cambio de negocio financiero a este
     * servicio de atribución dimensional).
     *
     * Excluye (nunca atribuye a un colaborador):
     *   - Excedentes ('Envío de utilidad a corporativo' / contiene EXCEDENTE)
     *   - Fondeo ('Préstamos Intersucursales' / contiene FONDEO o INTERSUCURSAL)
     *   - Pólizas (seguros/coberturas puente)
     *   - Nómina y Capital Humano → NOMINA/PAGO DE IMSS/DEDUCCIONES/DEDUCCIONES
     *     GENERALES/PAGO PRESTAMO Z/ANTICIPO DE NOMINA — accumulateGastos() nunca los
     *     suma a ningún KPI porque YA están cubiertos por NOI/IMSS; atribuirlos aquí
     *     duplicaría ese gasto en el EBITDA del colaborador (que usa NOI vía $neto).
     * Mantiene elegibles PAGO FINIQUITO / GASTOS MEDICOS (categoría Nómina y Capital
     * Humano): ya son atribuibles hoy vía GastosExcelBranchResolverService (precedente
     * existente, no nuevo) — son pagos reales y puntuales al colaborador, no una
     * duplicación de su nómina recurrente.
     */
    private function isEligibleForAttribution(string $category, string $concept): bool
    {
        $catUpper = mb_strtoupper(trim($category));
        $conceptUpper = preg_replace('/\s+/u', ' ', mb_strtoupper(trim($concept))) ?? mb_strtoupper(trim($concept));

        if ($catUpper === 'ENVÍO DE UTILIDAD A CORPORATIVO' || str_contains($catUpper, 'EXCEDENTE')) {
            return false;
        }
        if ($catUpper === 'PRÉSTAMOS INTERSUCURSALES' || str_contains($catUpper, 'FONDEO') || str_contains($catUpper, 'INTERSUCURSAL')) {
            return false;
        }
        if ($catUpper === 'PÓLIZAS') {
            return false;
        }

        if ($catUpper === 'NÓMINA Y CAPITAL HUMANO') {
            $duplicatesNoiOrImss = in_array($conceptUpper, [
                'NOMINA', 'PAGO DE IMSS', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO PRESTAMO Z', 'ANTICIPO DE NOMINA',
            ], true);
            if ($duplicatesNoiOrImss) {
                return false;
            }
            // FINIQUITO/MEDICO quedan elegibles (return true más abajo).
        } elseif (str_contains($catUpper, 'NOMINA') || str_contains($catUpper, 'NÓMINA')) {
            return false;
        }

        return true;
    }

    private function resolveRow(
        object $row,
        array $rosterByEmployeeId,
        array $rosterNormalizedIndex,
        array $aliasIndex,
        array $operativeMap,
    ): array {
        [$obsText, $justText] = $this->splitObservations((string) $row->observations);

        $obsMatch  = $obsText  !== null ? $this->matchAgainstRoster($obsText, $rosterNormalizedIndex, $aliasIndex, $rosterByEmployeeId) : null;
        $justMatch = $justText !== null ? $this->matchAgainstRoster($justText, $rosterNormalizedIndex, $aliasIndex, $rosterByEmployeeId) : null;

        $rawEmployeeName = is_array($row->raw_payload)
            ? ($row->raw_payload['solicitante'] ?? null)
            : (($decoded = json_decode((string) $row->raw_payload, true)) ? ($decoded['solicitante'] ?? null) : null);

        $base = [
            'fact_expense_id'      => $row->id,
            'period_id'            => $row->period_id,
            'report_upload_id'     => $row->report_upload_id,
            'category'             => (string) $row->category,
            'concept'              => (string) $row->concept,
            'amount'               => (float) $row->amount,
            'raw_employee_name'    => $rawEmployeeName,
            'observation'          => $obsText,
            'justification'        => $justText,
            'previous_employee_id' => $row->employee_id,
            'previous_branch_id'   => $row->branch_id,
        ];

        // CONFLICTO: ambos campos resuelven a personas VÁLIDAS y DISTINTAS — nunca
        // se elige arbitrariamente entre ellas.
        if (
            $obsMatch && $justMatch
            && ($obsMatch['employee_id'] ?? null) && ($justMatch['employee_id'] ?? null)
            && $obsMatch['employee_id'] !== $justMatch['employee_id']
        ) {
            return $base + [
                'employee_id' => null, 'employee_name' => null, 'branch_id' => null, 'branch_name' => null,
                'metodo' => 'conflict', 'confianza' => 0.0, 'fuente' => null,
                'estado' => 'conflicto', 'changed' => false,
            ];
        }

        $winner = null;
        $source = null;
        if ($obsMatch && ($obsMatch['employee_id'] ?? null)) {
            $winner = $obsMatch;
            $source = 'observation';
        } elseif ($justMatch && ($justMatch['employee_id'] ?? null)) {
            $winner = $justMatch;
            $source = 'justification';
        }

        if ($winner === null) {
            // Ninguno resolvió con confianza — ¿alguno fue "candidato pero ambiguo"?
            $ambiguous = ($obsMatch && $obsMatch['method'] === 'ambiguous') || ($justMatch && $justMatch['method'] === 'ambiguous');
            if ($ambiguous) {
                $amb = ($obsMatch && $obsMatch['method'] === 'ambiguous') ? $obsMatch : $justMatch;
                return $base + [
                    'employee_id' => null, 'employee_name' => null, 'branch_id' => null, 'branch_name' => null,
                    'metodo' => 'ambiguous', 'confianza' => $amb['confidence'] ?? 0.0,
                    'fuente' => ($obsMatch && $obsMatch['method'] === 'ambiguous') ? 'observation' : 'justification',
                    'estado' => 'ambiguo', 'changed' => false,
                ];
            }

            return $base + [
                'employee_id' => null, 'employee_name' => null, 'branch_id' => null, 'branch_name' => null,
                'metodo' => null, 'confianza' => 0.0, 'fuente' => null,
                'estado' => 'no_atribuible', 'changed' => false,
            ];
        }

        $winnerEmployeeId = $winner['employee_id'];
        $rosterRow        = $rosterByEmployeeId[$winnerEmployeeId];

        // Sucursal atribuida = la HISTÓRICA del colaborador para ESTE periodo (ya
        // resuelta por PeriodEmployeeRosterService vía employee_branch_assignments,
        // con fallback a la asignación histórica más reciente — nunca employees.
        // branch_id actual). Solo se acepta si es una de las 13 sucursales oficiales;
        // si no, se conserva el branch_id que ya tenía la fila (nunca se inventa ni
        // se blanquea sucursal).
        $branchId   = ($rosterRow['branch_id'] && $rosterRow['is_branch_operativa']) ? (int) $rosterRow['branch_id'] : $row->branch_id;
        $branchName = ($rosterRow['branch_id'] && $rosterRow['is_branch_operativa'])
            ? ($operativeMap[$branchId] ?? $rosterRow['branch_name'])
            : ($row->branch_id ? ($operativeMap[$row->branch_id] ?? null) : null);

        $changed = ((int) $row->employee_id !== $winnerEmployeeId) || ((int) $row->branch_id !== (int) $branchId);

        return $base + [
            'employee_id'   => $winnerEmployeeId,
            'employee_name' => $rosterRow['name'],
            'branch_id'     => $branchId,
            'branch_name'   => $branchName,
            'metodo'        => $winner['method'],
            'confianza'     => $winner['confidence'],
            'fuente'        => $source,
            'estado'        => $changed ? 'atribuido' : 'ya_correcto',
            'changed'       => $changed,
        ];
    }

    /**
     * "OBS | JUST" (ambos), "OBS" o "JUST" (solo uno) — reconstruye el formato EXACTO
     * usado por GastosLendusExcelImportService: implode(' | ', array_filter([obs, just])).
     * Con un único segmento no es posible saber si era observación o justificación —
     * no importa para la resolución (se intenta igual como "texto primario").
     */
    private function splitObservations(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }
        if (str_contains($raw, ' | ')) {
            [$first, $rest] = explode(' | ', $raw, 2);
            $first = trim($first) !== '' ? trim($first) : null;
            $rest  = trim($rest) !== '' ? trim($rest) : null;
            return [$first, $rest];
        }
        return [$raw, null];
    }

    /**
     * @return array{employee_id:?int,method:string,confidence:float}|null
     */
    private function matchAgainstRoster(string $text, array $rosterNormalizedIndex, array $aliasIndex, array $rosterByEmployeeId): ?array
    {
        $normalized = $this->personResolver->normalizePersonName($text);
        if ($normalized === '') {
            return null;
        }

        // 1. Alias confirmado (employee_aliases), restringido a empleados del roster.
        if (isset($aliasIndex[$normalized])) {
            return ['employee_id' => (int) $aliasIndex[$normalized], 'method' => 'alias', 'confidence' => 1.0];
        }

        // 2. Nombre exacto contra el roster (índice O(1)).
        if (isset($rosterNormalizedIndex[$normalized])) {
            return ['employee_id' => $rosterNormalizedIndex[$normalized], 'method' => 'exact_name', 'confidence' => 1.0];
        }

        // Pre-filtro barato: exige ≥2 tokens de ≥3 caracteres antes de intentar fuzzy —
        // descarta rápido texto operativo tipo "RECARGA DE EXTINTOR"/"SEMANA 23" sin
        // recorrer el roster completo por cada fila.
        $tokens = array_values(array_filter(explode(' ', $normalized), fn ($t) => mb_strlen($t) >= 3));
        if (count($tokens) < 2) {
            return null;
        }

        // 3. Fuzzy contra cada nombre del roster — nunca contra Employee::all().
        $best = null;
        $bestScore = 0.0;
        $secondScore = 0.0;
        foreach ($rosterByEmployeeId as $eid => $rosterRow) {
            $rosterNorm = $this->personResolver->normalizePersonName($rosterRow['name']);
            if ($rosterNorm === '') {
                continue;
            }
            $score = $this->personResolver->scoreNameSimilarity($normalized, $rosterNorm);
            if ($score > $bestScore) {
                $secondScore = $bestScore;
                $bestScore   = $score;
                $best        = $eid;
            } elseif ($score > $secondScore) {
                $secondScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }

        if ($bestScore >= self::FUZZY_ACCEPT_THRESHOLD && ($bestScore - $secondScore) >= self::FUZZY_ACCEPT_MARGIN) {
            return ['employee_id' => $best, 'method' => 'fuzzy_name', 'confidence' => round($bestScore / 100, 2)];
        }

        if ($bestScore >= self::AMBIGUOUS_FLOOR) {
            // Candidato real pero sin confianza/margen suficiente — reportado como
            // ambiguo, nunca auto-asignado.
            return ['employee_id' => null, 'method' => 'ambiguous', 'confidence' => round($bestScore / 100, 2)];
        }

        return null;
    }
}
