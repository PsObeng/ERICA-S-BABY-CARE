<?php

namespace App\Http\Controllers\Api;

use App\Actions\CompleteSale;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $orders = Order::with('customer:id,name')->latest('placed_at')
            ->when($r->query('q'), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->cursorPaginate(50);

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load('lines', 'payments', 'customer:id,name,type'));
    }

    public function store(Request $r, CompleteSale $complete): JsonResponse
    {
        $data = $r->validate([
            'client_uuid' => 'nullable|uuid',
            'customer_id' => 'nullable|string|size:26|exists:customers,id',
            'lines' => 'required|array|min:1|max:60',
            'lines.*.variant_id' => 'required|string|size:26',
            'lines.*.unit' => 'required|in:pack,carton',
            'lines.*.qty' => 'required|integer|min:1|max:10000',
            'discount' => 'nullable|array',
            'discount.type' => 'required_with:discount|in:pct,amount',
            'discount.value' => 'required_with:discount|numeric|min:0',
            'tenders' => 'required|array',
            'tenders.cash_pesewas' => 'nullable|integer|min:0',
            'tenders.credit_pesewas' => 'nullable|integer|min:0',
            'tenders.momo' => 'nullable|array',
            'tenders.momo.amount_pesewas' => 'required_with:tenders.momo|integer|min:1',
            'tenders.momo.network' => 'required_with:tenders.momo|in:MTN,Telecel,AT',
            'tenders.momo.txn_id' => 'required_with:tenders.momo|string|min:6|max:40|alpha_num',
            'tenders.momo.payer' => 'required_with:tenders.momo|string|max:20',
            'approval' => 'nullable|array',
            'approval.user_id' => 'required_with:approval|integer',
            'approval.pin' => 'required_with:approval|digits_between:4,6',
        ]);
        // A browser can never send "pin" or "otp" for the customer's wallet: the fields do not exist (PAY-004).
        $order = $complete($r->user(), $data);

        return response()->json($order, 201);
    }
}
