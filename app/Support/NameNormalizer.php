<?php

namespace App\Support;

use Illuminate\Support\Str;

class NameNormalizer {

    public static function normalize(?string $value): string {
        if (blank($value)) {
            return '';
        }
        $value = Str::ascii($value);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[^A-Z0-9\s]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }

    public static function normalizeBranch(?string $value): string {
        return self::normalize($value);
    }

    public static function normalizeConcept(?string $value): string {
        return self::normalize($value);
    }

}
