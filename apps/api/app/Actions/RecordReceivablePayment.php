<?php

namespace App\Actions;

use App\Domain\ReceivableAllocator;
use App\Exceptions\BusinessRuleViolation;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ReceivableInvoice;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;

/** Wholesale buyer pays on account; applied oldest invoice first (WHL-005). */
final class RecordReceivablePayment
{
    public function __invoke(User $user, Customer $c, int $amount, string $method, ?array $momo = null): Payment
    {
        if (! in_array($method, ['cash', 'momo_manual', 'bank'], true)) {
            throw new BusinessRuleViolation('invalid_method', 'Method must be cash, MoMo or bank.');
        }

        return DB::transaction(function () use ($user, $c, $amount, $method, $momo) {
            $shift = CashierShift::where('user_id', $user->id)->whereNull('closed_at')->first();
            if ($method === 'cash' && ! $shift) {
                throw new BusinessRuleViolation('shift_closed', 'Open a cash shift to take cash.');
            }
            $invoices = ReceivableInvoice::where('customer_id', $c->id)->whereRaw('amount_pesewas - paid_pesewas - credited_pesewas > 0')
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            try {
                $alloc = ReceivableAllocator::allocate($invoices->map(fn ($i) => ['id' => $i->id, 'outstanding' => $i->outstanding()])->values()->all(), $amount);
            } catch (\DomainException|\InvalidArgumentException $e) {
                throw new BusinessRuleViolation('invalid_amount', $e->getMessage());
            }
            $fields = ['customer_id' => $c->id, 'purpose' => 'receivable', 'method' => $method, 'amount_pesewas' => $amount,
                'user_id' => $user->id, 'shift_id' => $shift?->id, 'status' => 'received'];
            if ($method === 'momo_manual') {
                $txn = strtoupper(trim((string) ($momo['txn_id'] ?? '')));
                if (strlen($txn) < 6) {
                    throw new BusinessRuleViolation('momo_txn_required', 'Enter the MoMo transaction ID.');
                }
                if (Payment::where('network', $momo['network'] ?? null)->where('momo_txn_id', $txn)->exists()) {
                    throw new BusinessRuleViolation('momo_txn_reused', 'This MoMo transaction ID has already been used.');
                }
                $verified = Permissions::allows($user, 'momo.verify');
                $fields += ['network' => $momo['network'], 'momo_txn_id' => $txn, 'status' => $verified ? 'verified' : 'pending_verification',
                    'verified_by' => $verified ? $user->id : null, 'verified_at' => $verified ? now() : null];
            }
            $payment = Payment::create($fields);
            foreach ($alloc as $a) {
                ReceivableInvoice::whereKey($a['invoice_id'])->increment('paid_pesewas', $a['amount']);
                DB::table('receivable_allocations')->insert(['invoice_id' => $a['invoice_id'], 'payment_id' => $payment->id, 'kind' => 'payment',
                    'amount_pesewas' => $a['amount'], 'user_id' => $user->id, 'created_at' => now()]);
            }
            AuditLogger::record('receivable.payment', "{$c->name} {$amount}p {$method}", $user->id, $payment);

            return $payment;
        });
    }
}
