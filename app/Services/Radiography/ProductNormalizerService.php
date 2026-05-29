<?php

namespace App\Services\Radiography;

/**
 * Central product normalization result.
 */
class ProductNormalizationResult
{
    public function __construct(
        public readonly string  $rawProduct,
        public readonly string  $normalizedProduct,
        public readonly string  $family,
        public readonly ?int    $term,
        public readonly bool    $isOperational,
        public readonly bool    $isCrece,
        public readonly bool    $isDaily,
        public readonly bool    $isWeekly,
        public readonly bool    $isRestructure,
        public readonly bool    $isFunding,
        public readonly bool    $isInsurance,
        public readonly bool    $isExcluded,
        public readonly string  $reason,
    ) {}

    public function toArray(): array
    {
        return [
            'raw_product'         => $this->rawProduct,
            'normalized_product'  => $this->normalizedProduct,
            'family'              => $this->family,
            'term'                => $this->term,
            'is_operational'      => $this->isOperational,
            'is_crece'            => $this->isCrece,
            'is_daily'            => $this->isDaily,
            'is_weekly'           => $this->isWeekly,
            'is_restructure'      => $this->isRestructure,
            'is_funding'          => $this->isFunding,
            'is_insurance'        => $this->isInsurance,
            'is_excluded'         => $this->isExcluded,
            'reason'              => $this->reason,
        ];
    }
}

/**
 * Single source of truth for product normalization.
 *
 * Replaces scattered regex in BranchResolverService, snapshot, importers, and commands.
 * All rules live here — importers call normalize(), snapshot uses normalizeForDisplay().
 */
class ProductNormalizerService
{
    // ── Classification patterns ──────────────────────────────────────────────

    private const RESTRUCTURE_PATTERN = 'REESTRUCTURA|UNIFICACION|UNIFICACIÓN|MIGRACION|MIGRACIÓN|INSOLUTOS';
    private const FUNDING_PATTERN     = 'RECURSOS PROPIOS|FONDEO EXTERNO|LINEA DE CREDITO|LÍNEA DE CRÉDITO|CAPITAL EXTERNO';
    private const INSURANCE_PATTERN   = 'SEGURO|PÓLIZA|POLIZA|COBERTURA';
    private const CRECE_PATTERN       = 'CRECE';
    private const DAILY_PATTERN       = 'DIARIO';
    private const WEEKLY_PATTERN      = 'SEMANAL';

    // Products that are real operational products and must NOT be reclassified
    private const PASSTHROUGH_OPERATIONAL = [
        'A LA MEDIDA',
        'COMADRES',
        'CREDITO COMADRES',
        'PRIORITY',
        'MEMBRESÍA PRIORITY',
        'GANACREDITS',
        'GANACREDIT',
        'CORPORATIVO MENSUAL',
        'CREDITO CORPORATIVO',
        'CRÉDITO CORPORATIVO',
        'PRESTAMO PERSONAL MENSUAL',
        'CREDITO CONSUMO',
    ];

    // OFICINA MR LANA: the product name is the office itself — valid passthrough
    // but flagged for review since it lacks branch assignment
    private const OFFICE_PRODUCT_PATTERN = 'OFICINA MR LANA';

    /**
     * Normalize a raw product name.
     *
     * @param string      $rawProduct  Value from the "Producto de crédito" column
     * @param int|null    $term        "Número de pagos" / plazo (e.g. 12, 24, 40)
     * @param string|null $periodicity "Periodicidad" (SEMANAL / DIARIO / MENSUAL)
     * @param array       $rawPayload  Extra context from the raw row if available
     */
    public function normalize(
        string  $rawProduct,
        ?int    $term        = null,
        ?string $periodicity = null,
        array   $rawPayload  = []
    ): ProductNormalizationResult {
        $raw   = trim($rawProduct);
        $upper = strtoupper($raw);

        if ($raw === '') {
            return $this->result('', 'Sin producto', 'unknown', null, false, false, false, false, false, false, false, true, 'Producto vacío');
        }

        // ── 1. Restructure / non-operational ────────────────────────────────
        if (preg_match('/' . self::RESTRUCTURE_PATTERN . '/i', $upper)) {
            return $this->result($raw, $raw, 'restructura', null, false, false, false, false, true, false, false, true, 'Reestructura/unificación no es producto operativo');
        }

        // ── 2. Funding / línea ───────────────────────────────────────────────
        if (preg_match('/' . self::FUNDING_PATTERN . '/i', $upper)) {
            return $this->result($raw, $raw, 'fondeo', null, false, false, false, false, false, true, false, true, 'Fondeo/línea no es producto operativo, es fuente de capital');
        }

        // ── 3. Insurance ─────────────────────────────────────────────────────
        if (!str_contains($upper, 'CRECE') && preg_match('/' . self::INSURANCE_PATTERN . '/i', $upper)) {
            return $this->result($raw, $raw, 'seguro', null, false, false, false, false, false, false, true, true, 'Seguro/póliza no es producto de crédito operativo');
        }

        // ── 4. CRECE family ──────────────────────────────────────────────────
        if (str_contains($upper, 'CRECE')) {
            return $this->normalizeCrece($raw, $upper, $term, $periodicity);
        }

        // ── 5. DIARIO (daily credit) ─────────────────────────────────────────
        if (str_contains($upper, 'DIARIO')) {
            return $this->normalizeDaily($raw, $upper, $term, $periodicity);
        }

        // ── 6. SEMANAL (weekly credit) ───────────────────────────────────────
        if (str_contains($upper, 'SEMANAL')) {
            return $this->normalizeWeekly($raw, $upper, $term, $periodicity);
        }

        // ── 7. Bare i/s codes ────────────────────────────────────────────────
        if (preg_match('/^[Ii](20|30|40|60)$/', $raw, $m)) {
            $t = (int)$m[1];
            return $this->result($raw, 'i'.$t, 'diario', $t, true, false, true, false, false, false, false, false, "Código diario explícito i{$t}");
        }
        if (preg_match('/^[Ss](12|16|20|24)$/', $raw, $m)) {
            $t = (int)$m[1];
            return $this->result($raw, 's'.$t, 'semanal', $t, true, false, false, true, false, false, false, false, "Código semanal explícito s{$t}");
        }

        // ── 8. Passthrough operational products ──────────────────────────────
        foreach (self::PASSTHROUGH_OPERATIONAL as $pt) {
            if (str_contains($upper, strtoupper($pt))) {
                return $this->result($raw, $raw, 'operativo', null, true, false, false, false, false, false, false, false, "Producto operativo conocido: {$pt}");
            }
        }

        // ── 9. OFICINA MR LANA (office-as-product) ──────────────────────────
        if (str_contains($upper, self::OFFICE_PRODUCT_PATTERN)) {
            return $this->result($raw, $raw, 'oficina_producto', null, true, false, false, false, false, false, false, false, 'Producto = nombre de oficina. Revisar si debe reclasificarse o excluirse.');
        }

        // ── 10. Unknown but assume operational ───────────────────────────────
        return $this->result($raw, $raw, 'operativo', null, true, false, false, false, false, false, false, false, 'Sin regla específica, clasificado operativo por defecto');
    }

    /**
     * Quick normalize for use in importers (returns normalized name only).
     * Equivalent to BranchResolverService::normalizeProduct() but uses central rules.
     */
    public function normalizeString(string $rawProduct, ?int $term = null, ?string $periodicity = null): string
    {
        return $this->normalize($rawProduct, $term, $periodicity)->normalizedProduct;
    }

    /**
     * Group display name for UI (maps variants to canonical family label).
     */
    public function displayGroup(string $normalizedProduct): string
    {
        $upper = strtoupper($normalizedProduct);

        if (preg_match('/^CRECE12\s*SAC/', $upper)) return 'CRECE12 SAC';
        if (preg_match('/^CRECE24\s*SAC/', $upper)) return 'CRECE24 SAC';
        if (preg_match('/^CRECE12/', $upper))        return 'CRECE12';
        if (preg_match('/^CRECE24/', $upper))        return 'CRECE24';
        if (preg_match('/^CRECE/',   $upper))        return 'CRECE';
        if (preg_match('/^i(20|30|40|60)$/i', $normalizedProduct, $m)) return 'i'.$m[1];
        if (preg_match('/^s(12|16|20|24)$/i', $normalizedProduct, $m)) return 's'.$m[1];

        return $normalizedProduct;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function normalizeCrece(string $raw, string $upper, ?int $term, ?string $periodicity): ProductNormalizationResult
    {
        $hasSac = str_contains($upper, 'SAC');

        // Determine plazo: from product name first, then from $term parameter
        $plazo = $this->extractCreceTerm($upper, $term);

        $normalized = $this->buildCreceNorm($plazo, $hasSac);
        $reason = $plazo !== null
            ? "CRECE con plazo {$plazo}" . ($hasSac ? ' SAC' : '') . " → {$normalized}"
            : "CRECE sin plazo determinado → {$normalized}";

        return $this->result($raw, $normalized, 'crece', $plazo, true, true, false, false, false, false, false, false, $reason);
    }

    private function extractCreceTerm(string $upper, ?int $term): ?int
    {
        // Explicit number in product name wins
        if (preg_match('/CRECE\s*(\d+)/', $upper, $m)) {
            return (int)$m[1];
        }
        // "CRECE 12 ..." → 12
        if (preg_match('/CRECE\s+(\d+)/', $upper, $m)) {
            return (int)$m[1];
        }
        // Fallback to term parameter
        return $term;
    }

    private function buildCreceNorm(?int $plazo, bool $hasSac): string
    {
        if ($plazo === 12) return 'CRECE12' . ($hasSac ? ' SAC' : '');
        if ($plazo === 24) return 'CRECE24' . ($hasSac ? ' SAC' : '');
        if ($plazo !== null) return "CRECE{$plazo}" . ($hasSac ? ' SAC' : '');
        return 'CRECE' . ($hasSac ? ' SAC' : '');
    }

    private function normalizeDaily(string $raw, string $upper, ?int $term, ?string $periodicity): ProductNormalizationResult
    {
        // "I20" or "DIARIO I20" style embedded code
        if (preg_match('/[Ii](20|30|40|60)/', $upper, $m)) {
            $t = (int)$m[1];
            return $this->result($raw, 'i'.$t, 'diario', $t, true, false, true, false, false, false, false, false, "Código diario i{$t} embebido en nombre");
        }

        // "DIARIO 40" / "DIARIO 30" — number directly after DIARIO (no I prefix)
        if (preg_match('/DIARIO\s+(20|30|40|60)\b/', $upper, $m)) {
            $t = (int)$m[1];
            return $this->result($raw, 'i'.$t, 'diario', $t, true, false, true, false, false, false, false, false, "DIARIO + plazo {$t} en nombre → i{$t}");
        }

        // term parameter (e.g. num_payments=40 with periodicidad=DIARIO)
        if ($term !== null && in_array($term, [20, 30, 40, 60], true)) {
            return $this->result($raw, 'i'.$term, 'diario', $term, true, false, true, false, false, false, false, false, "DIARIO + num_pagos={$term} → i{$term}");
        }

        // "CREDITO DIARIO" without plazo — can't normalize, mark for review
        $reason = 'DIARIO sin plazo determinado — re-importar con columna Número de pagos para resolución correcta';
        return $this->result($raw, $raw, 'diario', null, true, false, true, false, false, false, false, false, $reason);
    }

    private function normalizeWeekly(string $raw, string $upper, ?int $term, ?string $periodicity): ProductNormalizationResult
    {
        // Embedded number in product name ("CREDITO SEMANAL 12")
        if (preg_match('/SEMANAL\s*(\d+)\b/', $upper, $m)) {
            $t = (int)$m[1];
            if (in_array($t, [12, 16, 20, 24], true)) {
                return $this->result($raw, 's'.$t, 'semanal', $t, true, false, false, true, false, false, false, false, "SEMANAL + plazo {$t} embebido → s{$t}");
            }
        }

        // "SEMANAL" generic with number embedded differently ("s12/s16" notation)
        if (preg_match('/\b(12|16|20|24)\b/', $upper, $m)) {
            $t = (int)$m[1];
            $hasCrece = str_contains($upper, 'CRECE');
            if (!$hasCrece) {
                return $this->result($raw, 's'.$t, 'semanal', $t, true, false, false, true, false, false, false, false, "SEMANAL + plazo {$t} en nombre → s{$t}");
            }
        }

        // term parameter
        if ($term !== null && in_array($term, [12, 16, 20, 24], true)) {
            return $this->result($raw, 's'.$term, 'semanal', $term, true, false, false, true, false, false, false, false, "SEMANAL + num_pagos={$term} → s{$term}");
        }

        return $this->result($raw, $raw, 'semanal', null, true, false, false, true, false, false, false, false, 'SEMANAL sin plazo determinado');
    }

    private function result(
        string $raw, string $normalized, string $family, ?int $term,
        bool $operational, bool $crece, bool $daily, bool $weekly,
        bool $restructure, bool $funding, bool $insurance, bool $excluded,
        string $reason
    ): ProductNormalizationResult {
        return new ProductNormalizationResult(
            rawProduct:        $raw,
            normalizedProduct: $normalized,
            family:            $family,
            term:              $term,
            isOperational:     $operational,
            isCrece:           $crece,
            isDaily:           $daily,
            isWeekly:          $weekly,
            isRestructure:     $restructure,
            isFunding:         $funding,
            isInsurance:       $insurance,
            isExcluded:        $excluded,
            reason:            $reason,
        );
    }
}
