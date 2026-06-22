<?php

namespace App\Support;

class ExpenseStatusNormalizer
{
    private const BILLABLE = [
        'AUTORIZADO',
        'PAGADA',
        'PAGADO',
        'PAGO AUTORIZADO',
        'POR COMPROBAR',
        'COMPROBADA',
        'COMPROBACION ACEPTADA',
    ];

    public static function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
        $value = str_replace(['-', '_'], ' ', $value);
        return (string) preg_replace('/\s+/', ' ', $value);
    }

    public static function isBillable(?string $raw): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }

        $norm = self::normalize($raw);

        foreach (self::BILLABLE as $billable) {
            if ($norm === $billable) {
                return true;
            }
        }

        return false;
    }
}
