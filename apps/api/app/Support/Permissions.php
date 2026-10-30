<?php

namespace App\Support;

use App\Models\User;

/**
 * Role + bundle permissions (PRD B3). Default deny. Checked on the server for every route;
 * hiding a button in the app is never the control (SEC-ROLE-001).
 */
final class Permissions
{
    public const ROLES = [
        'owner' => ['*'],
        'manager' => ['sell', 'sales.view', 'momo.verify', 'refund', 'prices.edit', 'stock.receive', 'stock.adjust',
            'customers.manage', 'receivables.manage', 'flyers.manage', 'reports.view', 'shifts.view_all', 'discount.manager'],
        'cashier' => ['sell', 'sales.view', 'customers.manage', 'stock.receive'],
        'rider' => ['deliveries.own'],
    ];

    /** Grants that can be added to a person without changing their role. */
    public const BUNDLES = ['momo.verify', 'prices.edit', 'stock.adjust', 'flyers.manage', 'reports.view', 'campaign.approve'];

    /** Owner-only, never grantable by bundle. */
    public const OWNER_ONLY = ['credit.set_limits', 'receivables.write_off', 'export.customers', 'export.full', 'staff.manage',
        'settings.manage', 'integrations.manage', 'devices.manage', 'discount.any', 'credit.override', 'price.below_cost'];

    public static function allows(User $user, string $ability): bool
    {
        if (! $user->active) {
            return false;
        }
        $grants = self::ROLES[$user->role] ?? [];
        if (in_array('*', $grants, true)) {
            return true;
        }
        if (in_array($ability, self::OWNER_ONLY, true)) {
            return false;
        }

        return in_array($ability, $grants, true)
            || (in_array($ability, self::BUNDLES, true) && in_array($ability, $user->bundles ?? [], true));
    }

    /** Largest discount % this person may give without approval (PRC-007). */
    public static function discountLimitPct(User $user, array $settings): float
    {
        return match ($user->role) {
            'owner' => 100.0,
            'manager' => (float) $settings['manager_discount_pct'],
            default => (float) $settings['cashier_discount_pct'],
        };
    }
}
