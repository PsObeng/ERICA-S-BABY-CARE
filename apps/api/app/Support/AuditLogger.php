<?php

namespace App\Support;

use App\Domain\HashChain;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Writes the hash-chained audit log (SEC-INT-060). A PostgreSQL advisory lock serialises
 * writers so two requests can never fork the chain. The table is append-only (trigger).
 */
final class AuditLogger
{
    public static function record(string $action, string $detail, ?int $userId = null, ?object $subject = null): AuditLog
    {
        return DB::transaction(function () use ($action, $detail, $userId, $subject) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [774433]);
            }
            $prev = AuditLog::query()->orderByDesc('seq')->lockForUpdate()->value('hash') ?? HashChain::GENESIS;
            $at = now('UTC')->format('Y-m-d\TH:i:sP'); // second precision: survives every DB driver unchanged
            $fields = [
                'user_id' => $userId,
                'action' => $action,
                'detail' => self::redact($detail),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
                'created_at' => $at,
            ];

            return AuditLog::create($fields + [
                'request_id' => request()?->header('X-Request-ID'),
                'prev_hash' => $prev,
                'hash' => HashChain::hash($prev, $fields),
            ]);
        });
    }

    /** Never store PINs, OTPs or full phone numbers in the log (SEC tier 1). */
    public static function redact(string $text): string
    {
        $text = preg_replace('/\b(pin|otp|password|cvv)\s*[:=]\s*\S+/i', '$1=[redacted]', $text) ?? $text;

        return preg_replace_callback('/\+?233\d{9}|\b0\d{9}\b/', fn ($m) => '•••• '.substr($m[0], -4), $text) ?? $text;
    }

    /** @return array{ok:bool, checked:int, broken_at:?int} */
    public static function verify(): array
    {
        $entries = (function () {
            foreach (AuditLog::query()->orderBy('seq')->cursor() as $row) {
                yield [
                    'prev_hash' => $row->prev_hash,
                    'hash' => $row->hash,
                    'fields' => [
                        'user_id' => $row->user_id,
                        'action' => $row->action,
                        'detail' => $row->detail,
                        'subject_type' => $row->subject_type,
                        'subject_id' => $row->subject_id,
                        'created_at' => $row->getRawOriginal('created_at') !== null ? $row->created_at->setTimezone('UTC')->format('Y-m-d\TH:i:sP') : null,
                    ],
                ];
            }
        })();

        return HashChain::verify($entries);
    }
}
