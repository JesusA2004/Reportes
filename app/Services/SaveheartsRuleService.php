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
    // Normalized keywords that identify a Savehearts row via the Operación column
    private const OPERATION_KEYWORDS = ['seguro', 'seguros'];

    // Normalized keywords that identify a Savehearts row via the Concepto column
    private const CONCEPT_KEYWORDS = ['savehearts', 'cobertura savehearts'];

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

        return false;
    }

    /**
     * Returns true when the product is any CRECE variant.
     * Case-insensitive, accent-insensitive.
     */
    public function isCreceProduct(string $product): bool
    {
        return str_contains($this->normalize($product), self::CRECE_KEYWORD);
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
