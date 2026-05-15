<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Norte region exclusion logic.
 *
 * Two layers of filtering:
 *
 * 1. UNAMBIGUOUS branch names — always excluded in ALL metrics.
 *    Only names that are 100% Norte and cannot be shared with operative branches.
 *    Used for: cartera, mora, colocación.
 *
 * 2. PRECISE payload-based detection — used for recuperación.
 *    Excludes Norte only when there is clear evidence in raw_payload:
 *    accredited_name prefix (CH/DG digits), zone_name text (Chihuahua/Durango/AGS),
 *    OR the branch name is in the unambiguous list.
 *    Generic sub-route names (CENTRO, VILLAS, MANANTIAL…) are NOT excluded
 *    unless one of the payload signals is also present.
 */
class RegionNorteFilter
{
    /**
     * Branch names that are 100% non-operative and unambiguous.
     * These can safely be matched by exact LOWER(b.name).
     */
    private const UNAMBIGUOUS = [
        // Norte region identifiers only — corporativo/falso are in OperationalExclusion
        'matriz norte',
        'region norte',
        // Chihuahua top-level + clearly-Norte sub-routes
        'chihuahua',
        'vis-nor',
        'vill-pab',
        'cer-gran',
        'orie-sanguil',
        'dovsto',
        'jimenez',
        'lon-cen',
        'volante ch',
        'san-guille',
        'gerencia ch',
        // Durango top-level + clearly-Norte sub-routes
        'durango',
        'victoria de durango',
        'dom-arrie',
        'joyas',
        'santuario',
        'morga',
        'cipres',
        // Aguascalientes top-level + clearly-Norte sub-routes
        'aguascalientes',
        'sur poniente',
        'cen-sur',
        'jesu-noro',
        'saf-nore',
        'sur-ori',
        'cen-este',
        'nor-oriente',
        'ags-gte',
    ];

    /**
     * These names are ambiguous — they exist in Norte AND in operative branches.
     * They are NOT excluded by name alone; payload evidence is required.
     * Listed here for documentation only.
     */
    private const AMBIGUOUS_SUB_ROUTES = [
        'centro', 'villas', 'manantial', 'factor', 'universal',
        'supervisor', 'pri', 'industrial', 'santa fe',
    ];

    /** Branch names to exclude in cartera / mora / colocación queries. */
    public static function names(): array
    {
        return self::UNAMBIGUOUS;
    }

    /**
     * Returns true if a branch name belongs to Región Norte.
     * Does NOT include Corporativo/Falso (see OperationalExclusion).
     */
    public static function isNorte(string $name): bool
    {
        $lower = mb_strtolower(trim($name));
        return in_array($lower, self::UNAMBIGUOUS, true);
    }

    // ── SQL helpers for recuperación (fact_recoveries) ───────────────────────

    /**
     * WHERE fragment that keeps only OPERATIVE PAGO recoveries.
     *
     * A recovery is Norte (and thus excluded) when ANY of:
     *   a) branch name is unambiguously Norte
     *   b) accredited_name code starts with CH / DG followed by a digit
     *   c) zone_name field contains Chihuahua / Durango / Aguascalientes / Norte keywords
     *
     * @param string $rAlias   alias for fact_recoveries (default 'r')
     * @param string $bAlias   alias for branches joined on r.branch_id (default 'b')
     */
    public static function applyRecoveryFilter(object $query, string $rAlias = 'r', string $bAlias = 'b'): object
    {
        $names = self::UNAMBIGUOUS;

        return $query
            // Exclude unambiguous Norte branch names
            ->where(function ($q) use ($bAlias, $names) {
                $q->whereNull("{$bAlias}.name")
                  ->orWhereNotIn(DB::raw("LOWER({$bAlias}.name)"), $names);
            })
            // Exclude by accredited_name Norte prefix (CH/DG + digit = Norte client code)
            ->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload, '$.accredited_name')), '') NOT REGEXP ?",
                ['^(CH|DG)[0-9]']
            )
            // Exclude by zone_name containing Norte region keywords
            ->whereRaw(
                "UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload, '$.zone_name')), '')) NOT REGEXP ?",
                ['CHIHUAHUA|DURANGO|AGUASCALIENTES|MATRIZ NORTE|REGION NORTE']
            );
    }

    /**
     * SQL expression string (for whereRaw) that evaluates to 1 when a recovery
     * row is Norte (should be excluded). Useful in SELECT for debug breakdowns.
     *
     * @param string $rAlias   alias for fact_recoveries
     * @param string $bAlias   alias for branches
     */
    public static function norteDetectionExpr(string $rAlias = 'r', string $bAlias = 'b'): string
    {
        $inList = implode(',', array_map(fn ($n) => "'" . addslashes($n) . "'", self::UNAMBIGUOUS));
        return "
            (
                ({$bAlias}.name IS NOT NULL AND LOWER({$bAlias}.name) IN ({$inList}))
                OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload,'$.accredited_name')),'') REGEXP '^(CH|DG)[0-9]'
                OR UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload,'$.zone_name')),'')) REGEXP 'CHIHUAHUA|DURANGO|AGUASCALIENTES|MATRIZ NORTE|REGION NORTE'
            )
        ";
    }

    /**
     * Returns the Norte detection reason string for a given record (PHP-side, for debug loops).
     * Pass the branch name and decoded raw_payload array.
     *
     * @param string|null $branchName
     * @param array $payload  decoded raw_payload
     * @return string|null  null if not Norte, description string if Norte
     */
    public static function detectNorteReason(?string $branchName, array $payload): ?string
    {
        $lower = mb_strtolower(trim($branchName ?? ''));

        if (in_array($lower, self::UNAMBIGUOUS, true)) {
            return 'Sucursal Norte inequívoca: ' . $branchName;
        }

        $accredited = strtoupper(trim($payload['accredited_name'] ?? ''));
        if (preg_match('/^(CH|DG)\d/', $accredited)) {
            $prefix = substr($accredited, 0, 2);
            $region = $prefix === 'CH' ? 'Chihuahua' : 'Durango';
            return "Prefijo acreditado {$prefix}: {$region} ({$accredited})";
        }

        $zone = strtoupper(trim($payload['zone_name'] ?? ''));
        if ($zone && preg_match('/(CHIHUAHUA|DURANGO|AGUASCALIENTES|MATRIZ NORTE|REGION NORTE)/i', $zone)) {
            return "Zona Norte: {$zone}";
        }

        return null;
    }
}
