<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Server-side price decision (PRC-004, WHL-002). The client never sends a final price.
 *
 * - carton unit: carton price if set, otherwise retail × packs per carton
 * - pack unit: Tier A when the quantity reaches the Tier A minimum, or the customer is wholesale
 */
final class PriceResolver
{
    /**
     * @param  array{retail:int, tier_a:?int, tier_a_min:int, carton_price:?int, carton_packs:int}  $variant
     * @return array{unit_price:int, tier:string, packs:int}
     */
    public static function resolve(array $variant, string $unit, int $qty, bool $wholesaleCustomer): array
    {
        if ($qty < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }
        if ($unit === 'carton') {
            $packsPer = max(1, $variant['carton_packs']);
            if ($packsPer === 1) {
                throw new \InvalidArgumentException('This product has no carton unit.');
            }
            $price = $variant['carton_price'] ?: $variant['retail'] * $packsPer;

            return ['unit_price' => $price, 'tier' => $variant['carton_price'] ? 'carton' : 'retail_x_carton', 'packs' => $qty * $packsPer];
        }
        if ($unit !== 'pack') {
            throw new \InvalidArgumentException("Unknown unit: {$unit}");
        }
        $tierA = $variant['tier_a'] && ($qty >= $variant['tier_a_min'] || $wholesaleCustomer);

        return ['unit_price' => $tierA ? $variant['tier_a'] : $variant['retail'], 'tier' => $tierA ? 'tier_a' : 'retail', 'packs' => $qty];
    }

    /** Gross margin % (PRC-002). Labelled gross, never net. */
    public static function grossMarginPct(int $price, int $cost): float
    {
        return $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0;
    }
}
