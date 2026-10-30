<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings, devices, audit chain, idempotency, outbox, sync and document numbering (B13, B14, B16A).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->json('value');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('devices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 80);
            $t->string('number_prefix', 8)->unique();       // offline receipt numbers, e.g. D2 (DOC-004)
            $t->text('public_key');                          // verifies signed offline events (SEC-INT-052)
            $t->foreignId('registered_by')->constrained('users');
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id('seq');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->string('action', 64);
            $t->text('detail');
            $t->string('subject_type', 64)->nullable();
            $t->string('subject_id', 40)->nullable();
            $t->string('request_id', 64)->nullable();
            $t->char('prev_hash', 64)->nullable();
            $t->char('hash', 64);
            $t->timestamp('created_at', 6);
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('idempotency_records', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 64);
            $t->string('key', 100);
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->json('response')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['scope', 'key', 'user_id']);
        });

        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 120)->unique();
            $t->string('type', 64);
            $t->json('payload');
            $t->string('status', 16)->default('pending'); // pending | published | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('available_at');
            $t->timestamps();
            $t->index(['status', 'available_at']);
        });

        Schema::create('sync_events', function (Blueprint $t) {
            $t->uuid('client_uuid')->primary();              // idempotency key from the device (OFF-004)
            $t->foreignUlid('device_id')->constrained('devices');
            $t->foreignId('user_id')->constrained('users');
            $t->string('type', 40);                          // sale | customer | consent | shift_open | shift_close | return
            $t->json('payload');
            $t->text('signature');
            $t->timestamp('client_created_at', 6);
            $t->string('snapshot_version', 40)->nullable();
            $t->string('status', 16);                        // accepted | flagged | rejected
            $t->text('result')->nullable();
            $t->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('type', 8);
            $t->unsignedSmallInteger('year');
            $t->unsignedBigInteger('next_value')->default(1);
            $t->primary(['type', 'year']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Append-only: no UPDATE or DELETE on the audit chain (SEC-INT-061).
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION dw360_forbid_change() RETURNS trigger AS $$
                BEGIN
                  RAISE EXCEPTION 'Table % is append-only; write a reversing row instead', TG_TABLE_NAME;
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs
                  FOR EACH ROW EXECUTE FUNCTION dw360_forbid_change();
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['document_sequences', 'sync_events', 'outbox_events', 'idempotency_records', 'audit_logs', 'devices', 'business_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS dw360_forbid_change() CASCADE');
        }
    }
};
