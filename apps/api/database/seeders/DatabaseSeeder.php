<?php

namespace Database\Seeders;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * SAMPLE data only — replace with the owner's verified catalogue, costs, prices and opening stock
 * before go-live (R1 gate). Developers never invent real prices (B1 rule of truth).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [['Erica', 'owner', 'owner@example.test', '1234'], ['Ama', 'manager', 'manager@example.test', '2222'], ['Kofi', 'cashier', 'cashier@example.test', '3333']];
        foreach ($staff as [$name, $role, $email, $pin]) {
            User::query()->forceCreate(['name' => $name, 'email' => $email, 'role' => $role, 'password' => Hash::make('change-me-'.$pin), 'pin_hash' => Hash::make($pin), 'active' => true]);
        }

        $rows = [
            ['Softcare', 'Diapers Medium (M)', 'diapers', 'M', 40, 4, 9000, 11500, 10800, 10, 42000, false, [[36, null]]],
            ['Softcare', 'Diapers Large (L)', 'diapers', 'L', 36, 4, 9400, 12000, 11300, 10, 44000, false, [[53, null]]],
            ['Molfix', 'Diapers Size 3 (M)', 'diapers', 'M', 58, 3, 13500, 16500, 15800, 10, 46500, false, [[28, null]]],
            ['Molfix', 'Pants Size 5 (XL)', 'pants', 'XL', 44, 3, 14800, 18000, 17200, 10, 51000, false, [[46, null]]],
            ['Pampers', 'Baby-Dry Size 4 (L)', 'diapers', 'L', 46, 3, 19500, 23500, 22500, 6, 66000, false, [[47, null]]],
            ['Huggies', 'Dry Comfort Newborn (NB)', 'diapers', 'NB', 30, 4, 8800, 11000, 10400, 10, 40000, false, [[8, null]]],
            ['Softcare', 'Baby Wipes 80', 'wipes', 'Wipes', 80, 12, 1500, 2200, 2000, 12, 22800, true, [[40, '+30 days'], [96, '+300 days']]],
            ['Molfix', 'Baby Wipes 60', 'wipes', 'Wipes', 60, 12, 1300, 1900, 1750, 12, 19800, true, [[6, '-5 days'], [72, '+240 days']]],
        ];
        foreach ($rows as $i => [$brand, $name, $cat, $size, $pcs, $carton, $cost, $retail, $tierA, $tierMin, $cartonPrice, $exp, $lots]) {
            $p = Product::create(['brand' => $brand, 'name' => $name, 'category' => $cat, 'active' => true]);
            $v = ProductVariant::create(['product_id' => $p->id, 'sku' => sprintf('SKU-%03d', $i + 1), 'size_label' => $size, 'pack_count' => $pcs,
                'carton_packs' => $carton, 'cost_pesewas' => $cost, 'retail_pesewas' => $retail, 'tier_a_pesewas' => $tierA, 'tier_a_min' => $tierMin,
                'carton_price_pesewas' => $cartonPrice, 'low_stock_packs' => $exp ? 24 : 12, 'expiry_tracked' => $exp, 'active' => true]);
            foreach ($lots as $k => [$qty, $rel]) {
                $lot = StockLot::create(['variant_id' => $v->id, 'batch' => $exp ? "B{$i}{$k}" : '-', 'expires_on' => $rel ? now()->modify($rel)->toDateString() : null,
                    'qty_on_hand' => $qty, 'received_at' => now()]);
                InventoryMovement::create(['variant_id' => $v->id, 'lot_id' => $lot->id, 'type' => 'opening', 'qty' => $qty,
                    'reason' => 'Opening stock (SAMPLE)', 'reference' => 'OPEN', 'user_id' => User::where('role', 'owner')->value('id'), 'created_at' => now()]);
            }
        }
    }
}
