<?php

namespace App\Console\Commands;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditOperationalPeriodsCommand extends Command
{
    protected $signature = 'reportes:audit-operational-periods {year}';
    protected $description = 'Valida la cadena continua de meses operativos: sin huecos, sin duplicados, sin semanas huérfanas';

    private const EXPECTED_RANGES_2026 = [
        4  => ['start' => '2026-03-30', 'end' => '2026-04-26', 'label' => 'Abril'],
        5  => ['start' => '2026-04-27', 'end' => '2026-05-24', 'label' => 'Mayo'],
        6  => ['start' => '2026-05-25', 'end' => '2026-06-21', 'label' => 'Junio'],
        7  => ['start' => '2026-06-22', 'end' => '2026-07-19', 'label' => 'Julio'],
        8  => ['start' => '2026-07-20', 'end' => '2026-08-16', 'label' => 'Agosto'],
        9  => ['start' => '2026-08-17', 'end' => '2026-09-13', 'label' => 'Septiembre'],
        10 => ['start' => '2026-09-14', 'end' => '2026-10-11', 'label' => 'Octubre'],
        11 => ['start' => '2026-10-12', 'end' => '2026-11-08', 'label' => 'Noviembre'],
        12 => ['start' => '2026-11-09', 'end' => '2026-12-06', 'label' => 'Diciembre'],
    ];

    public function handle(): int
    {
        $year = (int) $this->argument('year');

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA DE PERIODOS OPERATIVOS — {$year}");
        $this->info('══════════════════════════════════════════════════════════════════');

        $monthlyPeriods = Period::query()
            ->where('type', 'monthly')
            ->where('year', $year)
            ->orderBy('month')
            ->get();

        $allWeekly = Period::query()
            ->where('type', 'weekly')
            ->where(function ($q) use ($year) {
                $q->where('year', $year)
                  ->orWhere(function ($q2) use ($year) {
                      // Include Dec of previous year (start may be in prev year)
                      $q2->where('year', $year - 1)->where('month', 12);
                  });
            })
            ->orderBy('start_date')
            ->get();

        // ── 1. Tabla de meses operativos ─────────────────────────────────────
        $this->line('');
        $this->line('  MESES OPERATIVOS CONFIGURADOS:');
        $this->line('');

        $rows   = [];
        $errors = [];
        $allUsedIds = collect();

        foreach ($monthlyPeriods as $monthly) {
            $componentIds = collect($monthly->component_period_ids ?? [])
                ->map(fn ($id) => (int) $id);

            $componentWeeks = $allWeekly->whereIn('id', $componentIds->all());
            $weekCount = $componentWeeks->count();
            $days = null;
            $status = '✅ OK';

            if ($monthly->start_date && $monthly->end_date) {
                $days = $monthly->start_date->diffInDays($monthly->end_date) + 1;
            }

            // Check for duplicates with previously processed months
            $duplicates = $componentIds->intersect($allUsedIds);
            if ($duplicates->isNotEmpty()) {
                $status = '❌ SEMANAS DUPLICADAS';
                $errors[] = "Mes {$monthly->month} ({$monthly->name}): semanas duplicadas con meses anteriores: IDs " . $duplicates->implode(', ');
            }

            // Check expected range for 2026
            if ($year === 2026 && isset(self::EXPECTED_RANGES_2026[$monthly->month])) {
                $expected = self::EXPECTED_RANGES_2026[$monthly->month];
                $actualStart = optional($monthly->start_date)->format('Y-m-d');
                $actualEnd   = optional($monthly->end_date)->format('Y-m-d');

                if ($actualStart !== $expected['start'] || $actualEnd !== $expected['end']) {
                    $status = '⚠️  RANGO INCORRECTO';
                    $errors[] = "Mes {$monthly->month} ({$monthly->name}): "
                        . "esperado {$expected['start']} → {$expected['end']}, "
                        . "actual {$actualStart} → {$actualEnd}";
                }
            }

            $allUsedIds = $allUsedIds->concat($componentIds);

            $rows[] = [
                'Mes'      => sprintf('%02d', $monthly->month) . " {$monthly->name}",
                'Inicio'   => optional($monthly->start_date)->format('Y-m-d') ?? '—',
                'Fin'      => optional($monthly->end_date)->format('Y-m-d') ?? '—',
                'Semanas'  => $weekCount,
                'Días'     => $days ?? '—',
                'Estado'   => $status,
            ];
        }

        $this->table(
            ['Mes', 'Inicio', 'Fin', 'Semanas', 'Días', 'Estado'],
            $rows
        );

        // ── 2. Huecos entre meses operativos ─────────────────────────────────
        $this->line('');
        $this->line('  VERIFICACIÓN DE HUECOS:');

        $sortedMonthly = $monthlyPeriods->sortBy('start_date')->values();
        $gapErrors = [];

        for ($i = 1; $i < $sortedMonthly->count(); $i++) {
            $prev = $sortedMonthly[$i - 1];
            $curr = $sortedMonthly[$i];

            if (!$prev->end_date || !$curr->start_date) continue;

            $expectedNext = $prev->end_date->copy()->addDay();
            if (!$expectedNext->isSameDay($curr->start_date)) {
                $gap = $prev->end_date->diffInDays($curr->start_date) - 1;
                $gapErrors[] = "  ❌ HUECO de {$gap} día(s) entre {$prev->name} "
                    . "({$prev->end_date->format('Y-m-d')}) y {$curr->name} ({$curr->start_date->format('Y-m-d')})";
                $errors[] = "Hueco entre {$prev->name} y {$curr->name}";
            }
        }

        if (empty($gapErrors)) {
            $this->line('  ✅ Sin huecos entre meses operativos.');
        } else {
            foreach ($gapErrors as $msg) {
                $this->error($msg);
            }
        }

        // ── 3. Semanas huérfanas (no asignadas, dentro del rango activo) ─────
        $this->line('');
        $this->line('  SEMANAS HUÉRFANAS (no asignadas a ningún mes operativo):');

        $firstMonthly = $monthlyPeriods->sortBy('start_date')->first();
        $lastMonthly  = $monthlyPeriods->sortByDesc('end_date')->first();

        $orphanWeeks  = $allWeekly->whereNotIn('id', $allUsedIds->all());
        $activeRange  = collect();

        if ($firstMonthly && $lastMonthly) {
            $activeRange = $orphanWeeks->filter(function (Period $w) use ($firstMonthly, $lastMonthly) {
                return $w->start_date && $w->start_date->between(
                    $firstMonthly->start_date,
                    $lastMonthly->end_date
                );
            });

            if ($activeRange->isEmpty()) {
                $this->line('  ✅ Sin semanas huérfanas dentro del rango activo.');
            } else {
                foreach ($activeRange as $w) {
                    $this->warn(sprintf(
                        '  ⚠️  Semana huérfana: %s (%s → %s)',
                        $w->name,
                        optional($w->start_date)->format('Y-m-d') ?? '?',
                        optional($w->end_date)->format('Y-m-d') ?? '?'
                    ));
                    $errors[] = "Semana huérfana dentro del rango activo: {$w->name}";
                }
            }
        } else {
            $this->line('  ℹ️  Aún no hay meses operativos creados para comparar.');
        }

        // Semanas sin asignar (fuera del rango o futuras)
        $pending = $orphanWeeks->diff($activeRange);
        if ($pending->isNotEmpty()) {
            $this->line('');
            $this->line('  SEMANAS PENDIENTES / FUTURAS (no asignadas, fuera del rango activo):');
            foreach ($pending as $w) {
                $this->line(sprintf(
                    '  · %s (%s → %s)',
                    $w->name,
                    optional($w->start_date)->format('Y-m-d') ?? '?',
                    optional($w->end_date)->format('Y-m-d') ?? '?'
                ));
            }
        }

        // ── 4. Verificación de rangos esperados 2026 ─────────────────────────
        if ($year === 2026) {
            $this->line('');
            $this->line('  VERIFICACIÓN DE RANGOS OPERATIVOS 2026:');

            $monthlyByMonth = $monthlyPeriods->keyBy('month');

            foreach (self::EXPECTED_RANGES_2026 as $month => $expected) {
                $monthly = $monthlyByMonth->get($month);

                if (!$monthly) {
                    $this->warn(sprintf('  ⚠️  Mes %02d (%s): AÚN NO CREADO', $month, $expected['label']));
                    continue;
                }

                $actualStart = optional($monthly->start_date)->format('Y-m-d');
                $actualEnd   = optional($monthly->end_date)->format('Y-m-d');
                $ok = $actualStart === $expected['start'] && $actualEnd === $expected['end'];

                $this->line(sprintf(
                    '  %s Mes %02d (%s): %s → %s %s',
                    $ok ? '✅' : '❌',
                    $month,
                    $expected['label'],
                    $actualStart ?? '—',
                    $actualEnd ?? '—',
                    $ok ? '' : "(esperado: {$expected['start']} → {$expected['end']})"
                ));
            }
        }

        // ── 5. Periodos derivados ─────────────────────────────────────────────
        $this->line('');
        $this->line('  PERIODOS DERIVADOS (bimestres, trimestres, semestres, año):');
        $this->line('');

        $monthlyByMonth = $monthlyPeriods->keyBy('month');

        $derivedBlocks = [
            'bimonthly'  => [
                'label'  => 'Bimestre',
                'blocks' => [
                    1 => [1, 2], 2 => [3, 4],  3 => [5, 6],
                    4 => [7, 8], 5 => [9, 10], 6 => [11, 12],
                ],
            ],
            'quarterly'  => [
                'label'  => 'Trimestre',
                'blocks' => [
                    1 => [1, 2, 3], 2 => [4, 5, 6],
                    3 => [7, 8, 9], 4 => [10, 11, 12],
                ],
            ],
            'semiannual' => [
                'label'  => 'Semestre',
                'blocks' => [
                    1 => [1, 2, 3, 4, 5, 6],
                    2 => [7, 8, 9, 10, 11, 12],
                ],
            ],
            'annual'     => [
                'label'  => 'Año',
                'blocks' => [1 => range(1, 12)],
            ],
        ];

        $derivedRows = [];

        foreach ($derivedBlocks as $type => $config) {
            foreach ($config['blocks'] as $seq => $months) {
                $seqLabel   = $type === 'annual' ? (string) $year : "{$config['label']} {$seq}";
                $monthNames = [
                    1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
                    5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
                    9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
                ];
                $required = implode('+', array_map(fn ($m) => $monthNames[$m], $months));

                $missing      = [];
                $presentMonths = collect();
                foreach ($months as $m) {
                    $mp = $monthlyByMonth->get($m);
                    if ($mp) {
                        $presentMonths->push($mp);
                    } else {
                        $missing[] = $monthNames[$m];
                    }
                }

                $existing = Period::query()
                    ->where('type', $type)
                    ->where('year', $year)
                    ->where('sequence', $seq)
                    ->first();

                if (empty($missing)) {
                    // Debe existir
                    $start = optional($presentMonths->sortBy('start_date')->first()?->start_date)->format('Y-m-d') ?? '?';
                    $end   = optional($presentMonths->sortByDesc('end_date')->first()?->end_date)->format('Y-m-d') ?? '?';

                    if ($existing) {
                        // Verificar que los component_period_ids apuntan a mensuales
                        $compIds  = collect($existing->component_period_ids ?? []);
                        $allMonthly = $compIds->every(function ($id) {
                            return Period::where('id', $id)->where('type', 'monthly')->exists();
                        });

                        $rangeOk = optional($existing->start_date)->format('Y-m-d') === $start
                            && optional($existing->end_date)->format('Y-m-d') === $end;

                        if ($allMonthly && $rangeOk) {
                            $derivedRows[] = ['Nombre' => $seqLabel, 'Componentes' => $required, 'Rango' => "{$start} → {$end}", 'Estado' => '✅ OK'];
                        } elseif (!$allMonthly) {
                            $derivedRows[] = ['Nombre' => $seqLabel, 'Componentes' => $required, 'Rango' => "{$start} → {$end}", 'Estado' => '❌ USA SEMANAS'];
                            $errors[] = "{$seqLabel}: component_period_ids contiene IDs de semanas, no meses operativos";
                        } else {
                            $actualStart = optional($existing->start_date)->format('Y-m-d');
                            $actualEnd   = optional($existing->end_date)->format('Y-m-d');
                            $derivedRows[] = ['Nombre' => $seqLabel, 'Componentes' => $required, 'Rango' => "{$actualStart} → {$actualEnd}", 'Estado' => '❌ RANGO MAL'];
                            $errors[] = "{$seqLabel}: rango incorrecto (esperado {$start}→{$end})";
                        }
                    } else {
                        $derivedRows[] = ['Nombre' => $seqLabel, 'Componentes' => $required, 'Rango' => '—', 'Estado' => '⚠️  NO GENERADO'];
                        $errors[] = "{$seqLabel}: no generado aunque existen los meses necesarios";
                    }
                } else {
                    // Faltan meses → no debe existir
                    if ($existing) {
                        $derivedRows[] = ['Nombre' => $seqLabel, 'Componentes' => "FALTA: " . implode(',', $missing), 'Rango' => '—', 'Estado' => '❌ EXISTE SIN MESES'];
                        $errors[] = "{$seqLabel}: existe en BD aunque faltan meses operativos (" . implode(',', $missing) . ")";
                    } else {
                        $derivedRows[] = ['Nombre' => $seqLabel, 'Componentes' => "FALTA: " . implode(',', $missing), 'Rango' => '—', 'Estado' => '✅ No aplica'];
                    }
                }
            }
        }

        $this->table(['Nombre', 'Componentes', 'Rango', 'Estado'], $derivedRows);

        // ── 6. Resumen final ──────────────────────────────────────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════');

        if (empty($errors)) {
            $this->info('  ✅ RESULTADO: Todos los periodos operativos son correctos.');
        } else {
            $this->error('  ❌ RESULTADO: Se encontraron ' . count($errors) . ' problema(s):');
            foreach ($errors as $i => $e) {
                $this->error('    ' . ($i + 1) . '. ' . $e);
            }
        }

        $this->info('══════════════════════════════════════════════════════════════════');
        $this->line('');

        return empty($errors) ? 0 : 1;
    }
}
