<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Money in integer pesewas (1 GH₵ = 100 pesewas). Never floats (PRC-001).
 */
final class Money
{
    private function __construct(public readonly int $pesewas) {}

    public static function of(int $pesewas): self
    {
        return new self($pesewas);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** Parse user input such as "1,250.50" or "12" into pesewas without float rounding errors. */
    public static function parse(string $cedis): self
    {
        $clean = str_replace([',', ' ', 'GH₵', 'GHS'], '', trim($cedis));
        if ($clean === '' || ! preg_match('/^-?\d+(\.\d{1,2})?$/', $clean)) {
            throw new InvalidArgumentException("Not a valid cedi amount: {$cedis}");
        }
        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');
        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '0');
        $pesewas = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');

        return new self($negative ? -$pesewas : $pesewas);
    }

    public function plus(self $other): self
    {
        return new self($this->pesewas + $other->pesewas);
    }

    public function minus(self $other): self
    {
        return new self($this->pesewas - $other->pesewas);
    }

    public function times(int $qty): self
    {
        return new self($this->pesewas * $qty);
    }

    /** Percentage of this amount, rounded half-up to the pesewa. */
    public function percent(float $pct): self
    {
        return new self((int) round($this->pesewas * $pct / 100, 0, PHP_ROUND_HALF_UP));
    }

    public function isNegative(): bool
    {
        return $this->pesewas < 0;
    }

    public function format(): string
    {
        $sign = $this->pesewas < 0 ? '−' : '';
        $abs = abs($this->pesewas);

        return $sign.'GH₵'.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
