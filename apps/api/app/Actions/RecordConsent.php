<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Settings;

/** Append a consent change (opt-in, opt-out / STOP). History is never edited (CON-003/005). */
final class RecordConsent
{
    public function __invoke(?User $user, Customer $c, string $channel, string $status, string $source, ?string $evidence = null): Consent
    {
        if (! in_array($channel, ['whatsapp', 'sms'], true) || ! in_array($status, ['opted_in', 'withdrawn'], true)) {
            throw new BusinessRuleViolation('invalid_consent', 'Channel must be whatsapp or sms; status opted_in or withdrawn.');
        }
        $row = Consent::create(['customer_id' => $c->id, 'channel' => $channel, 'purpose' => 'marketing', 'status' => $status,
            'wording_version' => Settings::get('consent_wording_version'), 'source' => $source, 'evidence_ref' => $evidence,
            'user_id' => $user?->id, 'created_at' => now()]);
        AuditLogger::record('consent', "{$c->name} {$channel} {$status} via {$source}", $user?->id, $c);

        return $row;
    }
}
