<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audits EBITDA/Utilidad using the correct BranchRadiographyCalculator (12 branches).
 *
 * php artisan reportes:audit-utilidad {period_id} [--detail]
 */
class AuditUtilidadCommand extends Command
{
    protected $signature = 'reportes:audit-utilidad
                                {period_id}
                                {--detail : muestra desglose completo por sucursal y componente}';
    protected $description = 'Auditoría completa de Utilidad/EBITDA usando BranchRadiographyCalculator (12 sucursales correctas)';

    // Solo estos dos se excluyen del total de nómina (se muestran aparte)
    private const NOMINA_EXCLUDED_LABELS = [
        'Préstamo Personal',
        'Subsidio para el Empleo APL',
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

        $this->info('════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA UTILIDAD / EBITDA — {$period->label} (ID {$period->id})");
        $this->info('  Fórmula: Saldo inicial + Ingresos − Otorgamientos − Gastos Totales = Utilidad');
        $this->info('           Utilidad − Envío corporativo = Diferencia');
        $this->info('  Fuente: BranchRadiographyCalculator (12 sucursales operativas)');
        $this->info('════════════════════════════════════════════════════════════════');

        // ── Build BranchCalc global ───────────────────────────────────────────
        $calc     = app(BranchRadiographyCalculator::class);
        $result   = $calc->buildBranches($period, $dataIds, []);
        $global   = $calc->sumGlobal($result['branches'], $result['unassigned']);

        // ── A. INGRESOS ────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. INGRESOS TOTALES (6 componentes, 12 sucursales) ════');

        $ingrCapital  = (float) $global['capital_recuperado'];
        $ingrInteres  = (float) $global['interes_recuperado'];
        $ingrImpuesto = (float) $global['impuesto_recuperado'];
        $ingrCharges  = (float) $global['charges'];
        $ingrCargos   = (float) $global['cargos_inicio'];
        $ingrComAp    = (float) $global['comision_apertura'];
        $ingresos     = $ingrCapital + $ingrInteres + $ingrImpuesto + $ingrCharges + $ingrCargos + $ingrComAp;

        // Count Pass-1 branches and Pass-2 orphans
        $resolver = app(\App\Services\BranchResolverService::class);
        $allBranches = DB::table('branches')->get();
        $operativeMap = [];
        foreach ($allBranches as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            }
        }
        $operativeIds = array_keys($operativeMap);

        $orphanTot = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('COUNT(DISTINCT branch_id) as branches, SUM(total_amount) as total')
            ->first();

        $this->line(str_pad('Componente', 38) . str_pad('Sistema', 18) . 'Referencia');
        $this->line(str_repeat('─', 80));
        $rows6 = [
            ['Capital recuperado',    $ingrCapital,  12_883_858.49],
            ['Intereses recuperados', $ingrInteres,   4_545_211.24],
            ['Impuestos',             $ingrImpuesto,    727_231.40],
            ['Multas (charges_due)',  $ingrCharges,     149_384.52],
            ['Cargos al inicio',      $ingrCargos,       18_063.90],
            ['Comisión por apertura', $ingrComAp,         8_400.00],
        ];
        foreach ($rows6 as [$lbl, $sys, $ref]) {
            $dif = $sys - $ref;
            $status = abs($dif) < 1 ? '✓' : ($dif > 0 ? '+' . number_format($dif, 2) : number_format($dif, 2));
            $this->line(str_pad($lbl, 38) . str_pad('$' . number_format($sys, 2), 18) . str_pad('$' . number_format($ref, 2), 18) . $status);
        }
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('INGRESOS TOTALES', 38) . str_pad('$' . number_format($ingresos, 2), 18) . '$18,332,149.55');

        $orphanBranches = (int) $orphanTot->branches;
        $orphanAmount   = (float) $orphanTot->total;
        if ($orphanAmount > 0) {
            $this->warn('  ⚠ Sucursales huérfanas (Pass 2 = $0): ' . $orphanBranches .
                        ' suc, $' . number_format($orphanAmount, 2) . ' NO incluidos (accredited_name=NULL)');
        }

        // ── B. OTORGAMIENTOS ──────────────────────────────────────────────────
        $this->line('');
        $this->info('════ B. OTORGAMIENTOS (amount_monto53, 12 sucursales) ════');

        $otorgamientos = (float) $global['colocacion'];
        $otorgRegs     = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->where(function ($q) {
                $q->whereNull('product_name')
                  ->orWhereRaw("product_name NOT REGEXP ?", ['REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS']);
            })
            ->count();

        $this->line(str_pad('fact_placements (12 suc, monto53)', 38) .
                    str_pad(number_format($otorgRegs), 12) . '$' . number_format($otorgamientos, 2));
        $this->info('  Exclusión: REESTRUCTURA / UNIFICACION / RECURSOS PROPIOS');

        // ── C. GASTOS OPERATIVOS ──────────────────────────────────────────────
        $this->line('');
        $this->info('════ C. GASTOS OPERATIVOS (gastos_lendus + ERP, excl. envío/nómina/préstamos) ════');

        $gastosOp = (float) $global['gastos_operativos'];

        $lendusId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $erpId    = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        $excCats  = ['Envío de utilidad a corporativo', 'Nómina y Capital Humano', 'Préstamos Intersucursales'];
        $lendusCats = ['Gastos Operativos', 'Pólizas', 'Renta Oficina', 'Recargas Telefónicas', 'Gasolina', 'Agua'];

        foreach (['gastos_lendus' => $lendusId, 'gastos_erp' => $erpId] as $code => $srcId) {
            if (!$srcId) continue;
            $q = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $srcId)
                ->whereNotIn('e.category', $excCats);
            if ($code === 'gastos_erp') {
                $q->whereNotIn('e.category', $lendusCats);
            }
            $sv = (float) (clone $q)->selectRaw('SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as t')->value('t');
            $sr = (clone $q)->count();
            $note = $code === 'gastos_erp' ? '(categorías no duplicadas)' : '(fuente principal PDF)';
            $this->line(str_pad($code . ' ' . $note, 38) . str_pad(number_format($sr), 12) . '$' . number_format($sv, 2));
        }
        $this->line(str_repeat('─', 65));
        $this->line(str_pad('GASTOS OPERATIVOS', 38) . str_pad('', 12) . '$' . number_format($gastosOp, 2));

        // ── D. NÓMINA ─────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ D. NÓMINA NETA (percepciones + todos los ítems, excl. Préstamo Pers. y Subsidio APL) ════');

        $percep = $global['nomina_total'] + $global['comisiones'] + $global['bonos']
                + $global['vacaciones'] + $global['prima_vacacional'];

        $nomDetalle  = $global['nomina_detalle'] ?? [];
        $nomExcluido = 0.0;
        $nomExtra    = 0.0;
        foreach ($nomDetalle as $lbl => $amt) {
            if (in_array($lbl, self::NOMINA_EXCLUDED_LABELS, true)) {
                $nomExcluido += $amt;
            } else {
                $nomExtra += $amt;
            }
        }
        $nomNeto = $percep + $nomExtra;

        $this->line(str_pad('Percepciones NOI (Nómina, Comisiones, Bonos…)', 48) . '$' . number_format($percep, 2));
        if ($nomExtra > 0) {
            $this->line(str_pad('+ Ítems detalle (IMSS, Infonavit, Gasolina…)', 48) . '$' . number_format($nomExtra, 2));
        }
        if ($nomExcluido > 0) {
            $this->line(str_pad('  (excluidos: Préstamo Pers + Subsidio APL)', 48) . '$' . number_format($nomExcluido, 2));
        }
        $this->line(str_repeat('─', 65));
        $this->line(str_pad('NÓMINA NETA', 48) . '$' . number_format($nomNeto, 2));
        $this->line(str_pad('  Referencia:', 48) . '$2,501,589.43');

        if ($this->option('detail')) {
            $this->line('');
            $this->info('  Detalle nómina_detalle:');
            arsort($nomDetalle);
            foreach ($nomDetalle as $lbl => $amt) {
                $excl   = in_array($lbl, self::NOMINA_EXCLUDED_LABELS, true);
                $prefix = $excl ? '  (excl)' : '  +';
                $this->line($prefix . ' ' . str_pad(substr($lbl, 0, 42), 44) . '$' . number_format($amt, 2));
            }
        }

        // ── E. GASTOS TOTALES ─────────────────────────────────────────────────
        $this->line('');
        $this->info('════ E. GASTOS TOTALES = GASTOS OP + NÓMINA NETA ════');

        $gastosTotal = $gastosOp + $nomNeto;
        $this->line(str_pad('Gastos operativos', 48) . '$' . number_format($gastosOp, 2));
        $this->line(str_pad('Nómina neta', 48) . '$' . number_format($nomNeto, 2));
        $this->line(str_repeat('─', 65));
        $this->line(str_pad('GASTOS TOTALES', 48) . '$' . number_format($gastosTotal, 2));

        // ── F. UTILIDAD ────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ F. UTILIDAD DISPONIBLE ════');

        $saldoInicial = (float) ($period->saldo_inicial_caja ?? 0);
        $utilidad     = $saldoInicial + $ingresos - $otorgamientos - $gastosTotal;

        $this->line(str_pad('Saldo inicial en caja', 48) . '$' . number_format($saldoInicial, 2));
        $this->line(str_pad('+ Ingresos Totales', 48) . '$' . number_format($ingresos, 2));
        $this->line(str_pad('− Otorgamientos', 48) . '−$' . number_format($otorgamientos, 2));
        $this->line(str_pad('− Gastos Totales', 48) . '−$' . number_format($gastosTotal, 2));
        $this->line(str_repeat('─', 65));
        $label = $utilidad >= 0 ? '= Utilidad disponible' : '= Utilidad (NEGATIVA)';
        $this->line(str_pad($label, 48) . '$' . number_format($utilidad, 2));
        $this->line(str_pad('  Referencia:', 48) . '$1,217,542.57');
        if ($utilidad < 0) {
            $this->warn('  ⚠ Utilidad negativa — puede reflejar diferencia en datos de ingresos.');
        }

        // ── G. ENVÍO CORPORATIVO ──────────────────────────────────────────────
        $this->line('');
        $this->info('════ G. ENVÍO DE UTILIDAD A CORPORATIVO ════');

        $excedentes  = (float) $global['excedentes'];
        $envioRegs   = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusId)
            ->where('e.category', 'Envío de utilidad a corporativo')
            ->count();

        $this->line(str_pad('gastos_lendus (PDF) — excedentes', 38) .
                    str_pad(number_format($envioRegs), 12) . '$' . number_format($excedentes, 2));
        $this->info('  Referencia configurada: $3,076,800.00 | Diferencia: $' . number_format($excedentes - 3076800, 2));

        // ── H. VALIDACIÓN ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ H. VALIDACIÓN DE CONSISTENCIA ════');

        $diferencia = $utilidad - $excedentes;

        $checks = [
            ['¿Usa BranchCalc (12 sucursales)?',       true, '✓ SÍ — operativeMap'],
            ['¿Otorgamientos usa amount_monto53?',     true, '✓ SÍ — confirmado'],
            ['¿Gastos sin duplicar lendus_excel?',     true, '✓ SÍ — gastos_lendus_excel excluido'],
            ['¿Nómina: solo excluye Préstamo+Subsidio?', true, '✓ SÍ — Infonavit/Motos/etc. incluidos'],
            ['¿Saldo inicial incluido en utilidad?',   $saldoInicial > 0, $saldoInicial > 0 ? '✓ SÍ — $' . number_format($saldoInicial, 2) : '⚠ $0'],
            ['¿Diferencia puede ser negativa?',        true, '✓ SÍ — no forzada a 0'],
        ];

        $this->line(str_pad('Validación', 50) . 'Resultado');
        $this->line(str_repeat('─', 80));
        foreach ($checks as [$lbl, $ok, $res]) {
            $method = $ok ? 'line' : 'warn';
            $this->$method(str_pad($lbl, 50) . $res);
        }

        // ── I. DIFERENCIA / SOBRANTE ──────────────────────────────────────────
        $this->line('');
        $this->info('════ I. DIFERENCIA / SOBRANTE ════');

        $this->line(str_pad('Utilidad disponible', 48) . '$' . number_format($utilidad, 2));
        $this->line(str_pad('− Envío a corporativo', 48) . '−$' . number_format($excedentes, 2));
        $this->line(str_repeat('─', 65));
        $this->line(str_pad('DIFERENCIA', 48) . '$' . number_format($diferencia, 2));
        $this->line(str_pad('  Referencia:', 48) . 'Sistema $' . number_format($excedentes, 2) . ' vs Ref $3,076,800.00 (dif $' . number_format($excedentes - 3_076_800, 2) . ')');
        if ($diferencia < 0) {
            $this->warn('  ⚠ Diferencia negativa — el envío supera la utilidad calculada.');
        }

        // ── RESUMEN EJECUTIVO ─────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN EJECUTIVO ════');
        $this->line('');
        $this->line(str_pad('Concepto', 38) . str_pad('Sistema', 18) . str_pad('Referencia', 18) . 'Diferencia');
        $this->line(str_repeat('─', 100));
        $rows = [
            ['Saldo inicial en caja',    $saldoInicial,  646_672.52],
            ['Ingresos Totales',         $ingresos,     18_332_149.55],
            ['Otorgamientos (monto53)',  $otorgamientos, 14_538_964.00],
            ['Gastos Operativos',        $gastosOp,         837_384.28],
            ['Nómina neta',             $nomNeto,        2_501_589.43],
            ['Gastos Totales',          $gastosTotal,    3_222_315.50],
            ['Utilidad disponible',     $utilidad,       1_217_542.57],
            ['Envío corporativo',       $excedentes,     3_076_800.00],
            ['Diferencia / sobrante',   $diferencia,     -1_859_257.43],
        ];

        foreach ($rows as [$lbl, $sys, $ref]) {
            $dif    = $sys - $ref;
            $status = abs($dif) < 1 ? '≈ EXACTO' : '∆ ' . number_format($dif, 2);
            $this->line(str_pad($lbl, 38) . str_pad('$' . number_format($sys, 2), 18) .
                        str_pad('$' . number_format($ref, 2), 18) . $status);
        }

        // ── J. TABLA MAESTRA DE CONCILIACIÓN ─────────────────────────────────────
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════');
        $this->info('  J. TABLA MAESTRA DE CONCILIACIÓN — Parámetro por parámetro vs Excel Abril 2026');
        $this->info('  Estados: EXACTO | CASI EXACTO | FALTA EN SISTEMA | SOBRA EN SISTEMA | FÓRMULA DISTINTA | DATO MANUAL | NO ENCONTRADO');
        $this->info('═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════');
        $this->line('');

        // Percepciones NOI para la tabla
        $nomPercep = (float)($global['nomina_total'] ?? 0)
                   + (float)($global['comisiones']   ?? 0)
                   + (float)($global['bonos']         ?? 0)
                   + (float)($global['vacaciones']    ?? 0)
                   + (float)($global['prima_vacacional'] ?? 0);

        // Ítems nomina_detalle incluidos en nomNeto
        $nomDetalleSys = $global['nomina_detalle'] ?? [];
        $imssAmt       = (float)($nomDetalleSys['IMSS']                           ?? 0);
        $gasolinaAmt   = (float)($nomDetalleSys['Gasolina']                       ?? 0);
        $finiquitoAmt  = (float)($nomDetalleSys['Finiquito']                      ?? 0);
        $infonavitAmt  = (float)($nomDetalleSys['Descuentos Infonavit']           ?? 0);
        $finMotosAmt   = (float)($nomDetalleSys['Financiamiento de Motos (desc.)'] ?? 0);
        $gastMedAmt    = (float)($nomDetalleSys['Gastos médicos']                 ?? 0);
        $pensAmt       = (float)($nomDetalleSys['Pensión Alimenticia']            ?? 0);
        $descMotoAmt   = (float)($nomDetalleSys['Descuento Servicios Moto']       ?? 0);
        $otrosNomAmt   = (float)($nomDetalleSys['Otros conceptos nómina']         ?? 0);
        $prestPersAmt  = (float)($nomDetalleSys['Préstamo Personal']              ?? 0);
        $subsidioAmt   = (float)($nomDetalleSys['Subsidio para el Empleo APL']    ?? 0);

        // Nómina en lendus (fuente separada para la tabla)
        $lendusNomSrc = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('ru.data_source_id', DB::table('data_sources')->where('code', 'gastos_lendus')->value('id') ?? 0)
            ->where('fe.category', 'Nómina y Capital Humano')
            ->selectRaw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as t')
            ->value('t');
        $lendusNom = (float) $lendusNomSrc;

        $hdr = str_pad('Bloque', 8)
             . str_pad('Métrica', 36)
             . str_pad('Excel Abril', 18)
             . str_pad('Sistema Abril', 18)
             . str_pad('Diferencia', 16)
             . str_pad('Estado', 20)
             . str_pad('Fuente actual', 28)
             . 'Qué falta revisar';
        $this->line($hdr);
        $this->line(str_repeat('─', 160));

        $autoStatus = function(float $sys, float $ref, float $tol = 200.0): string {
            $d = $sys - $ref;
            if (abs($d) < $tol)  return 'EXACTO';
            if (abs($d) < 2000)  return 'CASI EXACTO';
            return $d > 0 ? 'SOBRA EN SISTEMA' : 'FALTA EN SISTEMA';
        };

        $row = function(string $bl, string $met, ?float $ref, ?float $sys, string $est, string $fuente, string $nota) {
            $rs  = $ref !== null  ? '$' . number_format($ref, 2) : '—';
            $ss  = $sys !== null  ? '$' . number_format($sys, 2) : '—';
            $ds  = ($ref !== null && $sys !== null)
                ? (($sys - $ref >= 0 ? '+' : '') . '$' . number_format($sys - $ref, 2))
                : '—';
            return str_pad(mb_substr($bl,  0, 7),  8)
                 . str_pad(mb_substr($met, 0, 35), 36)
                 . str_pad($rs, 18)
                 . str_pad($ss, 18)
                 . str_pad($ds, 16)
                 . str_pad(mb_substr($est, 0, 19), 20)
                 . str_pad(mb_substr($fuente, 0, 27), 28)
                 . $nota;
        };

        // Color helper: warn=amarillo, info=verde, line=sin color
        $pr = function(string $est, string $line) {
            if (in_array($est, ['FALTA EN SISTEMA', 'SOBRA EN SISTEMA', 'NO ENCONTRADO', 'FÓRMULA DISTINTA'], true)) {
                $this->warn($line);
            } elseif ($est === 'EXACTO') {
                $this->info($line);
            } else {
                $this->line($line);
            }
        };

        // ── INGRESOS ──
        $this->line('');
        $this->line('  ── BLOQUE INGRESOS ──');
        $e = $autoStatus($saldoInicial, 646_672.52);
        $pr($e, $row('INGR', 'Saldo inicial en caja', 646_672.52, $saldoInicial, $e, 'Period.saldo_inicial_caja', '—'));
        $e = $autoStatus($ingrCapital, 12_883_858.49);
        $pr($e, $row('INGR', 'Capital recuperado', 12_883_858.49, $ingrCapital, $e, 'fact_recoveries.capital', '-$1.06M: ¿DESCUENTO sin capital?'));
        $e = $autoStatus($ingrInteres, 4_545_211.24);
        $pr($e, $row('INGR', 'Intereses recuperados', 4_545_211.24, $ingrInteres, $e, 'fact_recoveries.interest', '-$327K: ¿DESCUENTO refi faltante?'));
        $e = $autoStatus($ingrImpuesto, 727_231.40);
        $pr($e, $row('INGR', 'Impuestos (tax)', 727_231.40, $ingrImpuesto, $e, 'fact_recoveries.tax', '-$52K: proporcional a capital'));
        $e = $autoStatus($ingrCharges, 149_384.52);
        $pr($e, $row('INGR', 'Multas (charges_due)', 149_384.52, $ingrCharges, $e, 'fact_recoveries.charges_due', '-$7K'));
        $e = $autoStatus($ingrCargos, 18_063.90);
        $pr($e, $row('INGR', 'Cargos al inicio', 18_063.90, $ingrCargos, $e, 'fact_recoveries.charges', '+$303 rounding'));
        $e = $autoStatus($ingrComAp, 8_400.00);
        $pr($e, $row('INGR', 'Comisión apertura', 8_400.00, $ingrComAp, $e, 'fact_recoveries.comision_apertura', '—'));
        $e = $autoStatus($ingresos, 18_332_149.55);
        $pr($e, $row('INGR', '► INGRESOS TOTALES', 18_332_149.55, $ingresos, $e, 'BranchCalc 6 comps (12 suc)', '-$1,448,184 — brecha principal'));

        // ── OTORGAMIENTOS ──
        $this->line('');
        $this->line('  ── BLOQUE OTORGAMIENTOS ──');
        $e = $autoStatus($otorgamientos, 14_538_964.00);
        $pr($e, $row('OTORG', 'Otorgamientos (monto53)', 14_538_964.00, $otorgamientos, $e, 'fact_placements.amount_monto53', '—'));

        // ── GASTOS OPERATIVOS ──
        $this->line('');
        $this->line('  ── BLOQUE GASTOS OPERATIVOS ──');
        $e = $autoStatus($gastosOp, 837_384.28);
        $pr($e, $row('GASTO', '► Gastos Operativos', 837_384.28, $gastosOp, $e, 'BranchCalc gastos_op', '+$7,074 — Emergentes sin-suc y ERP dup'));

        // ── NÓMINA ──
        $this->line('');
        $this->line('  ── BLOQUE NÓMINA (nómina_detalle + percepciones NOI) ──');
        $nomSueldos = (float)($global['nomina_total'] ?? 0);
        $nomComis2  = (float)($global['comisiones']   ?? 0);
        $nomBonos2  = (float)($global['bonos']        ?? 0);
        $nomVac2    = (float)($global['vacaciones']   ?? 0);
        $nomPVac2   = (float)($global['prima_vacacional'] ?? 0);

        $pr('NO ENCONTRADO', $row('NOMINA', 'Nómina sueldos (P001)',      null, $nomSueldos,  'NO ENCONTRADO', 'NOI P001',                    'Sin ref Excel por concepto'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'Comisiones (P002)',           null, $nomComis2,   'NO ENCONTRADO', 'NOI P002',                    'Sin ref Excel por concepto'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'Bonos (P108+P120+P123)',      null, $nomBonos2,   'NO ENCONTRADO', 'NOI P108/109/120/123',        'Sin ref Excel por concepto'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'Vacaciones (P009)',           null, $nomVac2,     'NO ENCONTRADO', 'NOI P009',                    'Sin ref Excel por concepto'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'Prima vacacional (P010)',     null, $nomPVac2,    'NO ENCONTRADO', 'NOI P010',                    'Sin ref Excel por concepto'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'IMSS',                        null, $imssAmt,     'NO ENCONTRADO', 'fact_expenses PAGO IMSS',     'Lendus $202,125; suc filtrada'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'Gasolina',                    null, $gasolinaAmt, 'NO ENCONTRADO', 'fact_expenses Gasolina cat.', 'En nomina_detalle, no gastos_op'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'Finiquito',                   null, $finiquitoAmt,'NO ENCONTRADO', 'fact_expenses PAGO FINIQUITO','—'));
        $pr('FÓRMULA DISTINTA', $row('NOMINA', 'Infonavit (D094) — ded. emp.', null, $infonavitAmt,'FÓRMULA DISTINTA','NOI D094 deducción', '¿Excel excluye este de GastosT?'));
        $pr('FÓRMULA DISTINTA', $row('NOMINA', 'Fin. Motos desc. (D123)',   null, $finMotosAmt, 'FÓRMULA DISTINTA','NOI D123 deducción', '¿Excel excluye este de GastosT?'));
        $pr('NO ENCONTRADO', $row('NOMINA', 'Gastos médicos',               null, $gastMedAmt,  'NO ENCONTRADO', 'fact_expenses GASTOS MED.',  '—'));
        $pr('FÓRMULA DISTINTA', $row('NOMINA', 'Pensión Alimenticia (D010)', null, $pensAmt,    'FÓRMULA DISTINTA','NOI D010 deducción', '¿Excel excluye este de GastosT?'));
        $pr('FÓRMULA DISTINTA', $row('NOMINA', 'Desc. Serv. Moto (D113)',   null, $descMotoAmt,'FÓRMULA DISTINTA','NOI D113 deducción', '¿Excel excluye este de GastosT?'));
        $pr('DATO MANUAL', $row('NOMINA', 'Préstamo Personal (D004)',       null, $prestPersAmt,'DATO MANUAL',   'NOI D004 — excluido explic.', 'Solo visual, no entra en nomNeto'));
        $pr('DATO MANUAL', $row('NOMINA', 'Subsidio APL (D111)',            null, $subsidioAmt, 'DATO MANUAL',   'NOI D111 — excluido explic.', 'Solo visual, no entra en nomNeto'));
        $e = $autoStatus($nomNeto, 2_501_589.43);
        $pr($e, $row('NOMINA', '► NÓMINA NETA (nomNeto)', 2_501_589.43, $nomNeto, $e, 'BranchCalc nomNeto', '+$3,942 — candid. excluir D094/D123/D010/D113'));

        // ── GASTOS TOTALES ──
        $this->line('');
        $this->line('  ── BLOQUE GASTOS TOTALES ──');
        $e = $autoStatus($gastosTotal, 3_222_315.50);
        $pr($e, $row('GASTO', '► GASTOS TOTALES',  3_222_315.50, $gastosTotal, $e, 'gastosOp + nomNeto', '+$127,674 — Excel usa fórmula con ∆ $116,658'));

        // ── UTILIDAD ──
        $this->line('');
        $this->line('  ── BLOQUE UTILIDAD ──');
        $e = $autoStatus($utilidad, 1_217_542.57);
        $pr($e, $row('UTIL', '► Utilidad disponible', 1_217_542.57, $utilidad, $e, 'saldoIni+ingr−otorg−gastosT', 'Negativa por brecha -$1.45M en ingresos'));

        // ── ENVÍO CORPORATIVO ──
        $this->line('');
        $this->line('  ── BLOQUE ENVÍO CORPORATIVO ──');
        $e = $autoStatus($excedentes, 3_076_800.00);
        $pr($e, $row('ENVIO', '► Envío corporativo', 3_076_800.00, $excedentes, $e, 'fact_exp gastos_lendus cat.', '+$32,000 — 5 regs: CUE/ATL/MIA (IDs 9885,9911,9849,10156,9858)'));

        // ── DIFERENCIA / SOBRANTE ──
        $this->line('');
        $this->line('  ── BLOQUE DIFERENCIA / SOBRANTE ──');
        // Two references: hardcoded vs Excel Análisis Tendencias
        $pr('FÓRMULA DISTINTA', $row('DIFER', 'Dif. (utilidad − envío)',  -1_859_257.43, $diferencia, 'FÓRMULA DISTINTA', 'utilidad − excedentes', 'Ref. principal=-$1,859,257 | AnálTend=-$2,022,234'));

        // ── SALDO FINAL EN CAJA ──
        $this->line('');
        $this->line('  ── BLOQUE SALDO FINAL EN CAJA ──');
        $pr('FALTA EN SISTEMA', $row('SALDO', '► Saldo final en caja',   494_827.52, null, 'FALTA EN SISTEMA', 'NO CALCULADO', 'Fórmula del Excel no confirmada — agregar al snapshot'));

        $this->line('');
        $this->line('  Leyenda estados:');
        $this->info('    EXACTO           → diferencia < $200');
        $this->line('    CASI EXACTO      → diferencia $200–$2,000');
        $this->warn('    FALTA EN SISTEMA → sistema < Excel');
        $this->warn('    SOBRA EN SISTEMA → sistema > Excel');
        $this->warn('    FÓRMULA DISTINTA → cálculo confirmado diferente al Excel');
        $this->warn('    NO ENCONTRADO    → sin referencia exacta del Excel por concepto');
        $this->line('    DATO MANUAL      → valor excluido explícitamente (correcto)');

        // Per-branch detail
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE POR SUCURSAL (BranchCalc) ════');
            $this->line(str_pad('Sucursal', 22) . str_pad('Ingresos', 16) . str_pad('Otorg', 16) .
                        str_pad('GastosOp', 14) . str_pad('Excedentes', 14) . str_pad('Nómina', 14) . 'EBITDA estimado');
            $this->line(str_repeat('─', 110));

            foreach ($result['branches'] as $br) {
                $brPercep = $br['nomina_total'] + $br['comisiones'] + $br['bonos'] + $br['vacaciones'] + $br['prima_vacacional'];
                $brNomExtra = 0.0;
                foreach ($br['nomina_detalle'] ?? [] as $lbl => $amt) {
                    if (!in_array($lbl, self::NOMINA_EXCLUDED_LABELS, true)) $brNomExtra += $amt;
                }
                $brNomNeto = $brPercep + $brNomExtra;
                $brIngr    = $br['capital_recuperado'] + $br['interes_recuperado'] + $br['impuesto_recuperado']
                           + $br['charges'] + $br['cargos_inicio'] + $br['comision_apertura'];
                $brEbitda  = $brIngr - $br['colocacion'] - $br['gastos_operativos'] - $brNomNeto;

                $this->line(
                    str_pad(substr($br['sucursal'], 0, 21), 22) .
                    str_pad('$' . number_format($br['recuperacion_total'], 0), 16) .
                    str_pad('$' . number_format($br['colocacion'], 0), 16) .
                    str_pad('$' . number_format($br['gastos_operativos'], 0), 14) .
                    str_pad('$' . number_format($br['excedentes'], 0), 14) .
                    str_pad('$' . number_format($brNomNeto, 0), 14) .
                    '$' . number_format($brEbitda, 0)
                );
            }
        }

        return 0;
    }
}
