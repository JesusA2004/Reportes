<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class AuditRadiographyCommand extends Command
{
    protected $signature   = 'reportes:audit-radiography
                                {period_id}
                                {--compare-reference : comparar contra referencia Abril 2026}
                                {--full              : ejecutar todos los sub-comandos y mostrar su salida completa}';
    protected $description = 'Auditoría maestra de radiografía financiera: ejecuta todos los módulos y produce tabla de estados';

    // ── Referencias Abril 2026 ────────────────────────────────────────────────
    private const REF = [
        'valor_cartera'         => 39_130_054.53,
        'otorgamientos'         => 14_538_964.00,
        'recuperacion'          => 18_323_749.55,
        'mora_0_30'             => 1_057_765.00,
        'mora_31_60'            => 926_001.80,
        'mora_61_90'            => 913_414.71,
        'mora_91_120'           => 816_584.91,
        'envio_corporativo'     => 3_076_800.00,
        'gastos_operativos'     => 837_384.28,
        'nomina'                => 2_501_589.43,
        'gastos_totales'        => 3_222_315.50,
        'utilidad'              => 1_217_542.57,
        'saldo_inicial'         => 646_672.52,
        'saldo_final'           => 494_827.52,
        'interbranch_activos'   => 449_425.00,
        'interbranch_neto'      => 0.00,
        'rotacion_bajas'        => 8,
        'rotacion_promedio'     => 123,
        'rotacion_indice'       => 6.50,
    ];

    private const EXCL_CATS = [
        'Nómina y Capital Humano',
        'Envío de utilidad a corporativo',
        'Préstamos Intersucursales',
    ];

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
        $this->info("════════════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA MAESTRA RADIOGRAFÍA — {$period->label} (ID {$period->id})");
        $this->info("════════════════════════════════════════════════════════════════════════");

        // ── Calcular métricas del sistema ─────────────────────────────────────
        $m = $this->computeMetrics($period, $dataIds, $allPeriods);

        // ── Sub-comandos (--full muestra salida completa) ─────────────────────
        if ($this->option('full')) {
            $subCommands = [
                ['reportes:audit-income',             [$period->id, '--compare-reference' => true, '--by-concept' => true]],
                ['reportes:audit-placements',         [$period->id, '--compare-reference' => true, '--by-branch' => true, '--by-origin' => true]],
                ['reportes:audit-portfolio',          [$period->id]],
                ['reportes:audit-mora',               [$period->id, '--compare-reference' => true]],
                ['reportes:audit-expenses',           [$period->id, '--compare-reference' => true, '--by-concept' => true]],
                ['reportes:audit-payroll',            [$period->id, '--compare-reference' => true, '--by-branch' => true]],
                ['reportes:audit-payroll-employees',  [$period->id, '--compare-reference' => true]],
                ['reportes:audit-total-expenses',     [$period->id, '--compare-reference' => true, '--detail' => true]],
                ['reportes:audit-interbranch-loans',  [$period->id, '--compare-reference' => true, '--detail' => true]],
                ['reportes:audit-rotation',           [$period->id, '--compare-reference' => true, '--detail' => true]],
                ['reportes:audit-corporate-transfer', [$period->id, '--compare-reference' => true]],
                ['reportes:audit-cash-flow',          [$period->id, '--compare-reference' => true, '--detail' => true]],
            ];

            foreach ($subCommands as [$cmd, $args]) {
                $this->line('');
                $this->line(str_repeat('═', 70));
                $this->line("  ▶ php artisan {$cmd} {$period->id}");
                $this->line(str_repeat('═', 70));

                if (Artisan::call($cmd, $args) !== 0) {
                    $this->warn("  ⚠ Comando terminó con error.");
                }
                $this->line(Artisan::output());
            }
        }

        // ── Tabla resumen ─────────────────────────────────────────────────────
        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════════════");
        $this->info("  TABLA RESUMEN — " . ($this->option('compare-reference') ? 'CON REFERENCIA ABRIL 2026' : 'SIN REFERENCIA'));
        $this->info("════════════════════════════════════════════════════════════════════════");

        $rows = [
            //  [Módulo, sistema, ref_key, estado, acción]
            ['Valor cartera',          $m['valor_cartera'],      'valor_cartera',       null, null],
            ['Otorgamientos',          $m['otorgamientos'],      'otorgamientos',       null, null],
            ['Recuperación (Ingresos)',$m['recuperacion'],       'recuperacion',        null, null],
            ['Mora 0-30 días',         $m['mora_0_30'],          'mora_0_30',           null, null],
            ['Mora 31-60 días',        $m['mora_31_60'],         'mora_31_60',          null, null],
            ['Mora 61-90 días',        $m['mora_61_90'],         'mora_61_90',          null, null],
            ['Mora 91-120 días',       $m['mora_91_120'],        'mora_91_120',         null, null],
            ['Envío corporativo',      $m['envio_corporativo'],  'envio_corporativo',   null, null],
            ['Gastos operativos',      $m['gastos_operativos'],  'gastos_operativos',   null, null],
            ['Nómina y Capital Humano',$m['nomina'],             'nomina',              null, null],
            ['Gastos totales',         $m['gastos_totales'],     'gastos_totales',      null, null],
            ['Utilidad',               $m['utilidad'],           'utilidad',            null, null],
            ['Saldo inicial caja',     $m['saldo_inicial'],      'saldo_inicial',       null, null],
            ['Saldo final caja',       $m['saldo_final'],        'saldo_final',         null, null],
            ['Intersucursales activos',$m['interbranch_activos'],'interbranch_activos', null, null],
            ['Intersucursales neto',   $m['interbranch_neto'],   'interbranch_neto',    null, null],
            ['Rotación bajas',         $m['rotacion_bajas'],     'rotacion_bajas',      null, null],
            ['Rotación promedio',      $m['rotacion_promedio'],  'rotacion_promedio',   null, null],
            ['Rotación índice %',      $m['rotacion_indice'],    'rotacion_indice',     null, null],
        ];

        $hdr = str_pad('Módulo', 30) .
            str_pad('Sistema', 20) .
            ($this->option('compare-reference') ? str_pad('Referencia', 20) . str_pad('Diferencia', 16) : '') .
            'Estado';
        $this->line($hdr);
        $this->line(str_repeat('─', $this->option('compare-reference') ? 100 : 70));

        foreach ($rows as [$lbl, $sysVal, $refKey, , ]) {
            $ref   = self::REF[$refKey] ?? null;
            [$estado, $accion] = $this->classify($sysVal, $ref, $refKey);

            $sysStr = is_int($sysVal)
                ? (string)$sysVal
                : '$' . number_format((float)$sysVal, 2);

            if ($this->option('compare-reference') && $ref !== null) {
                $refStr  = is_int($ref) ? (string)$ref : '$' . number_format((float)$ref, 2);
                $diff    = (float)$sysVal - (float)$ref;
                $sign    = $diff >= 0 ? '+' : '';
                $diffStr = is_int($ref)
                    ? $sign . number_format(abs($diff), 2)
                    : $sign . '$' . number_format(abs($diff), 2);
                $line = str_pad(mb_substr($lbl, 0, 28), 30) .
                    str_pad($sysStr, 20) .
                    str_pad($refStr, 20) .
                    str_pad($diffStr, 16) .
                    $estado;
            } else {
                $line = str_pad(mb_substr($lbl, 0, 28), 30) .
                    str_pad($sysStr, 20) .
                    $estado;
            }

            if (str_starts_with($estado, 'OK')) $this->info("  {$line}");
            elseif (str_starts_with($estado, 'Casi')) $this->warn("  {$line}");
            else $this->error("  {$line}");
        }

        // ── Acciones recomendadas ─────────────────────────────────────────────
        $this->line('');
        $this->info('════ ACCIONES RECOMENDADAS ════');

        $actions = $this->buildActionList($m);
        if (empty($actions)) {
            $this->info('  ✓ Todos los módulos están correctos. Ejecutar reproceso final si hubo cambios.');
        } else {
            foreach ($actions as $i => $action) {
                $n = $i + 1;
                $this->line("  {$n}. {$action}");
            }
        }

        $this->line('');
        $this->line('  Reproceso final:');
        $this->line("    php artisan optimize:clear");
        $this->line("    php artisan run-database-update {$period->id} --force-reset");
        $this->line("    php artisan generar-radiografia {$period->id}");

        return 0;
    }

    private function computeMetrics(Period $period, array $dataIds, $allPeriods): array
    {
        // Valor cartera
        $valorCartera = (float) DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->sum('total_balance');

        // Otorgamientos
        $otorgamientos = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->sum('amount');

        // Recuperación
        $recuperacion = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->whereRaw("UPPER(COALESCE(concept,'')) NOT LIKE '%COBERTURA SAVEHEARTS%'")
            ->sum('total_amount');

        // Mora buckets
        $moraBuckets = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('days_overdue')
            ->where('days_overdue', '>', 0)
            ->select(
                DB::raw('SUM(CASE WHEN days_overdue BETWEEN 1  AND  30 THEN overdue_balance ELSE 0 END) as b0'),
                DB::raw('SUM(CASE WHEN days_overdue BETWEEN 31 AND  60 THEN overdue_balance ELSE 0 END) as b1'),
                DB::raw('SUM(CASE WHEN days_overdue BETWEEN 61 AND  90 THEN overdue_balance ELSE 0 END) as b2'),
                DB::raw('SUM(CASE WHEN days_overdue BETWEEN 91 AND 120 THEN overdue_balance ELSE 0 END) as b3')
            )
            ->first();

        // Envío corporativo
        $envio = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->sum(DB::raw('COALESCE(NULLIF(fe.paid_amount,0),fe.amount)'));

        // Gastos operativos
        $gastosOp = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->whereIn('fe.period_id', $dataIds)
            ->whereNotIn('fe.category', self::EXCL_CATS)
            ->sum(DB::raw('COALESCE(NULLIF(fe.paid_amount,0),fe.amount)'));

        // Nómina
        $nomina = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->sum('amount');

        $gastosTotal = $gastosOp + $nomina;

        // Saldo inicial / final
        $saldoInicial = (float)($period->saldo_inicial_caja ?? 0);
        $saldoFinal   = $period->saldo_final_caja !== null ? (float)$period->saldo_final_caja : 0.0;

        // Utilidad
        $utilidad = $saldoInicial + $recuperacion - $otorgamientos - $gastosTotal;

        // Préstamos intersucursales
        $interbranchActivos = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->whereIn('fe.period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(fe.category,'')) REGEXP 'INTERSUCURSAL|FONDEO|PRESTAMO INTERSUCURSAL'")
            ->sum(DB::raw('COALESCE(NULLIF(fe.paid_amount,0),fe.amount)'));
        $interbranchNeto = 0.0; // siempre es $0 para misma empresa

        // Rotación
        $currentEmps  = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->distinct()->pluck('employee_id')->toArray();
        $currentCount = count($currentEmps);

        $prevPeriod  = $allPeriods->filter(fn ($p) => $p->id < $period->id)->sortByDesc('id')->first();
        $bajas = 0; $prevCount = 0;
        if ($prevPeriod) {
            $prevWeeklyIds = $prevPeriod->resolveBaseWeeklyIds($allPeriods);
            $prevDataIds   = array_values(array_unique(array_merge(empty($prevWeeklyIds) ? [] : $prevWeeklyIds, [$prevPeriod->id])));
            $prevEmps      = DB::table('fact_noi_movements')->whereIn('period_id', $prevDataIds)->whereNotNull('employee_id')->distinct()->pluck('employee_id')->toArray();
            $prevCount     = count($prevEmps);
            $currentSet    = array_flip($currentEmps);
            foreach ($prevEmps as $emp) { if (!isset($currentSet[$emp])) $bajas++; }
        }
        $promedio = $prevCount > 0 ? (int) round(($prevCount + $currentCount) / 2) : $currentCount;
        $indice   = $promedio > 0 ? round($bajas / $promedio * 100, 2) : 0.0;

        return [
            'valor_cartera'        => $valorCartera,
            'otorgamientos'        => $otorgamientos,
            'recuperacion'         => $recuperacion,
            'mora_0_30'            => (float)($moraBuckets->b0 ?? 0),
            'mora_31_60'           => (float)($moraBuckets->b1 ?? 0),
            'mora_61_90'           => (float)($moraBuckets->b2 ?? 0),
            'mora_91_120'          => (float)($moraBuckets->b3 ?? 0),
            'envio_corporativo'    => $envio,
            'gastos_operativos'    => $gastosOp,
            'nomina'               => $nomina,
            'gastos_totales'       => $gastosTotal,
            'utilidad'             => $utilidad,
            'saldo_inicial'        => $saldoInicial,
            'saldo_final'          => $saldoFinal,
            'interbranch_activos'  => $interbranchActivos,
            'interbranch_neto'     => $interbranchNeto,
            'rotacion_bajas'       => $bajas,
            'rotacion_promedio'    => $promedio,
            'rotacion_indice'      => $indice,
        ];
    }

    private function classify(mixed $sys, mixed $ref, string $key): array
    {
        if ($ref === null) {
            return ['Sin referencia', ''];
        }

        $diff = abs((float)$sys - (float)$ref);

        if ($diff < 1) {
            return ['OK', ''];
        }

        // Tolerancias y causas conocidas por módulo
        $knownArchiveIssues = ['otorgamientos'];
        $knownMappingIssues = ['gastos_operativos', 'mora_0_30', 'mora_31_60', 'mora_61_90', 'mora_91_120'];
        $knownMissingSource = ['saldo_inicial', 'saldo_final'];
        $knownFormula       = ['utilidad', 'gastos_totales'];
        $knownManual        = ['envio_corporativo', 'rotacion_bajas', 'rotacion_promedio', 'rotacion_indice'];

        if (in_array($key, $knownMissingSource) && (float)$sys == 0) {
            return ['Falta fuente', 'Configurar en admin de periodos'];
        }
        if (in_array($key, $knownArchiveIssues) && $diff > 100_000) {
            return ['Diferencia por archivo/corte', 'Re-importar con archivo correcto'];
        }
        if (in_array($key, $knownMappingIssues) && $diff < 500_000) {
            return ['Mapping incorrecto', 'Revisar canonicalGastoConcept() / buckets mora'];
        }
        if (in_array($key, $knownFormula) && $diff > 1_000_000) {
            return ['Fórmula incorrecta', 'Revisar RadiographyWorkbookBuilder'];
        }
        if (in_array($key, $knownManual)) {
            return ['Requiere revisión manual', 'Comparar vs ACUMULADOS ABRIL SUCURSALES.xls'];
        }

        if ($diff < 5_000)   return ['Casi OK',              'Investigar delta menor'];
        if ($diff < 100_000) return ['Diferencia moderada',  'Auditar sub-módulo correspondiente'];

        return ['Diferencia alta', 'Ejecutar audit específico'];
    }

    private function buildActionList(array $m): array
    {
        $actions = [];
        $ref = self::REF;

        if ($m['saldo_inicial'] == 0) {
            $actions[] = "Configurar saldo_inicial_caja en el periodo (ref: \$" . number_format($ref['saldo_inicial'], 2) . ")";
        }
        if ($m['saldo_final'] == 0) {
            $actions[] = "Configurar saldo_final_caja en el periodo (ref: \$" . number_format($ref['saldo_final'], 2) . ")";
        }
        if (abs($m['otorgamientos'] - $ref['otorgamientos']) > 1_000) {
            $actions[] = "Re-importar Ministraciones con archivo que totalice \$14,670,661.24 en columna 'Monto desembolsado'";
        }
        if (abs($m['gastos_operativos'] - $ref['gastos_operativos']) > 1_000) {
            $actions[] = "Revisar categorización de gastos: Renta Oficina (\$" . number_format($ref['gastos_operativos'], 2) . " ref). Posible exclusión por LENDUS_PRESENT_CATS";
        }
        if (abs($m['nomina'] - $ref['nomina']) > 1_000) {
            $actions[] = "Investigar diferencias nómina: Pensión Alimenticia +\$2,646, Financiamiento Motos +\$423, conceptos inválidos +\$334";
        }
        if (abs($m['envio_corporativo'] - $ref['envio_corporativo']) > 1_000) {
            $actions[] = "Investigar envío corporativo: sistema \$" . number_format($m['envio_corporativo'], 2) . " vs ref \$" . number_format($ref['envio_corporativo'], 2) . " (faltan \$253,000)";
        }
        if (abs($m['recuperacion'] - $ref['recuperacion']) > 5_000) {
            $actions[] = "Auditar recuperación: ejecutar reportes:audit-income {period_id} --compare-reference --by-concept";
        }

        return $actions;
    }
}
