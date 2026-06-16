<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Guarantees the 13 operative financial branches always exist in the DB.
 * Safe to run multiple times — uses firstOrCreate.
 * SAN JUAN DEL RÍO is period-aware: included when it has movement in a period.
 */
class BranchSeeder extends Seeder
{
    private const OPERATIVE_BRANCHES = [
        'ATLACOMULCO',
        'ATLIXCO',
        'CORDOBA',
        'CUERNAVACA',
        'HUAMANTLA',
        'IXTLAHUACA',
        'MIACATLAN',
        'ORIZABA',
        'SAN JUAN DEL RÍO',
        'SAN LUIS POTOSI',
        'TENANGO DEL VALLE',
        'TLAXCALA',
        'TULA',
    ];

    public function run(): void
    {
        foreach (self::OPERATIVE_BRANCHES as $name) {
            Branch::query()->firstOrCreate(
                ['normalized_name' => $this->normalize($name)],
                [
                    'name'      => $name,
                    'code'      => $this->buildCode($name),
                    'is_active' => true,
                ],
            );
        }
    }

    private function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );
    }

    private function buildCode(string $name): string
    {
        $code = mb_strtoupper($name, 'UTF-8');
        $code = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $code,
        );
        $code = preg_replace('/[^A-Z0-9]+/', '_', $code) ?? $code;
        return substr(trim($code, '_'), 0, 60);
    }
}
