<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Payment;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;

/** A person with momo.verify checks a manual MoMo payment against the merchant statement (PAY-002). */
final class VerifyMomoPayment
{
    public function __invoke(User $user, string $paymentId, bool $found, bool $checkedStatement): Payment
    {
        if (! $checkedStatement) {
            throw new BusinessRuleViolation('statement_not_checked', 'Confirm you checked the MoMo statement: same network, transaction ID and amount.');
        }

        return DB::transaction(function () use ($user, $paymentId, $found) {
            $p = Payment::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($p->method !== 'momo_manual' || $p->status !== 'pending_verification') {
                throw new BusinessRuleViolation('not_pending', 'This payment is not waiting for verification.');
            }
            $p->update(['status' => $found ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now()]);
            AuditLogger::record($found ? 'momo.verify' : 'momo.reject', "{$p->network} {$p->momo_txn_id} {$p->amount_pesewas}p", $user->id, $p);
            if (! $found) {
                Outbox::push('alert.owner', "momo-rejected:{$p->id}", ['payment_id' => $p->id, 'reason' => 'MoMo payment not found on statement']);
            }

            return $p->refresh();
        });
    }
}
