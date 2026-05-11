<?php

namespace App\Services;

/**
 * Centralizes human name normalization and fuzzy canonical grouping.
 * Used by RadiographySnapshotBuilder, EmployeeBranchAssignmentController,
 * debug commands, and anywhere employees/gestores are listed.
 */
class EmployeeNameCanonicalizer
{
    /**
     * Normalize a human name: lowercase, strip accents, strip special chars, collapse spaces.
     */
    public function normalize(string $name): string
    {
        $name = trim(mb_strtolower($name));
        $name = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $name,
        );
        $name = preg_replace('/[^a-z0-9\s]/', ' ', $name) ?? $name;
        return preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
    }

    /**
     * Build a map: normalizedName → normalizedCanonical for all names in $allNames.
     *
     * Two names merge when Levenshtein ≤ 2 AND they share ≥ 3 tokens of ≥ 3 chars.
     * Names in $payrollNames are preferred as the canonical (most authoritative source).
     *
     * @param  string[] $allNames     Raw display names to consider
     * @param  string[] $payrollNames NOI/payroll names (canonical preference)
     * @return array<string,string>   normalizedName => normalizedCanonical
     */
    public function buildCanonicalMap(array $allNames, array $payrollNames = []): array
    {
        $normAll     = array_map(fn($n) => $this->normalize($n), $allNames);
        $normPayroll = array_flip(array_map(fn($n) => $this->normalize($n), $payrollNames));
        $uniqueKeys  = array_values(array_unique($normAll));
        $assigned    = [];

        foreach ($uniqueKeys as $idxA => $keyA) {
            if (isset($assigned[$keyA])) continue;

            $tokensA = array_values(array_filter(explode(' ', $keyA), fn($t) => strlen($t) >= 3));
            if (count($tokensA) < 2) {
                $assigned[$keyA] = $keyA;
                continue;
            }

            foreach ($uniqueKeys as $idxB => $keyB) {
                if ($idxB <= $idxA || isset($assigned[$keyB])) continue;

                $tokensB = array_values(array_filter(explode(' ', $keyB), fn($t) => strlen($t) >= 3));
                if (count(array_intersect($tokensA, $tokensB)) < 3) continue;

                if (levenshtein($keyA, $keyB) <= 2) {
                    if (isset($normPayroll[$keyA])) {
                        [$canonical, $alias] = [$keyA, $keyB];
                    } elseif (isset($normPayroll[$keyB])) {
                        [$canonical, $alias] = [$keyB, $keyA];
                    } elseif (strlen($keyA) >= strlen($keyB)) {
                        [$canonical, $alias] = [$keyA, $keyB];
                    } else {
                        [$canonical, $alias] = [$keyB, $keyA];
                    }
                    $assigned[$canonical] ??= $canonical;
                    $assigned[$alias]      = $canonical;
                }
            }
            $assigned[$keyA] ??= $keyA;
        }

        foreach ($uniqueKeys as $k) {
            $assigned[$k] ??= $k;
        }

        return $assigned;
    }

    /**
     * Get the canonical normalized key for a raw name, given a canonical map.
     */
    public function canonicalKey(string $displayName, array $canonicalMap): string
    {
        $norm = $this->normalize($displayName);
        return $canonicalMap[$norm] ?? $norm;
    }

    /**
     * Return all alias normalized keys for a canonical key (excludes the canonical itself).
     */
    public function aliasesFor(string $canonicalNorm, array $canonicalMap): array
    {
        return array_values(array_filter(
            array_keys($canonicalMap),
            fn($k) => $canonicalMap[$k] === $canonicalNorm && $k !== $canonicalNorm,
        ));
    }

    /**
     * Explain why two already-normalized keys would or would not merge.
     */
    public function explainMerge(string $keyA, string $keyB): array
    {
        $tokA   = array_values(array_filter(explode(' ', $keyA), fn($t) => strlen($t) >= 3));
        $tokB   = array_values(array_filter(explode(' ', $keyB), fn($t) => strlen($t) >= 3));
        $common = count(array_intersect($tokA, $tokB));
        $lev    = levenshtein($keyA, $keyB);
        $merges = $lev > 0 && $lev <= 2 && $common >= 3;

        return [
            'merges'        => $merges,
            'levenshtein'   => $lev,
            'common_tokens' => $common,
            'reason'        => $merges
                ? "lev={$lev}≤2 + tokens_comunes={$common}≥3"
                : ($lev > 2 ? "lev={$lev}>2" : "tokens_comunes={$common}<3"),
        ];
    }
}
