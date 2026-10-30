<?php

use Illuminate\Support\Facades\Schedule;

// SEC-INT-041 / SEC-INT-060: hourly code integrity, daily audit-chain verification.
Schedule::command('integrity:verify')->hourly();
Schedule::command('audit:verify')->dailyAt('23:30')->timezone('Africa/Accra');
// Housekeeping: expired idempotency records.
Schedule::call(fn () => \Illuminate\Support\Facades\DB::table('idempotency_records')->where('expires_at', '<', now())->delete())->daily();
