<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPayrollCommand extends Command
{
    protected $signature = 'reportes:audit-payroll
                                {period_id}
                                {--by-branch        : Desglosa nómina por sucursal}
                                {--compare-reference : Compara vs. referencia Abril 2026}
                                {--detail           : Muestra breakdown completo de percepciones/deducciones NOI}
                                {--gasolina         : Sección D — validación de Gasolina por sucursal y fuente}';

    protected $description = 'Auditoría de nómina NOI + gastos: percepciones, ajustes, deducciones y total Capital Humano por sucursal.';

    // Referencia Abril 2026 por concepto (fuente: ACUMULADOS ABRIL SUCURSALES + ERP)
    private const REF_ABRIL = [
        'Nómina/Sueldo'             => 1_075_509.70,
        'Comisiones'                => 716_885.13,
        'Vacaciones'                => 20_988.43,
        'Prima vacacional'          => 5_562.16,
        'Bonos'                     => 291_655.85,
        'Gasolina'                  => 159_600.00,
        'Financiamiento de Motos'   => 154_624.37,
        'Cascos'                    => 19_933.50,
        'Finiquito'                 => 22_920.75,
        'Gastos médicos'            => 3_561.00,
        'Descuentos Infonavit'      => 28_686.48,
        'Descuento Servicios Moto'  => 1_662.06,
        'Pensión Alimenticia'       => 2_646.32,
        'TOTAL'                     => 2_501_589.43,
    ];

    private const REF_BY_BRANCH = [
        'ATLACOMULCO'       => 104_780.04,
        'ATLIXCO'           => 122_588.73,
        'CORDOBA'           => 481_832.24,
        'CUERNAVACA'        => 106_831.81,
        'HUAMANTLA'         => 130_086.96,
        'IXTLAHUACA'        =>  64_585.71,
        'MIACATLAN'         => 120_036.24,
        'ORIZABA'           => 679_867.00,
        'SAN JUAN DEL RÍO'  =>       0.00,
        'SAN LUIS POTOSI'   => 162_395.22,
        'TENANGO DEL VALLE' => 133_044.66,
        'TLAXCALA'          => 161_402.89,
        'TULA'              => 234_137.92,
    ];

    public function __construct(private readonly BranchResolverService $resolver)
    {
        parent::__construct();
    }

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

        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA NÓMINA — {$period->name} (ID {$period->id})");
        $this->info('════════════════════════════════════════════════════════════');
        $this->line('  IDs de datos: [' . implode(', ', $dataIds) . ']');

        // Aviso si no hay datos NOI en este periodo
        $noiCount = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->count();
        if ($noiCount === 0) {
            $this->warn("⚠  Este periodo no tiene datos en fact_noi_movements. Para Abril 2026 usa: reportes:audit-payroll 14");
        } else {
            $this->line("  Registros NOI: {$noiCount}");
        }

        // ── A. Totales NOI ────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. NOI — RESUMEN PERCEPCIONES Y AJUSTES ════');

        $noiByConcept = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('concept, concept_type, SUM(amount) as total, COUNT(DISTINCT employee_id) as emps')
            ->groupBy('concept', 'concept_type')
            ->orderByDesc('total')
            ->get();

        $p001 = 0.0; $p002 = 0.0; $p009 = 0.0; $p010 = 0.0; $bonos = 0.0;
        $d094 = 0.0; $d111 = 0.0; $d137 = 0.0; $d113 = 0.0; $d123 = 0.0;
        $d004 = 0.0; $d010 = 0.0;

        foreach ($noiByConcept as $r) {
            $code = mb_substr((string)$r->concept, 0, 4);
            $amt  = (float) $r->total;
            match ($code) {
                'P001' => $p001 += $amt,
                'P002' => $p002 += $amt,
                'P009' => $p009 += $amt,
                'P010' => $p010 += $amt,
                'P108', 'P109', 'P120', 'P123' => $bonos += $amt,
                'D094' => $d094 += $amt,
                'D111' => $d111 += $amt,
                'D137' => $d137 += $amt,
                'D113' => $d113 += $amt,
                'D123' => $d123 += $amt,
                'D004' => $d004 += $amt,
                'D010' => $d010 += $amt,
                default => null,
            };
        }

        $sueldoCalc = $p001 + $d111 - $d137;

        $this->line(str_pad('Concepto', 40) . str_pad('Sistema', 18) . 'Nota');
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('P001 SUELDO (bruto)',        40) . str_pad('$' . number_format($p001, 2), 18));
        $this->line(str_pad('D111 SUBSIDIO APL (+)',      40) . str_pad('+$' . number_format($d111, 2), 18) . '← se integra en Sueldo');
        $this->line(str_pad('D137 DIFERENCIA NF (−)',     40) . str_pad('−$' . number_format($d137, 2), 18) . '← se integra en Sueldo');
        $this->line(str_pad('→ Nómina/Sueldo calculado',  40) . str_pad('$' . number_format($sueldoCalc, 2), 18));
        if ($this->option('compare-reference')) {
            $diff = $sueldoCalc - self::REF_ABRIL['Nómina/Sueldo'];
            $this->line(str_pad('   Referencia',           40) . str_pad('$' . number_format(self::REF_ABRIL['Nómina/Sueldo'], 2), 18)
                . ($this->diffTag($diff)));
        }
        $this->line(str_pad('P002 Comisiones',           40) . str_pad('$' . number_format($p002, 2), 18));
        $this->line(str_pad('P009 Vacaciones',           40) . str_pad('$' . number_format($p009, 2), 18));
        $this->line(str_pad('P010 Prima vacacional',     40) . str_pad('$' . number_format($p010, 2), 18));
        $this->line(str_pad('Bonos (P108/109/120/123)',  40) . str_pad('$' . number_format($bonos, 2), 18));
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('D094 Infonavit',            40) . str_pad('$' . number_format($d094, 2), 18));
        $this->line(str_pad('D113 Serv. Moto',           40) . str_pad('$' . number_format($d113, 2), 18));
        $this->line(str_pad('D123 Prést. Moto (NOI)',    40) . str_pad('$' . number_format($d123, 2), 18));
        $this->line(str_pad('D004 Préstamo Personal',    40) . str_pad('$' . number_format($d004, 2), 18) . '← EXCLUIDO del total');
        $this->line(str_pad('D010 Pensión Alimenticia',  40) . str_pad('$' . number_format($d010, 2), 18) . '← EXCLUIDO del total');

        // ── B. Gastos de nómina desde fact_expenses ──────────────────────────
        $this->line('');
        $this->info('════ B. GASTOS NÓMINA (fact_expenses) ════');

        $srcIds = DB::table('data_sources')
            ->whereIn('code', ['gastos_lendus', 'gastos_erp', 'gastos_lendus_excel'])
            ->pluck('id', 'code');

        $gastosCats = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('ru.data_source_id', array_values($srcIds->toArray()))
            ->selectRaw("ds.code as source, e.category, COALESCE(e.concept,'') as concept,
                SUM(CASE WHEN e.branch_id IS NULL THEN 1 ELSE 0 END) as null_branch_rows,
                SUM(e.amount) as total_amount")
            ->groupBy('ds.code', 'e.category', 'e.concept')
            ->orderBy('ds.code')
            ->orderByDesc('total_amount')
            ->get();

        // Summary: Gasolina (ERP, operative), Finiquito, Cascos, etc.
        $gasolinaOp = 0.0; $financMoto = 0.0; $cascos = 0.0;
        $finiquito  = 0.0; $gastosMed  = 0.0; $anticipoNom = 0.0;

        // Operative branch IDs
        $operativeIds = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $this->resolver->isSheetBranch($real)) {
                $operativeIds[] = (int) $b->id;
            }
        }

        // Gasolina from ERP (operative branches)
        $gasolinaOp = (float) DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $srcIds['gastos_erp'] ?? 0)
            ->where('e.category', 'Gasolina')
            ->whereIn('e.branch_id', $operativeIds)
            ->sum(DB::raw('COALESCE(NULLIF(e.paid_amount,0), e.amount)'));

        // Nomina expenses from gastos_lendus (operative, excluding IMSS)
        $lendusNomina = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $srcIds['gastos_lendus'] ?? 0)
            ->whereIn('e.branch_id', $operativeIds)
            ->whereIn('e.category', ['Nómina y Capital Humano', 'Nomina y Capital Humano'])
            ->whereNotIn('e.concept', ['NOMINA', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO DE IMSS', 'GASTOS EMERGENTES', 'GASTOS POR TRANSPORTE'])
            ->selectRaw("UPPER(TRIM(COALESCE(e.concept,''))) as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('e.concept')
            ->get();

        foreach ($lendusNomina as $r) {
            $con = (string) $r->concept;
            if (str_contains($con, 'FINIQUITO')) { $finiquito += (float) $r->total; }
            elseif (str_contains($con, 'MEDICO') || str_contains($con, 'MÉDICO')) { $gastosMed += (float) $r->total; }
            elseif (str_contains($con, 'PRESTAMO Z') || str_contains($con, 'PRÉSTAMO Z')) { $anticipoNom += (float) $r->total; }
        }

        // Cascos + Financiamiento Moto from gastos_lendus_excel (all branches — null and assigned)
        if ($srcIds->has('gastos_lendus_excel')) {
            $excelRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $srcIds['gastos_lendus_excel'])
                ->whereIn('e.category', ['Nómina y Capital Humano', 'Nomina y Capital Humano'])
                ->whereIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), ['PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS'])
                ->selectRaw("UPPER(TRIM(COALESCE(e.concept,''))) as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.concept')
                ->get();

            foreach ($excelRows as $r) {
                $con = (string) $r->concept;
                if (str_contains($con, 'FINANCIAMIENTO MOTO')) { $financMoto += (float) $r->total; }
                elseif (str_contains($con, 'CASCO')) { $cascos += (float) $r->total; }
            }
        }

        // D123 contributes to Financiamiento de Motos (per-branch portion)
        $financMotoTotal = $financMoto + $d123;

        $this->line(str_pad('Concepto', 40) . str_pad('Sistema', 18) . str_pad('Fuente', 20));
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('Gasolina',                   40) . str_pad('$' . number_format($gasolinaOp, 2), 18) . 'ERP (sucursales op.)');
        $this->line(str_pad('Financiamiento de Motos',    40) . str_pad('$' . number_format($financMotoTotal, 2), 18) . 'Lendus Excel + D123 NOI');
        $this->line(str_pad('  └─ PAGO FINANCIAMIENTO MOTO', 40) . str_pad('$' . number_format($financMoto, 2), 18) . 'Lendus Excel (global)');
        $this->line(str_pad('  └─ D123 PRESTAMO MOTO (NOI)', 40) . str_pad('$' . number_format($d123, 2), 18) . 'NOI deducción');
        $this->line(str_pad('Cascos',                     40) . str_pad('$' . number_format($cascos, 2), 18) . 'Lendus Excel (global)');
        $this->line(str_pad('Finiquito',                  40) . str_pad('$' . number_format($finiquito, 2), 18) . 'Lendus (por sucursal)');
        $this->line(str_pad('Gastos médicos',             40) . str_pad('$' . number_format($gastosMed, 2), 18) . 'Lendus (por sucursal)');
        $this->line(str_pad('Anticipo de nómina',         40) . str_pad('$' . number_format($anticipoNom, 2), 18) . 'Lendus (por sucursal)');

        // ── C. TOTAL Nómina y Capital Humano ─────────────────────────────────
        $this->line('');
        $this->info('════ C. TOTAL NÓMINA Y CAPITAL HUMANO ════');

        $detalle = [
            'Nómina/Sueldo'            => $sueldoCalc,
            'Comisiones'               => $p002,
            'Vacaciones'               => $p009,
            'Prima vacacional'         => $p010,
            'Bonos'                    => $bonos,
            'Gasolina'                 => $gasolinaOp,
            'Financiamiento de Motos'  => $financMotoTotal,
            'Cascos'                   => $cascos,
            'Finiquito'                => $finiquito,
            'Gastos médicos'           => $gastosMed,
            'Descuentos Infonavit'     => $d094,
            'Descuento Servicios Moto' => $d113,
            'Pensión Alimenticia'      => $d010,
        ];

        $totalSistema = array_sum($detalle);

        $colW = $this->option('compare-reference') ? [38, 16, 16, 12] : [38, 18];
        $hdr  = str_pad('Concepto', $colW[0]) . str_pad('Sistema', $colW[1]);
        if ($this->option('compare-reference')) {
            $hdr .= str_pad('Referencia', $colW[2]) . 'Diferencia';
        }
        $this->line($hdr);
        $this->line(str_repeat('─', array_sum($colW)));

        foreach ($detalle as $label => $val) {
            $line = str_pad($label, $colW[0]) . str_pad('$' . number_format($val, 2), $colW[1]);
            if ($this->option('compare-reference') && isset(self::REF_ABRIL[$label])) {
                $ref  = self::REF_ABRIL[$label];
                $diff = $val - $ref;
                $line .= str_pad('$' . number_format($ref, 2), $colW[2]) . $this->diffTag($diff);
            }
            $this->line($line);
        }
        $this->line(str_repeat('─', array_sum($colW)));

        $totalLine = str_pad('TOTAL SISTEMA', $colW[0]) . str_pad('$' . number_format($totalSistema, 2), $colW[1]);
        if ($this->option('compare-reference')) {
            $ref  = self::REF_ABRIL['TOTAL'];
            $diff = $totalSistema - $ref;
            $totalLine .= str_pad('$' . number_format($ref, 2), $colW[2]) . $this->diffTag($diff);
        }
        $this->info($totalLine);

        // ── D. Gasolina por sucursal y fuente ────────────────────────────────
        if ($this->option('gasolina') || $this->option('by-branch')) {
            $this->showGasolinaByBranch($dataIds, $operativeIds);
        }

        // ── E. Nómina por sucursal ────────────────────────────────────────────
        if ($this->option('by-branch')) {
            $this->showByBranch($dataIds, $operativeIds);
        }

        if ($this->option('detail')) {
            $this->showNOIDetail($dataIds);
        }

        return 0;
    }

    private function showGasolinaByBranch(array $dataIds, array $operativeIds): void
    {
        $this->line('');
        $this->info('════ D. VALIDACIÓN GASOLINA POR SUCURSAL ════');

        $resolver = $this->resolver;
        $operativeMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = strtoupper(trim($real));
            }
        }

        // ERP gasoline per operative branch
        $erpGas = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ds.code', 'gastos_erp')
            ->where('e.category', 'Gasolina')
            ->whereRaw("UPPER(TRIM(COALESCE(e.concept,''))) LIKE '%OPERATIVA%'")
            ->whereNotNull('e.branch_id')
            ->selectRaw('e.branch_id, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total')
            ->groupBy('e.branch_id')
            ->get();

        $erpByBranch = [];
        foreach ($erpGas as $r) {
            $suc = $operativeMap[(int)$r->branch_id] ?? ('branch_id=' . $r->branch_id);
            $erpByBranch[$suc] = (float) $r->total;
        }

        // ERP gasoline under CORPORATIVO (operativa sucursales — misattributed)
        $corpGasCodes = DB::table('branches')->where('name', 'LIKE', '%orporativo%')->pluck('id')->toArray();
        $corpOpGas = 0.0;
        if ($corpGasCodes) {
            $corpOpGas = (float) DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ds.code', 'gastos_erp')
                ->where('e.category', 'Gasolina')
                ->whereIn('e.branch_id', $corpGasCodes)
                ->whereRaw("UPPER(TRIM(COALESCE(e.concept,''))) LIKE '%OPERATIVA%'")
                ->sum(DB::raw('COALESCE(NULLIF(e.paid_amount,0), e.amount)'));
        }

        // Lendus PDF gasoline per operative branch (category: Gasolina or similar)
        $lendusGas = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ds.code', 'gastos_lendus')
            ->whereRaw("UPPER(TRIM(COALESCE(e.category,''))) LIKE '%GASOLIN%'")
            ->whereIn('e.branch_id', $operativeIds)
            ->selectRaw('e.branch_id, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total')
            ->groupBy('e.branch_id')
            ->get();

        foreach ($lendusGas as $r) {
            $suc = $operativeMap[(int)$r->branch_id] ?? null;
            if ($suc) {
                $erpByBranch[$suc] = ($erpByBranch[$suc] ?? 0.0) + (float) $r->total;
            }
        }

        // All operative branches expected
        $allBranches = array_map('strtoupper', $resolver->operativeFinancialBranches());

        $this->line(str_pad('Sucursal', 24) . str_pad('ERP+Lendus', 16) . str_pad('Fuente', 16) . 'Estado');
        $this->line(str_repeat('─', 80));

        $sysTotal = 0.0;
        foreach ($allBranches as $suc) {
            $monto = $erpByBranch[$suc] ?? 0.0;
            $sysTotal += $monto;
            $fuente = $monto > 0 ? 'ERP/Lendus' : '—';
            $estado = $monto > 0 ? '' : '<fg=red>⚠ SIN DATOS</>';
            $this->line(sprintf('%-24s %s%-16s%-16s%s',
                mb_strimwidth($suc, 0, 22, '…'),
                str_pad('$' . number_format($monto, 2), 16),
                $fuente,
                '',
                $estado
            ));
        }

        $this->line(str_repeat('─', 80));
        $refTotal = self::REF_ABRIL['Gasolina'];
        $diff     = $sysTotal - $refTotal;
        $this->info(sprintf('%-24s %s%-16s%s',
            'TOTAL SISTEMA',
            str_pad('$' . number_format($sysTotal, 2), 16),
            '',
            $this->diffTag($diff)
        ));
        $this->line(sprintf('%-24s $%s', 'REFERENCIA', number_format($refTotal, 2)));

        if ($corpOpGas > 0) {
            $this->warn(sprintf("⚠  CORPORATIVO tiene $%.2f en 'GASOLINA OPERATIVA SUCURSALES' (excluida del total — no tiene sucursal asignada).", $corpOpGas));
        }

        // Identify branches with no gasoline
        $missing = array_filter($allBranches, fn($s) => ($erpByBranch[$s] ?? 0.0) == 0.0);
        if ($missing) {
            $this->warn('Sucursales sin gasolina en ERP/Lendus: ' . implode(', ', $missing));
            $this->warn('  → Verificar si el archivo ERP de este periodo incluye esas sucursales.');
        }
    }

    private function showByBranch(array $dataIds, array $operativeIds): void
    {
        $this->line('');
        $this->info('════ D. NÓMINA POR SUCURSAL ════');

        $resolver = $this->resolver;

        // Build operative map: branch_id → real name
        $operativeMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            }
        }

        // NOI per branch (P001 + D111 - D137 + P002 + P009 + P010 + bonos + D094 + D113 + D123 + D010)
        $noiByBranch = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept REGEXP '^[PD](001|002|009|010|108|109|120|123|094|111|113|123|137)'")
            ->selectRaw("COALESCE(eba.branch_id, -1) as branch_id, n.concept, n.concept_type, SUM(n.amount) as total")
            ->groupByRaw("COALESCE(eba.branch_id, -1), n.concept, n.concept_type")
            ->get();

        $blank = ['nomina' => 0.0, 'comisiones' => 0.0, 'vacaciones' => 0.0, 'prima' => 0.0, 'bonos' => 0.0,
                  'infonavit' => 0.0, 'serv_moto' => 0.0, 'fin_moto_noi' => 0.0, 'pension' => 0.0];
        $byBranch = [];
        foreach ($noiByBranch as $row) {
            $branchId = (int) $row->branch_id;
            $suc      = $branchId === -1 ? 'SIN ASIGNAR' : ($operativeMap[$branchId] ?? 'SIN ASIGNAR');
            $code     = mb_substr((string)$row->concept, 0, 4);
            $amt      = (float) $row->total;
            $byBranch[$suc] ??= $blank;
            match ($code) {
                'P001' => $byBranch[$suc]['nomina'] += $amt,
                'P002' => $byBranch[$suc]['comisiones'] += $amt,
                'P009' => $byBranch[$suc]['vacaciones'] += $amt,
                'P010' => $byBranch[$suc]['prima'] += $amt,
                'P108', 'P109', 'P120', 'P123' => $byBranch[$suc]['bonos'] += $amt,
                'D094' => $byBranch[$suc]['infonavit'] += $amt,
                'D111' => $byBranch[$suc]['nomina'] += $amt,
                'D137' => $byBranch[$suc]['nomina'] -= $amt,
                'D113' => $byBranch[$suc]['serv_moto'] += $amt,
                'D123' => $byBranch[$suc]['fin_moto_noi'] += $amt,
                'D010' => $byBranch[$suc]['pension'] += $amt,
                default => null,
            };
        }

        // Expenses per branch (Gasolina, Finiquito, Gastos médicos from gastos_lendus + gastos_erp)
        $srcAll = DB::table('data_sources')
            ->whereIn('code', ['gastos_lendus', 'gastos_erp', 'gastos_lendus_excel'])
            ->pluck('id', 'code');

        $expByBranch = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('e.branch_id', $operativeIds)
            ->whereIn('ru.data_source_id', array_values($srcAll->toArray()))
            ->where(function ($q) {
                $q->where('e.category', 'Gasolina')
                  ->orWhere(function ($q2) {
                      $q2->whereIn('e.category', ['Nómina y Capital Humano', 'Nomina y Capital Humano'])
                         ->whereNotIn('e.concept', ['NOMINA', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO DE IMSS', 'GASTOS EMERGENTES', 'GASTOS POR TRANSPORTE']);
                  });
            })
            ->selectRaw("ds.code as src, e.branch_id, e.category, UPPER(TRIM(COALESCE(e.concept,''))) as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('ds.code', 'e.branch_id', 'e.category', 'e.concept')
            ->get();

        foreach ($expByBranch as $row) {
            $branchId = (int) $row->branch_id;
            $suc      = $operativeMap[$branchId] ?? 'SIN ASIGNAR';
            $cat      = (string) $row->category;
            $con      = (string) $row->concept;
            $amt      = (float) $row->total;
            $byBranch[$suc] ??= $blank;
            if ($cat === 'Gasolina') {
                $byBranch[$suc]['gasolina'] = ($byBranch[$suc]['gasolina'] ?? 0.0) + $amt;
            } elseif (str_contains($con, 'FINANCIAMIENTO MOTO') || (str_contains($con, 'MOTO') && !str_contains($con, 'SERV'))) {
                $byBranch[$suc]['fin_moto_exp'] = ($byBranch[$suc]['fin_moto_exp'] ?? 0.0) + $amt;
            } elseif (str_contains($con, 'CASCO')) {
                $byBranch[$suc]['cascos'] = ($byBranch[$suc]['cascos'] ?? 0.0) + $amt;
            } elseif (str_contains($con, 'FINIQUITO')) {
                $byBranch[$suc]['finiquito'] = ($byBranch[$suc]['finiquito'] ?? 0.0) + $amt;
            } elseif (str_contains($con, 'MEDICO') || str_contains($con, 'MÉDICO')) {
                $byBranch[$suc]['gastos_med'] = ($byBranch[$suc]['gastos_med'] ?? 0.0) + $amt;
            } else {
                $byBranch[$suc]['otros_exp'] = ($byBranch[$suc]['otros_exp'] ?? 0.0) + $amt;
            }
        }

        $compareRef = $this->option('compare-reference');
        $hdr = str_pad('Sucursal', 22) . str_pad('Nómina', 13) . str_pad('Comis.', 13)
             . str_pad('Bonos', 12) . str_pad('Gasolina', 12) . str_pad('Total', 14);
        if ($compareRef) { $hdr .= str_pad('Referencia', 14) . 'Diferencia'; }
        $this->line($hdr);
        $this->line(str_repeat('─', $compareRef ? 102 : 86));

        $grandTotal = 0.0;
        $grandRef   = 0.0;
        ksort($byBranch);
        foreach ($byBranch as $suc => $data) {
            $finMoto = ($data['fin_moto_noi'] ?? 0) + ($data['fin_moto_exp'] ?? 0);
            $total = ($data['nomina'] ?? 0) + ($data['comisiones'] ?? 0) + ($data['vacaciones'] ?? 0)
                   + ($data['prima'] ?? 0) + ($data['bonos'] ?? 0) + ($data['gasolina'] ?? 0)
                   + ($data['finiquito'] ?? 0) + ($data['gastos_med'] ?? 0)
                   + ($data['infonavit'] ?? 0) + ($data['serv_moto'] ?? 0) + $finMoto
                   + ($data['cascos'] ?? 0) + ($data['pension'] ?? 0)
                   + ($data['otros_exp'] ?? 0);
            $grandTotal += $total;

            $line = str_pad(mb_strimwidth($suc, 0, 20, '…'), 22)
                  . str_pad('$' . number_format($data['nomina'] ?? 0, 0), 13)
                  . str_pad('$' . number_format($data['comisiones'] ?? 0, 0), 13)
                  . str_pad('$' . number_format($data['bonos'] ?? 0, 0), 12)
                  . str_pad('$' . number_format($data['gasolina'] ?? 0, 0), 12)
                  . str_pad('$' . number_format($total, 2), 14);

            if ($compareRef) {
                $ref = self::REF_BY_BRANCH[$suc] ?? null;
                if ($ref !== null) {
                    $diff = $total - $ref;
                    $grandRef += $ref;
                    $line .= str_pad('$' . number_format($ref, 2), 14) . $this->diffTag($diff);
                } else {
                    $line .= str_pad('—', 14) . '(sin referencia)';
                }
            }
            $this->line($line);
        }

        $this->line(str_repeat('─', $compareRef ? 102 : 86));
        $totalLine = str_pad('GRAND TOTAL', 22 + 13 + 13 + 12 + 12) . '$' . number_format($grandTotal, 2);
        if ($compareRef) {
            $diff = $grandTotal - $grandRef;
            $totalLine .= '  ' . str_pad('$' . number_format($grandRef, 2), 14) . $this->diffTag($diff);
        }
        $this->info($totalLine);
    }

    private function showNOIDetail(array $dataIds): void
    {
        $this->line('');
        $this->info('════ E. DETALLE NOI POR CONCEPTO ════');

        $rows = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('concept, concept_type, SUM(amount) as total, COUNT(DISTINCT employee_id) as emps, COUNT(*) as mvts')
            ->groupBy('concept', 'concept_type')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Concepto', 40) . str_pad('Tipo', 13) . str_pad('Empl', 7) . 'Total');
        $this->line(str_repeat('─', 75));
        foreach ($rows as $r) {
            $this->line(
                str_pad(mb_strimwidth($r->concept ?? 'NULL', 0, 38, '…'), 40)
                . str_pad($r->concept_type ?? '', 13)
                . str_pad(number_format((int)$r->emps), 7)
                . '$' . number_format((float)$r->total, 2)
            );
        }
    }

    private function diffTag(float $diff): string
    {
        if (abs($diff) < 1) { return '✓ OK'; }
        $sign = $diff > 0 ? '+' : '';
        $tag  = abs($diff) > 5000 ? ' ← REVISAR' : '';
        return $sign . number_format($diff, 2) . $tag;
    }
}
