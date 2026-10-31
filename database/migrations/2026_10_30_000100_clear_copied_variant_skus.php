<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shops imported through the WooCommerce REST API before 1.11.0 got the parent's SKU on every variation that had
 * none of its own (the API shows an empty variation SKU as the parent's). The admin refuses a variant SKU equal to
 * the product's, so none of those products could be saved. A variation's SKU that equals its product's is cleared:
 * orders, emails and reports already fall back to the product's SKU, so nothing a customer or the shop sees changes.
 * Not reversible (the cleared values were copies of the product's SKU).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('product_variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereNotNull('v.sku')->where('v.sku', '<>', '')
            ->whereColumn('v.sku', 'p.sku')
            ->pluck('v.id');

        foreach ($ids->chunk(1000) as $chunk) {
            DB::table('product_variations')->whereIn('id', $chunk->all())->update(['sku' => null]);
        }
    }

    public function down(): void
    {
        //
    }
};
