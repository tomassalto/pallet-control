<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function showByEan(string $ean)
    {
        $clean = preg_replace('/\D+/', '', $ean);

        $product = Product::where('ean', $clean)->first();

        if (!$product) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($product);
    }

    // Upsert: crea o actualiza por EAN
    public function store(Request $request)
    {
        $data = $request->validate([
            'ean'  => ['required', 'string', 'max:32'], // no unique si usás updateOrCreate
            'name' => ['required', 'string', 'max:255'],
        ]);

        $ean = preg_replace('/\D+/', '', $data['ean']);
        $name = trim($data['name']);
        $last4 = strlen($ean) >= 4 ? substr($ean, -4) : null;

        $product = Product::updateOrCreate(
            ['ean' => $ean],
            ['name' => $name, 'ean_last4' => $last4]
        );

        return response()->json($product, 201);
    }
}
