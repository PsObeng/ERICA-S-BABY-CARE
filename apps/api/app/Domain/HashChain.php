<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Tamper-evident hash chain (SEC-INT-060). Each entry stores the SHA-256 of the previous
 * entry's hash plus its own canonical content; editing or deleting any row breaks the chain.
 */
final class HashChain
{
    public const GENESIS = 'GENESIS';

    /** @param array<string, mixed> $fields */
    public static function hash(string $previous, array $fields): string
    {
        ksort($fields);

        return hash('sha256', $previous.'|'.json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  iterable<array{prev_hash:string, hash:string, fields:array<string,mixed>}>  $entries  in sequence order
     * @return array{ok:bool, checked:int, broken_at:?int}
     */
    public static function verify(iterable $entries): array
    {
        $prev = self::GENESIS;
        $n = 0;
        foreach ($entries as $e) {
            $n++;
            if ($e['prev_hash'] !== $prev || self::hash($prev, $e['fields']) !== $e['hash']) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => $n];
            }
            $prev = $e['hash'];
        }

        return ['ok' => true, 'checked' => $n, 'broken_at' => null];
    }
}
