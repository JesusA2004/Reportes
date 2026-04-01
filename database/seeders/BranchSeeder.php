<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BranchSeeder extends Seeder {

    public function run(): void {
        $branches = [
            'Atlacomulco',
            'Ixtlahuaca',
            'Tenango',
            'Tula',
            'Cuernavaca',
            'San Luis Potosi',
            'Miacatlan',
            'Atlixco',
            'Huamantla',
            'Tlaxcala',
            'Cordoba',
            'Orizaba',
        ];
        foreach ($branches as $name) {
            Branch::updateOrCreate(
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
