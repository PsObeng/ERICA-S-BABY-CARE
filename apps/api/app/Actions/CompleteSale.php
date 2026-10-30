<?php

namespace App\Actions;

use App\Domain\FefoAllocator;
use App\Domain\GhanaPhone;
use App\Domain\InsufficientStock;
use App\Domain\PriceResolver;
use App\Exceptions\BusinessRuleViolation;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderLineLot;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\ReceivableInvoice;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Approvals;
use App\Support\AuditLogger;
use App\Support\DocumentNumber;
use App\Support\Outbox;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A counter sale in one transaction: price (server-side), discount limits, credit limits, manual MoMo
 * rules, FEFO stock allocation with row locks, payments, receivable invoice, audit and outbox
 * (POS-001/002, PRC-004/007, WHL-004, PAY-001/002, INV-003/011).
 *
 * Input:
 *  client_uuid?, customer_id?, device_id?, device_prefix?, offline? (bool), placed_at?,
 *  lines: [{variant_id, unit: pack|carton, qty}],
 *  discount?: {type: pct|amount, value},
 *  tenders: {cash_pesewas?, momo?: {amount_pesewas, network, txn_id, payer}, credit_pesewas?},
 *  approval?: {user_id, pin}
 */
final class CompleteSale
{
    public function __invoke(User $user, array $in): Order
    {
        if (! empty($in['client_uuid']) && ($existing = Order::where('client_uuid', $in['client_uuid'])->first())) {
            return $existing; // retried submit: same order, no second sale (idempotent)
        }
        $s = Settings::all();
        $offline = (bool) ($in['offline'] ?? false);

        try {
            return DB::transaction(function () use ($user, $in, $s, $offline) {
                $shift = CashierShift::where('user_id', $user->id)->whereNull('closed_at')->lockForUpdate()->first();
                if (! $shift) {
                    throw new BusinessRuleViolation('shift_closed', 'Open a cash shift before selling.');
                }
                $customer = ! empty($in['customer_id']) ? Customer::findOrFail($in['customer_id']) : null;
                $wholesale = $customer?->isWholesale() ?? false;

                // 1. Price every line on the server.
                $variants = ProductVariant::with('product')->whereIn('id', array_column($in['lines'], 'variant_id'))->get()->keyBy('id');
                $lines = [];
                $priceFlags = [];
                foreach ($in['lines'] as $i => $l) {
                    $v = $variants[$l['variant_id']] ?? throw new BusinessRuleViolation('unknown_product', 'A product in the cart no longer exists.', ["lines.$i"]);
                    if (! $v->active || ! $v->product->active) {
                        throw new BusinessRuleViolation('inactive_product', $v->displayName().' is not for sale.', ["lines.$i"]);
                    }
                    try {
                        $p = PriceResolver::resolve($v->priceInputs(), $l['unit'], (int) $l['qty'], $wholesale);
                    } catch (\InvalidArgumentException $e) {
                        throw new BusinessRuleViolation('invalid_line', $e->getMessage(), ["lines.$i"]);
                    }
                    if ($offline && isset($l['charged_unit_pesewas']) && (int) $l['charged_unit_pesewas'] !== $p['unit_price']) {
                        // OFF-005: the customer keeps the price they were charged; the difference is flagged for review.
                        $priceFlags[] = sprintf('%s charged %dp, server price %dp', $v->displayName(), (int) $l['charged_unit_pesewas'], $p['unit_price']);
                        $p['unit_price'] = (int) $l['charged_unit_pesewas'];
                    }
                    $lines[] = ['variant' => $v, 'unit' => $l['unit'], 'qty' => (int) $l['qty']] + $p;
                }
                $subtotal = array_sum(array_map(fn ($l) => $l['unit_price'] * $l['qty'], $lines));

                // 2. Discount and its limit.
                $d = $in['discount'] ?? null;
                $discount = 0;
                if ($d && (float) $d['value'] > 0) {
                    $discount = $d['type'] === 'pct' ? (int) round($subtotal * (float) $d['value'] / 100) : (int) $d['value'];
                    $discount = max(0, min($discount, $subtotal));
                }
                $discountApprover = null;
                if ($discount > 0) {
                    $pct = $discount / $subtotal * 100;
                    if ($pct > Permissions::discountLimitPct($user, $s) + 1e-9) {
                        $roles = $pct > $s['manager_discount_pct'] ? ['owner'] : ['owner', 'manager'];
                        $discountApprover = Approvals::require($in['approval'] ?? null, $roles, sprintf('Discount of %.1f%% is above your limit.', $pct), $user);
                    }
                }
                $total = $subtotal - $discount;

                // 3. Tenders.
                $t = $in['tenders'] ?? [];
                $cash = (int) ($t['cash_pesewas'] ?? 0);
                $momo = $t['momo'] ?? null;
                $momoAmt = (int) ($momo['amount_pesewas'] ?? 0);
                $credit = (int) ($t['credit_pesewas'] ?? 0);
                if ($cash < 0 || $momoAmt < 0 || $credit < 0) {
                    throw new BusinessRuleViolation('invalid_tender', 'Payment amounts cannot be negative.');
                }
                if ($cash + $momoAmt + $credit < $total) {
                    throw new BusinessRuleViolation('payment_short', 'Payment is short of the total.');
                }
                if ($momoAmt + $credit > $total) {
                    throw new BusinessRuleViolation('overpaid_non_cash', 'MoMo and credit together cannot exceed the total; only cash gives change.');
                }
                $change = $cash + $momoAmt + $credit - $total;

                // 4. Credit (wholesale only, within limit, not badly overdue).
                if ($credit > 0) {
                    if (! $customer || ! $wholesale) {
                        throw new BusinessRuleViolation('credit_not_allowed', 'Credit is only for wholesale accounts.');
                    }
                    $over = $customer->balancePesewas() + $credit > $customer->credit_limit_pesewas;
                    $late = $customer->maxDaysOverdue() > $s['overdue_block_days'];
                    if ($over || $late) {
                        Approvals::require($in['approval'] ?? null, ['owner'], "{$customer->name}: ".($over ? 'credit limit exceeded' : 'account overdue'), $user);
                    }
                }

                // 5. Manual MoMo (customer pays the merchant number; staff record the transaction ID).
                $momoStatus = null;
                $momoVerifier = null;
                if ($momoAmt > 0) {
                    $txn = strtoupper(trim((string) ($momo['txn_id'] ?? '')));
                    if (strlen($txn) < 6) {
                        throw new BusinessRuleViolation('momo_txn_required', 'Enter the MoMo transaction ID (at least 6 characters).');
                    }
                    if (! in_array($momo['network'] ?? '', ['MTN', 'Telecel', 'AT'], true)) {
                        throw new BusinessRuleViolation('momo_network', 'Choose the MoMo network.');
                    }
                    if (! GhanaPhone::tryParse($momo['payer'] ?? null)) {
                        throw new BusinessRuleViolation('momo_payer', 'Enter a valid Ghana payer number.');
                    }
                    if (Payment::where('network', $momo['network'])->where('momo_txn_id', $txn)->exists()) {
                        throw new BusinessRuleViolation('momo_txn_reused', 'This MoMo transaction ID has already been used. It cannot be accepted twice.');
                    }
                    $momoStatus = 'pending_verification';
                    if (Permissions::allows($user, 'momo.verify') && ! $offline) {
                        [$momoStatus, $momoVerifier] = ['verified', $user->id];
                    } elseif ($momoAmt > $s['momo_release_limit_pesewas'] && ! $this->isKnownCustomer($customer) && ! $offline) {
                        $ap = Approvals::require($in['approval'] ?? null, ['owner', 'manager'], 'MoMo above the release limit must be checked against the statement now.', $user);
                        [$momoStatus, $momoVerifier] = ['verified', $ap->id];
                    }
                    $momo['txn_id'] = $txn;
                }

                // 6. Stock: lock this product's lots, allocate earliest expiry first.
                $today = now('Africa/Accra')->toDateString();
                $number = DocumentNumber::next('RCPT', $offline ? ($in['device_prefix'] ?? null) : null);
                $order = Order::create([
                    'number' => $number, 'source' => 'pos', 'status' => 'completed', 'customer_id' => $customer?->id,
                    'user_id' => $user->id, 'shift_id' => $shift->id, 'device_id' => $in['device_id'] ?? null,
                    'client_uuid' => $in['client_uuid'] ?? null, 'subtotal_pesewas' => $subtotal, 'discount_pesewas' => $discount,
                    'total_pesewas' => $total, 'change_given_pesewas' => $change, 'discount_approved_by' => $discountApprover?->id,
                    'needs_review' => false, 'review_note' => null, 'placed_at' => $in['placed_at'] ?? now(),
                ]);
                $flags = $priceFlags;
                foreach ($lines as $l) {
                    /** @var ProductVariant $v */
                    $v = $l['variant'];
                    $lots = StockLot::where('variant_id', $v->id)->lockForUpdate()->get();
                    try {
                        $alloc = FefoAllocator::allocate($lots->map(fn ($x) => ['id' => $x->id, 'qty' => $x->qty_on_hand, 'expires_on' => $x->expires_on?->toDateString()])->all(), $l['packs'], $today);
                    } catch (InsufficientStock $e) {
                        if (! $offline) {
                            throw new BusinessRuleViolation('insufficient_stock', $v->displayName().': '.$e->getMessage());
                        }
                        // OFF-005: an offline sale already happened at the counter; accept it, flag it, raise a count task.
                        $alloc = $this->allocateAllowingNegative($lots, $l['packs'], $today);
                        $flags[] = $v->displayName().' went below zero — stock count needed';
                    }
                    $line = OrderLine::create([
                        'order_id' => $order->id, 'variant_id' => $v->id, 'name_snapshot' => $v->displayName(), 'unit' => $l['unit'],
                        'qty' => $l['qty'], 'packs' => $l['packs'], 'conversion' => $l['unit'] === 'carton' ? $v->carton_packs : 1,
                        'unit_price_pesewas' => $l['unit_price'], 'tier' => $l['tier'], 'cost_snapshot_pesewas' => $v->cost_pesewas * $l['packs'],
                    ]);
                    foreach ($alloc as $a) {
                        StockLot::whereKey($a['lot_id'])->decrement('qty_on_hand', $a['qty']);
                        OrderLineLot::create(['order_line_id' => $line->id, 'lot_id' => $a['lot_id'], 'packs' => $a['qty']]);
                        InventoryMovement::create(['variant_id' => $v->id, 'lot_id' => $a['lot_id'], 'type' => 'sale', 'qty' => -$a['qty'],
                            'reason' => 'Sale', 'reference' => $number, 'user_id' => $user->id, 'device_id' => $in['device_id'] ?? null, 'created_at' => now()]);
                    }
                }
                if ($flags) {
                    $order->update(['needs_review' => true, 'review_note' => mb_substr(implode('; ', $flags), 0, 200)]);
                }

                // 7. Payments and receivable.
                $base = ['order_id' => $order->id, 'purpose' => 'sale', 'user_id' => $user->id, 'shift_id' => $shift->id];
                if ($cash - $change > 0) {
                    Payment::create($base + ['method' => 'cash', 'amount_pesewas' => $cash - $change, 'status' => 'received']);
                }
                if ($momoAmt > 0) {
                    Payment::create($base + ['method' => 'momo_manual', 'amount_pesewas' => $momoAmt, 'network' => $momo['network'],
                        'momo_txn_id' => $momo['txn_id'], 'payer_e164' => GhanaPhone::tryParse($momo['payer'])->e164, 'status' => $momoStatus,
                        'verified_by' => $momoVerifier, 'verified_at' => $momoVerifier ? now() : null]);
                }
                if ($credit > 0) {
                    Payment::create($base + ['method' => 'credit', 'amount_pesewas' => $credit, 'status' => 'on_account']);
                    ReceivableInvoice::create(['customer_id' => $customer->id, 'order_id' => $order->id, 'number' => $number,
                        'amount_pesewas' => $credit, 'due_on' => now('Africa/Accra')->addDays($customer->credit_terms_days ?: $s['credit_terms_days'])->toDateString()]);
                }

                AuditLogger::record('sale', "{$number} total {$total}p".($credit ? " credit {$credit}p" : '').($momoAmt ? " momo {$momo['network']} {$momo['txn_id']}" : ''), $user->id, $order);
                Outbox::push('sale.completed', "sale:{$order->id}", ['order_id' => $order->id]);

                return $order->load('lines', 'payments');
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'momo_txn_id') || str_contains(strtolower($e->getMessage()), 'unique')) {
                if (! empty($in['client_uuid']) && ($existing = Order::where('client_uuid', $in['client_uuid'])->first())) {
                    return $existing;
                }
                throw new BusinessRuleViolation('duplicate', 'This sale or MoMo transaction was already recorded.');
            }
            throw $e;
        }
    }

    private function isKnownCustomer(?Customer $c): bool
    {
        return $c !== null && ($c->created_at->lt(now()->subDays(30)) || Order::where('customer_id', $c->id)->exists());
    }

    /** @return list<array{lot_id:string, qty:int}> */
    private function allocateAllowingNegative($lots, int $packs, string $today): array
    {
        $sellable = $lots->filter(fn ($l) => ! $l->expires_on || $l->expires_on->toDateString() >= $today)->sortBy(fn ($l) => $l->expires_on?->toDateString() ?? '9999');
        $out = [];
        $need = $packs;
        foreach ($sellable as $l) {
            $take = min(max(0, $l->qty_on_hand), $need);
            if ($take > 0) {
                $out[] = ['lot_id' => $l->id, 'qty' => $take];
                $need -= $take;
            }
        }
        if ($need > 0) {
            $last = $sellable->last() ?? $lots->sortByDesc('received_at')->first();
            if (! $last) {
                throw new BusinessRuleViolation('no_lot', 'This product has no stock record; receive stock first.');
            }
            $out[] = ['lot_id' => $last->id, 'qty' => $need];
        }

        return $out;
    }
}
