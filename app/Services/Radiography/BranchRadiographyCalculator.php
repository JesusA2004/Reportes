<?php

namespace App\Services\Radiography;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Support\Facades\DB;

/**
 * Builds per-branch financial summaries for the 12 operative sucursales.
 * GLOBAL is always derived as the sum of those branch summaries,
 * never recalculated independently from all fact_* rows.
 */
class BranchRadiographyCalculator
{
    private const EXCEDENTES_CAT     = 'Envío de utilidad a corporativo';
    private const FONDEO_CAT         = 'Préstamos Intersucursales';
    private const NOMINA_CAT         = 'Nómina y Capital Humano';

    // Concepts inside 'Nómina y Capital Humano' from Lendus that must be excluded from
    // gastos_lendus_total (OPEX) here in accumulateGastos():
    //   - NOMINA/PAGO DE IMSS/DEDUCCIONES*/PAGO PRESTAMO Z: true payroll/IMSS items already
    //     covered by NOI and by the official IMSS file — excluded everywhere, never counted.
    //   - PAGO FINANCIAMIENTO MOTO / COMPRA DE CASCOS: real operational expenses, counted in
    //     OPEX (gastos_operativos) via the dedicated gastos_lendus_excel block inside
    //     accumulateNomina() (the PDF source read here would otherwise duplicate that same
    //     real-world payment). Also shown as nomina_informativo — display-only, NEVER summed
    //     into the KPI Nómina (regla 4/6C: gasto real → OPEX, no contamina Nómina).
    private const LENDUS_NOMINA_SKIP_CONCEPTS = [
        'NOMINA', 'PAGO DE IMSS', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO PRESTAMO Z',
        'PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS', 'ANTICIPO DE NOMINA',
    ];

    // Pólizas/seguros category — excluded from OPEX for BOTH sources (regla vigente 2026-07:
    // pólizas/seguros deben quedar en $0.00 dentro de OPEX). Lendus 'Pólizas' = passthrough a
    // aseguradoras (tracked as seguros_lendus_puente); ERP 'Pólizas' = seguros vehiculares/oficina
    // (SEGUROS AUTOMOVIL, etc., tracked as gastos_erp_excluido_seguros). Antes ERP se incluía en
    // OPEX — ya no, por instrucción explícita de excluir seguros/pólizas de OPEX en ambas fuentes.
    private const SEGUROS_LENDUS_CATS = ['Pólizas'];

    // DEPRECATED — kept only so old references don't break during the transition. No longer
    // used to compute nomina_total: since the 2026-07-10 rewrite, nomina_total already has
    // every NOI deduction subtracted at the source (accumulateNomina()), and nomina_detalle /
    // nomina_informativo are NEVER summed by any consumer. Regla: Nómina KPI = NOI neto
    // (percepciones − deducciones), nada de gastos ERP/Lendus/IMSS lo infla.
    public const NOMINA_DEDUCTION_LABELS = [
        'IMSS',
        'Descuentos Infonavit',
        'Descuento Servicios Moto',
        'Financiamiento Celular',
        'Descuento de uniformes',
        'Descuento gastos sin comprobar',
        'Descuento extravío tarjeta de circulación',
        'Descuentos Tienda Mr Lana',
        'Descuento Servicios Automóvil',
        'Descuento faltante en caja',
        'Anticipo de nómina',
        'Pensión Alimenticia',
        'Descuentos FONACOT',
    ];


    public function __construct(private readonly BranchResolverService $resolver) {}

    /**
     * Fuente canónica ÚNICA del KPI "Nómina y Capital Humano" para un branch summary o GLOBAL.
     * Regla vigente (2026-07, criterio final):
     *   = percepciones NOI normal + percepciones NOI fiscal (sueldo+comisiones+bonos+
     *     vacaciones+prima+otros — TODAS las percepciones, brutas, SIN restar deducciones)
     *   + IMSS patronal operativo (imss_patronal, excluye Corporativo/Tulancingo)
     *   + gastos reales de empleados: Financiamiento de Motos, Enganche de Motocicleta,
     *     Cascos, Finiquito, Gastos médicos (gastos_empleados_nomina).
     * Las deducciones NOI (nomina_detalle) YA NO se restan de nomina_total — son puramente
     * informativas para que el usuario las valide manualmente (ver accumulateNomina()).
     * nomina_informativo es desglose de IMSS/Motos/Cascos/Finiquito/Médicos — sus montos YA
     * están incluidos en imss_patronal/gastos_empleados_nomina, no se vuelven a sumar.
     * Todo consumidor (PeriodRadiographyService, RadiographySnapshotBuilder, RadiographyStyleHelper,
     * RadiographyWorkbookBuilder, PDF, Preview.vue) DEBE usar este método — no reimplementar la suma.
     */
    public static function nominaTotalFor(array $branchOrGlobal): float
    {
        return (float) ($branchOrGlobal['nomina_total'] ?? 0)
            + (float) ($branchOrGlobal['comisiones'] ?? 0)
            + (float) ($branchOrGlobal['bonos'] ?? 0)
            + (float) ($branchOrGlobal['bonos_aceleradores'] ?? 0)
            + (float) ($branchOrGlobal['vacaciones'] ?? 0)
            + (float) ($branchOrGlobal['prima_vacacional'] ?? 0)
            + (float) ($branchOrGlobal['otros_percepciones'] ?? 0)
            + (float) ($branchOrGlobal['imss_patronal'] ?? 0)
            + (float) ($branchOrGlobal['gastos_empleados_nomina'] ?? 0);
    }

    /**
     * Fuente canónica ÚNICA del "Ingreso base EBITDA" para un branch summary o GLOBAL.
     * Regla vigente (2026-07, criterio final): EBITDA NO usa Recuperación total (que incluye
     * capital recuperado — no es ingreso real) ni Colocación. El ingreso base EBITDA es
     * únicamente la suma de los componentes de Recuperación que SÍ son ingreso real:
     *   Intereses + Impuestos + Moratorios/Multas (charges) + Comisión por apertura
     *   + Cargos adicionales + Excedentes recuperados + Seguro CRECE reconocido (30%).
     * NUNCA incluye capital_recuperado. Todo consumidor de EBITDA (RadiographySnapshotBuilder,
     * RadiographyStyleHelper::branchEbitdaEstimate(), RadiographyWorkbookBuilder, PDF,
     * Preview.vue) DEBE usar este método — no reimplementar la suma.
     */
    public static function ingresoEbitdaBaseFor(array $branchOrGlobal): float
    {
        return (float) ($branchOrGlobal['interes_recuperado'] ?? 0)
            + (float) ($branchOrGlobal['impuesto_recuperado'] ?? 0)
            + (float) ($branchOrGlobal['charges'] ?? 0)
            + (float) ($branchOrGlobal['comision_apertura'] ?? 0)
            + (float) ($branchOrGlobal['cargos_adicionales'] ?? 0)
            + (float) ($branchOrGlobal['excedente_recuperado'] ?? 0)
            + (float) ($branchOrGlobal['seguro_crece_reconocido'] ?? 0);
    }

    /**
     * Gastos Totales = OPEX (gastos_operativos) + Nómina y Capital Humano (nominaTotalFor()).
     * Fuente canónica única — ver ingresoEbitdaBaseFor()/nominaTotalFor().
     */
    public static function gastosTotalesFor(array $branchOrGlobal): float
    {
        return (float) ($branchOrGlobal['gastos_operativos'] ?? 0) + self::nominaTotalFor($branchOrGlobal);
    }

    /**
     * EBITDA final = Ingreso base EBITDA − Gastos Totales. Fuente canónica única.
     */
    public static function ebitdaFinalFor(array $branchOrGlobal): float
    {
        return self::ingresoEbitdaBaseFor($branchOrGlobal) - self::gastosTotalesFor($branchOrGlobal);
    }

    /**
     * Margen EBITDA = EBITDA final / Ingreso base EBITDA * 100. Fuente canónica única.
     */
    public static function margenEbitdaFor(array $branchOrGlobal): float
    {
        $base = self::ingresoEbitdaBaseFor($branchOrGlobal);
        return $base > 0 ? round(self::ebitdaFinalFor($branchOrGlobal) / $base * 100, 2) : 0.0;
    }

    /**
     * Resolves all DB branches to their real operative sucursal.
     *
     * Returns:
     *   'operative'   => [branch_id => real_sucursal_name]  (only the 12 operative branches)
     *   'corporativo' => [branch_id, ...]
     */
    public function buildBranchMap(): array
    {
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

        return ['operative' => $operativeMap, 'corporativo' => $corporativoIds];
    }

    /**
     * Returns branch_ids that resolve to AGUASCALIENTES (any route/alias).
     */
    private function buildAguascalientesBranchIds(): array
    {
        $ids = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if ($real && strtoupper(trim($real)) === 'AGUASCALIENTES') {
                $ids[] = (int) $b->id;
            }
        }
        return $ids;
    }

    /**
     * Valor cartera / Cartera vencida — fuente "Saldos Por Cliente".
     * Valor cartera = SUM(balance / Saldo actual), único filtro: excluir Aguascalientes/AGS.
     * Cartera vencida = SUM(5 columnas vencidas) donde días_vencido > 0, mismo filtro AGS.
     * 5 columnas: capital_due + interes_atrasado + impuesto_atrasado
     *             + saldo_interes_moratorio + saldo_impuesto_interes_moratorio.
     * Sin filtro por estatus, producto, substatus, reestructura, membresía, migración.
     * Incluye TODAS las sucursales/rutas excepto AGS — distinto de accumulateCartera() que solo
     * usa las 13 operativas.
     */
    public function computeCarteraGlobalSinFiltro(array $dataIds): array
    {
        $agsIds = $this->buildAguascalientesBranchIds();

        $base = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->when(!empty($agsIds), fn ($q) => $q->whereNotIn('branch_id', $agsIds));

        $valorCartera = (float) $base->clone()->sum('balance');

        $carteraVencida = (float) ($base->clone()
            ->where('days_past_due', '>', 0)
            ->selectRaw('SUM(
                COALESCE(capital_due, 0)
                + COALESCE(interes_atrasado, 0)
                + COALESCE(impuesto_atrasado, 0)
                + COALESCE(saldo_interes_moratorio, 0)
                + COALESCE(saldo_impuesto_interes_moratorio, 0)
            ) as vencido')
            ->first()?->vencido ?? 0.0);

        return ['valor_cartera' => $valorCartera, 'cartera_vencida' => $carteraVencida];
    }

    /**
     * Raw NOI totals (percepciones/deducciones/neto pagado), unfiltered by the payroll
     * classification whitelist used for Sueldos/Comisiones/etc. — this is the "what the
     * worker actually received" figure, shown separately from Nómina y Capital Humano
     * (which is a company-expense concept, not a net-pay concept). Deductions are shown
     * here to explain the worker's net pay; they are NEVER added again as a company expense.
     */
    public function computeNoiPercepcionesDeducciones(array $dataIds): array
    {
        $percepciones = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->sum('amount');

        $deducciones = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'deduccion')
            ->sum('amount');

        return [
            'percepciones' => $percepciones,
            'deducciones'  => $deducciones,
            'neto_pagado'  => $percepciones - $deducciones,
        ];
    }

    /**
     * Builds per-branch summaries for the 12 operative branches plus an unassigned bucket.
     *
     * Returns ['branches' => [...12 summaries...], 'unassigned' => [nómina/comisiones/bonos/gastos sin sucursal]]
     * GLOBAL must be computed via sumGlobal($branches, $unassigned) to include unassigned amounts.
     *
     * @param array $includedBranchIds When non-empty, only these branch IDs contribute to metrics.
     */
    public function buildBranches(Period $period, array $dataIds, array $includedBranchIds = []): array
    {
        $maps            = $this->buildBranchMap();
        $operativeMap    = $maps['operative'];
        $corporativoIds  = $maps['corporativo'];

        // Filter operativeMap to only the requested branches.
        // Routes that resolve to the same real branch are excluded when that branch is excluded.
        if (!empty($includedBranchIds)) {
            $includedNames = DB::table('branches')
                ->whereIn('id', $includedBranchIds)
                ->pluck('name')
                ->map(fn ($n) => strtoupper(trim((string) \Illuminate\Support\Str::ascii($n))))
                ->all();

            $operativeMap = array_filter(
                $operativeMap,
                fn ($resolvedName) => in_array(
                    strtoupper(trim((string) \Illuminate\Support\Str::ascii($resolvedName))),
                    $includedNames,
                    true
                )
            );
        }

        $operativeIds    = array_keys($operativeMap);

        $summaries = [];
        foreach ($this->resolver->operativeFinancialBranches() as $sucursal) {
            if (!empty($includedBranchIds)) {
                $sucNorm = strtoupper(trim((string) \Illuminate\Support\Str::ascii($sucursal)));
                if (!in_array($sucNorm, $includedNames, true)) {
                    continue;
                }
            }
            $summaries[$sucursal] = $this->emptyBranchSummary($sucursal);
        }

        $unassigned = $this->emptyUnassigned();

        if (empty($operativeIds)) {
            return ['branches' => array_values($summaries), 'unassigned' => $unassigned];
        }

        // Cartera / colocación / recuperación / mora: branch_id / ruta, NO empleados
        $this->accumulateCartera($dataIds, $operativeIds, $operativeMap, $summaries);
        $this->accumulateColocacion($dataIds, $operativeIds, $operativeMap, $summaries);
        $this->accumulateRecuperacion($dataIds, $operativeIds, $operativeMap, $summaries, $corporativoIds);
        $this->accumulateMora($dataIds, $operativeIds, $operativeMap, $summaries);

        // Gastos: branch_id de Lendus/ERP; Corporativo → unassigned; Norte → excluido
        $this->accumulateGastos($dataIds, $operativeIds, $operativeMap, $summaries, $corporativoIds, $unassigned);

        // Nómina/comisiones/bonos: employee_branch_assignments; sin match → unassigned
        $this->accumulateNomina($dataIds, $operativeMap, $summaries, $unassigned, $corporativoIds);

        // Pólizas CRECE: 30% share stored in fact_recoveries.savehearts_crece_share
        $this->accumulatePolizasCrece($dataIds, $operativeIds, $operativeMap, $summaries);

        // IMSS patronal: cuota mensual por sucursal desde el archivo IMSS (data_source 'imss')
        $this->accumulateImssPatronal($dataIds, $operativeIds, $operativeMap, $summaries, $unassigned);

        return ['branches' => array_values($summaries), 'unassigned' => $unassigned];
    }

    /**
     * Sums all branch metrics to produce the GLOBAL summary.
     * GLOBAL = SUM(branches) + unassigned(nómina/comisiones/bonos/gastos).
     * Cartera / recuperación / colocación / mora come ONLY from branches (not unassigned).
     */
    public function sumGlobal(array $branches, array $unassigned = []): array
    {
        $global = $this->emptyBranchSummary('GLOBAL');

        foreach ($branches as $branch) {
            foreach ($branch as $key => $val) {
                if ($key === 'sucursal' || $key === 'gastos_detalle' || $key === 'nomina_detalle' || $key === 'nomina_informativo' || $key === 'otros_detalle') {
                    continue; // array fields handled separately
                }
                if (is_numeric($val) && array_key_exists($key, $global)) {
                    $global[$key] += (float) $val;
                }
            }
        }

        // Add unassigned EMPLOYEE amounts to GLOBAL (employees belong to the period regardless of branch).
        // CORPORATIVO gastos are NOT added — CORPORATIVO is excluded entirely from ERP/Lendus gastos.
        foreach (['nomina_total', 'comisiones', 'bonos', 'bonos_aceleradores', 'vacaciones', 'prima_vacacional', 'otros_percepciones', 'imss_patronal', 'gastos_empleados_nomina'] as $key) {
            $global[$key] += (float) ($unassigned[$key] ?? 0);
        }

        // Sum gastos_detalle across all branches for GLOBAL breakdown
        $global['gastos_detalle'] = [];
        foreach ($branches as $branch) {
            foreach ($branch['gastos_detalle'] ?? [] as $concept => $amount) {
                $global['gastos_detalle'][$concept] = ($global['gastos_detalle'][$concept] ?? 0.0) + (float) $amount;
            }
        }

        // Sum nomina_detalle across all branches + unassigned for GLOBAL breakdown
        $global['nomina_detalle'] = [];
        foreach ($branches as $branch) {
            foreach ($branch['nomina_detalle'] ?? [] as $label => $amount) {
                $global['nomina_detalle'][$label] = ($global['nomina_detalle'][$label] ?? 0.0) + (float) $amount;
            }
        }
        foreach ($unassigned['nomina_detalle'] ?? [] as $label => $amount) {
            $global['nomina_detalle'][$label] = ($global['nomina_detalle'][$label] ?? 0.0) + (float) $amount;
        }

        // Sum nomina_informativo across all branches + unassigned for GLOBAL breakdown (never
        // affects nomina_total — pure display, see accumulateNomina()/accumulateImssPatronal()).
        $global['nomina_informativo'] = [];
        foreach ($branches as $branch) {
            foreach ($branch['nomina_informativo'] ?? [] as $label => $amount) {
                $global['nomina_informativo'][$label] = ($global['nomina_informativo'][$label] ?? 0.0) + (float) $amount;
            }
        }
        foreach ($unassigned['nomina_informativo'] ?? [] as $label => $amount) {
            $global['nomina_informativo'][$label] = ($global['nomina_informativo'][$label] ?? 0.0) + (float) $amount;
        }

        // Sum otros_detalle across all branches for GLOBAL breakdown
        $global['otros_detalle'] = [];
        foreach ($branches as $branch) {
            foreach ($branch['otros_detalle'] ?? [] as $concept => $amount) {
                $global['otros_detalle'][$concept] = ($global['otros_detalle'][$concept] ?? 0.0) + (float) $amount;
            }
        }

        // Sum gastos_lendus_pago_generico_excluido across all branches (auditoría de robustez futura)
        $global['gastos_lendus_pago_generico_excluido'] = [];
        foreach ($branches as $branch) {
            foreach ($branch['gastos_lendus_pago_generico_excluido'] ?? [] as $concept => $amount) {
                $global['gastos_lendus_pago_generico_excluido'][$concept] = ($global['gastos_lendus_pago_generico_excluido'][$concept] ?? 0.0) + (float) $amount;
            }
        }

        return $global;
    }

    /**
     * Unified payroll data for ALL outputs: UI, Excel GLOBAL, hojas sucursal, NÓMINA, PDF.
     *
     * Returns a flat list of rows: [sucursal, concepto, monto, fuente, empleados].
     * "GLOBAL" row is the sum of branches + unassigned bucket.
     * All concepts (including D-codes shown in the report) are returned as positive amounts.
     */
    public function buildPayrollByBranchForRadiography(Period $period, array $dataIds): array
    {
        ['branches' => $branches, 'unassigned' => $unassigned] = $this->buildBranches($period, $dataIds);
        $global = $this->sumGlobal($branches, $unassigned);

        $rows    = [];
        $sources = [
            'nomina_total'     => ['label' => 'Nómina',           'fuente' => 'NOI P001 + D111 − todas las deducciones NOI'],
            'comisiones'       => ['label' => 'Comisiones',        'fuente' => 'NOI P002'],
            'bonos'            => ['label' => 'Bonos',             'fuente' => 'NOI P108/109/120/123'],
            'vacaciones'       => ['label' => 'Vacaciones',        'fuente' => 'NOI P009'],
            'prima_vacacional' => ['label' => 'Prima vacacional',  'fuente' => 'NOI P010'],
        ];

        $allBranches = array_merge($branches, ['GLOBAL' => $global, 'SIN ASIGNAR' => $unassigned]);

        foreach ($allBranches as $suc => $calc) {
            foreach ($sources as $key => ['label' => $label, 'fuente' => $fuente]) {
                $monto = (float) ($calc[$key] ?? 0);
                if ($monto == 0.0) continue;
                $rows[] = ['sucursal' => $suc, 'concepto' => $label, 'monto' => $monto, 'fuente' => $fuente, 'empleados' => 0];
            }
            foreach ($calc['nomina_detalle'] ?? [] as $label => $monto) {
                if ((float) $monto == 0.0) continue;
                $rows[] = ['sucursal' => $suc, 'concepto' => "{$label} (ya restado del total)", 'monto' => (float) $monto, 'fuente' => 'NOI D — deducción', 'empleados' => 0];
            }
            foreach ($calc['nomina_informativo'] ?? [] as $label => $monto) {
                if ((float) $monto == 0.0) continue;
                $rows[] = ['sucursal' => $suc, 'concepto' => "{$label} (informativo, no suma)", 'monto' => (float) $monto, 'fuente' => 'ERP/Lendus/IMSS — no afecta KPI', 'empleados' => 0];
            }
        }

        return $rows;
    }

    // ── Cartera ──────────────────────────────────────────────────────────────

    private function accumulateCartera(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        $rows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('branch_id, SUM(balance) as cartera, COUNT(*) as contratos')
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['valor_cartera'] += (float) $row->cartera;
            $summaries[$suc]['contratos']     += (int)   $row->contratos;
        }
    }

    // ── Colocación ───────────────────────────────────────────────────────────

    private function accumulateColocacion(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        // Otorgamientos = SUM(Monto desembolsado) donde credit_origin IN ('DESEMBOLSO','REFINANCIAMIENTO').
        // amount siempre = Monto desembolsado (col 53). Excluir REESTRUCTURACIÓN y UNIFICACIÓN por credit_origin.
        // El Seguro CRECE/COMADRES NO se resta del KPI de colocación — se calcula aparte, solo informativo.
        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->selectRaw("branch_id, SUM(amount) as colocacion, COUNT(*) as creditos,
                SUM(CASE
                    WHEN UPPER(COALESCE(product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(product_name,'')) LIKE '%COMADRES%'
                    THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.seguro')) AS DECIMAL(14,2)), 0)
                    ELSE 0
                END) as seguro_informativo")
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['colocacion']         += (float) $row->colocacion;
            $summaries[$suc]['creditos_colocados'] += (int)   $row->creditos;
            $summaries[$suc]['seguro_crece_comadres_informativo'] += (float) $row->seguro_informativo;
        }
    }

    // ── Recuperación: suma TOTAL del archivo, excluye seguros y condonaciones ─────
    // Regla validada: Recuperación = SUM(total_amount) − Seguros/Coberturas − Condonaciones.
    // Seguros (is_savehearts=1): excluidos COMPLETO de la Recuperación (ni siquiera el 30% CRECE
    // reconocido se suma aquí — ese 30% se reporta aparte como informativo/puente).
    // Condonaciones: columna `transaction` = 'CONDONACION' es la fuente autoritativa (cubre
    // Unificación de Cartera, Reestructura, Acuerdo con Cliente, Castigo, etc. — todo lo
    // marcado como condonación en el archivo). No se resta Unificación por separado para no
    // duplicar: si ya viene con transaction=CONDONACION, esa única exclusión la cubre.
    // Coincide exacto con la referencia validada manualmente: $18,324,971.76.

    /**
     * ÚNICA fuente de verdad para las reglas de inclusión/exclusión de Recuperación.
     * Usada por accumulateRecuperacion() (global/sucursal) y por
     * RadiographySnapshotBuilder::buildRecoveryByProduct() (por producto) — ambas
     * consultas DEBEN compartir exactamente estas mismas condiciones SQL para que
     * global == sucursales == productos siempre, sin excepción ni parche por periodo.
     *
     * Reglas:
     *  - Recuperación = únicamente transaction IN ('PAGO','DESCUENTO'). Todo lo demás
     *    (CONDONACION, etc.) queda fuera por definición de transacción, no por texto de
     *    concepto/operación.
     *  - Seguros no-CRECE (is_savehearts=1 con savehearts_crece_share=0: Savehearts,
     *    Cobertura Crédito Grupal/Comadres) se excluyen 100% — ni como componente ni
     *    como recovery.
     *  - Seguro CRECE (is_savehearts=1 con savehearts_crece_share>0) aporta ÚNICAMENTE
     *    el 30% reconocido (savehearts_crece_share) al total de recovery; sus columnas
     *    de componente (capital/interest/tax/etc.) se excluyen igual que cualquier
     *    seguro, porque una prima de seguro no es capital/interés/impuesto de crédito.
     *
     * @return array{components: string, recovery: string}
     */
    public static function recoveryExclusionSql(): array
    {
        // `transaction` va entre backticks: es palabra reservada en SQLite (usada también
        // en tests con RefreshDatabase) aunque no lo sea en MySQL — SQLite acepta backticks
        // como identificador por compatibilidad, así que esto funciona en ambos motores.
        return [
            'components' => "WHEN `transaction` NOT IN ('PAGO', 'DESCUENTO') THEN 0
                WHEN is_savehearts = 1 THEN 0",
            'recovery'   => "WHEN `transaction` NOT IN ('PAGO', 'DESCUENTO') THEN 0
                WHEN is_savehearts = 1 THEN COALESCE(savehearts_crece_share, 0)",
        ];
    }

    /**
     * Público (en vez de privado) para permitir pruebas unitarias dirigidas de las
     * reglas de Recuperación sin pasar por buildBranches() completo, que también
     * ejecuta accumulateColocacion() y otras consultas con funciones MySQL-only
     * (JSON_EXTRACT, LEFT) no portables a SQLite (usado por la suite de pruebas).
     */
    public function accumulateRecuperacion(array $dataIds, array $branchIds, array $operativeMap, array &$summaries, array $corporativoIds = []): void
    {
        // Recuperación = únicamente Transacción PAGO/DESCUENTO. Todo lo demás (CONDONACION,
        // UNIFICACION DE CARTERA, etc.) queda fuera por definición de transacción, no por
        // texto de concepto/operación. Seguros no-CRECE (is_savehearts=1, crece_share=0)
        // se excluyen por completo; Seguro CRECE solo aporta el 30% reconocido
        // (savehearts_crece_share), nunca el 100% del bruto.
        ['components' => $exclComponents, 'recovery' => $exclRecovery] = self::recoveryExclusionSql();

        $recoverySql = "SUM(CASE {$exclRecovery} ELSE total_amount END) as recovery,
        SUM(total_amount) as bruto,
        SUM(CASE WHEN is_savehearts = 1 AND savehearts_crece_share = 0 THEN total_amount ELSE 0 END) as seguro_excluido,
        SUM(CASE WHEN is_savehearts = 1 AND savehearts_crece_share = 0
            AND (UPPER(COALESCE(concept,'')) LIKE '%COMADRES%'
              OR UPPER(COALESCE(concept,'')) LIKE '%GRUPAL%'
              OR UPPER(COALESCE(operation,'')) LIKE '%COMADRES%'
              OR UPPER(COALESCE(operation,'')) LIKE '%GRUPAL%')
            THEN total_amount ELSE 0 END) as seguro_comadres,
        SUM(CASE WHEN is_savehearts = 1 AND savehearts_crece_share = 0
            AND NOT (UPPER(COALESCE(concept,'')) LIKE '%COMADRES%'
              OR UPPER(COALESCE(concept,'')) LIKE '%GRUPAL%'
              OR UPPER(COALESCE(operation,'')) LIKE '%COMADRES%'
              OR UPPER(COALESCE(operation,'')) LIKE '%GRUPAL%')
            THEN total_amount ELSE 0 END) as seguro_savehearts,
        SUM(CASE WHEN is_savehearts = 1 AND savehearts_crece_share > 0 THEN total_amount ELSE 0 END) as crece_bruto,
        -- crece_reconocido debe reflejar EXACTAMENTE lo que entra a `recovery` arriba
        -- (mismo filtro de transaction) para que nunca se pueda restar de más/menos en
        -- el residual: una fila de CRECE condonada (transaction fuera de PAGO/DESCUENTO)
        -- no debe aportar su 30% aquí tampoco, aunque tenga savehearts_crece_share > 0.
        SUM(CASE WHEN `transaction` IN ('PAGO', 'DESCUENTO') THEN COALESCE(savehearts_crece_share, 0) ELSE 0 END) as crece_reconocido,
        SUM(CASE {$exclComponents} ELSE capital END) as capital,
        SUM(CASE {$exclComponents} ELSE interest END) as interest,
        SUM(CASE {$exclComponents} ELSE tax END) as tax,
        SUM(CASE {$exclComponents} ELSE charges_due END) as charges,
        SUM(CASE {$exclComponents} ELSE charges END) as cargos_iniciales,
        SUM(CASE {$exclComponents} ELSE excedente END) as excedente_monto,
        -- unificacion_excluida es INFORMATIVO: subconjunto de condonacion_excluida (operation
        -- 'UNIFICACION DE CARTERA' dentro de transaction='CONDONACION'). NO se resta aparte —
        -- ya está cubierta por la exclusión de transaction='CONDONACION' arriba (evita doble resta).
        SUM(CASE WHEN `transaction` = 'CONDONACION' AND UPPER(COALESCE(operation,'')) LIKE '%UNIFICACION%' THEN total_amount ELSE 0 END) as unificacion_excluida,
        SUM(CASE WHEN `transaction` = 'CONDONACION' THEN total_amount ELSE 0 END) as condonacion_excluida";

        // Pass 1: branches already mapped to an operative sucursal via branch_id
        $rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw("branch_id, {$recoverySql}")
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['recuperacion_total']      += (float) $row->recovery;
            $summaries[$suc]['recuperacion_bruta']      += (float) $row->bruto;
            $summaries[$suc]['seguro_excluido_bruto']   += (float) $row->seguro_excluido;
            $summaries[$suc]['seguro_savehearts_bruto'] += (float) $row->seguro_savehearts;
            $summaries[$suc]['seguro_comadres_bruto']   += (float) $row->seguro_comadres;
            $summaries[$suc]['seguro_crece_bruto']      += (float) $row->crece_bruto;
            $summaries[$suc]['seguro_crece_reconocido'] += (float) $row->crece_reconocido;
            $summaries[$suc]['capital_recuperado']      += (float) $row->capital;
            $summaries[$suc]['interes_recuperado']      += (float) $row->interest;
            $summaries[$suc]['impuesto_recuperado']     += (float) $row->tax;
            $summaries[$suc]['charges']                 += (float) $row->charges;
            $summaries[$suc]['cargos_adicionales']      += (float) ($row->cargos_iniciales ?? 0);
            $summaries[$suc]['excedente_recuperado']    += (float) ($row->excedente_monto   ?? 0);
            $summaries[$suc]['unificacion_excluida']    += (float) ($row->unificacion_excluida ?? 0);
            $summaries[$suc]['condonacion_excluida']    += (float) ($row->condonacion_excluida ?? 0);
        }

        // Fallback rows (branch_id not in the operative map) are resolved via contract/
        // accredited_name prefix in Pass 2/4/6 below. When there are none for this
        // period, skip building those queries entirely — cheap existence check first,
        // avoids running the heavier LEFT()/JSON_EXTRACT() fallback SQL for nothing.
        $hasFallbackRows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->exists();

        // Pass 2: route branches not in operativeMap (e.g. CUITLAHUAC, ATOTONILCO…)
        // Resolve to operative sucursal via contract prefix.
        // CORPORATIVO branches are explicitly excluded.
        $fallback = $hasFallbackRows ? DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->selectRaw("branch_id, contract, {$recoverySql}")
            ->groupBy('branch_id', 'contract')
            ->get() : collect();

        foreach ($fallback as $row) {
            $suc = $this->resolver->resolveBranchNameFromCode((string) $row->contract);
            if (!$suc || !$this->resolver->isSheetBranch($suc) || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['recuperacion_total']      += (float) $row->recovery;
            $summaries[$suc]['recuperacion_bruta']      += (float) $row->bruto;
            $summaries[$suc]['seguro_excluido_bruto']   += (float) $row->seguro_excluido;
            $summaries[$suc]['seguro_savehearts_bruto'] += (float) $row->seguro_savehearts;
            $summaries[$suc]['seguro_comadres_bruto']   += (float) $row->seguro_comadres;
            $summaries[$suc]['seguro_crece_bruto']      += (float) $row->crece_bruto;
            $summaries[$suc]['seguro_crece_reconocido'] += (float) $row->crece_reconocido;
            $summaries[$suc]['capital_recuperado']      += (float) $row->capital;
            $summaries[$suc]['interes_recuperado']      += (float) $row->interest;
            $summaries[$suc]['impuesto_recuperado']     += (float) $row->tax;
            $summaries[$suc]['charges']                 += (float) $row->charges;
            $summaries[$suc]['cargos_adicionales']      += (float) ($row->cargos_iniciales ?? 0);
            $summaries[$suc]['excedente_recuperado']    += (float) ($row->excedente_monto   ?? 0);
            $summaries[$suc]['unificacion_excluida']    += (float) ($row->unificacion_excluida ?? 0);
            $summaries[$suc]['condonacion_excluida']    += (float) ($row->condonacion_excluida ?? 0);
        }

        // Pass 3: COMISIÓN POR APERTURA (mapped branches)
        $comAp = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('operation', 'COMISIÓN POR APERTURA')
            ->selectRaw('branch_id, SUM(total_amount) as comision')
            ->groupBy('branch_id')
            ->get();

        foreach ($comAp as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) continue;
            $summaries[$suc]['comision_apertura'] += (float) $row->comision;
        }

        // Pass 4: COMISIÓN POR APERTURA (fallback route branches)
        $comApFb = $hasFallbackRows ? DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->where('operation', 'COMISIÓN POR APERTURA')
            ->selectRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3) AS prefix3, SUM(total_amount) AS comision")
            ->groupByRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3)")
            ->get() : collect();

        foreach ($comApFb as $row) {
            $prefix3 = strtoupper(trim((string) $row->prefix3));
            $suc     = $this->resolver->resolveBranchNameFromCode($prefix3);
            if (!$suc || !$this->resolver->isSheetBranch($suc) || !isset($summaries[$suc])) continue;
            $summaries[$suc]['comision_apertura'] += (float) $row->comision;
        }

        // Pass 5: ACUERDO CON CLIENTE — charges_due maps to cargos_inicio (mapped branches)
        // Only include rows also counted in recuperacion_total (same exclusion filter as Pass 1+2).
        $acuerdos = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('operation', 'ACUERDO CON CLIENTE')
            ->where('charges_due', '>', 0)
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->where('is_savehearts', '!=', 1)
            ->selectRaw('branch_id, SUM(charges_due) as cargos')
            ->groupBy('branch_id')
            ->get();

        foreach ($acuerdos as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) continue;
            $summaries[$suc]['cargos_inicio'] += (float) $row->cargos;
        }

        // Pass 6: ACUERDO CON CLIENTE (fallback route branches) — same exclusion filter
        $acuerdosFb = $hasFallbackRows ? DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->where('operation', 'ACUERDO CON CLIENTE')
            ->where('charges_due', '>', 0)
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->where('is_savehearts', '!=', 1)
            ->selectRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3) AS prefix3, SUM(charges_due) AS cargos")
            ->groupByRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3)")
            ->get() : collect();

        foreach ($acuerdosFb as $row) {
            $prefix3 = strtoupper(trim((string) $row->prefix3));
            $suc     = $this->resolver->resolveBranchNameFromCode($prefix3);
            if (!$suc || !$this->resolver->isSheetBranch($suc) || !isset($summaries[$suc])) continue;
            $summaries[$suc]['cargos_inicio'] += (float) $row->cargos;
        }

        // Pass 7: "Otros" desglosado por concepto real — mismas filas que componen el
        // residual (ningún componente nombrado las explica), agrupadas por su concepto
        // real de origen en vez de una bolsa genérica "Otros cobros".
        $otrosCond = "`transaction` IN ('PAGO', 'DESCUENTO')
            AND is_savehearts != 1
            AND operation != 'COMISIÓN POR APERTURA'
            AND COALESCE(capital,0) = 0 AND COALESCE(interest,0) = 0 AND COALESCE(tax,0) = 0
            AND COALESCE(charges_due,0) = 0 AND COALESCE(charges,0) = 0 AND COALESCE(excedente,0) = 0
            AND total_amount != 0";

        $otros = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->whereRaw($otrosCond)
            ->selectRaw('branch_id, concept, operation, SUM(total_amount) as monto')
            ->groupBy('branch_id', 'concept', 'operation')
            ->get();

        $otrosFb = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->whereRaw($otrosCond)
            ->selectRaw("branch_id, contract, concept, operation, SUM(total_amount) as monto")
            ->groupBy('branch_id', 'contract', 'concept', 'operation')
            ->get();

        $applyOtros = function ($row, ?string $suc) use (&$summaries): void {
            if (!$suc || !isset($summaries[$suc])) return;
            $concept = trim((string) ($row->concept ?? ''));
            $label   = $concept !== '' ? $concept : (trim((string) ($row->operation ?? '')) ?: 'Otros conceptos');
            $summaries[$suc]['otros_detalle'][$label] = ($summaries[$suc]['otros_detalle'][$label] ?? 0.0) + (float) $row->monto;
        };

        foreach ($otros as $row) {
            $applyOtros($row, $operativeMap[(int) $row->branch_id] ?? null);
        }
        foreach ($otrosFb as $row) {
            $suc = $this->resolver->resolveBranchNameFromCode((string) $row->contract);
            $applyOtros($row, ($suc && $this->resolver->isSheetBranch($suc)) ? $suc : null);
        }

        // Residual: amounts in recuperacion_total not explained by any named component
        // (e.g. GASTOS DE COBRANZA rows where all 6 component columns are zero).
        // Ensures SUM(all components) == recuperacion_total exactly.
        // IMPORTANTE: seguro_crece_reconocido (30% de CRECE) SÍ forma parte de
        // recuperacion_total (ver $exclRecovery arriba) pero NO es uno de los 6
        // componentes de fact_recoveries (capital/interest/tax/charges/etc. vienen en 0
        // para esas filas de seguro) — debe restarse aquí como componente propio, si no,
        // el residual lo absorbe en silencio y "otros_recuperacion" queda inflado con un
        // monto que en realidad es Seguro CRECE reconocido, no un concepto desconocido.
        foreach ($summaries as $suc => &$sum) {
            $sum['otros_recuperacion'] = round(
                $sum['recuperacion_total']
                - $sum['capital_recuperado']
                - $sum['interes_recuperado']
                - $sum['impuesto_recuperado']
                - $sum['charges']
                - $sum['cargos_adicionales']
                - $sum['excedente_recuperado']
                - $sum['cargos_inicio']
                - $sum['comision_apertura']
                - $sum['seguro_crece_reconocido'],
                2
            );
        }
        unset($sum);
    }

    /**
     * Recuperación por producto — usa la MISMA fuente canónica de reglas que
     * accumulateRecuperacion() vía recoveryExclusionSql() (nunca una copia de texto
     * divergente), agrupada por producto en vez de por sucursal. La suma de todas las
     * filas == recuperacion_total global, siempre, incluyendo el 30% reconocido de
     * Seguro CRECE como columna propia (nunca oculto dentro de un residual "otros").
     */
    public function buildRecoveryByProduct(array $dataIds): array
    {
        $maps           = $this->buildBranchMap();
        $corporativoIds = $maps['corporativo'] ?? [];

        ['components' => $excl, 'recovery' => $exclRecovery] = self::recoveryExclusionSql();

        $rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->selectRaw("
                COALESCE(NULLIF(TRIM(product_name), ''), 'Sin producto') as product,
                SUM(CASE {$exclRecovery} ELSE total_amount END) as recovery,
                SUM(CASE {$excl} ELSE capital END) as capital,
                SUM(CASE {$excl} ELSE interest END) as interest,
                SUM(CASE {$excl} ELSE tax END) as tax,
                SUM(CASE {$excl} ELSE charges_due END) as moratorios,
                SUM(CASE {$excl} ELSE charges END) as cargos_adicionales,
                SUM(CASE {$excl} ELSE excedente END) as excedente,
                SUM(CASE WHEN operation = 'COMISIÓN POR APERTURA' THEN total_amount ELSE 0 END) as comision_apertura,
                SUM(CASE WHEN operation = 'ACUERDO CON CLIENTE' AND charges_due > 0
                    AND `transaction` IN ('PAGO', 'DESCUENTO') AND is_savehearts != 1
                    THEN charges_due ELSE 0 END) as cargos_inicio,
                SUM(CASE WHEN `transaction` IN ('PAGO', 'DESCUENTO') THEN COALESCE(savehearts_crece_share, 0) ELSE 0 END) as seguro_crece_reconocido
            ")
            ->groupBy('product_name')
            ->havingRaw('SUM(CASE ' . $exclRecovery . ' ELSE total_amount END) != 0')
            ->orderByDesc('recovery')
            ->get();

        $result = [];
        $total  = 0.0;
        foreach ($rows as $r) {
            $recovery   = (float) $r->recovery;
            $capital    = (float) $r->capital;
            $interest   = (float) $r->interest;
            $tax        = (float) $r->tax;
            $morat      = (float) $r->moratorios;
            $cargosAdic = (float) $r->cargos_adicionales;
            $excedente  = (float) $r->excedente;
            $comAp      = (float) $r->comision_apertura;
            $cargosIni  = (float) $r->cargos_inicio;
            $crece30    = (float) $r->seguro_crece_reconocido;
            $otros      = round($recovery - $capital - $interest - $tax - $morat - $cargosAdic - $excedente - $comAp - $cargosIni - $crece30, 2);
            $result[] = [
                'product'                => $r->product,
                'capital'                => $capital,
                'interes'                => $interest,
                'impuesto'               => $tax,
                'moratorios'             => $morat,
                'cargos_adicionales'     => $cargosAdic,
                'excedente_recuperado'   => $excedente,
                'comision_apertura'      => $comAp,
                'cargos_inicio'          => $cargosIni,
                'seguro_crece_reconocido'=> $crece30,
                'otros'                  => $otros,
                'total'                  => $recovery,
            ];
            $total += $recovery;
        }

        return ['rows' => $result, 'total' => round($total, 2)];
    }

    // ── Mora por bucket — FUENTE ÚNICA para cartera/mora (UI, Excel, PDF, dashboard) ──
    //
    // Columna de días: days_past_due (= "Días Vencido"). Sin ajustes/deltas de ningún tipo.
    // Monto: SUM de las 5 columnas vencidas del archivo Saldos Por Cliente:
    //   capital_due (Capital atrasado, col DE)
    //   + interes_atrasado (Interés atrasado, col DI)
    //   + impuesto_atrasado (Impuesto atrasado, col EG)
    //   + saldo_interes_moratorio (Saldo interés moratorio, col EE)
    //   + saldo_impuesto_interes_moratorio (Saldo impuesto interés moratorio, col EF)
    // Filtros: days_past_due > 0 (excluye contratos al corriente). Sin otros filtros.
    // Buckets: 1-30 / 31-60 / 61-90 / 91-120 / 120+ (sin contaminar entre sí).

    private function accumulateMora(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        $rows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('days_past_due', '>', 0)
            ->selectRaw('branch_id, days_past_due,
                SUM(COALESCE(capital_due, 0))                          as capital_vencido,
                SUM(COALESCE(interes_atrasado, 0))                     as interes_vencido,
                SUM(COALESCE(impuesto_atrasado, 0))                    as impuesto_vencido,
                SUM(COALESCE(saldo_interes_moratorio, 0))              as moratorio_vencido,
                SUM(COALESCE(saldo_impuesto_interes_moratorio, 0))     as imp_moratorio_vencido,
                SUM(
                    COALESCE(capital_due, 0)
                    + COALESCE(interes_atrasado, 0)
                    + COALESCE(impuesto_atrasado, 0)
                    + COALESCE(saldo_interes_moratorio, 0)
                    + COALESCE(saldo_impuesto_interes_moratorio, 0)
                ) as vencido,
                COUNT(*) as cnt')
            ->groupBy('branch_id', 'days_past_due')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }

            $dpd     = (int) $row->days_past_due;
            $vencido = (float) $row->vencido;
            $capital  = (float) $row->capital_vencido;
            $interes  = (float) $row->interes_vencido;
            $impuesto = (float) $row->impuesto_vencido;
            $morat    = (float) $row->moratorio_vencido;
            $impMorat = (float) $row->imp_moratorio_vencido;

            $bucket = match (true) {
                $dpd <= 30  => 'mora_0_30',
                $dpd <= 60  => 'mora_31_60',
                $dpd <= 90  => 'mora_61_90',
                $dpd <= 120 => 'mora_91_120',
                default     => 'mora_120_plus',
            };

            $summaries[$suc][$bucket]                   += $vencido;
            $summaries[$suc]["{$bucket}_cnt"]            += (int) $row->cnt;
            $summaries[$suc]["{$bucket}_capital"]        += $capital;
            $summaries[$suc]["{$bucket}_interes"]        += $interes;
            $summaries[$suc]["{$bucket}_impuesto"]       += $impuesto;
            $summaries[$suc]["{$bucket}_moratorio"]      += $morat;
            $summaries[$suc]["{$bucket}_imp_moratorio"]  += $impMorat;

            $summaries[$suc]['cartera_vencida']          += $vencido;
            $summaries[$suc]['mora_total_capital']        += $capital;
            $summaries[$suc]['mora_total_interes']        += $interes;
            $summaries[$suc]['mora_total_impuesto']       += $impuesto;
            $summaries[$suc]['mora_total_moratorio']      += $morat;
            $summaries[$suc]['mora_total_imp_moratorio']  += $impMorat;
        }
    }

    /**
     * Returns the Lendus data_source ID to query for the given period set.
     * Always uses gastos_lendus (PDF) — the XLS importer does not assign branch_ids,
     * causing all Excel rows to be excluded by the whereIn(branch_id) filter in
     * accumulateGastos(). PDF rows have proper branch_ids from the sucursal column.
     */
    /**
     * gastos_lendus (PDF) y gastos_lendus_excel (Excel) son el MISMO conjunto de transacciones
     * — verificado para Junio 2026: 848 filas, $4,393,164.00 exactos en ambas fuentes. En teoría
     * el Excel tiene categorías más finas y branch_id resuelto vía GastosExcelBranchResolverService,
     * pero en la práctica ese resolver no corre de forma confiable en cada periodo: verificado
     * Junio 2026 (period 21) con 672 de 848 filas del Excel en branch_id NULL, lo que colapsaba
     * gastos_lendus_total a $0 y dejaba el OPEX mostrado en pantalla como solo-ERP. El PDF
     * (gastos_lendus) siempre trae branch_id resuelto al 100% desde la columna Sucursal del
     * reporte, coincide exacto con reportes:audit-gastos-lendus / audit-fondeos, y es la fuente
     * usada aquí desde 2026-07. canonicalGastoConcept() ya está preparado para sus categorías
     * genéricas truncadas ("Gastos Operativos" con concept PAGO/COMPRA DE/ENGANCHE DE/etc.).
     */
    private function resolveLendusIds(array $dataIds): array
    {
        $pdfId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        return array_values(array_filter([$pdfId]));
    }

    // ── Gastos por categoría (excedentes, fondeo, gastos_op) ────────────────
    //
    // Nómina is handled separately by accumulateNomina() using NOI payroll data.
    //
    // Source logic:
    //   • gastos_lendus_excel (preferred) or gastos_lendus (PDF fallback)
    //   • gastos_erp → all valid-status rows (no LENDUS_PRESENT_CATS filter)

    private function accumulateGastos(
        array $dataIds,
        array $branchIds,
        array $operativeMap,
        array &$summaries,
        array $corporativoIds = [],
        array &$unassigned = [],
    ): void {
        $lendusIds = $this->resolveLendusIds($dataIds);

        $erpSrcId = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        $erpId = $erpSrcId;

        // IDs to query: operative branches + corporativo (corporativo goes to unassigned)
        $queryIds = array_unique(array_merge($branchIds, $corporativoIds));

        $corpIdSet = array_flip($corporativoIds);

        // Process Lendus and ERP separately to track individual totals and apply source-specific rules.
        // Lendus 'Pólizas' (seguros/coberturas passthrough) → seguros_lendus_puente, NOT gastos_operativos.
        // ERP 'Pólizas' (vehicle/office insurance, real expense) → gastos_erp_total + gastos_operativos.
        // ERP categories are NOT filtered by LENDUS_PRESENT_CATS — validated totals include all valid-status rows.

        $lendusRows = [];
        if (!empty($lendusIds)) {
            $lendusRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $queryIds)
                ->whereIn('ru.data_source_id', $lendusIds)
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, COALESCE(e.concept, '') as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category', 'e.concept')
                ->get();
        }

        $erpRows = [];
        if ($erpId) {
            $erpRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $queryIds)
                ->where('ru.data_source_id', $erpId)
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, COALESCE(e.concept, '') as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category', 'e.concept')
                ->get();
        }

        foreach ([['lendus', $lendusRows], ['erp', $erpRows]] as [$srcType, $rows]) {
            foreach ($rows as $row) {
                $branchId = (int) $row->branch_id;
                $cat      = (string) $row->category;
                $catUpper = strtoupper($cat);
                $amt      = (float) $row->total;

                $suc = $operativeMap[$branchId] ?? null;
                if (!$suc || !isset($summaries[$suc])) {
                    // CORPORATIVO and any non-operative/unmapped branch: excluded entirely.
                    // ERP: "Sucursal" filter only — Corporativo and blank are out, no exceptions.
                    // Lendus: has zero CORPORATIVO rows in practice, so this is a no-op for it.
                    continue;
                }

                if ($srcType === 'erp') {
                    // ERP: regla vigente (2026-07) — se suma COMPLETO a OPEX, sin reclasificar
                    // Gasolina/Financiamiento Celular a Nómina (esa reclasificación quedó
                    // obsoleta: ERP va íntegro a OPEX, solo excluyendo Corporativo arriba).
                    // Única excepción: Pólizas (seguros/coberturas — SEGUROS AUTOMOVIL, etc.)
                    // se excluye de OPEX, igual que en Lendus — pólizas/seguros deben quedar
                    // en $0.00 dentro de OPEX.
                    $summaries[$suc]['gastos_erp_cargado'] += $amt;
                    if (in_array($cat, self::SEGUROS_LENDUS_CATS, true)) {
                        $summaries[$suc]['gastos_erp_excluido_seguros'] += $amt;
                        continue;
                    }
                    $summaries[$suc]['gastos_operativos'] += $amt;
                    $summaries[$suc]['gastos_erp_total']  += $amt;
                    $concept   = (string) ($row->concept ?? '');
                    $canonical = $this->canonicalGastoConcept($cat, $concept) ?? $this->unmappedGastoLabel($cat, $concept);
                    $summaries[$suc]['gastos_detalle'][$canonical] = ($summaries[$suc]['gastos_detalle'][$canonical] ?? 0.0) + $amt;
                    continue;
                }

                // Lendus: track total cargado (before all exclusions)
                $summaries[$suc]['gastos_lendus_cargado'] += $amt;

                $conceptUp = strtoupper(trim((string) ($row->concept ?? '')));
                $conceptUp = preg_replace('/\s+/u', ' ', $conceptUp) ?? $conceptUp;

                // The PDF (gastos_lendus) records some concepts with only a generic label under
                // "Gastos Operativos":
                //
                // "PAGO" = PAGO FINANCIAMIENTO MOTO, "COMPRA DE" = COMPRA DE CASCOS, "ENGANCHE DE"
                // = ENGANCHE DE MOTOCICLETA, "ANTICIPO DE" = ANTICIPO DE NOMINA. Regla vigente
                // (2026-07): ninguno de los 4 forma parte de OPEX. PAGO/COMPRA DE/ENGANCHE DE SÍ
                // se suman al KPI Nómina y Capital Humano (gasto real de empleados — ver
                // accumulateNomina()); ANTICIPO DE NOMINA no se suma a ningún KPI (es un anticipo
                // al trabajador, no un gasto de la empresa ni nómina real).
                if ($cat === 'Gastos Operativos' && in_array($conceptUp, ['PAGO', 'COMPRA DE', 'ENGANCHE DE', 'ANTICIPO DE'], true)) {
                    $summaries[$suc]['gastos_lendus_excluido_nomina'] += $amt;
                    $summaries[$suc]['gastos_lendus_pago_generico_excluido'][$conceptUp] = ($summaries[$suc]['gastos_lendus_pago_generico_excluido'][$conceptUp] ?? 0.0) + $amt;
                    continue;
                }

                $isExcedente = $cat === self::EXCEDENTES_CAT || str_contains($catUpper, 'EXCEDENTE');
                $isFondeo    = $cat === self::FONDEO_CAT || str_contains($catUpper, 'FONDEO') || str_contains($catUpper, 'INTERSUCURSAL');
                // Regla vigente (2026-07): PAGO FINIQUITO y GASTOS MEDICOS (PDF, categoría Nómina
                // y Capital Humano) NO forman parte de OPEX — se mueven al KPI Nómina y Capital
                // Humano (ver accumulateNomina(), que los suma a gastos_empleados_nomina).
                // NOMINA/PAGO DE IMSS/DEDUCCIONES/PAGO PRESTAMO Z siguen sin sumar a ningún KPI:
                // ya están cubiertos por NOI y por el archivo IMSS oficial — sumarlos aquí también
                // duplicaría el gasto.
                if ($cat === self::NOMINA_CAT) {
                    $isNomina = in_array($conceptUp, self::LENDUS_NOMINA_SKIP_CONCEPTS, true)
                        || str_contains($conceptUp, 'FINIQUITO')
                        || str_contains($conceptUp, 'MEDICO')
                        || str_contains($conceptUp, 'MÉDICO');
                } else {
                    $isNomina = str_contains($catUpper, 'NOMINA') || str_contains($catUpper, 'NÓMINA');
                }
                $isSegurosPuente = in_array($cat, self::SEGUROS_LENDUS_CATS, true);

                if ($isExcedente) {
                    $summaries[$suc]['excedentes']                       += $amt;
                    $summaries[$suc]['gastos_lendus_excluido_excedentes'] += $amt;
                } elseif ($isFondeo) {
                    $summaries[$suc]['prestamos_fondea']                 += $amt;
                    $summaries[$suc]['gastos_lendus_excluido_fondeo']    += $amt;
                } elseif ($isNomina) {
                    $summaries[$suc]['gastos_lendus_excluido_nomina']    += $amt;
                    // skip — accumulateNomina() handles these (NOI, IMSS o Motos/Cascos vía Excel)
                } elseif ($isSegurosPuente) {
                    $summaries[$suc]['seguros_lendus_puente']            += $amt;
                    $summaries[$suc]['gastos_lendus_excluido_polizas']   += $amt;
                } else {
                    $summaries[$suc]['gastos_operativos']  += $amt;
                    $summaries[$suc]['gastos_lendus_total'] += $amt;
                    $concept   = (string) ($row->concept ?? '');
                    $canonical = $this->canonicalGastoConcept($cat, $concept) ?? $this->unmappedGastoLabel($cat, $concept);
                    $summaries[$suc]['gastos_detalle'][$canonical] = ($summaries[$suc]['gastos_detalle'][$canonical] ?? 0.0) + $amt;
                }
            }
        }
    }

    // ── Nómina / Comisiones / Bonos (via employee_branch_assignments) ───────
    //
    // Per-branch allocation uses employee_branch_assignments directly.
    // Employees WITHOUT a branch assignment → unassigned bucket.
    // Employees assigned to a non-operative branch → unassigned bucket.
    // GLOBAL = SUM(branches) + unassigned (all employees always counted).
    //
    // Sources: NOMINANUEVA (noi_nomina) + NOMINAF (noi_nomina_fiscal).
    // P001 SUELDO → nomina_total | P002 → comisiones | P108/109/120/123 → bonos.

    private function accumulateNomina(array $dataIds, array $operativeMap, array &$summaries, array &$unassigned, array $corporativoIds = []): void
    {
        $operativeIds = array_keys($operativeMap);

        // ── Percepciones NOI: P001 Sueldo, P002+P119 Comisiones, P009+P027+P113 Vacaciones,
        //    P010 Prima vacacional, P108/109/110/112/114/115/120/123/124 Bonos, P118 Bonos
        //    Aceleradores. Regla vigente (2026-07): TODAS las percepciones cuentan — un código no
        //    listado (ej. P107) cae en 'otros_percepciones' en vez de perderse silenciosamente,
        //    para que el total siempre sea igual a SUM(percepciones) sin excepción.
        $rows = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($join) use ($dataIds) {
                $join->on('n.employee_id', '=', 'eba.employee_id')
                    ->whereIn('eba.period_id', $dataIds);
            })
            ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'percepcion')
            ->selectRaw("
                COALESCE(eba.branch_id, -1) AS assigned_branch_id,
                n.concept,
                SUM(n.amount) AS total,
                COUNT(DISTINCT n.employee_id) AS emps
            ")
            ->groupByRaw("COALESCE(eba.branch_id, -1), n.concept")
            ->get();

        foreach ($rows as $row) {
            $branchId = (int) $row->assigned_branch_id;
            $concept  = (string) $row->concept;
            $amount   = (float) $row->total;

            $bucket = match (true) {
                str_starts_with($concept, 'P001')                                  => 'nomina_total',
                (bool) preg_match('/^P(002|119)/', $concept)                       => 'comisiones',
                (bool) preg_match('/^P(009|027|113)/', $concept)                   => 'vacaciones',
                str_starts_with($concept, 'P010')                                  => 'prima_vacacional',
                str_starts_with($concept, 'P118')                                  => 'bonos_aceleradores',
                (bool) preg_match('/^P(108|109|110|112|114|115|120|123|124)/', $concept) => 'bonos',
                default                                                             => 'otros_percepciones',
            };

            if ($branchId === -1) {
                $unassigned[$bucket] += $amount;
                continue;
            }

            $suc = $operativeMap[$branchId] ?? null;
            if ($suc && isset($summaries[$suc])) {
                $summaries[$suc][$bucket] += $amount;
            } else {
                $unassigned[$bucket] += $amount;
            }
        }

        // ── Deducciones NOI: regla final (2026-07) — YA NO reducen nomina_total ni ningún otro
        //    KPI. Son puramente informativas para que el usuario las valide manualmente (ver
        //    nomina_detalle abajo). "Nómina y Capital Humano" = percepciones brutas + IMSS +
        //    gastos reales de empleados, SIN restar deducciones. Cada deducción se muestra en
        //    nomina_detalle (positivo, informativo) para transparencia — nunca se resta ni se
        //    suma como gasto en ningún consumidor downstream.
        $dedRows = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($join) use ($dataIds) {
                $join->on('n.employee_id', '=', 'eba.employee_id')
                    ->whereIn('eba.period_id', $dataIds);
            })
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'deduccion')
            ->selectRaw("COALESCE(eba.branch_id, -1) AS assigned_branch_id, n.concept, SUM(n.amount) AS total")
            ->groupByRaw("COALESCE(eba.branch_id, -1), n.concept")
            ->get();

        foreach ($dedRows as $row) {
            $branchId = (int) $row->assigned_branch_id;
            $concept  = (string) $row->concept;
            $amount   = (float) $row->total;

            $label = match (true) {
                (bool) preg_match('/^D(092|094|105|129)/', $concept) => 'Descuentos Infonavit',
                str_starts_with($concept, 'D093')                    => 'Descuentos FONACOT',
                str_starts_with($concept, 'D113')                    => 'Descuento Servicios Moto',
                str_starts_with($concept, 'D125')                    => 'Financiamiento Celular',
                str_starts_with($concept, 'D112')                    => 'Descuento de uniformes',
                str_starts_with($concept, 'D119')                    => 'Descuento gastos sin comprobar',
                str_starts_with($concept, 'D117')                    => 'Descuento extravío tarjeta de circulación',
                (bool) preg_match('/^D(122|126|127)/', $concept)     => 'Descuentos Tienda Mr Lana',
                str_starts_with($concept, 'D114')                    => 'Descuento Servicios Automóvil',
                str_starts_with($concept, 'D118')                    => 'Descuento faltante en caja',
                str_starts_with($concept, 'D137')                    => 'Diferencia NF',
                (bool) preg_match('/^D(002|009)/', $concept)         => 'IMSS trabajador (retención)',
                str_starts_with($concept, 'D003')                    => 'Anticipo de nómina',
                str_starts_with($concept, 'D004')                    => 'Préstamo Personal',
                str_starts_with($concept, 'D010')                    => 'Pensión Alimenticia',
                (bool) preg_match('/^D(123|128)/', $concept)         => 'Descuento nómina — Financiamiento Moto (NOI)',
                str_starts_with($concept, 'D111')                    => 'Subsidio para el Empleo APL',
                default                                              => "Deducción NOI {$concept}",
            };

            // Route per-branch (or unassigned) — displayed en tabla, YA restado de nomina_total arriba.
            $suc = ($branchId !== -1) ? ($operativeMap[$branchId] ?? null) : null;
            if ($suc && isset($summaries[$suc])) {
                $summaries[$suc]['nomina_detalle'][$label] = ($summaries[$suc]['nomina_detalle'][$label] ?? 0.0) + $amount;
            } else {
                $unassigned['nomina_detalle'][$label] = ($unassigned['nomina_detalle'][$label] ?? 0.0) + $amount;
            }
        }

        // ── Gasto real de empleados vía Lendus (regla vigente 2026-07): Financiamiento de Motos,
        //    Enganche de Motocicleta, Cascos, Finiquito y Gastos médicos SÍ se suman al KPI Nómina
        //    y Capital Humano (gastos_empleados_nomina) — ya NO forman parte de OPEX (ver exclusión
        //    en accumulateGastos()). Se muestran también en nomina_informativo para el desglose
        //    visible en UI/Excel/PDF (ese desglose ya está incluido en el total, no es informativo
        //    "sin sumar" — el nombre del array se conserva por compatibilidad con los consumidores).
        if (!empty($operativeIds)) {
            $lendusIds  = $this->resolveLendusIds($dataIds);

            // PAGO FINIQUITO / GASTOS MEDICOS (gastos_lendus, PDF): $64,987.59 y $4,140.00 en
            // Junio 2026, 100% en sucursales operativas. Se suman a gastos_empleados_nomina.
            if (!empty($lendusIds)) {
                $finiquitoMedicoRows = DB::table('fact_expenses as e')
                    ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                    ->whereIn('e.period_id', $dataIds)
                    ->whereIn('ru.data_source_id', $lendusIds)
                    ->where('e.category', self::NOMINA_CAT)
                    ->whereIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), ['PAGO FINIQUITO', 'GASTOS MEDICOS'])
                    ->selectRaw("UPPER(TRIM(COALESCE(e.concept,''))) as concept, e.branch_id, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                    ->groupBy('e.branch_id', DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"))
                    ->get();

                foreach ($finiquitoMedicoRows as $row) {
                    $label  = str_contains((string) $row->concept, 'FINIQUITO') ? 'Finiquito' : 'Gastos médicos';
                    $amount = (float) $row->total;

                    $suc = $row->branch_id ? ($operativeMap[(int) $row->branch_id] ?? null) : null;
                    if ($suc && isset($summaries[$suc])) {
                        $summaries[$suc]['nomina_informativo'][$label] = ($summaries[$suc]['nomina_informativo'][$label] ?? 0.0) + $amount;
                        $summaries[$suc]['gastos_empleados_nomina'] += $amount;
                    } else {
                        $unassigned['nomina_informativo'][$label] = ($unassigned['nomina_informativo'][$label] ?? 0.0) + $amount;
                        $unassigned['gastos_empleados_nomina'] += $amount;
                    }
                }
            }

            // PAGO FINANCIAMIENTO MOTO / COMPRA DE CASCOS / ENGANCHE DE MOTOCICLETA (gastos_lendus,
            // PDF, categoría "Gastos Operativos" con concept truncado — ver misma lectura en
            // accumulateGastos()). $148,227.15 + $8,974.00 + $12,000.00 en Junio 2026. Se suman a
            // gastos_empleados_nomina.
            if (!empty($lendusIds)) {
                $motoCascoRows = DB::table('fact_expenses as e')
                    ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                    ->whereIn('e.period_id', $dataIds)
                    ->whereIn('ru.data_source_id', $lendusIds)
                    ->where('e.category', 'Gastos Operativos')
                    ->whereIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), ['PAGO', 'COMPRA DE', 'ENGANCHE DE'])
                    ->selectRaw("UPPER(TRIM(COALESCE(e.concept,''))) as concept, e.branch_id, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                    ->groupBy('e.branch_id', DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"))
                    ->get();

                foreach ($motoCascoRows as $row) {
                    $label = match ((string) $row->concept) {
                        'COMPRA DE'   => 'Cascos',
                        'ENGANCHE DE' => 'Enganche de Motocicleta',
                        default       => 'Financiamiento de Motos', // 'PAGO'
                    };
                    $amount = (float) $row->total;

                    $suc = $row->branch_id ? ($operativeMap[(int) $row->branch_id] ?? null) : null;
                    if ($suc && isset($summaries[$suc])) {
                        $summaries[$suc]['nomina_informativo'][$label] = ($summaries[$suc]['nomina_informativo'][$label] ?? 0.0) + $amount;
                        $summaries[$suc]['gastos_empleados_nomina'] += $amount;
                    } else {
                        $unassigned['nomina_informativo'][$label] = ($unassigned['nomina_informativo'][$label] ?? 0.0) + $amount;
                        $unassigned['gastos_empleados_nomina'] += $amount;
                    }
                }
            }
        }

        // Collect per-employee detail for the SIN ASIGNAR sheet
        $unassignedEmps = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($join) use ($dataIds) {
                $join->on('n.employee_id', '=', 'eba.employee_id')
                    ->whereIn('eba.period_id', $dataIds);
            })
            ->leftJoin('employees as emp', 'n.employee_id', '=', 'emp.id')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'percepcion')
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->where(function ($q) {
                $q->whereNull('eba.employee_id')->orWhereNull('eba.branch_id');
            })
            ->selectRaw("
                COALESCE(emp.full_name, CONCAT('Emp#', n.employee_id)) AS nombre,
                n.employee_id,
                ds.code AS fuente,
                SUM(CASE WHEN n.concept LIKE 'P001%' THEN n.amount ELSE 0 END) AS p001,
                SUM(CASE WHEN n.concept LIKE 'P002%' THEN n.amount ELSE 0 END) AS p002,
                SUM(CASE WHEN n.concept REGEXP '^P(108|109|120|123)' THEN n.amount ELSE 0 END) AS bonos
            ")
            ->groupBy('n.employee_id', 'emp.full_name', 'ds.id', 'ds.code')
            ->orderByDesc('p001')
            ->get();

        foreach ($unassignedEmps as $emp) {
            $unassigned['empleados'][] = [
                'nombre'  => $emp->nombre,
                'emp_id'  => $emp->employee_id,
                'fuente'  => $emp->fuente,
                'p001'    => (float) $emp->p001,
                'p002'    => (float) $emp->p002,
                'bonos'   => (float) $emp->bonos,
            ];
        }
    }

    // ── Pólizas CRECE (savehearts_crece_share) ───────────────────────────────

    private function accumulatePolizasCrece(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        if (empty($branchIds)) {
            return;
        }

        $rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('savehearts_crece_share', '>', 0)
            ->selectRaw('branch_id, SUM(savehearts_crece_share) as crece_share')
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['polizas_crece_30'] += (float) $row->crece_share;
        }
    }

    // ── IMSS Patronal ────────────────────────────────────────────────────────
    //
    // Fuente: fact_expenses con data_source.code = 'imss', category = 'IMSS'.
    // Importado desde el archivo "CALCULO DE CUOTA SEMANAL DEL IMSS POR SUCURSAL".
    // Regla vigente (2026-07): el IMSS patronal de las 13 sucursales operativas SÍ se suma
    // al KPI Nómina y Capital Humano (imss_patronal, ver nominaTotalFor()). Se excluyen
    // Corporativo y Tulancingo (no operativas) — ver imss_excluido más abajo. También se
    // guarda en nomina_informativo['IMSS'] para el desglose visible en UI/Excel/PDF. Se
    // audita por separado vía reportes:audit-imss. Distinto del D002/D009 de NOI (deducción
    // del trabajador, excluida por completo).

    private function accumulateImssPatronal(array $dataIds, array $branchIds, array $operativeMap, array &$summaries, array &$unassigned): void
    {
        // Fuente autoritativa: archivo IMSS manual (fact_expenses category=IMSS), MIENTRAS
        // exista. ReportAnalysisService reprocesa ese archivo en cada corrida del pipeline
        // (aunque la fuente ya no sea "requerida" en la UI), así que un periodo cerrado con
        // archivo cargado SIEMPRE reproduce el mismo IMSS validado, nunca el derivado.
        // El derivado (fact_imss, ImssFromNoiFiscalService) solo se usa para periodos NUEVOS
        // sin archivo manual — ver spec 2026-07-21 sección 6: "derivado desde NOI NO significa
        // cambiar el resultado".
        $imssSourceId = DB::table('data_sources')->where('code', 'imss')->value('id');

        $legacyRows = collect();
        if ($imssSourceId) {
            $legacyRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $imssSourceId)
                ->where('e.category', 'IMSS')
                ->selectRaw("e.branch_id, b.name as branch_name, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'b.name')
                ->get();
        }

        if ($legacyRows->isNotEmpty()) {
            foreach ($legacyRows as $row) {
                $branchId   = (int)    $row->branch_id;
                $branchName = (string) ($row->branch_name ?? 'Sin nombre');
                $amount     = (float)  $row->total;

                $suc = $operativeMap[$branchId] ?? null;
                if ($suc && isset($summaries[$suc])) {
                    $summaries[$suc]['nomina_informativo']['IMSS'] = ($summaries[$suc]['nomina_informativo']['IMSS'] ?? 0.0) + $amount;
                    $summaries[$suc]['imss_patronal'] += $amount;
                } else {
                    // Non-operative (CORPORATIVO, TULANCINGO, etc.): excluded from the report.
                    // Tracked in imss_excluido for audit but NOT added to unassigned/global totals.
                    $unassigned['imss_excluido'][$branchName] = ($unassigned['imss_excluido'][$branchName] ?? 0.0) + $amount;
                }
            }
            return;
        }

        // ── Sin archivo legacy: periodo nuevo, usar el derivado de NOI fiscal ──
        $derivedRows = DB::table('fact_imss')
            ->whereIn('period_id', $dataIds)
            ->where('included_in_report', true)
            ->selectRaw('branch_id, branch_name, SUM(amount) as total')
            ->groupBy('branch_id', 'branch_name')
            ->get();

        foreach ($derivedRows as $row) {
            $branchId   = (int)    $row->branch_id;
            $branchName = (string) ($row->branch_name ?? 'Sin nombre');
            $amount     = (float)  $row->total;

            $suc = $operativeMap[$branchId] ?? null;
            if ($suc && isset($summaries[$suc])) {
                $summaries[$suc]['nomina_informativo']['IMSS'] = ($summaries[$suc]['nomina_informativo']['IMSS'] ?? 0.0) + $amount;
                $summaries[$suc]['imss_patronal'] += $amount;
            } else {
                $unassigned['imss_excluido'][$branchName] = ($unassigned['imss_excluido'][$branchName] ?? 0.0) + $amount;
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Público para permitir pruebas unitarias dirigidas (ver accumulateRecuperacion()). */
    public function emptyBranchSummary(string $sucursal): array
    {
        return [
            'sucursal'            => $sucursal,
            'valor_cartera'       => 0.0,
            'contratos'           => 0,
            'colocacion'          => 0.0,
            'creditos_colocados'  => 0,
            'seguro_crece_comadres_informativo' => 0.0,
            'capital_recuperado'        => 0.0,
            'interes_recuperado'        => 0.0,
            'impuesto_recuperado'       => 0.0,
            'charges'                   => 0.0,  // charges_due moratorios (excl. ACUERDO CON CLIENTE)
            'cargos_adicionales'        => 0.0,  // DB charges column (PAGO A CONTRATO)
            'excedente_recuperado'      => 0.0,  // DB excedente column
            'cargos_inicio'             => 0.0,  // ACUERDO CON CLIENTE charges_due
            'comision_apertura'         => 0.0,
            'otros_recuperacion'        => 0.0,  // residual: total - named components
            'otros_detalle'             => [],   // concepto real => monto (desglose del residual, nunca bolsa genérica)
            'recuperacion_total'        => 0.0,
            'recuperacion_bruta'        => 0.0,
            'seguro_excluido_bruto'     => 0.0,  // total no-CRECE savehearts (Savehearts + Comadres)
            'seguro_savehearts_bruto'   => 0.0,  // solo Cobertura Savehearts (non-CRECE, non-Comadres)
            'seguro_comadres_bruto'     => 0.0,  // Cobertura Crédito Grupal / Comadres (non-CRECE)
            'seguro_crece_bruto'        => 0.0,
            'seguro_crece_reconocido'   => 0.0,
            'unificacion_excluida'      => 0.0,
            'condonacion_excluida'      => 0.0,
            'cartera_vencida'     => 0.0,  // SUM 5 cols vencidas donde days_past_due>0
            'mora_0_30'           => 0.0,
            'mora_31_60'          => 0.0,
            'mora_61_90'          => 0.0,
            'mora_91_120'         => 0.0,
            'mora_120_plus'       => 0.0,
            'mora_0_30_cnt'           => 0,
            'mora_31_60_cnt'          => 0,
            'mora_61_90_cnt'          => 0,
            'mora_91_120_cnt'         => 0,
            'mora_120_plus_cnt'       => 0,
            // Per-bucket component breakdown
            'mora_0_30_capital'       => 0.0,
            'mora_0_30_interes'       => 0.0,
            'mora_0_30_impuesto'      => 0.0,
            'mora_0_30_moratorio'     => 0.0,
            'mora_0_30_imp_moratorio' => 0.0,
            'mora_31_60_capital'       => 0.0,
            'mora_31_60_interes'       => 0.0,
            'mora_31_60_impuesto'      => 0.0,
            'mora_31_60_moratorio'     => 0.0,
            'mora_31_60_imp_moratorio' => 0.0,
            'mora_61_90_capital'       => 0.0,
            'mora_61_90_interes'       => 0.0,
            'mora_61_90_impuesto'      => 0.0,
            'mora_61_90_moratorio'     => 0.0,
            'mora_61_90_imp_moratorio' => 0.0,
            'mora_91_120_capital'       => 0.0,
            'mora_91_120_interes'       => 0.0,
            'mora_91_120_impuesto'      => 0.0,
            'mora_91_120_moratorio'     => 0.0,
            'mora_91_120_imp_moratorio' => 0.0,
            'mora_120_plus_capital'       => 0.0,
            'mora_120_plus_interes'       => 0.0,
            'mora_120_plus_impuesto'      => 0.0,
            'mora_120_plus_moratorio'     => 0.0,
            'mora_120_plus_imp_moratorio' => 0.0,
            // Totals across all buckets
            'mora_total_capital'       => 0.0,
            'mora_total_interes'       => 0.0,
            'mora_total_impuesto'      => 0.0,
            'mora_total_moratorio'     => 0.0,
            'mora_total_imp_moratorio' => 0.0,
            'gastos_operativos'                  => 0.0,  // = gastos_erp_total + gastos_lendus_total (OPEX final)
            'gastos_erp_total'                   => 0.0,  // ERP que queda en OPEX (completo, excl. Corporativo y Pólizas)
            'gastos_erp_cargado'                 => 0.0,  // ERP total cargado (antes de excluir Pólizas)
            'gastos_erp_excluido_seguros'        => 0.0,  // ERP excluido: Pólizas (seguros vehiculares/oficina)
            'gastos_lendus_total'                => 0.0,  // Lendus que queda en OPEX
            'gastos_lendus_cargado'              => 0.0,  // Lendus total cargado (antes de todas las exclusiones)
            'gastos_lendus_excluido_fondeo'      => 0.0,  // Lendus excluido: fondeos entre sucursales
            'gastos_lendus_excluido_excedentes'  => 0.0,  // Lendus excluido: envíos a corporativo/excedentes
            'gastos_lendus_excluido_nomina'      => 0.0,  // Lendus excluido: nómina real (NOMINA/IMSS/DEDUCCIONES)
            'gastos_lendus_reclasificado_nomina' => 0.0,  // Lendus reclasificado: FINIQUITO/MEDICO → Nómina
            'gastos_lendus_excluido_polizas'     => 0.0,  // Lendus excluido: seguros/coberturas puente
            'seguros_lendus_puente'              => 0.0,  // alias de gastos_lendus_excluido_polizas (backward compat)
            // Lendus PDF "PAGO"/"COMPRA DE"/"ANTICIPO DE" bajo "Gastos Operativos" — excluidos
            // de OPEX porque duplican financiamiento de motos/cascos/anticipo ya contados vía
            // el Excel. Se guarda aparte (no solo el total) para que la auditoría pueda
            // comparar contra el Excel y detectar si algún periodo futuro deja de coincidir.
            'gastos_lendus_pago_generico_excluido' => [], // 'PAGO'|'COMPRA DE'|'ENGANCHE DE'|'ANTICIPO DE' => amount
            'gastos_detalle'                     => [],   // canonical_concept => amount (summed from source data)
            'nomina_total'         => 0.0,  // KPI Nómina: SUM(percepciones NOI) − SUM(deducciones NOI). Fuente única.
            'comisiones'           => 0.0,
            'bonos'                => 0.0,
            'bonos_aceleradores'   => 0.0,  // P118, separado de 'bonos' por petición explícita
            'vacaciones'           => 0.0,
            'prima_vacacional'     => 0.0,
            'otros_percepciones'   => 0.0,  // percepciones NOI con código no catalogado (ej. P107) — igual cuentan (regla: TODAS las percepciones)
            'imss_patronal'            => 0.0,  // IMSS patronal operativo — SÍ se suma al KPI Nómina (ver nominaTotalFor())
            'gastos_empleados_nomina'  => 0.0,  // Financiamiento de Motos + Enganche + Cascos + Finiquito + Gastos médicos — SÍ se suma al KPI Nómina
            'nomina_detalle'       => [],   // display_label => amount — deducciones NOI (YA restadas de nomina_total, solo informativo)
            'nomina_informativo'   => [],   // display_label => amount — desglose de detalle (IMSS/Motos/Enganche/Cascos/Finiquito/Médicos YA incluidos en imss_patronal/gastos_empleados_nomina; el resto sigue siendo puramente informativo)
            'excedentes'           => 0.0,
            'prestamos_fondea'     => 0.0,
            'polizas_crece_30'     => 0.0,
        ];
    }

    /**
     * Maps a fact_expenses category + concept to a canonical nómina display label.
     */
    /**
     * Maps a raw fact_expenses category/concept (Nómina y Capital Humano) to its canonical
     * display label. Never falls back to a generic "Otros conceptos nómina" bucket — an
     * unmapped concept is identified by its own real name (visible in
     * RadiographyCalculator::AuditNominaMapeoCommand) instead of being hidden under a vague
     * label, per the rule that every peso must be traceable to its source concept.
     */
    private function canonicalNominaExpense(string $category, string $concept): string
    {
        $cat = $this->normalizeConceptText($category);
        $con = $this->normalizeConceptText($concept);

        if ($cat === 'GASOLINA') {
            return 'Gasolina';
        }
        if ($cat === 'FINANCIAMIENTO CELULAR' || str_contains($con, 'CELULAR')) {
            return 'Financiamiento Celular';
        }
        return match (true) {
            str_contains($con, 'CASCO')                                                             => 'Cascos',
            str_contains($con, 'MEDICO') || str_contains($con, 'MÉDICO')                           => 'Gastos médicos',
            str_contains($con, 'FINIQUITO')                                                         => 'Finiquito',
            str_contains($con, 'FORMATERIA') || str_contains($con, 'FORMATERÍA')                   => 'Formatería',
            str_contains($con, 'FINANCIAMIENTO MOTO') || str_contains($con, 'MOTO')                => 'Financiamiento de Motos',
            str_contains($con, 'UNIFORME')                                                          => 'Descuento de uniformes',
            str_contains($con, 'INFONAVIT')                                                         => 'Descuentos Infonavit',
            str_contains($con, 'FONACOT')                                                           => 'Descuentos FONACOT',
            str_contains($con, 'PENSION') || str_contains($con, 'PENSIÓN')                         => 'Pensión Alimenticia',
            str_contains($con, 'MR LANA') || str_contains($con, 'TIENDA')                          => 'Descuentos Tienda Mr Lana',
            str_contains($con, 'PRESTAMO Z') || str_contains($con, 'PRÉSTAMO Z')                   => 'Anticipo de nómina',
            default => $this->unmappedNominaLabel($category, $concept),
        };
    }

    /**
     * Collapses any whitespace variant (regular space, non-breaking space \u{00A0}, tabs) to a
     * single space before matching. Source files (Lendus exports) sometimes encode the spaces
     * between words as non-breaking spaces, which silently breaks str_contains() matching and
     * was the root cause of legitimate concepts (e.g. "PAGO·PRESTAMO·Z" with NBSPs) falling into
     * an unmapped/"Otros" bucket instead of their real label.
     */
    private function normalizeConceptText(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', strtoupper($text)) ?? '');
    }

    /**
     * An expense concept under "Nómina y Capital Humano" that doesn't match any known label.
     * Logged for audit (see reportes:audit-nomina-mapeo) and shown in the report under its own
     * real concept name — never silently merged into a generic "Otros" line.
     */
    private function unmappedNominaLabel(string $category, string $concept): string
    {
        \Illuminate\Support\Facades\Log::warning('Concepto de nómina sin mapeo canónico — revisar canonicalNominaExpense().', [
            'category' => $category,
            'concept'  => $concept,
        ]);

        $clean = trim($concept) !== '' ? trim($concept) : trim($category);

        return 'Sin clasificar: ' . $clean;
    }

    /**
     * Maps a raw DB expense category + concept to the canonical display name used in the Excel.
     * Concept is used when category is generic (e.g. 'Gastos Operativos').
     * Unrecognized concepts return null — caller routes them to an audit bucket.
     */
    private function canonicalGastoConcept(string $category, string $concept = ''): ?string
    {
        $cat  = strtoupper(trim($category));
        $con  = strtoupper(trim($concept));
        // Combined string for catch-all matching
        $both = $cat . ' ' . $con;

        // Exact category matches first (most specific)
        return match (true) {
            // --- Lendus PDF truncated concepts under 'Gastos Operativos' ---
            // The PDF omits the full description; matched against the Excel export same period.
            $cat === 'GASTOS OPERATIVOS' && $con === 'GASTOS'          => 'Emergentes',
            $cat === 'GASTOS OPERATIVOS' && $con === 'PAGO'            => 'Financiamiento de Motos',
            $cat === 'GASTOS OPERATIVOS' && $con === 'SERVICIOS DE'    => 'Señora Limpieza',
            $cat === 'GASTOS OPERATIVOS' && $con === 'ENGANCHE DE'     => 'Servicios de Motocicletas',
            $cat === 'GASTOS OPERATIVOS' && $con === 'GASTOS POR'      => 'Transportes',
            $cat === 'GASTOS OPERATIVOS' && $con === 'INSUMOS DE'      => 'Insumos de Papelería',
            $cat === 'GASTOS OPERATIVOS' && $con === 'COMISIONES POR'  => 'Comisiones Oxxo',
            // --- ERP concept-level matches (full concept, specific enough) ---
            str_contains($con, 'INSUMOS SUCURSALES')                   => 'Insumos de Cafetería',
            // FLYERS Y VINILES ya NO mapea a Publicidad: validado contra tabla manual Junio 2026
            // (target Publicidad = $0.00) — cae a unmappedGastoLabel() para quedar trazable como
            // "Sin clasificar: FLYERS Y VINILES" en vez de inflar Publicidad silenciosamente.
            // --- Category-level direct matches ---
            str_contains($cat, 'GASTOS EMERGENTES') || $cat === 'EMERGENTES'                                => 'Emergentes',
            str_contains($cat, 'INSUMOS DE CAFETERIA') || str_contains($cat, 'INSUMOS DE CAFETERÍA')        => 'Insumos de Cafetería',
            str_contains($cat, 'INSUMOS DE LIMPIEZA')                                                        => 'Insumos de Limpieza',
            str_contains($cat, 'INSUMOS DE PAPELERIA') || str_contains($cat, 'INSUMOS DE PAPELERÍA')        => 'Insumos de Papelería',
            str_contains($cat, 'SERVICIOS DE LIMPIEZA')                                                      => 'Señora Limpieza',
            str_contains($cat, 'SERVICIOS DE MOTOCICLETAS') || str_contains($cat, 'SERVICIOS MOTO')         => 'Servicios de Motocicletas',
            str_contains($cat, 'SOFTWARE PÓLIZA') || str_contains($cat, 'SOFTWARE POLIZA')                  => 'Software Póliza Anual',
            str_contains($cat, 'RENTA DE BODEGAS') || str_contains($cat, 'RENTAS BODEGAS')                  => 'Renta de Bodegas',
            str_contains($cat, 'RENTA OFICINA') || str_contains($cat, 'RENTAS OFICINAS')                    => 'Renta Oficina',
            str_contains($cat, 'RECARGAS TELEFÓNICAS') || str_contains($cat, 'RECARGAS TELEFONICAS')        => 'Recargas Telefónicas',
            str_contains($cat, 'COMISIONES OXXO')                                                            => 'Comisiones Oxxo',
            str_contains($cat, 'MULTAS E INFRACCIONES')                                                      => 'Multas e Infracciones',
            str_contains($cat, 'TRÁMITES GUBERNAMENTALES') || str_contains($cat, 'TRAMITES GUBERNAMENTALES') => 'Trámites Gubernamentales',
            str_contains($cat, 'MOBILIARIO Y EQUIPO')                                                        => 'Mobiliario y Equipo',
            str_contains($cat, 'MANTENIMIENTO')                                                              => 'Mantenimiento',
            str_contains($cat, 'PAQUETERÍA') || str_contains($cat, 'PAQUETERIA')                            => 'Paquetería',
            str_contains($cat, 'TELÉFONO E INTERNET') || str_contains($cat, 'TELEFONO E INTERNET')          => 'Teléfono e Internet',
            str_contains($cat, 'PÓLIZAS') || str_contains($cat, 'POLIZAS')                                  => 'Pólizas',
            str_contains($cat, 'PUBLICIDAD')                                                                  => 'Publicidad',
            str_contains($cat, 'MECÁNICOS') || str_contains($cat, 'MECANICOS')                              => 'Mecánicos',
            str_contains($cat, 'TRANSPORTES')                                                                => 'Transportes',
            str_contains($cat, 'PEGOTES')                                                                    => 'Pegotes',
            str_contains($cat, 'PERMISOS VEHICULARES')                                                       => 'Permisos Vehiculares',
            str_contains($cat, 'VIÁTICOS') || str_contains($cat, 'VIATICOS')                                => 'Viáticos',
            str_contains($cat, 'FLETES')                                                                     => 'Fletes',
            str_contains($cat, 'FORMATERÍA') || str_contains($cat, 'FORMATERIA')                            => 'Formatería',
            str_contains($cat, 'EVENTOS')                                                                    => 'Eventos',
            str_contains($cat, 'GASTOS LEGALES')                                                             => 'Gastos legales',
            $cat === 'GASOLINA'                                                                               => 'Gasolina',
            $cat === 'FINANCIAMIENTO CELULAR'                                                                 => 'Financiamiento Celular',
            $cat === 'LUZ'                                                                                    => 'Luz',
            $cat === 'AGUA'                                                                                   => 'Agua',
            // --- Concept-level matching for generic categories (e.g. 'Gastos Operativos') ---
            str_contains($both, 'EMERGENTE')                                                                 => 'Emergentes',
            str_contains($both, 'CAFETERIA') || str_contains($both, 'CAFETERÍA')                            => 'Insumos de Cafetería',
            str_contains($both, 'LIMPIEZA') && str_contains($both, 'SEÑORA')                                 => 'Señora Limpieza',
            str_contains($both, 'LIMPIEZA')                                                                  => 'Insumos de Limpieza',
            str_contains($both, 'PAPELERIA') || str_contains($both, 'PAPELERÍA')                            => 'Insumos de Papelería',
            str_contains($both, 'RENTA') && str_contains($both, 'BODEGA')                                   => 'Renta de Bodegas',
            str_contains($both, 'RENTA') && !str_contains($both, 'BODEGA')                                  => 'Renta Oficina',
            str_contains($both, 'OXXO') || str_contains($both, 'TRANSACCION')                               => 'Comisiones Oxxo',
            str_contains($both, 'MOTOCICLETA') || (str_contains($both, 'MOTO') && str_contains($both, 'SERVICIO')) => 'Servicios de Motocicletas',
            str_contains($both, 'POLIZA ANUAL') || str_contains($both, 'PÓLIZA ANUAL') || str_contains($both, 'SOFTWARE') || str_contains($both, 'LICENCIA') => 'Software Póliza Anual',
            str_contains($both, 'POLIZA') || str_contains($both, 'PÓLIZA') || str_contains($both, 'SEGURO') => 'Pólizas',
            str_contains($both, 'RECARGA')                                                                   => 'Recargas Telefónicas',
            str_contains($both, 'INTERNET') || str_contains($both, 'TELEFON')                               => 'Teléfono e Internet',
            str_contains($both, 'LUZ') || str_contains($both, 'ELECTR')                                     => 'Luz',
            str_contains($both, 'AGUA')                                                                      => 'Agua',
            str_contains($both, 'MOBILIARIO') || str_contains($both, 'EQUIPO DE COMPUTO') || str_contains($both, 'ACCESORIOS EQUIPO') => 'Mobiliario y Equipo',
            str_contains($both, 'MANTENIMIENTO')                                                             => 'Mantenimiento',
            str_contains($both, 'PAQUETERIA') || str_contains($both, 'PAQUETERÍA') || str_contains($both, 'ENVIO') => 'Paquetería',
            str_contains($both, 'GUBERNAMENTAL') || str_contains($both, 'TRAMITE')                          => 'Trámites Gubernamentales',
            str_contains($both, 'PUBLICIDAD')                                                                => 'Publicidad',
            str_contains($both, 'MECANICO') || str_contains($both, 'MECÁNICO')                              => 'Mecánicos',
            str_contains($both, 'MULTA') || str_contains($both, 'INFRACCION')                               => 'Multas e Infracciones',
            str_contains($both, 'TRANSPORTE') || str_contains($both, 'TAXI') || str_contains($both, 'RUTA') || str_contains($both, 'DILIGENCIA') => 'Transportes',
            str_contains($both, 'PEGOTE')                                                                    => 'Pegotes',
            str_contains($both, 'PERMISO')                                                                   => 'Permisos Vehiculares',
            str_contains($both, 'VIATICO') || str_contains($both, 'VIÁTICO') || str_contains($both, 'SUPERVISION') || str_contains($both, 'CAPACITACION') => 'Viáticos',
            str_contains($both, 'FLETE')                                                                     => 'Fletes',
            str_contains($both, 'FORMATERIA') || str_contains($both, 'FORMATERÍA')                          => 'Formatería',
            str_contains($both, 'EVENTO')                                                                    => 'Eventos',
            str_contains($both, 'LEGAL') || str_contains($both, 'DOMINIO')                                  => 'Gastos legales',
            default                                                                                          => null,
        };
    }

    private function unmappedGastoLabel(string $category, string $concept): string
    {
        $text = trim($concept) !== '' ? trim($concept) : trim($category);
        \Illuminate\Support\Facades\Log::warning('Concepto de gasto operativo sin mapeo canónico — revisar canonicalGastoConcept().', [
            'category' => $category,
            'concept'  => $concept,
        ]);
        return 'Sin clasificar: ' . $text;
    }

    private function emptyUnassigned(): array
    {
        return [
            'nomina_total'       => 0.0,
            'comisiones'         => 0.0,
            'bonos'              => 0.0,
            'bonos_aceleradores' => 0.0,  // P118
            'vacaciones'         => 0.0,
            'prima_vacacional'   => 0.0,
            'otros_percepciones' => 0.0,
            'imss_patronal'            => 0.0,
            'gastos_empleados_nomina'  => 0.0,
            'nomina_detalle'     => [],   // display_label => amount
            'nomina_informativo' => [],   // display_label => amount — desglose de detalle (ver nota en emptyBranchSummary())
            'gastos_operativos'                  => 0.0,
            'gastos_erp_total'                   => 0.0,
            'gastos_erp_cargado'                 => 0.0,
            'gastos_erp_excluido_seguros'        => 0.0,
            'gastos_lendus_total'                => 0.0,
            'gastos_lendus_cargado'              => 0.0,
            'gastos_lendus_excluido_fondeo'      => 0.0,
            'gastos_lendus_excluido_excedentes'  => 0.0,
            'gastos_lendus_excluido_nomina'      => 0.0,
            'gastos_lendus_reclasificado_nomina' => 0.0,
            'gastos_lendus_excluido_polizas'     => 0.0,
            'seguros_lendus_puente'              => 0.0,
            'empleados'          => [],   // per-employee detail for SIN ASIGNAR sheet
            'gastos_items'       => [],   // per-gasto detail for SIN ASIGNAR sheet
            'pension_alimenticia_detectada' => 0.0, // D010 — auditoría, de respaldo (va a nomina_detalle)
            'prestamo_personal_detectado'   => 0.0, // D004 — auditoría, NO se suma a Nómina y Capital Humano
            'imss_excluido'                 => [],  // branch_name => amount — IMSS de sucursales no operativas (CORPORATIVO/TULANCINGO), excluido del global
        ];
    }
}
