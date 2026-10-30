<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Ghana phone numbers (CRM-002): accepts 0XX XXX XXXX, 233XXXXXXXXX and +233XXXXXXXXX,
 * stores E.164, shows local format, and guesses the network from the prefix.
 * The prefix table is configuration because numbers can be ported between networks.
 */
final class GhanaPhone
{
    public const DEFAULT_PREFIXES = [
        'MTN' => ['24', '54', '55', '59', '25', '53'],
        'Telecel' => ['20', '50'],
        'AT' => ['26', '56', '27', '57'],
    ];

    private function __construct(public readonly string $e164) {}

    public static function tryParse(?string $input): ?self
    {
        $digits = preg_replace('/\D+/', '', (string) $input) ?? '';
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 9 || $digits[0] === '0') {
            return null;
        }

        return new self('+233'.$digits);
    }

    public function local(): string
    {
        $l = '0'.substr($this->e164, 4);

        return substr($l, 0, 3).' '.substr($l, 3, 3).' '.substr($l, 6);
    }

    /** @param array<string, list<string>> $prefixes */
    public function network(array $prefixes = self::DEFAULT_PREFIXES): string
    {
        $p = substr($this->e164, 4, 2);
        foreach ($prefixes as $name => $list) {
            if (in_array($p, $list, true)) {
                return $name;
            }
        }

        return 'Other';
    }

    /** Last 4 digits only — for logs and reports (SEC tier 1 redaction). */
    public function masked(): string
    {
        return '•••• '.substr($this->e164, -4);
    }
}
