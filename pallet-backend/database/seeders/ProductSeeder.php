<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['ean' => '7790000000001', 'name' => 'Yerba Mate 1kg'],
            ['ean' => '7790000000002', 'name' => 'Azúcar 1kg'],
            ['ean' => '7790000000003', 'name' => 'Arroz 1kg'],
            ['ean' => '7790000000004', 'name' => 'Fideos 500g'],
            ['ean' => '7790000000005', 'name' => 'Aceite 900ml'],
            ['ean' => '7790000000006', 'name' => 'Leche 1L'],
            ['ean' => '7790000000007', 'name' => 'Galletitas 300g'],
            ['ean' => '7790000000008', 'name' => 'Atún (NO) - ejemplo reemplazable'],
            ['ean' => '7790000000009', 'name' => 'Tomate triturado 520g'],
            ['ean' => '7790000000010', 'name' => 'Harina 1kg'],
            ['ean' => '7790000000011', 'name' => 'Sal fina 500g'],
            ['ean' => '7790000000012', 'name' => 'Detergente 750ml'],
            ['ean' => '7790000000013', 'name' => 'Papel higiénico x4'],
            ['ean' => '7790000000014', 'name' => 'Lavandina 1L'],
            ['ean' => '7790000000015', 'name' => 'Café 250g'],
        ];

        foreach ($products as $p) {
            Product::updateOrCreate(
                ['ean' => $p['ean']],
                ['name' => $p['name']]
            );
        }
    }
}
