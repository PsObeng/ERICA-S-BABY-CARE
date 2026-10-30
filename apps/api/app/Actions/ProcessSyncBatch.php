<?php

namespace App\Actions;

use App\Exceptions\ApprovalRequired;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Device;
use App\Models\SyncEvent;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\DeviceSignature;
use Illuminate\Support\Facades\DB;

/**
 * Applies events queued on a shop device while offline (OFF-001..007).
 * - each event is idempotent by client_uuid (re-sending a batch is harmless)
 * - each event must carry a valid device signature; edited local data is rejected
 * - an offline sale is always accepted (the goods already left) but is flagged when it breaks a rule
 *   that only the server can see (stock went negative, price changed since the device's snapshot)
 */
final class ProcessSyncBatch
{
    public const ALLOWED_OFFLINE = ['sale', 'customer', 'shift_open', 'shift_close'];

    public function __construct(
        private CompleteSale $completeSale,
        private RegisterCustomer $registerCustomer,
        private CashShift $shifts,
    ) {}

    /** @return list<array{client_uuid:string, status:string, result:?string}> */
    public function __invoke(User $user, Device $device, array $events): array
    {
        if (! $device->isActive()) {
            throw new BusinessRuleViolation('device_revoked', 'This device has been removed. Ask the owner to register it again.');
        }
        $out = [];
        usort($events, fn ($a, $b) => strcmp($a['client_created_at'], $b['client_created_at']));
        foreach ($events as $e) {
            if ($done = SyncEvent::find($e['client_uuid'])) {
                $out[] = ['client_uuid' => $done->client_uuid, 'status' => $done->status, 'result' => $done->result];
                continue;
            }
            if (! DeviceSignature::verify($device->public_key, DeviceSignature::canonical($e), $e['signature'] ?? '')) {
                $out[] = $this->store($user, $device, $e, 'rejected', 'Signature check failed — data was changed on the device.');
                AuditLogger::record('sync.rejected_signature', "device {$device->name} event {$e['client_uuid']}", $user->id, $device);
                continue;
            }
            if (! in_array($e['type'], self::ALLOWED_OFFLINE, true)) {
                $out[] = $this->store($user, $device, $e, 'rejected', "'{$e['type']}' is not allowed offline.");
                continue;
            }
            try {
                [$status, $result] = DB::transaction(fn () => $this->apply($user, $device, $e));
            } catch (BusinessRuleViolation|ApprovalRequired $ex) {
                [$status, $result] = ['flagged', $ex->getMessage()];
            }
            $out[] = $this->store($user, $device, $e, $status, $result);
        }
        $device->update(['last_sync_at' => now()]);

        return $out;
    }

    /** @return array{0:string, 1:?string} */
    private function apply(User $user, Device $device, array $e): array
    {
        $p = $e['payload'];

        return match ($e['type']) {
            'sale' => (function () use ($user, $device, $e, $p) {
                $order = ($this->completeSale)($user, $p + ['client_uuid' => $e['client_uuid'], 'offline' => true,
                    'device_id' => $device->id, 'device_prefix' => $device->number_prefix, 'placed_at' => $e['client_created_at']]);
                return [$order->needs_review ? 'flagged' : 'accepted', $order->needs_review ? "{$order->number}: {$order->review_note}" : $order->number];
            })(),
            'customer' => ['accepted', ($this->registerCustomer)($user, $p + ['source' => 'offline'])->id],
            'shift_open' => ['accepted', $this->shifts->open($user, (int) $p['float_pesewas'], $device->id)->id],
            'shift_close' => ['accepted', $this->shifts->close($user, (int) $p['counted_pesewas'], $p['reason'] ?? null)->id],
        };
    }

    private function store(User $user, Device $device, array $e, string $status, ?string $result): array
    {
        SyncEvent::create(['client_uuid' => $e['client_uuid'], 'device_id' => $device->id, 'user_id' => $user->id, 'type' => $e['type'],
            'payload' => $e['payload'], 'signature' => $e['signature'] ?? '', 'client_created_at' => $e['client_created_at'],
            'snapshot_version' => $e['snapshot_version'] ?? null, 'status' => $status, 'result' => $result]);

        return ['client_uuid' => $e['client_uuid'], 'status' => $status, 'result' => $result];
    }
}
