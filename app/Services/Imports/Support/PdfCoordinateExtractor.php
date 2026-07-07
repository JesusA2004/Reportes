<?php

namespace App\Services\Imports\Support;

use Smalot\PdfParser\Page;

/**
 * Reconstructs visual rows/columns from a PDF page using word coordinates
 * instead of relying on the linear text order returned by getText().
 *
 * Some PDF exporters draw text objects out of visual order (e.g. section
 * headers get emitted after the table bodies), which breaks naive
 * line-by-line text parsing. Grouping words by their Y position (with a
 * small tolerance for sub/superscript baseline drift) and sorting by X
 * within each row reconstructs the table exactly as a human would read it.
 */
class PdfCoordinateExtractor
{
    /**
     * @return array<int, array{y: float, tokens: array<int, array{x: float, text: string}>, text: string}>
     *         Rows ordered top-to-bottom (PDF Y descending); tokens within a row ordered left-to-right.
     */
    public static function extractRows(Page $page, float $yTolerance = 1.5): array
    {
        $words = [];
        foreach ($page->getDataTm() as $entry) {
            $tm   = $entry[0] ?? null;
            $text = trim((string) ($entry[1] ?? ''));
            if ($text === '' || !is_array($tm)) {
                continue;
            }
            $words[] = [
                'x'    => (float) ($tm[4] ?? 0),
                'y'    => (float) ($tm[5] ?? 0),
                'text' => $text,
            ];
        }

        if (empty($words)) {
            return [];
        }

        usort($words, static fn (array $a, array $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);

        $rows = [];
        foreach ($words as $w) {
            $target = null;
            foreach ($rows as $idx => $row) {
                if (abs($row['y'] - $w['y']) <= $yTolerance) {
                    $target = $idx;
                    break;
                }
            }
            if ($target === null) {
                $rows[] = ['y' => $w['y'], 'tokens' => []];
                $target = array_key_last($rows);
            }
            $rows[$target]['tokens'][] = ['x' => $w['x'], 'text' => $w['text']];
        }

        foreach ($rows as &$row) {
            usort($row['tokens'], static fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $row['text'] = implode(' ', array_column($row['tokens'], 'text'));
        }
        unset($row);

        usort($rows, static fn (array $a, array $b) => $b['y'] <=> $a['y']);

        return array_values($rows);
    }

    /**
     * Returns the token text whose X is nearest to $targetX, or null if the
     * row is empty or the nearest token is farther than $maxDistance.
     */
    public static function nearestToken(array $row, float $targetX, float $maxDistance = 8.0): ?string
    {
        $best     = null;
        $bestDist = INF;
        foreach ($row['tokens'] ?? [] as $tok) {
            $d = abs($tok['x'] - $targetX);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best     = $tok['text'];
            }
        }
        return ($best !== null && $bestDist <= $maxDistance) ? $best : null;
    }

    public static function normalizeUpper(string $value): string
    {
        $value = trim(mb_strtoupper($value, 'UTF-8'));
        return strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }
}
