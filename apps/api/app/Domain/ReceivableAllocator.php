<?php

declare(strict_types=1);

namespace App\Domain;

/** Oldest-invoice-first payment allocation (WHL-005). */
final class ReceivableAllocator
{
    /**
     * @param  list<array{id:string, outstanding:int}>  $invoicesOldestFirst
     * @return list<array{invoice_id:string, amount:int}>
     */
    public static function allocate(array $invoicesOldestFirst, int $amount): array
    {
        $total = array_sum(array_column($invoicesOldestFirst, 'outstanding'));
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment must be more than zero.');
        }
        if ($amount > $total) {
            throw new \DomainException('Payment is more than the amount owed.');
        }
        $left = $amount;
        $out = [];
        foreach ($invoicesOldestFirst as $inv) {
            if ($left === 0) {
                break;
            }
            $take = min($left, $inv['outstanding']);
            if ($take > 0) {
                $out[] = ['invoice_id' => $inv['id'], 'amount' => $take];
                $left -= $take;
            }
        }

        return $out;
    }
}
