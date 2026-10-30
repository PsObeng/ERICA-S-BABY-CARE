<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProcessSyncBatch;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Device;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Offline POS: device registration, the cached snapshot, and the signed event upload (OFF-001..007). */
class SyncController extends Controller
{
    public function registerDevice(Request $r): JsonResponse
    {
        $data = $r->validate(['name' => 'required|string|max:80', 'public_key_pem' => 'required|string|starts_with:-----BEGIN PUBLIC KEY-----|max:1000']);
        if (openssl_pkey_get_public($data['public_key_pem']) === false) {
            return response()->json(['error' => ['code' => 'bad_key', 'message' => 'The device key is not valid.']], 422);
        }
        $prefix = 'D'.(Device::count() + 1);
        $d = Device::create(['name' => $data['name'], 'number_prefix' => $prefix, 'public_key' => $data['public_key_pem'], 'registered_by' => $r->user()->id]);
        AuditLogger::record('device.register', "{$d->name} ({$prefix})", $r->user()->id, $d);

        return response()->json($d->only('id', 'name', 'number_prefix'), 201);
    }

    public function revokeDevice(Request $r, Device $device): JsonResponse
    {
        $device->update(['revoked_at' => now()]);
        AuditLogger::record('device.revoke', $device->name, $r->user()->id, $device);

        return response()->json(['ok' => true]);
    }

    /** What the device caches. Never full exports, never payment data (OFF-002). */
    public function snapshot(Request $r, OperationsController $ops): JsonResponse
    {
        $catalogue = $ops->catalogue()->getData(true);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'snapshot_version' => $catalogue['snapshot_version'],
            'settings' => collect(Settings::all())->only(['business_name', 'location', 'phone_primary', 'phone_secondary', 'momo_merchant_number',
                'cashier_discount_pct', 'manager_discount_pct', 'momo_release_limit_pesewas', 'near_expiry_days', 'return_window_days',
                'consent_wording', 'consent_wording_version', 'offline_catalogue_warn_hours', 'offline_catalogue_block_days']),
            'variants' => $catalogue['variants'],
            'customers' => Customer::query()->select('id', 'name', 'phone_e164', 'type', 'credit_limit_pesewas')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone_e164, 'type' => $c->type,
                'credit_available_pesewas' => $c->type === 'wholesale' ? max(0, $c->credit_limit_pesewas - $c->balancePesewas()) : 0,
                'can_whatsapp' => $c->canBeMarketedOn('whatsapp'),
            ]),
        ]);
    }

    public function upload(Request $r, ProcessSyncBatch $process): JsonResponse
    {
        $data = $r->validate([
            'device_id' => 'required|string|size:26|exists:devices,id',
            'events' => 'required|array|min:1|max:500',
            'events.*.client_uuid' => 'required|uuid',
            'events.*.type' => 'required|string|max:40',
            'events.*.client_created_at' => 'required|date',
            'events.*.payload' => 'required|array',
            'events.*.signature' => 'required|string|max:200',
            'events.*.snapshot_version' => 'nullable|string|max:40',
        ]);
        $results = $process($r->user(), Device::findOrFail($data['device_id']), $data['events']);

        return response()->json(['results' => $results, 'server_time' => now()->toIso8601String(), 'request_id' => (string) Str::uuid()]);
    }
}
