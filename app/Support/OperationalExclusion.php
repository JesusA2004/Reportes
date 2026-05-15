<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Branches excluded from financial metrics (cartera, mora, colocación, recuperación, productos)
 * for operational reasons, NOT because they are Región Norte.
 *
 * - CORPORATIVO: does not generate loans, placement, portfolio, or recovery.
 *   It CAN appear in gastos (expenses).
 * - FALSO: test/placeholder branch, never counts.
 * - Any branch with "inactiva" or "vacante" in its name.
 *
 * Keep this separate from RegionNorteFilter so debug output can distinguish
 * "excluido por Norte" vs "excluido por Corporativo/Falso".
 */
class OperationalExclusion
{
    private const NAMES = ['corporativo', 'falso'];

    /** @return string[] lowercase branch names excluded for operational (non-Norte) reasons */
    public static function names(): array
    {
        return self::NAMES;
    }

    /** Returns true if the branch is excluded for operational reasons. */
    public static function isExcluded(string $name): bool
    {
        $lower = mb_strtolower(trim($name));
        if (str_contains($lower, 'inactiva') || str_contains($lower, 'vacante')) {
            return true;
        }
        return in_array($lower, self::NAMES, true);
    }

    /**
     * Apply exclusion to a query builder.
     * Excludes corporativo, falso, and any branch with "inactiva"/"vacante".
     *
     * @param string $branchNameExpr SQL expression for the raw branch name column (e.g. 'b.name', 'br.name')
     */
    public static function applyTo(object $query, string $branchNameExpr = 'b.name'): object
    {
        return $query
            ->where(function ($q) use ($branchNameExpr) {
                $q->whereNull($branchNameExpr)
                  ->orWhereNotIn(DB::raw("LOWER({$branchNameExpr})"), self::NAMES);
            })
            ->whereRaw("({$branchNameExpr} IS NULL OR LOWER({$branchNameExpr}) NOT LIKE ?)", ['%inactiva%'])
            ->whereRaw("({$branchNameExpr} IS NULL OR LOWER({$branchNameExpr}) NOT LIKE ?)", ['%vacante%']);
    }
}
