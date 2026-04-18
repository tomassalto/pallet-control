<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();

        // Búsqueda por nombre o quit
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('quit', 'like', "%{$search}%");
            });
        }

        // Limitar resultados para autocomplete
        $limit = $request->integer('limit', 20);
        if ($limit > 50) $limit = 50;

        return $query->orderBy('name')->limit($limit)->get();
    }

    public function show(Customer $customer)
    {
        $customer->load(['orders' => function ($query) {
            $query->orderByDesc('created_at');
        }]);
        return response()->json($customer);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'quit' => 'nullable|string|max:255|unique:customers,quit',
        ]);

        $customer = Customer::create($data);

        return response()->json($customer, 201);
    }
}
