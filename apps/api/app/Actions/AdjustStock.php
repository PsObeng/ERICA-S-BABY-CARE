<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleViolation;
use App\Models\InventoryMovement;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Approvals;
use App\Support\AuditLogger;
use App\Support\DocumentNumber;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/** Stock adjustment / write-off with reason and value-based approval (INV-001/006). */
final class AdjustStock
{
    public const REASONS = ['damaged', 'expired', 'count_correction', 'found', 'missing', 'other'];

    public function __invoke(User $user, string $lotId, int $delta, string $reason, ?string $note, ?array $approval): InventoryMovement
    {
        if ($delta === 0 || ! in_array($reason, self::REASONS, true)) {
            throw new BusinessRuleViolation('invalid_adjustment', 'Enter a non-zero change and a reason.');
        }

        return DB::transaction(function () use ($user, $lotId, $delta, $reason, $note, $approval) {
            $lot = StockLot::with('variant.product')->whereKey($lotId)->lockForUpdate()->firstOrFail();
            if ($lot->qty_on_hand + $delta < 0) {
                throw new BusinessRuleViolation('negative_stock', 'That would make the batch negative.');
            }
            $value = abs($delta) * $lot->variant->cost_pesewas;
            $approver = null;
            $label = sprintf('Adjust %s by %+d packs (%s)', $lot->variant->displayName(), $delta, $reason);
            if ($value > Settings::get('adjust_owner_approval_pesewas')) {
                $approver = Approvals::require($approval, ['owner'], $label, $user);
            } elseif (! Permissions::allows($user, 'stock.adjust')) {
                $approver = Approvals::require($approval, ['owner', 'manager'], $label, $user);
            }
            $lot->increment('qty_on_hand', $delta);
            $number = DocumentNumber::next('ADJ');
            $m = InventoryMovement::create(['variant_id' => $lot->variant_id, 'lot_id' => $lot->id,
                'type' => $delta < 0 && in_array($reason, ['damaged', 'expired'], true) ? 'write_off' : 'adjustment',
                'qty' => $delta, 'reason' => $reason.($note ? ": {$note}" : ''), 'reference' => $number, 'user_id' => $user->id,
                'approved_by' => $approver && $approver->id !== $user->id ? $approver->id : null, 'created_at' => now()]);
            AuditLogger::record('stock.adjust', "{$number} {$label}", $user->id, $m);

            return $m;
        });
    }
}
