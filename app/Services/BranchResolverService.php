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
     * Resolves a real (sucursal) branch name from a route/office name.
     * Priority: code prefix first, then route-name table, then partial real-branch match.
     * Returns null when the mapping is unknown.
     */
    public function resolveRealBranchFromRoute(?string $routeName, ?string $code = null): ?string
    {
        // 1. Code prefix beats everything
        if ($code) {
            $resolved = $this->resolveBranchNameFromCode($code);
            if ($resolved) {
                return $resolved;
            }
        }

        if (!$routeName) {
            return null;
        }

        // Normalize: uppercase + strip accents so DB values like "Fortín" match "FORTIN"
        $upper = $this->normalizeRouteKey($routeName);

        // 2. Explicit route → real branch table (evaluated FIRST — overrides fuzzy prefix)
        //    All keys must be accent-free UPPERCASE.
        static $routeMap = [
            // ── Orizaba area ─────────────────────────────────────────────────
            'ORIZABA'                => 'ORIZABA',
            'ORIZABA SUR'            => 'ORIZABA',
            'ORIZABA NORTE'          => 'ORIZABA',
            'ORIZABA CENTRO'         => 'ORIZABA',
            'RIO BLANCO'             => 'ORIZABA',
            'RIO BLANCO NORTE'       => 'ORIZABA',
            'RIO BLANCO SUR'         => 'ORIZABA',
            'LA PERLA'               => 'ORIZABA',
            'IXTACZOQUITLAN'         => 'ORIZABA',
            'IXTACZOQUITLAN SUR'     => 'ORIZABA',
            'IXTACZOQUITLAN NORTE'   => 'ORIZABA',
            'IXTACZOQUITLAN CENTRO'  => 'ORIZABA',
            'IXHUATANCILLO'          => 'ORIZABA',
            'IXHUATANCILLO SUR'      => 'ORIZABA',
            'FORTIN'                 => 'ORIZABA',
            'NOGALES'                => 'ORIZABA',
            'NOGALES SUR'            => 'ORIZABA',
            'NOGALES NORTE'          => 'ORIZABA',
            'CIUDAD MENDOZA'         => 'ORIZABA',
            'RAFAEL DELGADO'         => 'ORIZABA',
            'MOTZORONGO'             => 'ORIZABA',
            'TLILAPAN'               => 'ORIZABA',
            'JALAPILLA'              => 'ORIZABA',
            'LOPEZ ARIAS'            => 'ORIZABA',
            'LOPEZ MATEOS'           => 'ORIZABA',
            // ── Cordoba area ─────────────────────────────────────────────────
            'CORDOBA'                => 'CORDOBA',
            'CORDOBA CENTRO'         => 'CORDOBA',
            'CENTRO B'               => 'CORDOBA',
            'CENTRO C'               => 'CORDOBA',
            'CENTRO D'               => 'CORDOBA',
            'COSCOMATEPEC'           => 'CORDOBA',
            'COSCOMATEPEC DE BRAVO'  => 'CORDOBA',
            'CHOCAMAN'               => 'CORDOBA',
            'AMATLÁN DE LOS REYES'   => 'CORDOBA',
            'AMATLAN DE LOS REYES'   => 'CORDOBA',
            'YANGA'                  => 'CORDOBA',
            // ── Cuernavaca area ──────────────────────────────────────────────
            'CUERNAVACA'             => 'CUERNAVACA',
            'CUERNAVACA GTE'         => 'CUERNAVACA',
            'CUERNAVACA ORIENTE'     => 'CUERNAVACA',
            'CUERNAVACA NORTE'       => 'CUERNAVACA',
            'CUERNAVACA SUR'         => 'CUERNAVACA',
            'YAUTEPEC'               => 'CUERNAVACA',
            'JIUTEPEC'               => 'CUERNAVACA',
            'CUAUTLA'                => 'CUERNAVACA',
            'CUAUTLAPAN'             => 'CUERNAVACA',
            'DOS RIOS'               => 'CUERNAVACA',
            'CUAUTLAPAN / DOS RIOS'  => 'CUERNAVACA',
            'CUAUTLAPAN/DOS RIOS'    => 'CUERNAVACA',
            'VILLA ALTA'             => 'CUERNAVACA',
            'IZACAN'                 => 'CUERNAVACA',
            'IZTACAN'                => 'CUERNAVACA',
            'PASO DEL MACHO'         => 'CUERNAVACA',
            'XOCOCOTLA'              => 'CUERNAVACA',
            'JOJUTLA'                => 'CUERNAVACA',
            'XOCOCOTLA - JOJUTLA'    => 'CUERNAVACA',
            'XOCOCOTLA-JOJUTLA'      => 'CUERNAVACA',
            // ── Huamantla ────────────────────────────────────────────────────
            'HUAMANTLA'              => 'HUAMANTLA',
            'HUAMANTLA (VENCIDOS)'   => 'HUAMANTLA',
            'HUAMANTLA VENCIDOS'     => 'HUAMANTLA',
            // ── San Juan del Río ─────────────────────────────────────────────
            'SJR'                    => 'SAN JUAN DEL RÍO',
            'SAN JUAN DEL RIO'       => 'SAN JUAN DEL RÍO',
            'SAN JUAN DEL RIO'       => 'SAN JUAN DEL RÍO',
            'SAN JUAN DEL RIO CENTRO'=> 'SAN JUAN DEL RÍO',
            // ── San Luis Potosí ──────────────────────────────────────────────
            'SLP'                    => 'SAN LUIS POTOSI',
            'SAN LUIS POTOSI'        => 'SAN LUIS POTOSI',
            'SAN LUIS POTOSI CENTRO' => 'SAN LUIS POTOSI',
            // ── Ixtlahuaca ───────────────────────────────────────────────────
            'IXTLAHUACA'             => 'IXTLAHUACA',
            'IXTLAHUACA CENTRO'      => 'IXTLAHUACA',
            // ── Tenango del Valle ────────────────────────────────────────────
            'TENANGO'                => 'TENANGO DEL VALLE',
            'TENANGO DEL VALLE'      => 'TENANGO DEL VALLE',
            // ── Tula / Hidalgo area ──────────────────────────────────────────
            'TULA'                   => 'TULA',
            'TULA CENTRO'            => 'TULA',
            'TULA DE ALLENDE'        => 'TULA',
            'TEZONTEPEC'             => 'TULA',
            'TEZONTEPEC DE ALDAMA'   => 'TULA',
            'APIZ CEN'               => 'TLAXCALA',
            'APIZACO'                => 'TLAXCALA',
            'APIZACO CENTRO'         => 'TLAXCALA',
            '20 DE NOVIEMBRE'        => 'TLAXCALA',
            'APIZ-CEN'               => 'TLAXCALA',
            'APIZ-NOR'               => 'TLAXCALA',
            'APIX-SUR'               => 'TLAXCALA',
            'CHIAUTEMPAN'            => 'TLAXCALA',
            'CONTLA'                 => 'TLAXCALA',
            'TECOAC'                 => 'TLAXCALA',
            'TETLANOHCAN'            => 'TLAXCALA',
            'ZACATELCO'              => 'TLAXCALA',
            'TEPEJI'                 => 'TULA',
            'TLAXCOAPAN'             => 'TULA',
            'ATITALAQUIA'            => 'TULA',
            // ── Others (self-map) ────────────────────────────────────────────
            'ATLACOMULCO'            => 'ATLACOMULCO',
            'ATLIXCO'                => 'ATLIXCO',
            'MIACATLAN'              => 'MIACATLAN',
            'TLAXCALA'               => 'TLAXCALA',
            'AGUASCALIENTES'         => 'AGUASCALIENTES',
            'CHIHUAHUA'              => 'CHIHUAHUA',
            'DURANGO'                => 'DURANGO',
            'PACHUCA'                => 'PACHUCA',
            'TULANCINGO'             => 'TULANCINGO',
            // ── Corporativo ──────────────────────────────────────────────────
            'CORPORATIVO'            => 'CORPORATIVO',
            'CORP'                   => 'CORPORATIVO',
            // ── Orizaba: typo/spelling variants ──────────────────────────────
            'FORTN'                  => 'ORIZABA',
            'IXTACZOQUITLAM'         => 'ORIZABA',
            'IXTACZOQUITLAM SUR'     => 'ORIZABA',
            'IXTACZOQUITLAM NORTE'   => 'ORIZABA',
            'IXTACZOQUITLAM CENTRO'  => 'ORIZABA',
            'IXHUATLANCILLO'         => 'ORIZABA',
            'IXHUATLANCILLO SUR'     => 'ORIZABA',
            // ── Cordoba extra sub-offices ─────────────────────────────────────
            'CORDOBA ALAMEDA'        => 'CORDOBA',
            'CORDOBA CENTRO 2'       => 'CORDOBA',
            'CORDOBA FORTIN'         => 'CORDOBA',
            'CORDOBA PADELMA'        => 'CORDOBA',
            'ATZACAN'                => 'CORDOBA',
            // ── Atlixco abbreviated routes ────────────────────────────────────
            'ATLIX-GTE'              => 'ATLIXCO',
            'ATLIX-MATP'             => 'ATLIXCO',
            'ATLIX-NOR'              => 'ATLIXCO',
            // ── San Luis Potosi routes ────────────────────────────────────────
            'CENTRO-SLP'             => 'SAN LUIS POTOSI',
            'ORIENTE-SLP'            => 'SAN LUIS POTOSI',
            'SOLEDAD'                => 'SAN LUIS POTOSI',
            // ── San Juan del Rio routes ───────────────────────────────────────
            'SJR II'                 => 'SAN JUAN DEL RÍO',
            'SJR 2'                  => 'SAN JUAN DEL RÍO',
            'ESEQUIEL MONTES'        => 'SAN JUAN DEL RÍO',
            // ── Cuernavaca extra routes ───────────────────────────────────────
            'TEMIXCO'                => 'CUERNAVACA',
            'XOCHITEPEC'             => 'CUERNAVACA',
            'HUITZILAC'              => 'CUERNAVACA',
            'XOXOCOTLA - JOJUTLA'    => 'CUERNAVACA',
            'XOXOCOTLA  - JOJUTLA'   => 'CUERNAVACA',
            'XOXOCOTLA-JOJUTLA'      => 'CUERNAVACA',
            'PNT-IXTLA'              => 'CUERNAVACA',
            // ── Miacatlan routes ──────────────────────────────────────────────
            'MAZATEPEC'              => 'MIACATLAN',
            'TETECALA'               => 'MIACATLAN',
            // ── Ixtlahuaca routes ─────────────────────────────────────────────
            'IXT - SANTA ANA'        => 'IXTLAHUACA',
            'JIQUIPILCO'             => 'IXTLAHUACA',
            'ALMOLOYA DE JUARES'     => 'IXTLAHUACA',
            'ALMOLOYA DE JUAREZ'     => 'IXTLAHUACA',
            'TEMOAYA'                => 'IXTLAHUACA',
            // ── Tenango del Valle routes ──────────────────────────────────────
            'TENANGO-1'              => 'TENANGO DEL VALLE',
            'TENANCINGO-1'           => 'TENANGO DEL VALLE',
            'CALIMAYA-2'             => 'TENANGO DEL VALLE',
            'CAPULHUAC'              => 'TENANGO DEL VALLE',
            'METEPEC-2'              => 'TENANGO DEL VALLE',
            'SAN BARTOLO MORELOS'    => 'TENANGO DEL VALLE',
        ];

        if (isset($routeMap[$upper])) {
            return $routeMap[$upper];
        }

        // 3. Route name might be a short branch code (e.g. "ORI", "TLA", "SJR", "CH")
        //    Only try for exact 2-3 char codes to avoid false matches like CHOL → CHIHUAHUA.
        if (strlen($upper) <= 3) {
            $fromCode = $this->resolveBranchNameFromCode($upper);
            if ($fromCode) {
                return $fromCode;
            }
        }

        // 4. Partial prefix: route name starts with a real branch name
        foreach (array_values(array_merge(self::PREFIX_3, self::PREFIX_2)) as $realBranch) {
            if (str_starts_with($upper, $this->normalizeRouteKey($realBranch))) {
                return $realBranch;
            }
        }

        return null;
    }

    /**
     * Uppercases a route key and strips Spanish accent marks so that
     * "Fortín" and "FORTIN" both match the accent-free routeMap keys.
     */
    private function normalizeRouteKey(string $value): string
    {
        $value = trim(mb_strtoupper($value, 'UTF-8'));
        return strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);
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

    /**
     * Returns true only for real operational sucursales (prefix-resolved names).
     * Routes like FORTIN, CENTRO B, JALAPILLA, etc. return false.
     */
    public function isRealOperationalBranch(string $name): bool
    {
        $key      = $this->normalizeRouteKey($name);
        $realKeys = array_map(fn ($n) => $this->normalizeRouteKey($n), $this->realOperationalBranches());
        return in_array($key, $realKeys, true);
    }

    /**
     * Returns true for real sucursales + CORPORATIVO.
     * Use this to decide whether a branch name is reportable.
     */
    public function isRealReportBranch(string $name): bool
    {
        if ($this->isRealOperationalBranch($name)) {
            return true;
        }
        return $this->normalizeRouteKey($name) === 'CORPORATIVO';
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
