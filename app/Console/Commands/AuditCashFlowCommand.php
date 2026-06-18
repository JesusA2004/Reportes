<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditCashFlowCommand extends Command
{
    protected $signature   = 'reportes:audit-cash-flow
                                {period_id}
                                {--compare-reference : comparar contra referencia Abril 2026}
                                {--detail            : mostrar desglose de cada componente}';
    protected $description = 'Auditoría de flujo de caja: saldo inicial, utilidad, envío corporativo, diferencia';

    // Referencia Abril 2026
    private const REF = [
        'saldo_inicial'       => 646_672.52,
        'ingresos_totales'    => 18_332_149.55,
        'otorgamientos'       => 13_351_612.00,
        'gastos_totales'      => 3_222_315.50,
        'utilidad'            => 2_404_894.57,
        'saldo_final'         => 494_827.52,
        'envio_corporativo'   => 3_076_800.00,
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
        $this->info("════════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA FLUJO DE CAJA — {$period->label} (ID {$period->id})");
        $this->info("  Fórmula: Utilidad = Saldo inicial + Ingresos − Otorgamientos − Gastos");
        $this->info("════════════════════════════════════════════════════════════════════");

        $lendusId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');

        // ── Saldo inicial ─────────────────────────────────────────────────────
        $saldoInicial = (float)($period->saldo_inicial_caja ?? 0);
        $saldoFinal   = $period->saldo_final_caja !== null ? (float)$period->saldo_final_caja : null;

        // ── Ingresos (capital + interés + impuesto + cargos + comisión apertura) ──
        $ingresos = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->whereRaw("UPPER(COALESCE(concept, '')) NOT LIKE '%COBERTURA SAVEHEARTS%'")
            ->sum('total_amount');

        $comisionApertura = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(operation, '')) LIKE '%COMISION POR APERTURA%'")
            ->sum('total_amount');

        $ingresosTotal = $ingresos + $comisionApertura;

        // ── Otorgamientos (colocación, KPI = Monto desembolsado; Seguro CRECE/COMADRES NO se resta) ──
        $otorgamientos = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->sum('amount');

        // ── Gastos operativos (excl. Nómina, Excedentes, Fondeo) ─────────────
        $excCats = ['Nómina y Capital Humano', 'Envío de utilidad a corporativo', 'Préstamos Intersucursales'];
        $gastosOp = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->whereIn('fe.period_id', $dataIds)
            ->whereNotIn('fe.category', $excCats)
            ->sum(DB::raw('COALESCE(NULLIF(fe.paid_amount,0),fe.amount)'));

        // ── Nómina neta (NOI percepciones) ───────────────────────────────────
        $nominaNeta = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('percepcion','deduccion','descuento')")
            ->sum('amount');

        $gastosTotal = $gastosOp + $nominaNeta;

        // ── Envío corporativo ─────────────────────────────────────────────────
        $envio = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->sum(DB::raw('COALESCE(NULLIF(fe.paid_amount,0),fe.amount)'));

        // ── Utilidad ─────────────────────────────────────────────────────────
        $utilidad = $saldoInicial + $ingresosTotal - $otorgamientos - $gastosTotal;

        // ── Output ───────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ FLUJO DE CAJA CALCULADO ════');

        $items = [
            ['Saldo inicial en caja',        $saldoInicial,   self::REF['saldo_inicial']],
            ['+ Ingresos Totales',           $ingresosTotal,  self::REF['ingresos_totales']],
            ['  (Recuperación depurada)',     $ingresos,       null],
            ['  (Comisión por apertura)',     $comisionApertura, null],
            ['− Otorgamientos',              $otorgamientos,  self::REF['otorgamientos']],
            ['− Gastos Totales',             $gastosTotal,    self::REF['gastos_totales']],
            ['  (Gastos operativos)',         $gastosOp,       null],
            ['  (Nómina neta)',              $nominaNeta,     null],
            ['= Utilidad',                   $utilidad,       self::REF['utilidad']],
            ['Saldo final en caja',          $saldoFinal ?? 0.0, self::REF['saldo_final']],
            ['Envío utilidad corporativo',   $envio,          self::REF['envio_corporativo']],
        ];

        $this->line(str_pad('Concepto', 36) . str_pad('Sistema', 20) . ($this->option('compare-reference') ? str_pad('Referencia', 20) . 'Diferencia' : ''));
        $this->line(str_repeat('─', $this->option('compare-reference') ? 80 : 56));

        foreach ($items as [$lbl, $sys, $ref]) {
            $isDetail = str_starts_with($lbl, '  (');
            $sysStr = '$' . number_format($sys, 2);
            if ($this->option('compare-reference') && $ref !== null) {
                $diff = $sys - $ref;
                $sign = $diff >= 0 ? '+' : '';
                $match = abs($diff) < 1 ? '✓' : '✗';
                $line = str_pad($lbl, 36) .
                    str_pad($sysStr, 20) .
                    str_pad('$' . number_format($ref, 2), 20) .
                    "{$sign}$" . number_format(abs($diff), 2) . " {$match}";
                if ($match === '✓') $this->info("  {$line}");
                elseif ($isDetail) $this->line("    {$line}");
                else $this->warn("  {$line}");
            } else {
                $line = str_pad($lbl, 36) . $sysStr;
                if ($isDetail) $this->line("    {$line}");
                else $this->line("  {$line}");
            }
        }

        // ── Checks ────────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ CHECKLIST ════');
        $this->printCheck($saldoInicial > 0, "Saldo inicial > 0 (\${$saldoInicial})");
        $this->printCheck($saldoFinal !== null, 'Saldo final configurado en el periodo (saldo_final_caja)');
        $this->printCheck(abs($otorgamientos - self::REF['otorgamientos']) < 1, 'Otorgamientos = $' . number_format(self::REF['otorgamientos'], 2));
        $this->printCheck(abs($ingresosTotal - self::REF['ingresos_totales']) < 10, 'Ingresos totales ≈ $' . number_format(self::REF['ingresos_totales'], 2));
        $this->printCheck(abs($envio - self::REF['envio_corporativo']) < 1, 'Envío corporativo = $' . number_format(self::REF['envio_corporativo'], 2));
        $this->printCheck(abs($utilidad - self::REF['utilidad']) < 100, 'Utilidad ≈ $' . number_format(self::REF['utilidad'], 2));

        if ($saldoInicial == 0) {
            $this->line('');
            $this->warn('  → Configurar saldo_inicial_caja en el periodo. Usar el admin de periodos o:');
            $this->warn("  → php artisan tinker => Period::find({$period->id})->update(['saldo_inicial_caja' => 646672.52])");
        }
        if ($saldoFinal === null) {
            $this->warn('  → Configurar saldo_final_caja en el periodo:');
            $this->warn("  → php artisan tinker => Period::find({$period->id})->update(['saldo_final_caja' => 494827.52])");
        }

        return 0;
    }

    private function printCheck(bool $ok, string $label): void
    {
        $icon = $ok ? '✓' : '✗';
        $msg  = "  [{$icon}] {$label}";
        if ($ok) $this->info($msg);
        else     $this->warn($msg);
    }
}
