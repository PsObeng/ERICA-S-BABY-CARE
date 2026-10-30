<?php

namespace App\Support;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Business settings with owner-editable defaults (PRD B20). Nothing here is hard-coded in features.
 */
final class Settings
{
    public const DEFAULTS = [
        'business_name' => "ERICA'S BABY CARE",
        'location' => 'Hansua–Techiman',
        'phone_primary' => '0544935335',
        'phone_secondary' => '0241087822',
        'momo_merchant_number' => '0544935335',
        'cashier_discount_pct' => 5,
        'manager_discount_pct' => 15,
        'momo_release_limit_pesewas' => 30000,
        'near_expiry_days' => 60,
        'adjust_owner_approval_pesewas' => 20000,
        'credit_terms_days' => 14,
        'overdue_block_days' => 7,
        'return_window_days' => 7,
        'marketing_max_per_week' => 2,
        'marketing_max_per_month' => 6,
        'quiet_hours' => ['20:00', '07:30'],
        'vat_registered' => false,
        'vat_goods_threshold_pesewas' => 75000000,
        'consent_wording_version' => 'C1-2026-10',
        'consent_wording' => 'May we send you offers and new-stock alerts? About 1–2 messages a week. You can reply STOP at any time.',
        'offline_catalogue_warn_hours' => 72,
        'offline_catalogue_block_days' => 7,
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return Cache::remember('business_settings', 60, function () {
            $stored = BusinessSetting::query()->pluck('value', 'key')->all();

            return array_replace(self::DEFAULTS, $stored);
        });
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function forget(): void
    {
        Cache::forget('business_settings');
    }
}
