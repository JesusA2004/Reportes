<?php

namespace Database\Seeders;

use App\Models\FinancialProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FinancialProductSeeder extends Seeder {

    public function run(): void {
        $products = [
            'S12',
            'Comadres',
            'S16',
            'S20',
        ];

        foreach ($products as $name) {
            FinancialProduct::updateOrCreate(
                ['normalized_name' => Str::upper(Str::ascii($name))],
                [
                    'code' => Str::slug($name, '_'),
                    'name' => $name,
                    'normalized_name' => Str::upper(Str::ascii($name)),
                    'is_active' => true,
                ]
            );
        }
    }
}
