<?php

declare(strict_types=1);

namespace App\Domain;

final class InsufficientStock extends \DomainException
{
    public function __construct(public readonly int $available, public readonly int $requested)
    {
        parent::__construct("Only {$available} sellable packs available; {$requested} requested (expired batches are blocked).");
    }
}
