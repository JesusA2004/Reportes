<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\BranchResolverService;

class PersonIdentityResolverService
{
    // Contract prefix → operative branch name
    private const CONTRACT_PREFIXES = [
        'ATLIX' => 'ATLIXCO',          // longer prefix first to avoid ATLA ambiguity
        'ATLA'  => 'ATLACOMULCO',
        'CORD'  => 'CORDOBA',
        'CUER'  => 'CUERNAVACA',
        'HUAM'  => 'HUAMANTLA',
        'IXTA'  => 'IXTLAHUACA',
        'MIAC'  => 'MIACATLAN',
        'ORIZ'  => 'ORIZABA',
        'SLP'   => 'SAN LUIS POTOSI',
        'TENA'  => 'TENANGO DEL VALLE',
        'TLAX'  => 'TLAXCALA',
        'TULA'  => 'TULA',
    ];

    public function normalizePersonName(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    /**
     * Returns 0–100 similarity score between two names (normalized internally).
     * BELASCO / BELAZCO → ~85.7, well above the 80 threshold.
     */
    public function scoreNameSimilarity(string $a, string $b): float
    {
        $aN = $this->normalizePersonName($a);
        $bN = $this->normalizePersonName($b);

        if ($aN === $bN) return 100.0;
        if ($aN === '' || $bN === '') return 0.0;

        similar_text($aN, $bN, $pct);
        return (float) $pct;
    }

    public function isLikelySamePerson(string $a, string $b, float $threshold = 80.0): bool
    {
        return $this->scoreNameSimilarity($a, $b) >= $threshold;
    }

    /**
     * Find an existing employee matching the given name.
     * Resolution order: exact normalized → fuzzy (≥80%).
     * Returns ['employee' => Employee, 'method' => string, 'score' => float] or null.
     */
    public function findExistingPersonMatch(string $name, ?int $excludeId = null): ?array
    {
        $normalized = $this->normalizePersonName($name);
        if ($normalized === '') return null;

        // 1. Exact normalized match
        $exact = Employee::query()
            ->where('normalized_name', $normalized)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();

        if ($exact) {
            return ['employee' => $exact, 'method' => 'exact_name', 'score' => 100.0];
        }

        // 2. Fuzzy match — similar_text ≥ 80%
        $candidates = Employee::query()
            ->whereNotNull('normalized_name')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['id', 'full_name', 'normalized_name']);

        $bestScore = 0.0;
        $bestMatch = null;

        foreach ($candidates as $c) {
            $score = $this->scoreNameSimilarity($name, (string) $c->normalized_name);
            if ($score >= 80.0 && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $c;
            }
        }

        if ($bestMatch) {
            return ['employee' => $bestMatch, 'method' => 'fuzzy_name', 'score' => $bestScore];
        }

        return null;
    }

    /**
     * Infer branch from contract-number prefixes found in fact_recoveries
     * for rows where the promoter name matches the given person.
     */
    public function resolveBranchFromContractPrefix(string $personName): ?int
    {
        $normalized = $this->normalizePersonName($personName);

        $contracts = DB::table('fact_recoveries')
            ->whereRaw('LOWER(promoter_name) = ?', [$normalized])
            ->whereNotNull('contract')
            ->pluck('contract')
            ->unique()
            ->take(20);

        // Sort prefixes longest-first to avoid ATLA shadowing ATLIX
        $prefixes = self::CONTRACT_PREFIXES;
        uksort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($contracts as $contract) {
            $upper = strtoupper(trim((string) $contract));
            foreach ($prefixes as $key => $branchName) {
                if (str_starts_with($upper, $key)) {
                    $branchId = DB::table('branches')
                        ->whereRaw('UPPER(TRIM(name)) = ?', [$branchName])
                        ->value('id');
                    if ($branchId) return (int) $branchId;
                }
            }
        }

        return null;
    }

    // The 12 operative branches that are valid for employee assignment.
    private const OPERATIVE_BRANCH_NAMES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI',
        'TENANGO DEL VALLE', 'TLAXCALA', 'TULA', 'CORPORATIVO',
        'SAN JUAN DEL RÍO', 'SAN JUAN DEL RIO',
    ];

    /**
     * Returns a subquery that restricts branch_id to operative branches only.
     * Routes/offices/zones that are NOT one of the operative sucursales are excluded.
     */
    private function operativeBranchIds(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = DB::table('branches')
            ->whereIn(DB::raw('UPPER(TRIM(name))'), self::OPERATIVE_BRANCH_NAMES)
            ->pluck('id')
            ->toArray();

        return $cache;
    }

    /**
     * Look for another employee record with the same normalized_name (canonical duplicate)
     * and return their branch_id if they have one. Used to unify multi-source duplicates.
     * Only returns operative branch IDs (never routes or non-operative offices).
     */
    public function resolveBranchFromCanonicalEmployee(Employee $employee): ?int
    {
        $normalized = $employee->normalized_name;
        if (!$normalized) return null;

        // Find all employee IDs with the same normalized name (excluding self)
        $canonicalIds = DB::table('employees')
            ->where('normalized_name', $normalized)
            ->where('id', '!=', $employee->id)
            ->pluck('id')
            ->toArray();

        if (empty($canonicalIds)) return null;

        $operativeIds = $this->operativeBranchIds();
        if (empty($operativeIds)) return null;

        return DB::table('employee_branch_assignments')
            ->whereIn('employee_id', $canonicalIds)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->value('branch_id');
    }

    /**
     * Resolve branch using saved aliases: if another employee has this person's
     * normalized name stored as an alias, inherit their branch.
     * Only returns operative branch IDs.
     */
    public function resolveBranchFromAlias(Employee $employee): ?int
    {
        $normalized = $employee->normalized_name;
        if (!$normalized) return null;

        $canonicalEmployeeId = DB::table('employee_aliases')
            ->where('normalized_alias', $normalized)
            ->value('employee_id');

        if (!$canonicalEmployeeId || $canonicalEmployeeId === $employee->id) return null;

        $operativeIds = $this->operativeBranchIds();
        if (empty($operativeIds)) return null;

        return DB::table('employee_branch_assignments')
            ->where('employee_id', $canonicalEmployeeId)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->value('branch_id');
    }

    /**
     * Return the branch from the most recent existing assignment for an employee.
     * Only returns operative branch IDs — never routes or non-operative offices.
     */
    public function resolveBranchFromExistingAssignments(int $employeeId): ?int
    {
        $operativeIds = $this->operativeBranchIds();
        if (empty($operativeIds)) return null;

        return DB::table('employee_branch_assignments')
            ->where('employee_id', $employeeId)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->value('branch_id');
    }

    /**
     * Build a full resolution trace for an employee that ended up sin sucursal.
     * Accepts pre-loaded collections to avoid N+1 queries.
     *
     * @param  \Illuminate\Support\Collection $allEmployees  All employees (id, full_name, normalized_name)
     * @param  \Illuminate\Support\Collection $sampleMovs    NOI movements for this employee (movement_date, concept, amount, raw_payload, source)
     */
    public function buildResolutionTrace(
        int $employeeId,
        string $fullName,
        \Illuminate\Support\Collection $allEmployees,
        \Illuminate\Support\Collection $sampleMovs,
        ?string $employeeCode = null,
        ?string $historicalBranch = null,
        ?array $dataIds = null,
    ): array {
        $normalized = $this->normalizePersonName($fullName);

        $trace = [
            'normalized_name'               => $normalized,
            'checked_exact_name'            => true,
            'checked_fuzzy_name'            => true,
            'checked_contract_prefix'       => false,
            'checked_historical_assignment' => true,
            'checked_lendus_directory'      => false,
            'lendus_match'                  => null,
            'candidate_matches'             => [],
            'detected_codes' => [
                'employee_code'   => $employeeCode,
                'contract_prefix' => null,
                'branch_hint'     => $historicalBranch,
                'lendus_codigo'   => null,
                'lendus_puesto'   => null,
                'raw_clues'       => [],
            ],
            'sample_movements' => [],
            'result'           => 'unresolved',
            'reason'           => '',
            'suggested_action' => 'Asignar sucursal manualmente.',
        ];

        // ── Fuzzy candidates ─────────────────────────────────────────────
        // Pre-load one branch per candidate for the description
        $allCandidateIds = $allEmployees->pluck('id')->filter(fn ($id) => (int) $id !== $employeeId)->all();
        $candidateBranches = empty($allCandidateIds) ? [] : DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.employee_id', $allCandidateIds)
            ->whereNotNull('eba.branch_id')
            ->orderByDesc('eba.period_id')
            ->select('eba.employee_id', 'b.name as branch_name')
            ->get()
            ->unique('employee_id')
            ->pluck('branch_name', 'employee_id')
            ->all();

        $seen = [];
        foreach ($allEmployees as $c) {
            if ((int) $c->id === $employeeId) continue;
            $normC = $this->normalizePersonName((string) $c->normalized_name);
            if (isset($seen[$normC])) continue; // skip duplicate employee records for same name
            $seen[$normC] = true;

            $score = $this->scoreNameSimilarity($fullName, (string) $c->normalized_name);
            if ($score >= 60.0) {
                $scoreRound    = round($score, 1);
                $candidateBranch = $candidateBranches[(int) $c->id] ?? null;
                $accepted      = $score >= 80.0;

                if ($accepted && !$candidateBranch) {
                    $reason = "score {$scoreRound}% sobre umbral, pero el candidato tampoco tiene sucursal asignada";
                } elseif ($accepted) {
                    $reason = "score {$scoreRound}% sobre umbral — debió haberse resuelto";
                } else {
                    $reason = "score {$scoreRound}% menor al umbral de 80%";
                }

                $trace['candidate_matches'][] = [
                    'employee_id' => (int) $c->id,
                    'name'        => $c->full_name,
                    'score'       => $scoreRound,
                    'branch'      => $candidateBranch,
                    'accepted'    => $accepted,
                    'reason'      => $reason,
                ];
            }
        }
        usort($trace['candidate_matches'], fn ($a, $b) => $b['score'] <=> $a['score']);
        $trace['candidate_matches'] = array_slice($trace['candidate_matches'], 0, 5);

        // ── Contract prefix ──────────────────────────────────────────────
        $trace['checked_contract_prefix'] = true;
        $branchFromContract = $this->resolveBranchFromContractPrefix($fullName);
        if ($branchFromContract) {
            $branchName = DB::table('branches')->where('id', $branchFromContract)->value('name');
            $trace['detected_codes']['contract_prefix'] = (string) $branchName;
            if (!$trace['detected_codes']['branch_hint']) {
                $trace['detected_codes']['branch_hint'] = (string) $branchName;
            }
        } else {
            $contracts = DB::table('fact_recoveries')
                ->whereRaw('LOWER(promoter_name) = ?', [$normalized])
                ->whereNotNull('contract')
                ->pluck('contract')
                ->take(3)
                ->toArray();
            if (!empty($contracts)) {
                $trace['detected_codes']['raw_clues'][] = 'Contratos en cobranza: ' . implode(', ', $contracts) . ' — sin prefijo de sucursal reconocido';
            }
        }

        // ── Ministraciones / Colocación check ────────────────────────────
        $trace['checked_ministraciones'] = !empty($dataIds);
        $placementMatch = null;
        if (!empty($dataIds)) {
            $promoterRows = DB::table('fact_placements')
                ->whereIn('period_id', $dataIds)
                ->whereNotNull('branch_id')
                ->selectRaw('normalized_promoter_name, MAX(promoter_code) as promoter_code, MAX(branch_id) as branch_id')
                ->groupBy('normalized_promoter_name')
                ->get()
                ->keyBy('normalized_promoter_name');

            if ($promoterRows->has($normalized)) {
                $pl = $promoterRows->get($normalized);
                $placementMatch = ['name' => $normalized, 'code' => $pl->promoter_code, 'branch_id' => $pl->branch_id, 'score' => 100.0, 'method' => 'exact'];
            } else {
                $bestScore = 0.0;
                $bestPl = null;
                $bestPname = null;
                foreach ($promoterRows as $pname => $pl) {
                    similar_text($normalized, $pname, $pct);
                    if ($pct > $bestScore) {
                        $bestScore = $pct;
                        $bestPl = $pl;
                        $bestPname = $pname;
                    }
                }
                if ($bestScore >= 95.0 && $bestPl) {
                    $placementMatch = ['name' => $bestPname, 'code' => $bestPl->promoter_code, 'branch_id' => $bestPl->branch_id, 'score' => round($bestScore, 1), 'method' => 'fuzzy'];
                }
            }
        }
        $trace['placement_match'] = $placementMatch;
        if ($placementMatch) {
            $placementBranchName = DB::table('branches')->where('id', $placementMatch['branch_id'])->value('name');
            $trace['detected_codes']['placement_code']   = $placementMatch['code'];
            $trace['detected_codes']['placement_branch'] = $placementBranchName;
            if (!$trace['detected_codes']['branch_hint']) {
                $trace['detected_codes']['branch_hint'] = (string) $placementBranchName;
            }
        }

        // ── Lendus employee directory ────────────────────────────────────
        $lendusMatch = null;

        // Load all operational records (no inferred_branch_id filter — resolve from codigo if needed)
        $allLendus = DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('normalized_name')
            ->get(['id', 'codigo', 'nombre', 'normalized_name', 'puesto', 'estatus', 'inferred_branch_id', 'inferred_branch_name']);

        if ($allLendus->isNotEmpty()) {
            $trace['checked_lendus_directory'] = true;

            // Exact match first, then fuzzy ≥85%
            $lendusRecord    = $allLendus->first(fn ($r) => $r->normalized_name === $normalized);
            $bestLendusScore = 100.0;
            if (!$lendusRecord) {
                $bestLendusScore = 0.0;
                foreach ($allLendus as $rec) {
                    similar_text($normalized, $rec->normalized_name, $pct);
                    if ($pct > $bestLendusScore) {
                        $bestLendusScore = $pct;
                        $lendusRecord    = $rec;
                    }
                }
                if ($bestLendusScore < 85.0) {
                    $lendusRecord = null;
                }
            }

            if ($lendusRecord) {
                $lendusBranchId   = $lendusRecord->inferred_branch_id;
                $lendusBranchName = $lendusRecord->inferred_branch_name;

                // Fallback: resolve from CODIGO prefix when inferred_branch_id was not set at import time
                if (!$lendusBranchId && $lendusRecord->codigo) {
                    $branchResolver = app(BranchResolverService::class);
                    $resolvedName   = $branchResolver->resolveBranchNameFromCode((string) $lendusRecord->codigo);
                    if ($resolvedName) {
                        $resolvedId = DB::table('branches')
                            ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($resolvedName))])
                            ->value('id');
                        $lendusBranchId   = $resolvedId ? (int) $resolvedId : null;
                        $lendusBranchName = $resolvedName;
                    }
                }

                if ($lendusBranchId || $lendusBranchName) {
                    $lendusMatch = [
                        'codigo'      => $lendusRecord->codigo,
                        'nombre'      => $lendusRecord->nombre,
                        'puesto'      => $lendusRecord->puesto,
                        'estatus'     => $lendusRecord->estatus,
                        'branch_id'   => $lendusBranchId,
                        'branch_name' => $lendusBranchName,
                        'score'       => round($bestLendusScore, 1),
                        'method'      => $bestLendusScore >= 100.0 ? 'exact' : 'fuzzy',
                    ];
                }
            }
        }

        $trace['lendus_match'] = $lendusMatch;
        if ($lendusMatch) {
            $trace['detected_codes']['lendus_codigo'] = $lendusMatch['codigo'];
            $trace['detected_codes']['lendus_puesto'] = $lendusMatch['puesto'];
            if (!$trace['detected_codes']['branch_hint']) {
                $trace['detected_codes']['branch_hint'] = $lendusMatch['branch_name'];
            }
        }

        // ── Sample movements ─────────────────────────────────────────────
        foreach ($sampleMovs->sortByDesc('amount')->take(3) as $mov) {
            $payload = is_string($mov->raw_payload) ? json_decode($mov->raw_payload, true) : (array) $mov->raw_payload;
            $code = null;
            if (is_array($payload)) {
                foreach (['employee_code', 'codigo_empleado', 'codigo', 'clave', 'code'] as $k) {
                    if (!empty($payload[$k])) { $code = $payload[$k]; break; }
                }
            }
            $trace['sample_movements'][] = [
                'date'    => $mov->movement_date ?? null,
                'concept' => mb_substr((string) $mov->concept, 0, 40),
                'amount'  => (float) $mov->amount,
                'code'    => $code,
                'source'  => $mov->source ?? null,
            ];
        }

        // ── Build reason ─────────────────────────────────────────────────
        $reasons = [];
        $bestMatch = $trace['candidate_matches'][0] ?? null;

        if ($bestMatch && !$bestMatch['accepted']) {
            $reasons[] = "Mejor coincidencia: \"{$bestMatch['name']}\" con {$bestMatch['score']}% — por debajo del mínimo de 80%.";
        } elseif (empty($trace['candidate_matches'])) {
            $reasons[] = "No se encontró empleado con nombre similar (≥60%).";
        }

        if (!$trace['detected_codes']['contract_prefix']) {
            if (!empty($trace['detected_codes']['raw_clues'])) {
                $reasons[] = "Contratos en cobranza sin prefijo de sucursal reconocido.";
            } else {
                $reasons[] = "Sin contratos en cobranza — no se pudo inferir sucursal por prefijo.";
            }
        }

        if ($trace['checked_ministraciones']) {
            if ($placementMatch) {
                $label = $placementMatch['method'] === 'exact' ? 'exacto' : "variante {$placementMatch['score']}%";
                $reasons[] = "Ministraciones: código promotor {$placementMatch['code']} ({$label}) → {$trace['detected_codes']['placement_branch']}.";
            } else {
                $reasons[] = "Sin registro como promotor en Ministraciones/Colocación del periodo.";
            }
        }

        if ($trace['checked_lendus_directory']) {
            if ($lendusMatch) {
                $label = $lendusMatch['method'] === 'exact' ? 'exacto' : "variante {$lendusMatch['score']}%";
                $reasons[] = "Directorio Lendus: {$lendusMatch['nombre']} ({$label}) — {$lendusMatch['puesto']} → {$lendusMatch['branch_name']}.";
            } else {
                $reasons[] = "No encontrado en el directorio de empleados Lendus.";
            }
        }

        if (!$historicalBranch) {
            $reasons[] = "Sin asignaciones históricas en periodos anteriores.";
        }

        $trace['reason'] = implode(' ', $reasons) ?: "No se encontró nombre equivalente, contrato ni código de sucursal.";

        // ── Suggested action ─────────────────────────────────────────────
        if ($lendusMatch && $lendusMatch['branch_name']) {
            $label = $lendusMatch['method'] === 'exact' ? 'exacto' : "variante {$lendusMatch['score']}%";
            $trace['suggested_action'] = "Directorio Lendus ({$label}): sucursal {$lendusMatch['branch_name']} (código {$lendusMatch['codigo']}). Verificar y asignar.";
        } elseif ($placementMatch && !empty($trace['detected_codes']['placement_branch'])) {
            $trace['suggested_action'] = "Ministraciones: sucursal {$trace['detected_codes']['placement_branch']} (código {$placementMatch['code']}). Verificar y asignar.";
        } elseif ($bestMatch && $bestMatch['score'] >= 70.0) {
            $trace['suggested_action'] = "Posible coincidencia: \"{$bestMatch['name']}\" ({$bestMatch['score']}%). Verificar y asignar manualmente.";
        } elseif ($trace['detected_codes']['branch_hint']) {
            $trace['suggested_action'] = "Posible sucursal detectada: {$trace['detected_codes']['branch_hint']}. Verificar y asignar.";
        } else {
            $trace['suggested_action'] = "Asignar sucursal manualmente desde el panel de incidencias.";
        }

        return $trace;
    }

    /**
     * Return all near-duplicate candidates for a name, sorted by score desc.
     */
    public function suggestPossibleMatches(string $name, float $minScore = 75.0, ?int $excludeId = null): array
    {
        $matches    = [];
        $candidates = Employee::query()
            ->whereNotNull('normalized_name')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['id', 'full_name', 'normalized_name']);

        foreach ($candidates as $c) {
            $score = $this->scoreNameSimilarity($name, (string) $c->normalized_name);
            if ($score >= $minScore) {
                $matches[] = [
                    'id'        => $c->id,
                    'full_name' => $c->full_name,
                    'score'     => round($score, 1),
                    'method'    => $score >= 95.0 ? 'near_exact' : 'fuzzy',
                ];
            }
        }

        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $matches;
    }
}
