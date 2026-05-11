<?php

namespace App\Services;

use App\Models\Branch;

/**
 * Resolves the REAL branch (sucursal) from a contract/promoter code prefix,
 * using the same mapping logic as the company's Excel macro.
 *
 * Priority: 3-letter prefixes are evaluated before 2-letter (CH, DG) to avoid
 * partial collisions. This mirrors the nested SI() formula in the macro.
 */
class BranchResolverService
{
    // Checked FIRST — 3-letter prefixes
    private const PREFIX_3 = [
        'AGS' => 'AGUASCALIENTES',
        'ATL' => 'ATLIXCO',
        'SJR' => 'SAN JUAN DEL RÍO',
        'MIA' => 'MIACATLAN',
        'HUA' => 'HUAMANTLA',
        'ORI' => 'ORIZABA',
        'COR' => 'CORDOBA',
        'TLX' => 'TLAXCALA',
        'TLN' => 'TULANCINGO',
        'PAC' => 'PACHUCA',
        'ATC' => 'ATLACOMULCO',
        'IXT' => 'IXTLAHUACA',
        'TNG' => 'TENANGO DEL VALLE',
        'TLA' => 'TULA',
        'CUE' => 'CUERNAVACA',
        'SLP' => 'SAN LUIS POTOSI',
    ];

    // Checked AFTER 3-letter — 2-letter prefixes
    private const PREFIX_2 = [
        'CH' => 'CHIHUAHUA',
        'DG' => 'DURANGO',
    ];

    // Products excluded from financial metrics
    private const EXCLUDED_PRODUCT_PATTERNS = [
        'REESTRUCTURA',
        'UNIFICACION',
        'UNIFICACIONES',
    ];

    // Products that are insurance (excluded from cartera/colocación)
    private const INSURANCE_PRODUCT_PATTERNS = [
        'SEGURO',
    ];

    private array $branchCache = [];

    public function clearCache(): void
    {
        $this->branchCache = [];
    }

    /**
     * Returns the real branch display name from a contract/promoter code.
     * Returns null if the prefix is not recognized.
     *
     * Examples:
     *   "ORI09247"  → "ORIZABA"
     *   "HUA071663" → "HUAMANTLA"
     *   "TLA07553"  → "TULA"
     *   "SLP123"    → "SAN LUIS POTOSI"
     *   "CH001"     → "CHIHUAHUA"
     */
    public function resolveBranchNameFromCode(string $code): ?string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        $prefix3 = substr($code, 0, 3);
        if (isset(self::PREFIX_3[$prefix3])) {
            return self::PREFIX_3[$prefix3];
        }

        $prefix2 = substr($code, 0, 2);
        if (isset(self::PREFIX_2[$prefix2])) {
            return self::PREFIX_2[$prefix2];
        }

        return null;
    }

    /**
     * Returns the 2- or 3-letter prefix extracted from the code, or null if unrecognized.
     */
    public function extractPrefix(string $code): ?string
    {
        $code = strtoupper(trim($code));

        $prefix3 = substr($code, 0, 3);
        if (isset(self::PREFIX_3[$prefix3])) {
            return $prefix3;
        }

        $prefix2 = substr($code, 0, 2);
        if (isset(self::PREFIX_2[$prefix2])) {
            return $prefix2;
        }

        return null;
    }

    /**
     * Finds or creates a Branch record for the real branch resolved from a code.
     * Returns null if the code prefix is not recognized.
     */
    public function findOrCreateBranchByCode(string $code): ?Branch
    {
        $branchName = $this->resolveBranchNameFromCode($code);

        if (!$branchName) {
            return null;
        }

        return $this->findOrCreateBranchByName($branchName);
    }

    /**
     * Finds or creates a Branch record by its real display name.
     * Uses normalized_name as the lookup key to avoid duplicates.
     */
    public function findOrCreateBranchByName(string $branchName): Branch
    {
        $normalized = $this->normalizeText($branchName);

        if (isset($this->branchCache[$normalized])) {
            return $this->branchCache[$normalized];
        }

        $existing = Branch::query()
            ->where('normalized_name', $normalized)
            ->first();

        if ($existing) {
            return $this->branchCache[$normalized] = $existing;
        }

        $code = $this->buildBranchCode($branchName);

        // Handle rare code collision by appending a suffix
        $finalCode = $code;
        $attempt = 0;
        while (Branch::query()->where('code', $finalCode)->exists()) {
            $attempt++;
            $finalCode = substr($code, 0, 55) . '_' . $attempt;
        }

        $branch = Branch::query()->create([
            'code'            => $finalCode,
            'name'            => $branchName,
            'normalized_name' => $normalized,
            'is_active'       => true,
        ]);

        return $this->branchCache[$normalized] = $branch;
    }

    /**
     * Normalizes a raw product name using Número de pagos and Periodicidad.
     *
     * Rules:
     * - "MR LANA DIARIO i20/i30/i60" + num_payments=20 → "i20"
     * - Periodicidad SEMANAL + num_payments=12/16/20/24 → "s12","s16","s20","s24"
     *   (unless product already has a specific CRECE/commercial name)
     * - CRECE12 SAC, CRECE24 SAC → kept as-is
     */
    public function normalizeProduct(string $product, ?int $numPayments = null, ?string $periodicity = null): string
    {
        $product = trim($product);
        if ($product === '') {
            return 'Sin producto';
        }

        $upper = strtoupper($product);

        // MR LANA DIARIO / LANA DIARIO with multi-option slash notation (i20/i30/i60)
        if ((str_contains($upper, 'LANA') && str_contains($upper, 'DIARIO'))
            || preg_match('/\bi\d+\s*\/\s*i\d+/i', $product)
        ) {
            if ($numPayments !== null && in_array($numPayments, [20, 30, 60], true)) {
                return 'i' . $numPayments;
            }
        }

        // MR LANA SEMANAL with multi-option slash notation (s12/s16/s20/s24)
        if ((str_contains($upper, 'LANA') && str_contains($upper, 'SEMANAL'))
            || preg_match('/\bs\d+\s*\/\s*s\d+/i', $product)
        ) {
            if ($numPayments !== null && in_array($numPayments, [12, 16, 20, 24], true)) {
                return 's' . $numPayments;
            }
        }

        // Semanal + standard payment counts → normalize only if no specific product name
        if ($periodicity !== null && strtoupper(trim($periodicity)) === 'SEMANAL' && $numPayments !== null) {
            if (in_array($numPayments, [12, 16, 20, 24], true)) {
                $hasSpecificName = preg_match(
                    '/CRECE|A LA MEDIDA|COMERCIAL|REESTRUCTURA|UNIFICACION|CRECE\d/i',
                    $upper
                );
                if (!$hasSpecificName) {
                    return 's' . $numPayments;
                }
            }
        }

        // Diario + standard payment counts → normalize only if no specific product name
        if ($periodicity !== null && strtoupper(trim($periodicity)) === 'DIARIO' && $numPayments !== null) {
            if (in_array($numPayments, [20, 30, 60], true)) {
                $hasSpecificName = preg_match(
                    '/CRECE|A LA MEDIDA|COMERCIAL|REESTRUCTURA|UNIFICACION/i',
                    $upper
                );
                if (!$hasSpecificName) {
                    return 'i' . $numPayments;
                }
            }
        }

        // Bare diario code: "I20", "I30", "I60" → normalize to lowercase
        if (preg_match('/^[Ii](20|30|60)$/', $product)) {
            return 'i' . substr($product, 1);
        }

        // Bare semanal code: "S12", "S16", "S20", "S24" → normalize to lowercase
        if (preg_match('/^[Ss](12|16|20|24)$/', $product)) {
            return 's' . substr($product, 1);
        }

        return $product;
    }

    /**
     * Returns true if a product should be excluded from main financial metrics.
     */
    public function isExcludedProduct(string $product): bool
    {
        $upper = strtoupper(trim($product));

        foreach (self::EXCLUDED_PRODUCT_PATTERNS as $pattern) {
            if (str_starts_with($upper, $pattern) || str_contains($upper, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if a product is insurance (excluded from cartera/colocación).
     */
    public function isInsuranceProduct(string $product): bool
    {
        $upper = strtoupper(trim($product));

        foreach (self::INSURANCE_PRODUCT_PATTERNS as $pattern) {
            if (str_starts_with($upper, $pattern) || str_contains($upper, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the full prefix → branch name catalog.
     */
    public function getCatalog(): array
    {
        $catalog = [];
        foreach (self::PREFIX_3 as $prefix => $name) {
            $catalog[$prefix] = $name;
        }
        foreach (self::PREFIX_2 as $prefix => $name) {
            $catalog[$prefix] = $name;
        }
        return $catalog;
    }

    /**
     * UPPERCASE list of real operational branches (excludes routes and CORPORATIVO).
     */
    public function realOperationalBranches(): array
    {
        return array_values(array_unique(array_values(array_merge(self::PREFIX_3, self::PREFIX_2))));
    }

    /**
     * UPPERCASE list of branches assignable to employees (operational + CORPORATIVO).
     */
    public function realAssignableBranches(): array
    {
        $branches   = $this->realOperationalBranches();
        $branches[] = 'CORPORATIVO';
        return $branches;
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );

        return preg_replace('/\s+/', ' ', $value) ?? trim($value);
    }

    private function buildBranchCode(string $branchName): string
    {
        $code = strtoupper($branchName);
        $code = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N', 'A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $code,
        );
        $code = preg_replace('/[^A-Z0-9]+/', '_', $code) ?? $code;
        $code = trim($code, '_');

        if ($code === '') {
            $code = 'BR_' . substr(md5($branchName), 0, 8);
        }

        return substr($code, 0, 60);
    }
}
