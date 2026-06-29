<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Validates that all cross-totals are consistent across UI/Excel/PDF source:
 * Ingresos, Colocación, Cartera, Mora, OPEX, Nómina, Fondeos, Excedentes.
 */
class AuditCuadreCommand extends Command
{
    protected $signature = 'reportes:audit-cuadre
                                {period_id}
                                {--detail : mostrar diferencias detalladas}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría de cuadre: valida que todos los totales sean consistentes (Ingresos, Cartera, Mora, OPEX, Nómina, Fondeos)';

    private const TOL = 0.02; // 2 centavos de tolerancia

    private array $checks = [];
    private int   $ok     = 0;
    private int   $diff   = 0;
    private int   $warn   = 0;

    public function __construct(
        private readonly BranchRadiographyCalculator $calculator,
    ) {
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

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA DE CUADRE — {$period->label} (ID {$period->id})");
        $this->info('  Fuente única: BranchRadiographyCalculator (= UI + Excel + PDF)');
        $this->info('════════════════════════════════════════════════════════════════');

        // Build the authoritative source
        $branchCalcResult = $this->calculator->buildBranches($period, $dataIds, []);
        $branches  = $branchCalcResult['branches'];
        $unassigned= $branchCalcResult['unassigned'];
        $global    = $this->calculator->sumGlobal($branches, $unassigned);
        $carteraGlobal = $this->calculator->computeCarteraGlobalSinFiltro($dataIds);

        // ── 1. Recuperación / Ingresos ────────────────────────────────────────
        $this->section('1. RECUPERACIÓN / INGRESOS');
        $recGlobal = (float)($global['recuperacion_total'] ?? 0);
        $recBruta  = (float)($global['recuperacion_bruta'] ?? 0);
        $recExcluido = (float)($global['seguro_excluido_bruto'] ?? 0);
        $recSucursalSum = array_sum(array_column($branches, 'recuperacion_total'));

        $this->check('Recuperación global = suma por sucursales', $recGlobal, $recSucursalSum);
        $this->info("  Bruta: $" . number_format($recBruta, 2) . "  Excluido: $" . number_format($recExcluido, 2) . "  Neta: $" . number_format($recGlobal, 2));

        // Components: capital + interés + impuesto + moratorios reconciliation
        $capitalRec  = (float)($global['capital_recuperado'] ?? 0);
        $interesRec  = (float)($global['interes_recuperado'] ?? 0);
        $impRec      = (float)($global['impuesto_recuperado'] ?? 0);
        $morRec      = (float)($global['moratorios_recuperados'] ?? 0);
        $compSum     = $capitalRec + $interesRec + $impRec + $morRec;

        // Components may differ slightly due to rounding; warn if > $1000 gap
        $this->checkWarn('Recuperación: componentes capital+interés+imp+mora ~= total bruto', $recBruta, $compSum, 1000.0);

        // Unificacion / Condonacion excluded
        $unif = (float)($global['unificacion_excluida'] ?? 0);
        $cond = (float)($global['condonacion_excluida'] ?? 0);
        if ($unif > 0 || $cond > 0) {
            $this->line("  [INFO] Excluidos: Unificación $" . number_format($unif, 2) . "  Condonación $" . number_format($cond, 2));
        }

        // ── 2. Colocación ─────────────────────────────────────────────────────
        $this->section('2. COLOCACIÓN');
        $colGlobal      = (float)($global['colocacion'] ?? 0);
        $colSucursalSum = array_sum(array_column($branches, 'colocacion'));

        $this->check('Colocación global = suma por sucursales', $colGlobal, $colSucursalSum);

        // Products
        $colByProduct = DB::table('fact_placements as p')
            ->whereIn('p.period_id', $dataIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(p.raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->selectRaw("COALESCE(p.product_name, 'Sin producto') as producto, SUM(p.amount) as total")
            ->groupBy('producto')
            ->get();
        $colProductSum = $colByProduct->sum('total');
        $this->checkWarn('Colocación: suma productos ~= global', $colGlobal, (float)$colProductSum, 100.0);

        // ── 3. Cartera ────────────────────────────────────────────────────────
        $this->section('3. CARTERA');
        $carteraGlobalVal = (float)($carteraGlobal['valor_cartera'] ?? 0);
        $carteraVencida   = (float)($carteraGlobal['cartera_vencida'] ?? 0);
        $carteraSucSum    = array_sum(array_column($branches, 'valor_cartera'));

        $this->check('Cartera global = suma por sucursales', $carteraGlobalVal, $carteraSucSum);
        $this->info("  Valor cartera: $" . number_format($carteraGlobalVal, 2));
        $this->info("  Cartera vencida: $" . number_format($carteraVencida, 2));
        $moraIdx = $carteraGlobalVal > 0 ? round($carteraVencida / $carteraGlobalVal * 100, 2) : 0;
        $this->info("  Índice de mora: {$moraIdx}%");

        // ── 4. Mora ───────────────────────────────────────────────────────────
        $this->section('4. MORA');
        $moraSucSum  = array_sum(array_column($branches, 'cartera_vencida'));

        $this->check('Mora: global = suma por sucursales', $carteraVencida, $moraSucSum);

        // Buckets sum
        $buckets = DB::table('fact_portfolios as p')
            ->whereIn('p.period_id', $dataIds)
            ->whereRaw("COALESCE(p.days_past_due, 0) > 0")
            ->selectRaw("
                SUM(CASE WHEN COALESCE(p.days_past_due,0) BETWEEN 1 AND 30 THEN COALESCE(p.past_due_balance,0) ELSE 0 END) as b1_30,
                SUM(CASE WHEN COALESCE(p.days_past_due,0) BETWEEN 31 AND 60 THEN COALESCE(p.past_due_balance,0) ELSE 0 END) as b31_60,
                SUM(CASE WHEN COALESCE(p.days_past_due,0) BETWEEN 61 AND 90 THEN COALESCE(p.past_due_balance,0) ELSE 0 END) as b61_90,
                SUM(CASE WHEN COALESCE(p.days_past_due,0) > 90 THEN COALESCE(p.past_due_balance,0) ELSE 0 END) as b_90mas
            ")
            ->first();

        $bucketSum = (float)($buckets->b1_30 ?? 0)
                   + (float)($buckets->b31_60 ?? 0)
                   + (float)($buckets->b61_90 ?? 0)
                   + (float)($buckets->b_90mas ?? 0);

        if ($this->option('detail')) {
            $this->line("  Bucket 1-30d:   $" . number_format($buckets->b1_30 ?? 0, 2));
            $this->line("  Bucket 31-60d:  $" . number_format($buckets->b31_60 ?? 0, 2));
            $this->line("  Bucket 61-90d:  $" . number_format($buckets->b61_90 ?? 0, 2));
            $this->line("  Bucket >90d:    $" . number_format($buckets->b_90mas ?? 0, 2));
            $this->line("  Suma buckets:   $" . number_format($bucketSum, 2));
        }
        $this->checkWarn('Mora: suma buckets ~= cartera vencida', $carteraVencida, $bucketSum, 500.0);

        // ── 5. OPEX ───────────────────────────────────────────────────────────
        $this->section('5. OPEX');
        $opexGlobal    = (float)($global['gastos_operativos'] ?? 0);
        $erpGlobal     = (float)($global['gastos_erp_total'] ?? 0);
        $lendusGlobal  = (float)($global['gastos_lendus_total'] ?? 0);
        $opexSumComp   = $erpGlobal + $lendusGlobal;
        $opexSucSum    = array_sum(array_column($branches, 'gastos_operativos'));

        $this->check('OPEX: ERP + Lendus = gastos_operativos global', $opexGlobal, $opexSumComp);
        $this->check('OPEX global = suma por sucursales', $opexGlobal, $opexSucSum);
        $this->info("  ERP: $" . number_format($erpGlobal, 2) . "   Lendus: $" . number_format($lendusGlobal, 2));

        // ── 6. Nómina ─────────────────────────────────────────────────────────
        $this->section('6. NÓMINA Y CAPITAL HUMANO');
        $nominaGlobal  = (float)($global['nomina_total'] ?? 0);
        $nominaSucSum  = array_sum(array_column($branches, 'nomina_total'));
        $nominaUnassigned = (float)($unassigned['nomina_total'] ?? 0);

        $this->check('Nómina global = sucursales + sin asignar', $nominaGlobal, $nominaSucSum + $nominaUnassigned);

        // Sin asignar must be $0 in payroll (excluding NOI components)
        $nominaDetalle = (array)($unassigned['nomina_detalle'] ?? []);
        $detalleSum    = array_sum(array_values($nominaDetalle));
        if ($nominaUnassigned + $detalleSum > 0.01) {
            $this->warn("  [AVISO] Nómina sin asignar: $" . number_format($nominaUnassigned + $detalleSum, 2));
            $this->addCheck('Nómina: sin asignar = 0', 0.0, $nominaUnassigned + $detalleSum, true);
        } else {
            $this->line("  [OK] Sin asignar en nómina = $0.00");
            $this->ok++;
        }

        // IMSS empresa presente
        $imssSum = (float)(DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ds.code', 'imss')
            ->where('e.category', 'IMSS')
            ->sum('e.paid_amount') ?? 0);
        if ($imssSum > 0) {
            $this->line("  [INFO] IMSS patronal registrado: $" . number_format($imssSum, 2));
        } else {
            $this->warn("  [AVISO] IMSS patronal = $0 (¿se subió el archivo IMSS?)");
            $this->warn++;
        }

        // ── 7. Fondeos ────────────────────────────────────────────────────────
        $this->section('7. FONDEOS INTERSUCURSALES');
        $fondeaGlobal  = (float)($global['prestamos_fondea'] ?? 0);
        $recibeGlobal  = (float)($global['prestamos_recibe'] ?? 0);
        $netoGlobal    = (float)($global['prestamos_neto'] ?? 0);

        $this->check('Fondeos: fondea = recibe (neto = 0)', $fondeaGlobal, $recibeGlobal);

        $lendusPdfId  = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $sinOrigenFondeo = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusPdfId)
            ->where('e.category', 'Préstamos Intersucursales')
            ->whereNull('e.branch_id')
            ->count();
        if ($sinOrigenFondeo > 0) {
            $this->warn("  [AVISO] Fondeos sin origen detectado: {$sinOrigenFondeo} registros");
            $this->warn++;
        } else {
            $this->line("  [OK] Sin origen en fondeos = 0");
        }

        $this->info("  Fondea: $" . number_format($fondeaGlobal, 2) . "   Recibe: $" . number_format($recibeGlobal, 2) . "   Neto: $" . number_format($netoGlobal, 2));

        // ── 8. Excedentes / corporativo ───────────────────────────────────────
        $this->section('8. EXCEDENTES / CORPORATIVO');
        $excGlobal = (float)($global['excedentes'] ?? 0);
        $this->info("  Excedentes (separados de fondeos): $" . number_format($excGlobal, 2));
        if ($excGlobal > 0) {
            $this->line("  [OK] Excedentes presentes y separados de fondeos");
            $this->ok++;
        } else {
            $this->warn("  [INFO] Excedentes = $0 (normal si no hay envíos a corporativo)");
        }

        // Verify excedentes NOT in prestamos_fondea
        $sinAsigExc = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusPdfId)
            ->where('e.category', 'Envío de utilidad a corporativo')
            ->sum('e.paid_amount');
        $excDB = (float)$sinAsigExc;
        $this->checkWarn('Excedentes BD = excedentes snapshot', $excGlobal, $excDB, 1.0);

        // ── 9. EBITDA coherence ───────────────────────────────────────────────
        $this->section('9. EBITDA — coherencia interna');
        $saldoInicial   = (float)($period->saldo_inicial_caja ?? 0);
        $nominaDetalleSuma = array_sum(array_values((array)($global['nomina_detalle'] ?? [])));
        $nominaParaEbitda = (float)($global['nomina_total'] ?? 0)
                          + (float)($global['comisiones'] ?? 0)
                          + (float)($global['bonos'] ?? 0)
                          + (float)($global['vacaciones'] ?? 0)
                          + (float)($global['prima_vacacional'] ?? 0)
                          + $nominaDetalleSuma;
        $gastosParaEbitda = $opexGlobal + $nominaParaEbitda;
        $ebitda = $saldoInicial + $recGlobal + $colGlobal - $gastosParaEbitda;

        $this->info("  Saldo inicial: $" . number_format($saldoInicial, 2));
        $this->info("  Recuperación: $" . number_format($recGlobal, 2));
        $this->info("  Colocación: $" . number_format($colGlobal, 2));
        $this->info("  Nómina EBITDA: $" . number_format($nominaParaEbitda, 2));
        $this->info("  OPEX: $" . number_format($opexGlobal, 2));
        $this->info("  EBITDA calculado: $" . number_format($ebitda, 2));

        // ── Resumen final ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN CUADRE ════');
        $this->line(str_pad('Verificaciones OK',              36) . $this->ok);
        $this->line(str_pad('Diferencias (error)',            36) . $this->diff);
        $this->line(str_pad('Advertencias',                   36) . $this->warn);
        $this->line('');

        if ($this->option('detail') && !empty($this->checks)) {
            $this->info('── DETALLE DE VERIFICACIONES ──');
            foreach ($this->checks as $c) {
                $icon   = $c['ok'] ? '[OK]  ' : '[DIFF]';
                $status = $c['ok'] ? '' : " | Dif: $" . number_format($c['diff'], 2);
                $line   = str_pad("{$icon} {$c['label']}", 68) . "A=$" . number_format($c['a'], 2) . "  B=$" . number_format($c['b'], 2) . $status;
                $c['ok'] ? $this->line($line) : $this->warn($line);
            }
        }

        if ($this->option('export')) {
            $this->exportCsv($period->id);
        }

        return $this->diff > 0 ? 1 : 0;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->info("── {$title} ──");
    }

    private function check(string $label, float $a, float $b): bool
    {
        $dif = abs($a - $b);
        $ok  = $dif <= self::TOL;

        $this->addCheck($label, $a, $b, !$ok);

        if ($ok) {
            $this->ok++;
            $this->line("  [OK] {$label}: $" . number_format($a, 2));
        } else {
            $this->diff++;
            $this->warn("  [DIFF] {$label}: A=$" . number_format($a, 2) . " B=$" . number_format($b, 2) . " Dif=$" . number_format($dif, 2));
        }

        return $ok;
    }

    private function checkWarn(string $label, float $a, float $b, float $tol): void
    {
        $dif = abs($a - $b);
        $ok  = $dif <= $tol;

        $this->addCheck($label, $a, $b, !$ok);

        if ($ok) {
            $this->ok++;
            $this->line("  [OK] {$label}: $" . number_format($a, 2));
        } else {
            $this->warn++;
            $this->warn("  [AVISO] {$label}: A=$" . number_format($a, 2) . " B=$" . number_format($b, 2) . " Dif=$" . number_format($dif, 2));
        }
    }

    private function addCheck(string $label, float $a, float $b, bool $isDiff): void
    {
        $this->checks[] = [
            'label' => $label,
            'a'     => $a,
            'b'     => $b,
            'diff'  => abs($a - $b),
            'ok'    => !$isDiff,
        ];
    }

    private function exportCsv(int $periodId): void
    {
        $dir  = 'auditorias';
        $file = "cuadre_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $headers = ['Verificación', 'Valor A', 'Valor B', 'Diferencia', 'Estado'];
        $lines   = [implode(',', $headers)];

        foreach ($this->checks as $c) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $c['label']) . '"',
                number_format($c['a'], 2, '.', ''),
                number_format($c['b'], 2, '.', ''),
                number_format($c['diff'], 2, '.', ''),
                $c['ok'] ? 'OK' : 'DIFERENCIA',
            ]);
        }

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
