<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Detects and quantifies Savehearts (insurance) rows in Lendus Ingresos Cobranza.
 *
 * Rules:
 * - Non-CRECE Savehearts: excluded completely from all metrics.
 * - CRECE Savehearts: excluded from normal recovery; only 30% of Total goes to EBITDA
 *   as a separate component (ingreso_savehearts_crece_30).
 */
class SaveheartsRuleService
{
    // Normalized keywords that identify a Savehearts/insurance row via the Operación column
    private const OPERATION_KEYWORDS = ['seguro', 'seguros', 'poliza', 'polizas', 'cobertura'];

    // Normalized keywords that identify a Savehearts row via the Concepto column
    private const CONCEPT_KEYWORDS = ['savehearts', 'cobertura savehearts', 'cobertura credito grupal', 'cobertura'];

    // Normalized keywords that identify a Savehearts row via the Producto column
    private const PRODUCT_KEYWORDS = ['seguro', 'seguros', 'savehearts', 'cobertura'];

    // CRECE product keyword
    private const CRECE_KEYWORD = 'crece';

    /**
     * Returns true when a mapped row is a Savehearts/insurance movement.
     *
     * Checks:
     *   - row['operation'] contains SEGURO / SEGUROS
     *   - OR row['concept'] contains SAVEHEARTS / COBERTURA SAVEHEARTS
     *
     * Case-insensitive, accent-insensitive, whitespace-tolerant.
     *
     * @param array{operation?: string|null, concept?: string|null} $row
     */
    public function isSaveheartsRow(array $row): bool
    {
        $op      = $this->normalize($row['operation'] ?? '');
        $concept = $this->normalize($row['concept'] ?? '');
        $product = $this->normalize($row['product_name'] ?? '');

        foreach (self::OPERATION_KEYWORDS as $kw) {
            if ($op !== '' && str_contains($op, $this->normalize($kw))) {
                return true;
            }
        }

        foreach (self::CONCEPT_KEYWORDS as $kw) {
            if ($concept !== '' && str_contains($concept, $this->normalize($kw))) {
                return true;
            }
        }

        foreach (self::PRODUCT_KEYWORDS as $kw) {
            if ($product !== '' && str_contains($product, $this->normalize($kw))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the product is any CRECE variant.
     * Handles: CRECE, CRECE12, CRECE 12, CRECE SAC, CRECE12 SAC,
     * CRECE CON/SIN ACTIVIDAD COMERCIAL, CRECE24, CRECE 24.
     * Optional $duration (from a separate column): if product="CRECE" and duration=12,
     * treats it as CRECE 12.
     */
    public function isCreceProduct(string $product, ?int $duration = null): bool
    {
        $norm = $this->normalize($product);
        if (!str_contains($norm, self::CRECE_KEYWORD)) {
            return false;
        }
        // Any product whose normalized form contains 'crece' is a CRECE product.
        // The duration parameter is used externally for sub-classification only.
        return true;
    }

    /**
     * Returns the canonical CRECE product label (e.g. "CRECE 12", "CRECE 24", "CRECE").
     */
    public function creceProductLabel(string $product, ?int $duration = null): string
    {
        $norm = $this->normalize($product);

        if (str_contains($norm, 'crece') && (str_contains($norm, '24') || $duration === 24)) {
            return 'CRECE 24';
        }
        if (str_contains($norm, 'crece') && (str_contains($norm, '12') || $duration === 12)) {
            return 'CRECE 12';
        }

        return 'CRECE';
    }

    /**
     * Returns the company's 30% share for CRECE Savehearts rows.
     * Returns 0.0 for non-Savehearts or non-CRECE rows.
     *
     * @param array{operation?: string|null, concept?: string|null, product_name?: string|null, total?: float|null, total_amount?: float|null} $row
     */
    public function saveheartsCompanyShare(array $row): float
    {
        if (!$this->isSaveheartsRow($row)) {
            return 0.0;
        }

        if (!$this->isCreceProduct((string) ($row['product_name'] ?? ''))) {
            return 0.0;
        }

        $total = (float) ($row['total'] ?? $row['total_amount'] ?? 0.0);

        return round($total * 0.30, 2);
    }

    private function normalize(string $s): string
    {
        return (string) Str::of($s)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim();
    }
}
