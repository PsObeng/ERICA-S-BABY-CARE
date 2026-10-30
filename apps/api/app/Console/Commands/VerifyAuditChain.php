<?php

namespace App\Console\Commands;

use App\Support\AuditLogger;
use Illuminate\Console\Command;

/** Scheduled daily: verify the hash-chained audit log and alert on a break (SEC-INT-060/070). */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the tamper-evident audit chain';

    public function handle(): int
    {
        $r = AuditLogger::verify();
        if ($r['ok']) {
            $this->info("Audit chain intact — {$r['checked']} entries.");

            return self::SUCCESS;
        }
        $this->error("Audit chain BROKEN at entry #{$r['broken_at']}. Records were changed or deleted.");
        logger()->critical('audit_chain_broken', $r);

        return self::FAILURE;
    }
}
