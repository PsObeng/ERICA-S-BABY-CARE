<?php

namespace App\Actions;

use App\Domain\WeightedAverageCost;
use App\Exceptions\BusinessRuleViolation;
use App\Models\GoodsReceipt;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Goods received note (INV-021/022): only accepted packs become sellable; expiry is required for
 * expiry-tracked products and an expired batch is refused; landed cost is spread by value;
 * weighted average cost is updated; past sales keep their own cost snapshots.
 *
 * Input: supplier_name, supplier_invoice?, landed_cost_pesewas?,
 *        lines: [{variant_id, unit: carton|pack, qty, cost_per_unit_pesewas, damaged_packs?, batch?, expires_on?}]
 */
final class ReceiveGoods
{
    /** @return array{receipt: GoodsReceipt, below_cost: list<string>} */
    public function __invoke(User $user, array $in): array
    {
        $today = now('Africa/Accra')->toDateString();

        return DB::transaction(function () use ($user, $in, $today) {
            $supplier = Supplier::firstOrCreate(['name' => trim($in['supplier_name'])]);
            $prepared = [];
            foreach ($in['lines'] as $i => $l) {
                $v = ProductVariant::with('product')->whereKey($l['variant_id'])->lockForUpdate()->firstOrFail();
                $conv = $l['unit'] === 'carton' ? $v->carton_packs : 1;
                $packs = (int) $l['qty'] * $conv;
                $damaged = (int) ($l['damaged_packs'] ?? 0);
                if ($packs < 1 || $damaged > $packs) {
                    throw new BusinessRuleViolation('invalid_quantity', 'Check the quantity and damaged packs for '.$v->displayName(), ["lines.$i"]);
                }
                if ($v->expiry_tracked && empty($l['expires_on'])) {
                    throw new BusinessRuleViolation('expiry_required', 'Expiry date is required for '.$v->displayName(), ["lines.$i.expires_on"]);
                }
                if (! empty($l['expires_on']) && $l['expires_on'] < $today) {
                    throw new BusinessRuleViolation('expired_batch', $v->displayName().': that batch has already expired. Do not accept it into sellable stock.', ["lines.$i.expires_on"]);
                }
                $prepared[] = ['v' => $v, 'l' => $l, 'conv' => $conv, 'packs' => $packs, 'accepted' => $packs - $damaged,
                    'damaged' => $damaged, 'line_cost' => (int) $l['cost_per_unit_pesewas'] * (int) $l['qty']];
            }
            $landed = (int) ($in['landed_cost_pesewas'] ?? 0);
            $shares = WeightedAverageCost::spreadLanded($landed, array_column($prepared, 'line_cost'));
            $number = DocumentNumber::next('GRN');
            $grn = GoodsReceipt::create(['number' => $number, 'supplier_id' => $supplier->id, 'supplier_invoice' => $in['supplier_invoice'] ?? null,
                'landed_cost_pesewas' => $landed, 'total_pesewas' => array_sum(array_column($prepared, 'line_cost')) + $landed,
                'received_by' => $user->id, 'received_at' => now()]);

            $belowCost = [];
            foreach ($prepared as $k => $p) {
                $v = $p['v'];
                $perPack = (int) round(($p['line_cost'] + $shares[$k]) / max(1, $p['accepted']));
                $onHand = (int) StockLot::where('variant_id', $v->id)->sum('qty_on_hand');
                $v->update(['cost_pesewas' => WeightedAverageCost::next($onHand, $v->cost_pesewas, $p['accepted'], $perPack)]);
                if ($v->retail_pesewas < $v->cost_pesewas) {
                    $belowCost[] = $v->displayName();
                }
                $lot = StockLot::create(['variant_id' => $v->id, 'batch' => $p['l']['batch'] ?? '-', 'expires_on' => $p['l']['expires_on'] ?? null,
                    'qty_on_hand' => $p['accepted'], 'received_at' => now()]);
                DB::table('goods_receipt_lines')->insert(['goods_receipt_id' => $grn->id, 'variant_id' => $v->id, 'lot_id' => $lot->id,
                    'unit' => $p['l']['unit'], 'qty' => (int) $p['l']['qty'], 'conversion' => $p['conv'], 'packs_accepted' => $p['accepted'],
                    'packs_damaged' => $p['damaged'], 'line_cost_pesewas' => $p['line_cost'], 'cost_per_pack_pesewas' => $perPack]);
                InventoryMovement::create(['variant_id' => $v->id, 'lot_id' => $lot->id, 'type' => 'receive', 'qty' => $p['accepted'],
                    'reason' => "From {$supplier->name}".($p['damaged'] ? "; {$p['damaged']} damaged/short not accepted" : ''),
                    'reference' => $number, 'user_id' => $user->id, 'created_at' => now()]);
            }
            AuditLogger::record('stock.receive', "{$number} from {$supplier->name}, ".count($prepared).' lines', $user->id, $grn);

            return ['receipt' => $grn, 'below_cost' => $belowCost];
        });
    }
}
