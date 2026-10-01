<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Catalogue\QuickUpdateRequest;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Admin\Catalogue\ProductSaver;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single-variant actions outside the product editor (the editor saves all variants with the product form).
 *
 *   PATCH  admin/variations/{variation}   admin.variations.update    JSON inline edit (Inventory page): prices, stock
 *   DELETE admin/variations/{variation}   admin.variations.destroy   remove one variant
 */
class VariationController extends Controller
{
    public function update(QuickUpdateRequest $request, ProductVariation $variation): JsonResponse
    {
        $result = ProductSaver::quickUpdate($variation, $request->changes());
        $variation->refresh();
        $stock = CatalogueTools::stock($variation);
        $message = 'Saved.';
        if ($summary = CatalogueTools::alertSummary($result['alerts'])) {
            $message .= ' '.$summary;
        }

        return response()->json([
            'message' => $message,
            'row' => [
                'regular_price' => $variation->regular_price !== null ? number_format((float) $variation->regular_price, 2, '.', '') : '',
                'sale_price' => $variation->sale_price !== null ? number_format((float) $variation->sale_price, 2, '.', '') : '',
                'manage_stock' => (bool) $variation->manage_stock,
                'stock_quantity' => $variation->manage_stock ? $variation->stock_quantity : null,
                'stock_status' => $variation->stock_status,
                'stock_label' => $stock['label'],
                'stock_color' => $stock['color'],
            ],
        ]);
    }

    public function destroy(Request $request, ProductVariation $variation): JsonResponse|RedirectResponse
    {
        $product = $variation->product;
        $variation->delete();
        if ($product) {
            ProductSaver::refreshVariable($product);
        }
        CatalogueTools::flushStorefrontCaches();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Variant removed.']);
        }

        return back()->with('success', 'Variant removed.');
    }
}
