<?php

declare(strict_types=1);

namespace App\Domain;

/** Weighted average cost per pack (INV-022). Past sales keep their own cost snapshots. */
final class WeightedAverageCost
{
    public static function next(int $onHand, int $currentCost, int $receivedPacks, int $receivedCostPerPack): int
    {
        $onHand = max(0, $onHand);
        $total = $onHand + $receivedPacks;
        if ($total === 0) {
            return $currentCost;
        }

        return (int) round(($onHand * $currentCost + $receivedPacks * $receivedCostPerPack) / $total);
    }

    /**
     * Spread a delivery's landed cost (transport, offloading) over lines by line value.
     *
     * @param  list<int>  $lineCosts
     * @return list<int>
     */
    public static function spreadLanded(int $landed, array $lineCosts): array
    {
        $total = array_sum($lineCosts);
        if ($landed === 0 || $total === 0) {
            return array_fill(0, count($lineCosts), 0);
        }
        $shares = array_map(fn ($c) => intdiv($landed * $c, $total), $lineCosts);
        $shares[array_key_last($shares)] += $landed - array_sum($shares); // remainder to the last line

        return $shares;
    }
}
