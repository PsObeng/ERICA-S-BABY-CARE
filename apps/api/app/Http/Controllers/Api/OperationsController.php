<?php

namespace App\Http\Controllers\Api;

use App\Actions\AdjustStock;
use App\Actions\CashShift;
use App\Actions\ReceiveGoods;
use App\Actions\RecordConsent;
use App\Actions\RecordReceivablePayment;
use App\Actions\RegisterCustomer;
use App\Actions\VerifyMomoPayment;
use App\Domain\FefoAllocator;
use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PriceChangeEvent;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Support\Approvals;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Catalogue, stock, customers, MoMo verification, receivables and shifts — thin wrappers over Actions. */
class OperationsController extends Controller
{
    // ---- Catalogue & pricing ----
    public function catalogue(): JsonResponse
    {
        $today = now('Africa/Accra')->toDateString();
        $near = (int) Settings::get('near_expiry_days');
        $variants = ProductVariant::with(['product', 'lots'])->where('active', true)->get()->map(function ($v) use ($today, $near) {
            $sellable = $v->lots->filter(fn ($l) => ! $l->expires_on || $l->expires_on->toDateString() >= $today);

            return [
                'id' => $v->id, 'sku' => $v->sku, 'brand' => $v->product->brand, 'name' => $v->product->name, 'category' => $v->product->category,
                'size' => $v->size_label, 'pack_count' => $v->pack_count, 'carton_packs' => $v->carton_packs,
                'retail' => $v->retail_pesewas, 'tier_a' => $v->tier_a_pesewas, 'tier_a_min' => $v->tier_a_min, 'carton_price' => $v->carton_price_pesewas,
                'available_packs' => (int) $sellable->sum('qty_on_hand'), 'low_stock_packs' => $v->low_stock_packs,
                'near_expiry' => $v->lots->contains(fn ($l) => $l->qty_on_hand > 0 && FefoAllocator::isNearExpiry($l->expires_on?->toDateString(), $today, $near)),
            ];
        });

        return response()->json(['snapshot_version' => (string) ProductVariant::max('updated_at'), 'variants' => $variants]);
    }

    public function updatePrices(Request $r, ProductVariant $variant): JsonResponse
    {
        $data = $r->validate([
            'retail_pesewas' => 'required|integer|min:1', 'tier_a_pesewas' => 'nullable|integer|min:1', 'tier_a_min' => 'required|integer|min:1',
            'carton_price_pesewas' => 'nullable|integer|min:1', 'reason' => 'required|string|min:3|max:200',
            'approval' => 'nullable|array', 'approval.user_id' => 'required_with:approval|integer', 'approval.pin' => 'required_with:approval|digits_between:4,6',
        ]);
        $below = collect(['retail_pesewas', 'tier_a_pesewas'])->filter(fn ($k) => ! empty($data[$k]) && $data[$k] < $variant->cost_pesewas)->all();
        if (! empty($data['carton_price_pesewas']) && intdiv($data['carton_price_pesewas'], max(1, $variant->carton_packs)) < $variant->cost_pesewas) {
            $below[] = 'carton_price_pesewas';
        }
        $approver = $below ? Approvals::require($data['approval'] ?? null, ['owner'], 'Price below cost for '.$variant->sku, $r->user()) : null;

        DB::transaction(function () use ($variant, $data, $r, $approver) {
            foreach (['retail_pesewas', 'tier_a_pesewas', 'tier_a_min', 'carton_price_pesewas'] as $f) {
                if (array_key_exists($f, $data) && $variant->{$f} !== $data[$f]) {
                    PriceChangeEvent::create(['variant_id' => $variant->id, 'field' => $f, 'old_value' => $variant->{$f}, 'new_value' => $data[$f],
                        'reason' => $data['reason'], 'changed_by' => $r->user()->id, 'approved_by' => $approver?->id, 'effective_at' => now()]);
                }
            }
            $variant->update(collect($data)->only(['retail_pesewas', 'tier_a_pesewas', 'tier_a_min', 'carton_price_pesewas'])->all());
            AuditLogger::record('price.change', "{$variant->sku}: {$data['reason']}", $r->user()->id, $variant);
            // PRC-005 (R2): flyers/campaigns that show this product are held for re-approval by a listener on this event.
        });

        return response()->json($variant->refresh());
    }

    // ---- Stock ----
    public function receive(Request $r, ReceiveGoods $receive): JsonResponse
    {
        $data = $r->validate([
            'supplier_name' => 'required|string|max:120', 'supplier_invoice' => 'nullable|string|max:60', 'landed_cost_pesewas' => 'nullable|integer|min:0',
            'lines' => 'required|array|min:1|max:100', 'lines.*.variant_id' => 'required|string|size:26', 'lines.*.unit' => 'required|in:pack,carton',
            'lines.*.qty' => 'required|integer|min:1', 'lines.*.cost_per_unit_pesewas' => 'required|integer|min:1',
            'lines.*.damaged_packs' => 'nullable|integer|min:0', 'lines.*.batch' => 'nullable|string|max:40', 'lines.*.expires_on' => 'nullable|date_format:Y-m-d',
        ]);
        $res = $receive($r->user(), $data);

        return response()->json(['receipt' => $res['receipt'], 'warnings' => array_map(fn ($n) => "Retail price is now below cost for {$n}", $res['below_cost'])], 201);
    }

    public function adjust(Request $r, AdjustStock $adjust): JsonResponse
    {
        $data = $r->validate(['lot_id' => 'required|string|size:26', 'delta' => 'required|integer|not_in:0', 'reason' => 'required|in:'.implode(',', AdjustStock::REASONS),
            'note' => 'nullable|string|max:150', 'approval' => 'nullable|array', 'approval.user_id' => 'required_with:approval|integer', 'approval.pin' => 'required_with:approval|digits_between:4,6']);

        return response()->json($adjust($r->user(), $data['lot_id'], (int) $data['delta'], $data['reason'], $data['note'] ?? null, $data['approval'] ?? null), 201);
    }

    public function expiry(): JsonResponse
    {
        return response()->json(StockLot::with('variant.product')->where('qty_on_hand', '>', 0)->whereNotNull('expires_on')->orderBy('expires_on')->get());
    }

    // ---- Customers & consent ----
    public function customers(Request $r): JsonResponse
    {
        return response()->json(Customer::query()->when($r->query('q'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))->orderBy('name')->cursorPaginate(50));
    }

    public function registerCustomer(Request $r, RegisterCustomer $register): JsonResponse
    {
        $data = $r->validate(['name' => 'required|string|min:2|max:120', 'phone' => 'required|string|max:20', 'type' => 'required|in:retail,wholesale',
            'town' => 'nullable|string|max:80', 'ghana_post_gps' => 'nullable|string|max:20|regex:/^[A-Za-z]{2}-?\d{3,4}-?\d{4}$/',
            'credit_limit_pesewas' => 'nullable|integer|min:0', 'credit_terms_days' => 'nullable|integer|min:0|max:120',
            'consent' => 'required|array', 'consent.whatsapp' => 'required|in:opted_in,withdrawn', 'consent.sms' => 'required|in:opted_in,withdrawn']);

        return response()->json($register($r->user(), $data), 201);
    }

    public function consent(Request $r, Customer $customer, RecordConsent $record): JsonResponse
    {
        $data = $r->validate(['channel' => 'required|in:whatsapp,sms', 'status' => 'required|in:opted_in,withdrawn']);

        return response()->json($record($r->user(), $customer, $data['channel'], $data['status'], 'staff ('.$r->user()->name.')'), 201);
    }

    // ---- MoMo verification ----
    public function pendingMomo(): JsonResponse
    {
        return response()->json(Payment::with('order:id,number')->where('status', 'pending_verification')->oldest()->get());
    }

    public function verifyMomo(Request $r, Payment $payment, VerifyMomoPayment $verify): JsonResponse
    {
        $data = $r->validate(['found' => 'required|boolean', 'checked_statement' => 'required|accepted']);

        return response()->json($verify($r->user(), $payment->id, (bool) $data['found'], (bool) $data['checked_statement']));
    }

    // ---- Receivables ----
    public function receivables(): JsonResponse
    {
        return response()->json(Customer::where('type', 'wholesale')->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone_e164, 'town' => $c->town, 'limit' => $c->credit_limit_pesewas,
            'owes' => $c->balancePesewas(), 'days_overdue' => $c->maxDaysOverdue(),
        ]));
    }

    public function receivablePayment(Request $r, Customer $customer, RecordReceivablePayment $pay): JsonResponse
    {
        $data = $r->validate(['amount_pesewas' => 'required|integer|min:1', 'method' => 'required|in:cash,momo_manual,bank',
            'momo' => 'required_if:method,momo_manual|array', 'momo.network' => 'required_with:momo|in:MTN,Telecel,AT', 'momo.txn_id' => 'required_with:momo|alpha_num|min:6|max:40']);

        return response()->json($pay($r->user(), $customer, (int) $data['amount_pesewas'], $data['method'], $data['momo'] ?? null), 201);
    }

    public function setCreditLimit(Request $r, Customer $customer): JsonResponse
    {
        $data = $r->validate(['credit_limit_pesewas' => 'required|integer|min:0', 'credit_terms_days' => 'required|integer|min:0|max:120']);
        if (! $customer->isWholesale()) {
            throw new BusinessRuleViolation('not_wholesale', 'Credit limits are only for wholesale accounts.');
        }
        $customer->update($data);
        AuditLogger::record('credit.limit', "{$customer->name} limit {$data['credit_limit_pesewas']}p terms {$data['credit_terms_days']}d", $r->user()->id, $customer);

        return response()->json($customer);
    }

    // ---- Shifts ----
    public function currentShift(Request $r, CashShift $shifts): JsonResponse
    {
        $s = CashierShift::where('user_id', $r->user()->id)->whereNull('closed_at')->first();

        return response()->json($s ? $s->toArray() + ['expected_cash_pesewas_now' => $shifts->expectedCash($s)] : null);
    }

    public function openShift(Request $r, CashShift $shifts): JsonResponse
    {
        $data = $r->validate(['float_pesewas' => 'required|integer|min:0']);

        return response()->json($shifts->open($r->user(), (int) $data['float_pesewas']), 201);
    }

    public function cashMove(Request $r, CashShift $shifts): JsonResponse
    {
        $data = $r->validate(['direction' => 'required|in:in,out', 'amount_pesewas' => 'required|integer|min:1', 'reason' => 'required|string|max:200']);
        $shifts->cashMove($r->user(), $data['direction'], (int) $data['amount_pesewas'], $data['reason']);

        return response()->json(['ok' => true], 201);
    }

    public function closeShift(Request $r, CashShift $shifts): JsonResponse
    {
        $data = $r->validate(['counted_pesewas' => 'required|integer|min:0', 'reason' => 'nullable|string|max:200']);

        return response()->json($shifts->close($r->user(), (int) $data['counted_pesewas'], $data['reason'] ?? null));
    }

    // ---- Audit ----
    public function verifyAudit(): JsonResponse
    {
        return response()->json(AuditLogger::verify());
    }
}
