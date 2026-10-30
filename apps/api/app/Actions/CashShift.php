<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleViolation;
use App\Models\CashierShift;
use App\Models\Payment;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Open and close cashier shifts with expected vs counted cash (POS-020/021). */
final class CashShift
{
    public function open(User $user, int $float, ?string $deviceId = null): CashierShift
    {
        if (CashierShift::where('user_id', $user->id)->whereNull('closed_at')->exists()) {
            throw new BusinessRuleViolation('shift_already_open', 'You already have an open shift.');
        }
        $s = CashierShift::create(['user_id' => $user->id, 'device_id' => $deviceId, 'opening_float_pesewas' => max(0, $float), 'opened_at' => now()]);
        AuditLogger::record('shift.open', "{$user->name} float {$float}p", $user->id, $s);

        return $s;
    }

    public function expectedCash(CashierShift $s): int
    {
        $cashIn = (int) Payment::where('shift_id', $s->id)->where('method', 'cash')->sum('amount_pesewas');
        $refunds = (int) DB::table('order_returns')->where('shift_id', $s->id)->where('refund_method', 'cash')->sum('amount_pesewas');
        $moves = DB::table('shift_cash_movements')->where('shift_id', $s->id)->get();
        $net = $moves->sum(fn ($m) => $m->direction === 'in' ? $m->amount_pesewas : -$m->amount_pesewas);

        return $s->opening_float_pesewas + $cashIn - $refunds + (int) $net;
    }

    public function cashMove(User $user, string $direction, int $amount, string $reason): void
    {
        $s = CashierShift::where('user_id', $user->id)->whereNull('closed_at')->firstOrFail();
        if (! in_array($direction, ['in', 'out'], true) || $amount <= 0 || trim($reason) === '') {
            throw new BusinessRuleViolation('invalid_cash_move', 'Enter an amount and what it was for.');
        }
        DB::table('shift_cash_movements')->insert(['shift_id' => $s->id, 'direction' => $direction, 'amount_pesewas' => $amount,
            'reason' => $reason, 'user_id' => $user->id, 'created_at' => now()]);
        AuditLogger::record('shift.cash_'.$direction, "{$amount}p {$reason}", $user->id, $s);
    }

    public function close(User $user, int $counted, ?string $reason): CashierShift
    {
        return DB::transaction(function () use ($user, $counted, $reason) {
            $s = CashierShift::where('user_id', $user->id)->whereNull('closed_at')->lockForUpdate()->firstOrFail();
            $expected = $this->expectedCash($s);
            $variance = $counted - $expected;
            if ($variance !== 0 && trim((string) $reason) === '') {
                throw new BusinessRuleViolation('variance_reason_required', 'There is a cash difference. Enter the reason.', ['reason']);
            }
            $s->update(['closed_at' => now(), 'expected_cash_pesewas' => $expected, 'counted_cash_pesewas' => $counted,
                'variance_pesewas' => $variance, 'variance_reason' => $reason]);
            AuditLogger::record('shift.close', "{$user->name} expected {$expected}p counted {$counted}p variance {$variance}p", $user->id, $s);

            return $s;
        });
    }
}
