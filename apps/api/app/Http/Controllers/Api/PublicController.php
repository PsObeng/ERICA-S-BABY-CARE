<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/** Public storefront data (WEB-001): availability labels only, never exact stock counts or costs. */
class PublicController extends Controller
{
    public function business(): JsonResponse
    {
        return response()->json(collect(Settings::all())->only(['business_name', 'location', 'phone_primary', 'phone_secondary']));
    }

    public function products(): JsonResponse
    {
        $today = now('Africa/Accra')->toDateString();

        return response()->json(ProductVariant::with(['product', 'lots'])->where('active', true)->get()->map(function ($v) use ($today) {
            $avail = (int) $v->lots->filter(fn ($l) => ! $l->expires_on || $l->expires_on->toDateString() >= $today)->sum('qty_on_hand');

            return ['sku' => $v->sku, 'brand' => $v->product->brand, 'name' => $v->product->name, 'size' => $v->size_label,
                'pack_count' => $v->pack_count, 'price_pesewas' => $v->retail_pesewas,
                'availability' => $avail <= 0 ? 'out' : ($avail <= $v->low_stock_packs ? 'low' : 'in_stock')];
        }));
    }
}
