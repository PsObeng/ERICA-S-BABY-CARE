<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Outbox pattern (B13): the event row commits in the same transaction as the business change;
 * a queue worker publishes it (WhatsApp/SMS, reports). Duplicate keys are ignored.
 */
final class Outbox
{
    public static function push(string $type, string $eventKey, array $payload): void
    {
        DB::table('outbox_events')->insertOrIgnore([
            'event_key' => $eventKey,
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
