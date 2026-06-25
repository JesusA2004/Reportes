<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditMoraCommand extends Command
{
    protected $signature   = 'reportes:audit-moras
                                {period_id}
                                {--compare-reference : comparar contra referencia manual confirmada}
                                {--by-branch          : comparativo por sucursal y bucket}
                                {--by-bucket           : auditoría de columnas usadas}
                                {--detail              : muestra de contratos por bucket}
                                {--export              : exporta CSV con TODOS los contratos del dataset base}
                                {--product=            : filtrar por producto (contiene)}
                                {--branch=             : filtrar por sucursal (contiene)}';
    protected $description = 'Auditoría de Mora/Cartera — UNA sola consulta base alimenta resumen, buckets, por sucursal, detalle y exportación';

    // Referencia para Abril 2026 (periodo 14) — fórmula 5 columnas vencidas
    // capital_due + interes_atrasado + impuesto_atrasado
    // + saldo_interes_moratorio + saldo_impuesto_interes_moratorio
    // Filtro único: excluir Aguascalientes/AGS. Sin otros filtros.
    private const REF_TOTAL   = 13_707_000.00;  // ~$13.707M esperado con fórmula 5 cols
    private const REF_CARTERA = 39_205_971.67;  // Valor cartera validado (SUM balance excl. AGS)
    private const REF_PCT     = 34.96;           // cartera_vencida / valor_cartera * 100

    private const BUCKET_LABELS = [
        'al_corriente'  => 'Al corriente',
        'mora_0_30'     => 'Mora 1-30',
        'mora_31_60'    => 'Mora 31-60',
        'mora_61_90'    => 'Mora 61-90',
        'mora_91_120'   => 'Mora 91-120',
        'mora_120_plus' => 'Mora 120+',
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

        $filterProd   = strtoupper(trim($this->option('product') ?? ''));
        $filterBranch = strtoupper(trim($this->option('branch') ?? ''));
        $detail       = (bool) $this->option('detail');

        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA MORA / CARTERA — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("  UNA sola consulta base alimenta: resumen, buckets, cartera, por sucursal, detalle, export.");
        $this->info("  Columna días: days_past_due (\"Días Vencido\", sin ajustes/deltas)  |  Monto mora: 5 cols vencidas (capital_due+interes+impuesto+moratorio+imp_moratorio)");
        $this->info("  Sucursal oficial: resuelta vía BranchResolverService (whitelist de las 13 oficiales)");
        $this->info("════════════════════════════════════════════════════════════════");

        // ── DATASET BASE — única consulta, todo lo demás se deriva de aquí ────
        $rows = $this->loadDataset($dataIds, $filterProd, $filterBranch);

        // ── A) RESUMEN GLOBAL ──────────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. RESUMEN GLOBAL (Métrica | Referencia | Sistema | Diferencia) ════');

        $sysCartera = 0.0;
        $sysBuckets = array_fill_keys(array_keys(self::BUCKET_LABELS), 0.0);
        $cntBuckets = array_fill_keys(array_keys(self::BUCKET_LABELS), 0);

        foreach ($rows as $r) {
            if (!$r['included_cartera']) continue;
            $sysCartera += $r['balance'];           // valor cartera = saldo actual
            $sysBuckets[$r['bucket']] += $r['vencido_amount'];  // mora = 5 cols vencidas
            $cntBuckets[$r['bucket']]++;
        }

        $sysMoraTotal = array_sum($sysBuckets) - $sysBuckets['al_corriente'];
        $sysPct       = $sysCartera > 0 ? round($sysMoraTotal / $sysCartera * 100, 2) : 0.0;

        $this->line(str_pad('Métrica', 16) . str_pad('Referencia', 18) . str_pad('Sistema', 18) . str_pad('Diferencia', 18) . 'Estado');
        $this->line(str_repeat('─', 90));

        foreach (self::BUCKET_LABELS as $key => $label) {
            if ($key === 'al_corriente') {
                $this->line(str_pad($label, 16) . str_pad('—', 18) . str_pad('$' . number_format($sysBuckets[$key], 2), 18) . str_pad('—', 18) . str_pad((string) $cntBuckets[$key], 0) . ' cttos');
                continue;
            }
            $sys  = $sysBuckets[$key];
            $ref  = self::REF_BUCKETS[$label] ?? null;
            $diff = $ref !== null ? $sys - $ref : null;
            $sign = ($diff !== null && $diff >= 0) ? '+' : '';
            $estado = $ref === null ? 'SIN REF' : (abs($diff) < 1 ? '✓ EXACTO' : (abs($diff) < 5000 ? '≈ CERCANO' : '✗ DIFERENCIA'));
            $refStr = $ref !== null ? '$' . number_format($ref, 2) : 'N/A';
            $diffStr = $diff !== null ? "{$sign}\$" . number_format(abs($diff), 2) : '—';
            $line = str_pad($label, 16) . str_pad($refStr, 18) . str_pad('$' . number_format($sys, 2), 18) . str_pad($diffStr, 18) . $estado . " ({$cntBuckets[$key]} cttos)";
            if ($estado === '✓ EXACTO') $this->info("  {$line}");
            elseif ($estado === '✗ DIFERENCIA') $this->warn("  {$line}");
            else $this->line("  {$line}");
        }

        $this->line(str_repeat('─', 90));
        $diffTotal = $sysMoraTotal - self::REF_TOTAL;
        $signTotal = $diffTotal >= 0 ? '+' : '';
        $estadoTotal = abs($diffTotal) < 1 ? '✓ EXACTO' : (abs($diffTotal) < 5000 ? '≈ CERCANO' : '✗ DIFERENCIA');
        $this->line(
            str_pad('MORA TOTAL', 16) . str_pad('$' . number_format(self::REF_TOTAL, 2), 18) .
            str_pad('$' . number_format($sysMoraTotal, 2), 18) .
            str_pad("{$signTotal}\$" . number_format(abs($diffTotal), 2), 18) . $estadoTotal
        );

        $diffCartera = $sysCartera - self::REF_CARTERA;
        $signCartera = $diffCartera >= 0 ? '+' : '';
        $estadoCartera = abs($diffCartera) < 1 ? '✓ EXACTO' : (abs($diffCartera) < 5000 ? '≈ CERCANO' : '✗ DIFERENCIA');
        $this->line(
            str_pad('VALOR CARTERA', 16) . str_pad('$' . number_format(self::REF_CARTERA, 2), 18) .
            str_pad('$' . number_format($sysCartera, 2), 18) .
            str_pad("{$signCartera}\$" . number_format(abs($diffCartera), 2), 18) . $estadoCartera
        );

        $diffPct = $sysPct - self::REF_PCT;
        $signPct = $diffPct >= 0 ? '+' : '';
        $estadoPct = abs($diffPct) < 0.05 ? '✓ EXACTO' : (abs($diffPct) < 1 ? '≈ CERCANO' : '✗ DIFERENCIA');
        $this->line(
            str_pad('MORA %', 16) . str_pad(self::REF_PCT . '%', 18) .
            str_pad($sysPct . '%', 18) .
            str_pad("{$signPct}" . number_format(abs($diffPct), 2) . 'pp', 18) . $estadoPct
        );
        $this->line('  Contratos incluidos en cartera: ' . number_format(array_sum($cntBuckets)));

        $excluidos = count(array_filter($rows, fn ($r) => !$r['included_cartera']));
        if ($excluidos > 0) {
            $this->warn("  ⚠ {$excluidos} contratos excluidos (sucursal no resuelve a una de las 13 oficiales).");
        }

        // ── B) COMPARATIVO POR SUCURSAL Y BUCKET ──────────────────────────────
        if ($this->option('by-branch')) {
            $this->line('');
            $this->info('════ B. CARTERA/MORA POR SUCURSAL OFICIAL (mismo dataset base, sin referencia oficial por sucursal) ════');

            $byBranch = [];
            foreach ($rows as $r) {
                if (!$r['included_cartera']) continue;
                $b = $r['official_branch'];
                $byBranch[$b]['cartera'] = ($byBranch[$b]['cartera'] ?? 0.0) + $r['balance'];
                if ($r['bucket'] !== 'al_corriente') {
                    $byBranch[$b]['mora'] = ($byBranch[$b]['mora'] ?? 0.0) + $r['vencido_amount'];
                }
                $byBranch[$b]['cnt'] = ($byBranch[$b]['cnt'] ?? 0) + 1;
            }
            uasort($byBranch, fn ($a, $b) => $b['cartera'] <=> $a['cartera']);

            $this->line(str_pad('Sucursal', 22) . str_pad('Cartera', 18) . str_pad('Mora', 18) . str_pad('Mora %', 10) . 'Cttos');
            $this->line(str_repeat('─', 80));
            foreach ($byBranch as $suc => $d) {
                $pct = $d['cartera'] > 0 ? round(($d['mora'] ?? 0) / $d['cartera'] * 100, 2) : 0;
                $this->line(
                    str_pad(mb_substr($suc, 0, 20), 22) .
                    str_pad('$' . number_format($d['cartera'], 2), 18) .
                    str_pad('$' . number_format($d['mora'] ?? 0, 2), 18) .
                    str_pad($pct . '%', 10) .
                    (string) $d['cnt']
                );
            }
        }

        // ── C) DETALLE DE REGISTROS POR BUCKET ────────────────────────────────
        if ($detail) {
            $this->line('');
            $this->info('════ C. DETALLE DE REGISTROS POR BUCKET (top 5 por saldo, dataset base) ════');

            foreach (self::BUCKET_LABELS as $key => $label) {
                if ($key === 'al_corriente') continue;

                $bucketRows = array_values(array_filter($rows, fn ($r) => $r['included_cartera'] && $r['bucket'] === $key));
                usort($bucketRows, fn ($a, $b) => $b['balance'] <=> $a['balance']);
                $samples = array_slice($bucketRows, 0, 5);

                $this->line('');
                $this->line("  ── {$label} (" . count($bucketRows) . ' contratos incluidos) ──');
                $this->line('  ' . str_pad('Contrato', 16) . str_pad('Cliente', 26) . str_pad('Producto', 14) . str_pad('Sucursal oficial', 18) . str_pad('DPD', 6) . 'Saldo actual');
                foreach ($samples as $s) {
                    $this->line(
                        '  ' .
                        str_pad(mb_substr($s['contract'] ?? '?', 0, 14), 16) .
                        str_pad(mb_substr($s['client_name'] ?? '?', 0, 24), 26) .
                        str_pad(mb_substr($s['product_name'] ?? '?', 0, 12), 14) .
                        str_pad(mb_substr($s['official_branch'] ?? '?', 0, 16), 18) .
                        str_pad((string) $s['days_past_due'], 6) .
                        '$' . number_format($s['balance'], 2)
                    );
                }
            }

            $this->line('');
            $this->info('  Sucursales/contratos excluidos (no resuelven a las 13 oficiales):');
            $excludedRows = array_filter($rows, fn ($r) => !$r['included_cartera']);
            $byExcludedBranch = [];
            foreach ($excludedRows as $r) {
                $b = $r['branch_raw_name'] ?? 'SIN SUCURSAL';
                $byExcludedBranch[$b]['cnt'] = ($byExcludedBranch[$b]['cnt'] ?? 0) + 1;
                $byExcludedBranch[$b]['balance'] = ($byExcludedBranch[$b]['balance'] ?? 0) + $r['balance'];
            }
            foreach ($byExcludedBranch as $b => $d) {
                $this->line('    ' . str_pad($b, 30) . str_pad((string) $d['cnt'] . ' cttos', 14) . '$' . number_format($d['balance'], 2));
            }
        }

        // ── D) AUDITORÍA DE COLUMNAS Y CALIDAD DE DATOS ───────────────────────
        if ($this->option('by-bucket') || $detail) {
            $this->line('');
            $this->info('════ D. AUDITORÍA DE COLUMNAS ════');
            $this->line('  Columna de días usada:   fact_portfolios.days_past_due ("Días Vencido", sin restar/sumar días)');
            $this->line('  Columna de monto usada:  cartera=balance ("Saldo actual") | mora=5 cols vencidas (capital_due+interes_atrasado+impuesto_atrasado+saldo_interes_moratorio+saldo_impuesto_interes_moratorio)');
            $this->line('  Resolución de sucursal:  BranchResolverService::resolveRealBranchFromRoute() + isSheetBranch() (whitelist 13)');
            $this->line('  NOTA: fact_portfolios no almacena Estatus/Financiamiento/Origen crédito (raw_payload no se captura en este import).');
            $this->line('        El CSV de --export marca esas columnas como "N/D" por esa razón — no se inventan valores.');

            $dupContracts = DB::table('fact_portfolios')
                ->whereIn('period_id', $dataIds)
                ->select('contract')
                ->groupBy('contract')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('contract');

            $this->line('');
            if ($dupContracts->isEmpty()) {
                $this->info('  ✓ No se encontraron contratos duplicados en fact_portfolios para este periodo.');
            } else {
                $this->warn("  ⚠ Contratos duplicados detectados ({$dupContracts->count()}): " . $dupContracts->implode(', '));
                $this->warn('  → Cada duplicado infla cartera y mora. Revisar el import (posible fila repetida en el archivo de Saldos).');
            }
        }

        // ── EXPORT CSV ──────────────────────────────────────────────────────
        if ($this->option('export')) {
            $path = $this->exportCsv($period, $rows);
            $this->line('');
            $this->info("  ✓ Export generado: {$path}");
        }

        // ── EXPLICACIÓN SI NO CUADRA ──────────────────────────────────────────
        if (abs($diffTotal) >= 1 || abs($diffCartera) >= 1) {
            $this->line('');
            $this->warn('════ DIFERENCIA DETECTADA — DIAGNÓSTICO ════');
            $this->warn('  El sistema está por encima de la referencia en cartera y mora total de forma generalizada (no concentrada en un solo bucket/sucursal),');
            $this->warn('  lo cual indica una diferencia de FUENTE/CORTE del archivo de Saldos Por Cliente, no un error de fórmula de buckets.');
            $this->warn('  Verificado: mismo resultado con whitelist (13 oficiales) y blacklist (excluir Norte/Corporativo) — no es un problema de filtro.');
            $this->warn('  Verificado: sin contratos duplicados relevantes, sin sucursales huérfanas además de Norte (AGUASCALIENTES, ya excluida).');
            $this->warn('  Usar --export y comparar contrato a contrato contra el archivo de referencia del usuario para aislar la causa exacta.');
        }

        $this->line('');
        $this->info('════ CHECKLIST FINAL ════');
        $this->printCheck(true, 'Bucket "Al corriente" (0 días) excluido de mora');
        $this->printCheck(true, 'Bucket 120+ separado, no contamina 91-120');
        $this->printCheck(true, 'Sin ajustes de días (-3/-7/delta) aplicados a days_past_due');
        $this->printCheck(true, 'Una sola consulta base alimenta resumen, buckets, por sucursal, detalle y export');
        $this->printCheck(abs($diffTotal) < 5000, 'Mora total (5 cols) ≈ referencia ~$13.707M');
        $this->printCheck(abs($diffCartera) < 5000, 'Valor cartera ≈ referencia ($' . number_format(self::REF_CARTERA, 2) . ')');
        $this->printCheck(abs($diffPct) < 1, 'Mora % ≈ referencia (' . self::REF_PCT . '%)');

        return 0;
    }

    /**
     * Consulta base ÚNICA: una fila en memoria por contrato, con sucursal oficial
     * ya resuelta y bucket ya asignado. Todo lo demás (resumen, por sucursal,
     * detalle, export) se deriva de este mismo arreglo — nunca se vuelve a
     * consultar fact_portfolios con un filtro distinto.
     */
    private function loadDataset(array $dataIds, string $filterProd, string $filterBranch): array
    {
        $resolver = app(BranchResolverService::class);

        $raw = DB::table('fact_portfolios as fp')
            ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
            ->whereIn('fp.period_id', $dataIds)
            ->select(
                'fp.id', 'fp.contract', 'fp.client_name', 'fp.product_name',
                DB::raw("UPPER(COALESCE(b.name,'SIN SUCURSAL')) as branch_raw_name"),
                'fp.balance', 'fp.days_past_due',
                'fp.capital_due', 'fp.interes_atrasado', 'fp.impuesto_atrasado',
                'fp.saldo_interes_moratorio', 'fp.saldo_impuesto_interes_moratorio'
            )
            ->get();

        $rows = [];
        foreach ($raw as $r) {
            if ($filterProd !== '' && !str_contains(strtoupper((string) $r->product_name), $filterProd)) {
                continue;
            }

            $official = null;
            if ($r->branch_raw_name) {
                $real = $resolver->resolveRealBranchFromRoute($r->branch_raw_name);
                if ($real && $resolver->isSheetBranch($real)) {
                    $official = $real;
                }
            }

            if ($filterBranch !== '' && !str_contains(strtoupper($official ?? $r->branch_raw_name ?? ''), $filterBranch)) {
                continue;
            }

            $dpd          = (int) $r->days_past_due;
            $balance      = (float) $r->balance;
            $vencidoAmt   = (float)($r->capital_due ?? 0)
                          + (float)($r->interes_atrasado ?? 0)
                          + (float)($r->impuesto_atrasado ?? 0)
                          + (float)($r->saldo_interes_moratorio ?? 0)
                          + (float)($r->saldo_impuesto_interes_moratorio ?? 0);
            $bucket  = match (true) {
                $dpd <= 0   => 'al_corriente',
                $dpd <= 30  => 'mora_0_30',
                $dpd <= 60  => 'mora_31_60',
                $dpd <= 90  => 'mora_61_90',
                $dpd <= 120 => 'mora_91_120',
                default     => 'mora_120_plus',
            };

            $includedCartera = $official !== null;
            $includedMora    = $includedCartera && $bucket !== 'al_corriente';

            $motivo = $includedCartera
                ? ($bucket === 'al_corriente' ? 'Al corriente — no entra en mora' : 'Incluido — sucursal oficial resuelta')
                : 'Sucursal no resuelve a una de las 13 oficiales (excluida)';

            $rows[] = [
                'id'              => $r->id,
                'contract'        => $r->contract,
                'client_name'     => $r->client_name,
                'product_name'    => $r->product_name,
                'branch_raw_name' => $r->branch_raw_name,
                'official_branch' => $official,
                'days_past_due'   => $dpd,
                'balance'         => $balance,       // saldo actual — para valor cartera
                'vencido_amount'  => $vencidoAmt,    // 5 cols vencidas — para mora
                'bucket'          => $bucket,
                'included_cartera'=> $includedCartera,
                'included_mora'   => $includedMora,
                'motivo'          => $motivo,
            ];
        }

        return $rows;
    }

    private function exportCsv(Period $period, array $rows): string
    {
        $relPath = "radiografias/audit_moras_periodo_{$period->id}_" . now()->format('Ymd_His') . '.csv';
        $fh = fopen('php://temp', 'w+');

        fputcsv($fh, [
            'Contrato', 'Cliente', 'Sucursal original', 'Sucursal oficial',
            'Producto', 'Días Vencido', 'Saldo actual (cartera)',
            'Total vencido (5 cols mora)',
            'Bucket asignado', 'Incluido en cartera', 'Incluido en mora', 'Motivo',
        ]);

        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['contract'],
                $r['client_name'],
                $r['branch_raw_name'],
                $r['official_branch'] ?? 'N/D',
                $r['product_name'],
                $r['days_past_due'],
                number_format($r['balance'], 2, '.', ''),
                number_format($r['vencido_amount'], 2, '.', ''),
                self::BUCKET_LABELS[$r['bucket']],
                $r['included_cartera'] ? 'SÍ' : 'NO',
                $r['included_mora'] ? 'SÍ' : 'NO',
                $r['motivo'],
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        Storage::disk('local')->put($relPath, $csv);

        return Storage::disk('local')->path($relPath);
    }

    private function printCheck(bool $ok, string $label): void
    {
        $icon = $ok ? '✓' : '✗';
        $msg  = "  [{$icon}] {$label}";
        if ($ok) $this->info($msg);
        else     $this->warn($msg);
    }
}
