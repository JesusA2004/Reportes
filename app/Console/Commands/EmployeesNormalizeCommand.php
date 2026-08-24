<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeMatchRejection;
use App\Services\BranchResolverService;
use App\Services\EmployeeNameCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fusiona de forma segura colaboradores duplicados (mismo nombre normalizado, o
 * variantes conservadoras detectadas por EmployeeNameCanonicalizer — typo/acento/
 * espacio, nunca personas distintas). Por defecto corre en modo --dry-run: solo
 * imprime el plan. --apply ejecuta la fusión dentro de una transacción, moviendo
 * cada relación (employee_branch_assignments, fact_noi_movements,
 * fact_period_employee_summary, fact_expenses, employee_aliases,
 * employee_match_rejections, period_radiography_runs, period_employee_rosters)
 * al registro canónico antes de borrar el redundante. Nunca hace DELETE sin mover
 * primero las relaciones, y nunca fusiona un par que el usuario ya rechazó
 * explícitamente en employee_match_rejections.
 */
class EmployeesNormalizeCommand extends Command
{
    protected $signature = 'employees:normalize {--apply : Ejecuta la fusión. Sin esta bandera solo se muestra el plan (dry-run).}';

    protected $description = 'Fusiona colaboradores duplicados (nombre exacto o variante conservadora) de forma segura y trazable.';

    /** Tablas con employee_id sujeto a UNIQUE(period_id, employee_id) — requieren detección de colisión. */
    private const PERIOD_SCOPED_UNIQUE_TABLES = [
        'employee_branch_assignments',
        'fact_period_employee_summary',
    ];

    public function handle(EmployeeNameCanonicalizer $canonicalizer, BranchResolverService $branchResolver): int
    {
        $apply = (bool) $this->option('apply');

        $employees = Employee::query()->get(['id', 'full_name', 'normalized_name']);
        $uniqueNames = $employees->pluck('normalized_name')->filter()->unique()->values()->all();
        $canonicalMap = $canonicalizer->buildCanonicalMap($uniqueNames);

        // Agrupar TODOS los employee_id (no solo nombres únicos) bajo su nombre canónico.
        $byCanonical = [];
        foreach ($employees as $e) {
            if (!$e->normalized_name) continue;
            $canonicalName = $canonicalMap[$e->normalized_name] ?? $e->normalized_name;
            $byCanonical[$canonicalName][] = $e;
        }

        $rejectedPairs = EmployeeMatchRejection::query()->pluck('pair_key')->flip();

        $plans = [];
        foreach ($byCanonical as $canonicalName => $group) {
            if (count($group) < 2) continue;

            // Nunca fusionar un par que el usuario rechazó explícitamente.
            $group = collect($group)->reject(function ($e) use ($group, $rejectedPairs) {
                foreach ($group as $other) {
                    if ($other->id === $e->id) continue;
                    if ($rejectedPairs->has(EmployeeMatchRejection::pairKey($e->id, $other->id))) {
                        return true;
                    }
                }
                return false;
            })->values();

            if ($group->count() < 2) continue;

            // Canónico = quien tenga más asignaciones a sucursal OPERATIVA (nunca una ruta
            // como "CENTRO C" — ver sección 19-20 de la spec: una ruta jamás debe ganarle a
            // una sucursal real al elegir el registro que sobrevive); empate → más
            // asignaciones con branch_id resuelto (aunque no operativa); empate → más
            // movimientos NOI; empate → id más antiguo.
            $operativeAssignCount = function (int $employeeId) use ($branchResolver): int {
                return DB::table('employee_branch_assignments as eba')
                    ->join('branches as b', 'eba.branch_id', '=', 'b.id')
                    ->where('eba.employee_id', $employeeId)
                    ->get(['b.name'])
                    ->filter(fn ($r) => $branchResolver->isSheetBranch((string) $r->name))
                    ->count();
            };

            $sorted = $group->all();
            usort($sorted, function ($a, $b) use ($operativeAssignCount) {
                $aOp = $operativeAssignCount($a->id);
                $bOp = $operativeAssignCount($b->id);
                if ($aOp !== $bOp) return $bOp <=> $aOp;
                $aAssign = DB::table('employee_branch_assignments')->where('employee_id', $a->id)->whereNotNull('branch_id')->count();
                $bAssign = DB::table('employee_branch_assignments')->where('employee_id', $b->id)->whereNotNull('branch_id')->count();
                if ($aAssign !== $bAssign) return $bAssign <=> $aAssign;
                $aMov = DB::table('fact_noi_movements')->where('employee_id', $a->id)->count();
                $bMov = DB::table('fact_noi_movements')->where('employee_id', $b->id)->count();
                if ($aMov !== $bMov) return $bMov <=> $aMov;
                return $a->id <=> $b->id;
            });

            $canonical = $sorted[0];
            $duplicates = array_slice($sorted, 1);

            $plans[] = [
                'canonical' => $canonical,
                'duplicates' => $duplicates,
            ];
        }

        if (empty($plans)) {
            $this->info('No se encontraron duplicados fusionables (o todos los pares detectados fueron rechazados previamente).');
            return self::SUCCESS;
        }

        $this->info(($apply ? 'Aplicando' : '[DRY RUN] Plan de') . ' fusión de ' . count($plans) . ' grupo(s) de colaboradores duplicados:');
        foreach ($plans as $plan) {
            $canonical = $plan['canonical'];
            $dupLabels = collect($plan['duplicates'])->map(fn ($d) => "#{$d->id} \"{$d->full_name}\"")->implode(', ');
            $this->line("  → CANÓNICO #{$canonical->id} \"{$canonical->full_name}\"  ⟵  {$dupLabels}");
        }

        if (!$apply) {
            $this->line('');
            $this->comment('Ejecuta con --apply para aplicar esta fusión (transaccional, mueve relaciones antes de borrar).');
            return self::SUCCESS;
        }

        $merged = 0;
        DB::transaction(function () use ($plans, &$merged) {
            foreach ($plans as $plan) {
                $canonical = $plan['canonical'];
                foreach ($plan['duplicates'] as $dup) {
                    $this->mergeInto($canonical, $dup);
                    $merged++;
                }
            }
        });

        $this->info("Fusión completada: {$merged} colaborador(es) duplicado(s) fusionado(s) hacia su registro canónico.");
        return self::SUCCESS;
    }

    private function mergeInto(Employee $canonical, Employee $dup): void
    {
        // 1) Alias — preserva la variante de escritura para que el sistema la reconozca
        //    automáticamente en el futuro (nunca vuelve a preguntar por este par).
        DB::table('employee_aliases')->updateOrInsert(
            ['employee_id' => $canonical->id, 'normalized_alias' => $dup->normalized_name],
            ['alias_name' => $dup->full_name, 'source' => 'employees:normalize', 'confidence' => 1.0, 'updated_at' => now(), 'created_at' => now()],
        );
        // Alias que ya apuntaban al duplicado ahora deben apuntar al canónico.
        DB::table('employee_aliases')
            ->where('employee_id', $dup->id)
            ->get()
            ->each(function ($row) use ($canonical) {
                $exists = DB::table('employee_aliases')->where('employee_id', $canonical->id)->where('normalized_alias', $row->normalized_alias)->exists();
                if ($exists) {
                    DB::table('employee_aliases')->where('id', $row->id)->delete();
                } else {
                    DB::table('employee_aliases')->where('id', $row->id)->update(['employee_id' => $canonical->id]);
                }
            });

        // 2) Tablas period-scoped con UNIQUE(period_id, employee_id): mover solo cuando
        //    el canónico no tenga ya una fila para ese periodo; si la tiene, se conserva
        //    la del canónico y se descarta la del duplicado (nunca se pierden datos del
        //    canónico, que es la fuente preferida).
        foreach (self::PERIOD_SCOPED_UNIQUE_TABLES as $table) {
            $canonicalPeriods = DB::table($table)->where('employee_id', $canonical->id)->pluck('period_id')->all();
            DB::table($table)->where('employee_id', $dup->id)->whereIn('period_id', $canonicalPeriods)->delete();
            DB::table($table)->where('employee_id', $dup->id)->update(['employee_id' => $canonical->id]);
        }

        // 3) Tablas sin restricción única sobre employee_id: reasignar directo.
        foreach (['fact_noi_movements', 'fact_expenses', 'period_radiography_runs'] as $table) {
            DB::table($table)->where('employee_id', $dup->id)->update(['employee_id' => $canonical->id]);
        }

        // 4) employee_match_rejections — repuntar hacia el canónico, evitando pares
        //    duplicados o auto-referenciados.
        DB::table('employee_match_rejections')
            ->where('employee_id_a', $dup->id)->orWhere('employee_id_b', $dup->id)
            ->get()
            ->each(function ($row) use ($canonical, $dup) {
                $other = $row->employee_id_a === $dup->id ? $row->employee_id_b : $row->employee_id_a;
                if ($other === $canonical->id) {
                    DB::table('employee_match_rejections')->where('id', $row->id)->delete();
                    return;
                }
                $newKey = EmployeeMatchRejection::pairKey($canonical->id, $other);
                $exists = DB::table('employee_match_rejections')->where('pair_key', $newKey)->exists();
                if ($exists) {
                    DB::table('employee_match_rejections')->where('id', $row->id)->delete();
                } else {
                    DB::table('employee_match_rejections')->where('id', $row->id)->update([
                        'employee_id_a' => min($canonical->id, $other),
                        'employee_id_b' => max($canonical->id, $other),
                        'pair_key'      => $newKey,
                    ]);
                }
            });

        // 5) period_employee_rosters es derivada/reconstruible — simplemente se borran las
        //    filas del duplicado; el próximo "Actualizar BD" la reconstruye ya unificada.
        DB::table('period_employee_rosters')->where('employee_id', $dup->id)->delete();

        // 6) Solo hasta que TODAS las relaciones fueron movidas se borra el registro redundante.
        DB::table('employees')->where('id', $dup->id)->delete();
    }
}
