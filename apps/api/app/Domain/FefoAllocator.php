<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * First-expiry-first-out allocation (INV-011). Expired lots are never sellable.
 * Lots without an expiry date sort last.
 */
final class FefoAllocator
{
    /**
     * @param  list<array{id:string, qty:int, expires_on:?string}>  $lots
     * @return list<array{lot_id:string, qty:int}>
     *
     * @throws InsufficientStock
     */
    public static function allocate(array $lots, int $packs, string $today): array
    {
        $sellable = array_values(array_filter($lots, fn ($l) => $l['qty'] > 0 && ($l['expires_on'] === null || $l['expires_on'] >= $today)));
        usort($sellable, fn ($a, $b) => strcmp($a['expires_on'] ?? '9999-12-31', $b['expires_on'] ?? '9999-12-31'));

        $need = $packs;
        $out = [];
        foreach ($sellable as $lot) {
            if ($need === 0) {
                break;
            }
            $take = min($lot['qty'], $need);
            $out[] = ['lot_id' => $lot['id'], 'qty' => $take];
            $need -= $take;
        }
        if ($need > 0) {
            throw new InsufficientStock($packs - $need, $packs);
        }

        return $out;
    }

    public static function isNearExpiry(?string $expiresOn, string $today, int $windowDays): bool
    {
        if ($expiresOn === null || $expiresOn < $today) {
            return false;
        }

        return (strtotime($expiresOn) - strtotime($today)) / 86400 <= $windowDays;
    }
}
