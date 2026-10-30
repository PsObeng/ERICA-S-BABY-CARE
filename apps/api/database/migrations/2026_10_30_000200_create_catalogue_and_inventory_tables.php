<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue, pricing, lots/expiry, the inventory ledger and purchasing (B5, B6).
 * Money columns are integer pesewas; quantities are integer packs (base unit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('brand', 60);
            $t->string('name', 120);
            $t->string('category', 30);                       // diapers | pants | wipes | bundles | other
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $t->string('sku', 30)->unique();
            $t->string('barcode', 40)->nullable()->unique();
            $t->string('size_label', 20);
            $t->unsignedSmallInteger('pack_count');            // pieces per pack
            $t->unsignedSmallInteger('carton_packs')->default(1); // CAT-002: 1 carton = n packs
            $t->unsignedBigInteger('cost_pesewas');            // weighted average cost per pack
            $t->unsignedBigInteger('retail_pesewas');
            $t->unsignedBigInteger('tier_a_pesewas')->nullable();
            $t->unsignedSmallInteger('tier_a_min')->default(10);
            $t->unsignedBigInteger('carton_price_pesewas')->nullable();
            $t->unsignedInteger('low_stock_packs')->default(12);
            $t->boolean('expiry_tracked')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('price_change_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('variant_id')->constrained('product_variants');
            $t->string('field', 30);
            $t->unsignedBigInteger('old_value')->nullable();
            $t->unsignedBigInteger('new_value')->nullable();
            $t->string('reason', 200);
            $t->foreignId('changed_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('effective_at');
            $t->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 120)->unique();
            $t->string('phone_e164', 16)->nullable();
            $t->string('location', 120)->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->timestamps();
        });

        Schema::create('stock_lots', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('variant_id')->constrained('product_variants');
            $t->string('batch', 40)->default('-');
            $t->date('expires_on')->nullable();
            $t->integer('qty_on_hand');                         // may go negative only via flagged offline sync (OFF-005)
            $t->timestamp('received_at');
            $t->timestamps();
            $t->index(['variant_id', 'expires_on']);
        });

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 24)->unique();
            $t->foreignUlid('supplier_id')->constrained('suppliers');
            $t->string('supplier_invoice', 60)->nullable();
            $t->unsignedBigInteger('landed_cost_pesewas')->default(0);
            $t->unsignedBigInteger('total_pesewas');
            $t->foreignId('received_by')->constrained('users');
            $t->timestamp('received_at');
            $t->timestamps();
        });

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('goods_receipt_id')->constrained('goods_receipts');
            $t->foreignUlid('variant_id')->constrained('product_variants');
            $t->foreignUlid('lot_id')->constrained('stock_lots');
            $t->string('unit', 10);
            $t->unsignedInteger('qty');
            $t->unsignedSmallInteger('conversion');            // snapshot of carton_packs (CAT-002)
            $t->unsignedInteger('packs_accepted');
            $t->unsignedInteger('packs_damaged')->default(0);
            $t->unsignedBigInteger('line_cost_pesewas');
            $t->unsignedBigInteger('cost_per_pack_pesewas');
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('variant_id')->constrained('product_variants');
            $t->foreignUlid('lot_id')->constrained('stock_lots');
            $t->string('type', 20);  // opening | receive | sale | return | write_off | adjustment | count | carton_break | supplier_return
            $t->integer('qty');      // packs, signed
            $t->string('reason', 200);
            $t->string('reference', 30);
            $t->foreignId('user_id')->constrained('users');
            $t->foreignUlid('device_id')->nullable()->constrained('devices');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('created_at', 6);
            $t->index(['variant_id', 'created_at']);
        });

        Schema::create('stock_count_sessions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 24)->unique();
            $t->foreignId('counted_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->json('lines');                                  // [{variant_id, counted, system, variance}]
            $t->bigInteger('variance_value_pesewas');
            $t->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER inventory_movements_append_only BEFORE UPDATE OR DELETE ON inventory_movements
                  FOR EACH ROW EXECUTE FUNCTION dw360_forbid_change();
                CREATE TRIGGER price_change_events_append_only BEFORE UPDATE OR DELETE ON price_change_events
                  FOR EACH ROW EXECUTE FUNCTION dw360_forbid_change();
                ALTER TABLE product_variants ADD CONSTRAINT carton_price_not_above_packs
                  CHECK (carton_price_pesewas IS NULL OR carton_packs = 1 OR carton_price_pesewas <= retail_pesewas * carton_packs);
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['stock_count_sessions', 'inventory_movements', 'goods_receipt_lines', 'goods_receipts', 'stock_lots', 'suppliers', 'price_change_events', 'product_variants', 'products'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
