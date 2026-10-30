<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R1 go-live acceptance cases (PRD B17). Run: php artisan test
 * CI runs these against PostgreSQL 16 so the database triggers are exercised too.
 */
class R1AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $cashier;
    private ProductVariant $diapers;
    private ProductVariant $wipes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->role('owner', '1234')->create();
        $this->manager = User::factory()->role('manager', '2222')->create();
        $this->cashier = User::factory()->role('cashier', '3333')->create();

        $p = Product::create(['brand' => 'Softcare', 'name' => 'Diapers Medium (M)', 'category' => 'diapers']);
        $this->diapers = ProductVariant::create(['product_id' => $p->id, 'sku' => 'SKU-001', 'size_label' => 'M', 'pack_count' => 40, 'carton_packs' => 4,
            'cost_pesewas' => 9000, 'retail_pesewas' => 11500, 'tier_a_pesewas' => 10800, 'tier_a_min' => 10, 'carton_price_pesewas' => 42000]);
        StockLot::create(['variant_id' => $this->diapers->id, 'qty_on_hand' => 40, 'received_at' => now()]);

        $w = Product::create(['brand' => 'Molfix', 'name' => 'Baby Wipes 60', 'category' => 'wipes']);
        $this->wipes = ProductVariant::create(['product_id' => $w->id, 'sku' => 'SKU-002', 'size_label' => 'Wipes', 'pack_count' => 60, 'carton_packs' => 12,
            'cost_pesewas' => 1300, 'retail_pesewas' => 1900, 'expiry_tracked' => true]);
        StockLot::create(['variant_id' => $this->wipes->id, 'batch' => 'OLD', 'expires_on' => now()->subDays(5)->toDateString(), 'qty_on_hand' => 6, 'received_at' => now()]);
        StockLot::create(['variant_id' => $this->wipes->id, 'batch' => 'SOON', 'expires_on' => now()->addDays(30)->toDateString(), 'qty_on_hand' => 10, 'received_at' => now()]);
        StockLot::create(['variant_id' => $this->wipes->id, 'batch' => 'LATE', 'expires_on' => now()->addDays(300)->toDateString(), 'qty_on_hand' => 50, 'received_at' => now()]);

        foreach ([$this->owner, $this->manager, $this->cashier] as $u) {
            $this->actingAs($u)->postJson('/api/v1/shifts/open', ['float_pesewas' => 20000], $this->idem())->assertCreated();
        }
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => Str::random(24)];
    }

    private function sale(User $u, array $body, ?array $headers = null)
    {
        return $this->actingAs($u)->postJson('/api/v1/sales', $body, $headers ?? $this->idem());
    }

    public function test_split_tender_sale_with_pending_momo_and_fefo(): void
    {
        $r = $this->sale($this->cashier, [
            'lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 2], ['variant_id' => $this->wipes->id, 'unit' => 'pack', 'qty' => 12]],
            'tenders' => ['cash_pesewas' => 10000, 'momo' => ['amount_pesewas' => 35800, 'network' => 'MTN', 'txn_id' => 'ABC123456', 'payer' => '0244123456']],
        ])->assertCreated();

        // 2 × 115.00 retail + 12 × 19.00 (no tier A on wipes) = 458.00
        $this->assertSame(45800, $r->json('total_pesewas'));
        $this->assertSame('pending_verification', Payment::where('method', 'momo_manual')->value('status'));
        // FEFO: SOON (10) first, then LATE (2). The expired OLD lot is untouched.
        $this->assertSame(0, StockLot::where('batch', 'SOON')->value('qty_on_hand'));
        $this->assertSame(48, StockLot::where('batch', 'LATE')->value('qty_on_hand'));
        $this->assertSame(6, StockLot::where('batch', 'OLD')->value('qty_on_hand'));
    }

    public function test_momo_transaction_id_cannot_be_used_twice(): void
    {
        $body = fn () => ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 1]],
            'tenders' => ['momo' => ['amount_pesewas' => 11500, 'network' => 'MTN', 'txn_id' => 'DUPL1CATE', 'payer' => '0244123456']]];
        $this->sale($this->cashier, $body())->assertCreated();
        $this->sale($this->cashier, $body())->assertStatus(422)->assertJsonPath('error.code', 'momo_txn_reused');
    }

    public function test_retried_request_with_same_idempotency_key_creates_one_sale(): void
    {
        $h = $this->idem();
        $body = ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 1]], 'tenders' => ['cash_pesewas' => 20000]];
        $first = $this->sale($this->cashier, $body, $h)->assertCreated();
        $this->sale($this->cashier, $body, $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('number', $first->json('number'));
        $this->assertSame(1, Order::count());
        $this->assertSame(8500, $first->json('change_given_pesewas'));
    }

    public function test_expired_stock_is_never_sold(): void
    {
        $this->sale($this->cashier, ['lines' => [['variant_id' => $this->wipes->id, 'unit' => 'pack', 'qty' => 61]], 'tenders' => ['cash_pesewas' => 200000]])
            ->assertStatus(422)->assertJsonPath('error.code', 'insufficient_stock');
    }

    public function test_server_decides_the_price_and_tiers(): void
    {
        $r = $this->sale($this->cashier, ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'carton', 'qty' => 1], ['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 10]],
            'tenders' => ['cash_pesewas' => 200000], 'unit_price' => 1])->assertCreated();
        $this->assertSame(42000 + 10 * 10800, $r->json('total_pesewas'));
    }

    public function test_discount_above_cashier_limit_needs_a_manager_pin(): void
    {
        $body = ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 1]], 'discount' => ['type' => 'pct', 'value' => 10], 'tenders' => ['cash_pesewas' => 11500]];
        $this->sale($this->cashier, $body)->assertStatus(403)->assertJsonPath('error.code', 'approval_required');
        $this->sale($this->cashier, $body + ['approval' => ['user_id' => $this->manager->id, 'pin' => '9999']])->assertStatus(403);
        $this->sale($this->cashier, $body + ['approval' => ['user_id' => $this->manager->id, 'pin' => '2222']])->assertCreated()->assertJsonPath('discount_pesewas', 1150);
    }

    public function test_wholesale_credit_limit_and_owner_override(): void
    {
        $c = Customer::create(['name' => 'Nkoranza Baby Shop', 'phone_e164' => '+233245556677', 'type' => 'wholesale', 'credit_limit_pesewas' => 50000, 'credit_terms_days' => 14]);
        $body = ['customer_id' => $c->id, 'lines' => [['variant_id' => $this->diapers->id, 'unit' => 'carton', 'qty' => 2]], 'tenders' => ['credit_pesewas' => 84000]];
        $this->sale($this->cashier, $body)->assertStatus(403)->assertJsonPath('error.approver_roles', ['owner']);
        $this->sale($this->cashier, $body + ['approval' => ['user_id' => $this->owner->id, 'pin' => '1234']])->assertCreated();
        $this->assertSame(84000, $c->refresh()->balancePesewas());

        // Payment applies to the oldest invoice.
        $this->actingAs($this->manager)->postJson("/api/v1/customers/{$c->id}/payments", ['amount_pesewas' => 30000, 'method' => 'cash'], $this->idem())->assertCreated();
        $this->assertSame(54000, $c->refresh()->balancePesewas());
    }

    public function test_credit_is_refused_for_retail_customers(): void
    {
        $c = Customer::create(['name' => 'Akosua', 'phone_e164' => '+233244123456', 'type' => 'retail']);
        $this->sale($this->cashier, ['customer_id' => $c->id, 'lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 1]], 'tenders' => ['credit_pesewas' => 11500]])
            ->assertStatus(422)->assertJsonPath('error.code', 'credit_not_allowed');
    }

    public function test_customer_registration_requires_both_consent_answers_and_blocks_duplicates(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/v1/customers', ['name' => 'Adwoa', 'phone' => '055 999 8877', 'type' => 'retail', 'consent' => ['whatsapp' => 'opted_in']], $this->idem())
            ->assertStatus(422);
        $this->actingAs($this->cashier)->postJson('/api/v1/customers', ['name' => 'Adwoa', 'phone' => '055 999 8877', 'type' => 'retail', 'consent' => ['whatsapp' => 'opted_in', 'sms' => 'withdrawn']], $this->idem())
            ->assertCreated()->assertJsonPath('phone_e164', '+233559998877')->assertJsonPath('network', 'MTN');
        $c = Customer::first();
        $this->assertTrue($c->canBeMarketedOn('whatsapp'));
        $this->assertFalse($c->canBeMarketedOn('sms'));
        $this->assertDatabaseHas('outbox_events', ['event_key' => "welcome:{$c->id}:whatsapp"]);
        $this->actingAs($this->cashier)->postJson('/api/v1/customers', ['name' => 'Someone', 'phone' => '+233559998877', 'type' => 'retail', 'consent' => ['whatsapp' => 'opted_in', 'sms' => 'opted_in']], $this->idem())
            ->assertStatus(422)->assertJsonPath('error.code', 'duplicate_customer');
    }

    public function test_opt_out_takes_effect_immediately(): void
    {
        $c = Customer::create(['name' => 'Yaw', 'phone_e164' => '+233201987654']);
        $this->actingAs($this->cashier)->postJson("/api/v1/customers/{$c->id}/consents", ['channel' => 'whatsapp', 'status' => 'opted_in'], $this->idem())->assertCreated();
        $this->assertTrue($c->canBeMarketedOn('whatsapp'));
        $this->actingAs($this->cashier)->postJson("/api/v1/customers/{$c->id}/consents", ['channel' => 'whatsapp', 'status' => 'withdrawn'], $this->idem())->assertCreated();
        $this->assertFalse($c->refresh()->canBeMarketedOn('whatsapp'));
    }

    public function test_only_permitted_staff_verify_momo_and_cashiers_cannot_set_credit_limits(): void
    {
        $this->sale($this->cashier, ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 1]],
            'tenders' => ['momo' => ['amount_pesewas' => 11500, 'network' => 'Telecel', 'txn_id' => 'TEL123456', 'payer' => '0201234567']]])->assertCreated();
        $p = Payment::where('method', 'momo_manual')->first();
        $this->actingAs($this->cashier)->postJson("/api/v1/payments/{$p->id}/verify", ['found' => true, 'checked_statement' => true], $this->idem())->assertForbidden();
        $this->actingAs($this->manager)->postJson("/api/v1/payments/{$p->id}/verify", ['found' => true, 'checked_statement' => true], $this->idem())->assertOk()->assertJsonPath('status', 'verified');

        $c = Customer::create(['name' => 'Shop', 'phone_e164' => '+233268889900', 'type' => 'wholesale']);
        $this->actingAs($this->cashier)->patchJson("/api/v1/customers/{$c->id}/credit", ['credit_limit_pesewas' => 9999999, 'credit_terms_days' => 30])->assertForbidden();
        $this->actingAs($this->manager)->patchJson("/api/v1/customers/{$c->id}/credit", ['credit_limit_pesewas' => 9999999, 'credit_terms_days' => 30])->assertForbidden();
        $this->actingAs($this->owner)->patchJson("/api/v1/customers/{$c->id}/credit", ['credit_limit_pesewas' => 200000, 'credit_terms_days' => 14])->assertOk();
    }

    public function test_shift_close_requires_reason_for_variance(): void
    {
        $this->sale($this->cashier, ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 1]], 'tenders' => ['cash_pesewas' => 11500]])->assertCreated();
        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/close', ['counted_pesewas' => 30000], $this->idem())->assertStatus(422)->assertJsonPath('error.code', 'variance_reason_required');
        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/close', ['counted_pesewas' => 31500], $this->idem())->assertOk()->assertJsonPath('variance_pesewas', 0);
    }

    public function test_audit_chain_detects_tampering(): void
    {
        $this->sale($this->cashier, ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 1]], 'tenders' => ['cash_pesewas' => 11500]])->assertCreated();
        $this->assertTrue(AuditLogger::verify()['ok']);
        if (DB::getDriverName() === 'pgsql') {
            $this->expectException(\Illuminate\Database\QueryException::class); // append-only trigger refuses the edit
        }
        DB::table('audit_logs')->where('action', 'sale')->update(['detail' => 'edited']);
        $this->assertFalse(AuditLogger::verify()['ok']);
    }

    public function test_offline_sync_is_idempotent_and_rejects_edited_events(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($key)['key'];
        $device = $this->actingAs($this->owner)->postJson('/api/v1/devices', ['name' => 'Counter tablet', 'public_key_pem' => $pem])->assertCreated()->json();

        $event = ['client_uuid' => (string) Str::uuid(), 'type' => 'sale', 'client_created_at' => now()->toIso8601String(),
            'payload' => ['lines' => [['variant_id' => $this->diapers->id, 'unit' => 'pack', 'qty' => 50]], 'tenders' => ['cash_pesewas' => 575000]]];
        $event['signature'] = $this->sign($key, $event);

        $res = $this->actingAs($this->cashier)->postJson('/api/v1/sync/events', ['device_id' => $device['id'], 'events' => [$event]])->assertOk();
        // 50 packs sold offline but only 40 on hand: accepted as a real sale, flagged for a stock count.
        $this->assertSame('flagged', $res->json('results.0.status'));
        $this->assertStringStartsWith('RCPT-D1-', Order::first()->number);

        $this->actingAs($this->cashier)->postJson('/api/v1/sync/events', ['device_id' => $device['id'], 'events' => [$event]])->assertOk();
        $this->assertSame(1, Order::count(), 're-sending the batch must not duplicate the sale');

        $tampered = ['client_uuid' => (string) Str::uuid()] + $event;
        $tampered['signature'] = $this->sign($key, $tampered);
        $tampered['payload']['tenders']['cash_pesewas'] = 1; // edited after signing
        $this->actingAs($this->cashier)->postJson('/api/v1/sync/events', ['device_id' => $device['id'], 'events' => [$tampered]])
            ->assertOk()->assertJsonPath('results.0.status', 'rejected');
    }

    private function sign($key, array $event): string
    {
        openssl_sign(\App\Support\DeviceSignature::canonical($event), $der, $key, OPENSSL_ALGO_SHA256);
        // DER → raw r||s (what WebCrypto produces on the device)
        $pos = 3;
        $rLen = ord($der[$pos]);
        $r = substr($der, $pos + 1, $rLen);
        $pos += 1 + $rLen + 1;
        $sLen = ord($der[$pos]);
        $s = substr($der, $pos + 1, $sLen);
        $pad = fn ($x) => str_pad(ltrim($x, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return base64_encode($pad($r).$pad($s));
    }

    public function test_public_storefront_never_shows_costs_or_exact_stock(): void
    {
        $r = $this->getJson('/api/v1/public/products')->assertOk()->json();
        $this->assertArrayNotHasKey('cost_pesewas', $r[0]);
        $this->assertArrayNotHasKey('available_packs', $r[0]);
        $this->assertContains($r[0]['availability'], ['in_stock', 'low', 'out']);
    }

    public function test_security_headers_are_set(): void
    {
        $this->getJson('/api/v1/public/business')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
