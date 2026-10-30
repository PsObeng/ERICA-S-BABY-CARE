<?php

namespace App\Actions;

use App\Domain\GhanaPhone;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Outbox;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Register a customer with separate WhatsApp and SMS answers (CRM-004, CON-001/002).
 * Both answers are required (yes or no); nothing is pre-selected; buying is not consent.
 *
 * Input: name, phone, type, town?, ghana_post_gps?, credit_limit_pesewas?, credit_terms_days?,
 *        consent: {whatsapp: opted_in|withdrawn, sms: opted_in|withdrawn}, source?, client_uuid?
 */
final class RegisterCustomer
{
    public function __invoke(User $user, array $in): Customer
    {
        $phone = GhanaPhone::tryParse($in['phone'] ?? null)
            ?? throw new BusinessRuleViolation('invalid_phone', 'Enter a valid Ghana phone number, e.g. 024 123 4567.', ['phone']);
        if ($dup = Customer::where('phone_e164', $phone->e164)->first()) {
            throw new BusinessRuleViolation('duplicate_customer', "This number already belongs to {$dup->name}.", ['phone']);
        }
        foreach (['whatsapp', 'sms'] as $ch) {
            if (! in_array($in['consent'][$ch] ?? null, ['opted_in', 'withdrawn'], true)) {
                throw new BusinessRuleViolation('consent_answer_required', 'Record the customer\'s answer for both WhatsApp and SMS (yes or no).', ["consent.$ch"]);
            }
        }
        $type = $in['type'] ?? 'retail';
        $s = Settings::all();

        return DB::transaction(function () use ($user, $in, $phone, $type, $s) {
            $owner = Permissions::allows($user, 'credit.set_limits');
            $c = Customer::create([
                'name' => trim($in['name']),
                'phone_e164' => $phone->e164,
                'network' => $phone->network(),
                'type' => $type,
                'town' => $in['town'] ?? null,
                'ghana_post_gps' => isset($in['ghana_post_gps']) ? strtoupper($in['ghana_post_gps']) : null,
                'credit_limit_pesewas' => $type === 'wholesale' && $owner ? (int) ($in['credit_limit_pesewas'] ?? 0) : 0,
                'credit_terms_days' => $type === 'wholesale' && $owner ? (int) ($in['credit_terms_days'] ?? $s['credit_terms_days']) : $s['credit_terms_days'],
                'source' => $in['source'] ?? 'counter',
            ]);
            foreach (['whatsapp', 'sms'] as $ch) {
                Consent::create(['customer_id' => $c->id, 'channel' => $ch, 'purpose' => 'marketing', 'status' => $in['consent'][$ch],
                    'wording_version' => $s['consent_wording_version'], 'source' => ($in['source'] ?? 'counter').' (staff-assisted)',
                    'user_id' => $user->id, 'created_at' => now()]);
            }
            AuditLogger::record('customer.create', "{$c->name} {$phone->masked()} wa:{$in['consent']['whatsapp']} sms:{$in['consent']['sms']}", $user->id, $c);
            if ($in['consent']['whatsapp'] === 'opted_in') {
                // Mode B (R2) sends the approved welcome template via the outbox, once (CAM-006).
                Outbox::push('welcome.due', "welcome:{$c->id}:whatsapp", ['customer_id' => $c->id, 'channel' => 'whatsapp']);
            }

            return $c;
        });
    }
}
