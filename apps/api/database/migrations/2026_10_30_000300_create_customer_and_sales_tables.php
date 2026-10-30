<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers + consent ledger, cashier shifts, orders, payments (incl. manual MoMo),
 * wholesale receivables, returns and the manual-message log (B7, B8, WHL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 120);
            $t->string('phone_e164', 16)->unique();             // CRM-003: duplicates blocked on normalised phone
            $t->string('network', 10)->nullable();
            $t->string('type', 12)->default('retail');         // retail | wholesale
            $t->string('town', 80)->nullable();
            $t->string('ghana_post_gps', 20)->nullable();
            $t->unsignedBigInteger('credit_limit_pesewas')->default(0);
            $t->unsignedSmallInteger('credit_terms_days')->default(14);
            $t->boolean('do_not_market')->default(false);
            $t->string('source', 20)->default('counter');
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('consents', function (Blueprint $t) {               // append-only ledger (CON-005)
            $t->id();
            $t->foreignUlid('customer_id')->constrained('customers');
            $t->string('channel', 10);                          // whatsapp | sms
            $t->string('purpose', 16)->default('marketing');
            $t->string('status', 12);                           // opted_in | withdrawn
            $t->string('wording_version', 20);
            $t->string('source', 60);
            $t->string('evidence_ref', 120)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamp('created_at', 6);
            $t->index(['customer_id', 'channel', 'created_at']);
        });

        Schema::create('message_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('customer_id')->constrained('customers');
            $t->string('kind', 16);                             // welcome | marketing | transactional | statement
            $t->string('channel', 10);
            $t->string('mode', 8)->default('manual');           // manual (Mode A) | api (Mode B)
            $t->string('provider_message_id', 120)->nullable();
            $t->string('status', 16)->default('sent_manually');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamp('created_at', 6);
            $t->index(['customer_id', 'kind', 'created_at']);
        });

        Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignUlid('device_id')->nullable()->constrained('devices');
            $t->unsignedBigInteger('opening_float_pesewas');
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->bigInteger('expected_cash_pesewas')->nullable();
            $t->bigInteger('counted_cash_pesewas')->nullable();
            $t->bigInteger('variance_pesewas')->nullable();
            $t->string('variance_reason', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('shift_cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('shift_id')->constrained('cashier_shifts');
            $t->string('direction', 4);                         // in | out
            $t->unsignedBigInteger('amount_pesewas');
            $t->string('reason', 200);
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('created_at', 6);
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 28)->unique();
            $t->string('source', 12);                           // pos | web | whatsapp | phone
            $t->string('status', 16);                           // completed | received | confirmed | ... | cancelled | returned
            $t->foreignUlid('customer_id')->nullable()->constrained('customers');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->foreignUlid('shift_id')->nullable()->constrained('cashier_shifts');
            $t->foreignUlid('device_id')->nullable()->constrained('devices');
            $t->uuid('client_uuid')->nullable()->unique();      // idempotency for offline/online submits
            $t->unsignedBigInteger('subtotal_pesewas');
            $t->unsignedBigInteger('discount_pesewas')->default(0);
            $t->unsignedBigInteger('total_pesewas');
            $t->unsignedBigInteger('change_given_pesewas')->default(0);
            $t->foreignId('discount_approved_by')->nullable()->constrained('users');
            $t->boolean('needs_review')->default(false);        // e.g. offline sale that drove stock negative
            $t->string('review_note', 200)->nullable();
            $t->timestamp('placed_at', 6);
            $t->timestamps();
            $t->index(['placed_at']);
        });

        Schema::create('order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('order_id')->constrained('orders');
            $t->foreignUlid('variant_id')->constrained('product_variants');
            $t->string('name_snapshot', 200);
            $t->string('unit', 10);
            $t->unsignedInteger('qty');
            $t->unsignedInteger('packs');
            $t->unsignedSmallInteger('conversion');
            $t->unsignedBigInteger('unit_price_pesewas');       // immutable snapshot (PRC-008)
            $t->string('tier', 20);
            $t->unsignedBigInteger('cost_snapshot_pesewas');
            $t->unsignedInteger('returned_qty')->default(0);
        });

        Schema::create('order_line_lots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_line_id')->constrained('order_lines');
            $t->foreignUlid('lot_id')->constrained('stock_lots');
            $t->unsignedInteger('packs');
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('order_id')->nullable()->constrained('orders');
            $t->foreignUlid('customer_id')->nullable()->constrained('customers'); // for receivable payments
            $t->string('purpose', 12)->default('sale');         // sale | receivable
            $t->string('method', 12);                           // cash | momo_manual | momo_gateway | card | bank | credit
            $t->unsignedBigInteger('amount_pesewas');
            $t->string('network', 10)->nullable();
            $t->string('momo_txn_id', 40)->nullable();
            $t->string('payer_e164', 16)->nullable();
            $t->string('status', 16);                           // received | pending_verification | verified | rejected | on_account
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->foreignUlid('shift_id')->nullable()->constrained('cashier_shifts');
            $t->string('provider', 20)->nullable();             // R3 gateway
            $t->string('provider_reference', 80)->nullable();
            $t->timestamps();
            $t->unique(['network', 'momo_txn_id']);             // PAY-002: one transaction ID can be used once
            $t->unique(['provider', 'provider_reference']);
        });

        Schema::create('receivable_invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('customer_id')->constrained('customers');
            $t->foreignUlid('order_id')->constrained('orders');
            $t->string('number', 28)->unique();
            $t->unsignedBigInteger('amount_pesewas');
            $t->unsignedBigInteger('paid_pesewas')->default(0);
            $t->unsignedBigInteger('credited_pesewas')->default(0); // returns to account + write-offs
            $t->date('due_on');
            $t->timestamps();
            $t->index(['customer_id', 'due_on']);
        });

        Schema::create('receivable_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('invoice_id')->constrained('receivable_invoices');
            $t->foreignUlid('payment_id')->nullable()->constrained('payments');
            $t->string('kind', 12);                             // payment | return | write_off
            $t->unsignedBigInteger('amount_pesewas');
            $t->string('reason', 200)->nullable();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('created_at', 6);
        });

        Schema::create('order_returns', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 28)->unique();
            $t->foreignUlid('order_id')->constrained('orders');
            $t->unsignedBigInteger('amount_pesewas');
            $t->string('refund_method', 12);                    // cash | account
            $t->string('condition', 10);                        // stock | damaged
            $t->string('reason', 200);
            $t->json('lines');                                  // [{order_line_id, qty}]
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->foreignUlid('shift_id')->nullable()->constrained('cashier_shifts');
            $t->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER consents_append_only BEFORE UPDATE OR DELETE ON consents
                  FOR EACH ROW EXECUTE FUNCTION dw360_forbid_change();
                CREATE TRIGGER receivable_allocations_append_only BEFORE UPDATE OR DELETE ON receivable_allocations
                  FOR EACH ROW EXECUTE FUNCTION dw360_forbid_change();

                -- Payments: only the verification fields may change after insert; never deleted.
                CREATE OR REPLACE FUNCTION dw360_payments_guard() RETURNS trigger AS $$
                BEGIN
                  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'payments are never deleted'; END IF;
                  IF (NEW.amount_pesewas, NEW.method, NEW.network, NEW.momo_txn_id, NEW.order_id, NEW.customer_id)
                     IS DISTINCT FROM (OLD.amount_pesewas, OLD.method, OLD.network, OLD.momo_txn_id, OLD.order_id, OLD.customer_id)
                  THEN RAISE EXCEPTION 'payment amount, method and reference are immutable'; END IF;
                  RETURN NEW;
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER payments_guard BEFORE UPDATE OR DELETE ON payments
                  FOR EACH ROW EXECUTE FUNCTION dw360_payments_guard();

                -- Order lines are price/cost snapshots: only returned_qty may change.
                CREATE OR REPLACE FUNCTION dw360_order_lines_guard() RETURNS trigger AS $$
                BEGIN
                  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'order lines are never deleted'; END IF;
                  IF (NEW.unit_price_pesewas, NEW.qty, NEW.cost_snapshot_pesewas, NEW.variant_id)
                     IS DISTINCT FROM (OLD.unit_price_pesewas, OLD.qty, OLD.cost_snapshot_pesewas, OLD.variant_id)
                  THEN RAISE EXCEPTION 'order line snapshots are immutable'; END IF;
                  IF NEW.returned_qty > NEW.qty THEN RAISE EXCEPTION 'cannot return more than sold'; END IF;
                  RETURN NEW;
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER order_lines_guard BEFORE UPDATE OR DELETE ON order_lines
                  FOR EACH ROW EXECUTE FUNCTION dw360_order_lines_guard();

                ALTER TABLE receivable_invoices ADD CONSTRAINT receivable_not_overpaid
                  CHECK (paid_pesewas + credited_pesewas <= amount_pesewas);
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['order_returns', 'receivable_allocations', 'receivable_invoices', 'payments', 'order_line_lots', 'order_lines', 'orders', 'shift_cash_movements', 'cashier_shifts', 'message_logs', 'consents', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS dw360_payments_guard() CASCADE; DROP FUNCTION IF EXISTS dw360_order_lines_guard() CASCADE;');
        }
    }
};
