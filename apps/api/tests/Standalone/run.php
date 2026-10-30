<?php

declare(strict_types=1);

/*
 * Framework-free tests for the pure domain rules in app/Domain.
 * Run: php tests/Standalone/run.php   (no composer install needed)
 * The full Laravel feature tests live in tests/Feature and run with `php artisan test`.
 */

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\Domain\\')) {
        require __DIR__.'/../../app/Domain/'.substr($class, 11).'.php';
    }
});

use App\Domain\FefoAllocator;
use App\Domain\GhanaPhone;
use App\Domain\HashChain;
use App\Domain\InsufficientStock;
use App\Domain\Money;
use App\Domain\PriceResolver;
use App\Domain\ReceivableAllocator;
use App\Domain\WeightedAverageCost;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  PASS " : "  FAIL ").$name.PHP_EOL;
}
function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
}

echo "Money\n";
check('parses 1,250.50 to 125050 pesewas', Money::parse('1,250.50')->pesewas === 125050);
check('parses 0.1 without float error', Money::parse('0.1')->pesewas === 10);
check('parses 19.99 exactly', Money::parse('19.99')->pesewas === 1999);
check('rejects 1.234', throws(fn () => Money::parse('1.234'), InvalidArgumentException::class));
check('formats negative with minus sign', Money::of(-34850)->format() === '−GH₵348.50');
check('formats thousands', Money::of(259800)->format() === 'GH₵2,598.00');
check('percent rounds half up', Money::of(1005)->percent(10)->pesewas === 101);

echo "GhanaPhone\n";
check('0244123456 -> +233244123456', GhanaPhone::tryParse('024 412 3456')?->e164 === '+233244123456');
check('233 prefix accepted', GhanaPhone::tryParse('233559998877')?->e164 === '+233559998877');
check('+233 prefix accepted', GhanaPhone::tryParse('+233 20 198 7654')?->e164 === '+233201987654');
check('too short rejected', GhanaPhone::tryParse('02441234') === null);
check('local display', GhanaPhone::tryParse('0244123456')?->local() === '024 412 3456');
check('MTN detected', GhanaPhone::tryParse('0551234567')?->network() === 'MTN');
check('Telecel detected', GhanaPhone::tryParse('0201234567')?->network() === 'Telecel');
check('AT detected', GhanaPhone::tryParse('0271234567')?->network() === 'AT');
check('masked keeps last 4', GhanaPhone::tryParse('0244123456')?->masked() === '•••• 3456');

echo "PriceResolver\n";
$v = ['retail' => 11500, 'tier_a' => 10800, 'tier_a_min' => 10, 'carton_price' => 42000, 'carton_packs' => 4];
check('retail below tier A minimum', PriceResolver::resolve($v, 'pack', 2, false)['unit_price'] === 11500);
check('tier A from 10 packs', PriceResolver::resolve($v, 'pack', 10, false)['unit_price'] === 10800);
check('wholesale customer always tier A', PriceResolver::resolve($v, 'pack', 1, true)['tier'] === 'tier_a');
check('carton price and packs', PriceResolver::resolve($v, 'carton', 2, false) === ['unit_price' => 42000, 'tier' => 'carton', 'packs' => 8]);
check('carton without carton price = retail x packs', PriceResolver::resolve(['carton_price' => null] + $v, 'carton', 1, false)['unit_price'] === 46000);
check('no carton unit rejected', throws(fn () => PriceResolver::resolve(['carton_packs' => 1] + $v, 'carton', 1, false), InvalidArgumentException::class));
check('gross margin %', PriceResolver::grossMarginPct(11500, 9000) === 21.7);

echo "FefoAllocator\n";
$lots = [
    ['id' => 'late', 'qty' => 96, 'expires_on' => '2027-08-26'],
    ['id' => 'expired', 'qty' => 6, 'expires_on' => '2026-10-25'],
    ['id' => 'soon', 'qty' => 40, 'expires_on' => '2026-11-29'],
    ['id' => 'none', 'qty' => 5, 'expires_on' => null],
];
$a = FefoAllocator::allocate($lots, 45, '2026-10-30');
check('earliest expiry first, then next', $a === [['lot_id' => 'soon', 'qty' => 40], ['lot_id' => 'late', 'qty' => 5]]);
check('expired lot never used', ! in_array('expired', array_column(FefoAllocator::allocate($lots, 141, '2026-10-30'), 'lot_id'), true));
check('insufficient sellable stock throws', throws(fn () => FefoAllocator::allocate($lots, 142, '2026-10-30'), InsufficientStock::class));
check('near expiry within 60 days', FefoAllocator::isNearExpiry('2026-11-29', '2026-10-30', 60));
check('expired is not "near expiry"', ! FefoAllocator::isNearExpiry('2026-10-25', '2026-10-30', 60));

echo "WeightedAverageCost\n";
check('WAC blends old and new', WeightedAverageCost::next(10, 9000, 30, 10000) === 9750);
check('WAC from empty stock = new cost', WeightedAverageCost::next(0, 9000, 12, 9500) === 9500);
check('landed cost spread sums exactly', array_sum(WeightedAverageCost::spreadLanded(1001, [3000, 3000, 3000])) === 1001);

echo "ReceivableAllocator\n";
$inv = [['id' => 'i1', 'outstanding' => 132600], ['id' => 'i2', 'outstanding' => 137200]];
check('oldest first', ReceivableAllocator::allocate($inv, 150000) === [['invoice_id' => 'i1', 'amount' => 132600], ['invoice_id' => 'i2', 'amount' => 17400]]);
check('overpayment refused', throws(fn () => ReceivableAllocator::allocate($inv, 300000), DomainException::class));

echo "HashChain\n";
$entries = [];
$prev = HashChain::GENESIS;
foreach ([['action' => 'sale', 'detail' => 'RCPT-1'], ['action' => 'momo.verify', 'detail' => 'MTN ABC'], ['action' => 'refund', 'detail' => 'RTN-1']] as $f) {
    $h = HashChain::hash($prev, $f);
    $entries[] = ['prev_hash' => $prev, 'hash' => $h, 'fields' => $f];
    $prev = $h;
}
check('intact chain verifies', HashChain::verify($entries)['ok']);
$tampered = $entries;
$tampered[1]['fields']['detail'] = 'MTN XYZ';
check('edited entry detected', HashChain::verify($tampered)['broken_at'] === 2);
$deleted = [$entries[0], $entries[2]];
check('deleted entry detected', HashChain::verify($deleted)['broken_at'] === 2);

echo PHP_EOL."{$pass} passed, {$fail} failed".PHP_EOL;
exit($fail ? 1 : 0);
