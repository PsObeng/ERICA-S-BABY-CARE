<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * SEC-INT-041: hash every deployed code file and compare with the signed release manifest
 * (release-manifest.json, produced and signed in CI). Any mismatch → alert + read-only safe mode.
 * Runs at boot (deploy hook) and hourly (scheduler).
 */
class VerifyReleaseIntegrity extends Command
{
    protected $signature = 'integrity:verify {--manifest=release-manifest.json}';

    protected $description = 'Compare deployed files with the signed release manifest';

    public function handle(): int
    {
        $path = base_path($this->option('manifest'));
        if (! is_file($path)) {
            $this->warn('No release manifest found (normal in local development).');

            return self::SUCCESS;
        }
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $sigPath = $path.'.sig';
        $pubKey = (string) config('integrity.release_public_key');
        if ($pubKey === '' || ! is_file($sigPath) || openssl_verify((string) file_get_contents($path), (string) file_get_contents($sigPath), $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->tampered(['manifest signature invalid']);
        }
        $problems = [];
        foreach ($manifest['files'] as $file => $hash) {
            $full = base_path($file);
            if (! is_file($full)) {
                $problems[] = "missing: {$file}";
            } elseif (hash_file('sha256', $full) !== $hash) {
                $problems[] = "changed: {$file}";
            }
        }
        foreach (['app', 'bootstrap', 'config', 'routes', 'database', 'public'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = ltrim(str_replace(base_path(), '', $f->getPathname()), '/');
                if (str_ends_with($rel, '.php') && ! isset($manifest['files'][$rel]) && ! str_starts_with($rel, 'bootstrap/cache/')) {
                    $problems[] = "unexpected: {$rel}";
                }
            }
        }
        if ($problems) {
            return $this->tampered($problems);
        }
        Cache::forget('integrity_safe_mode');
        $this->info('Release '.$manifest['version'].' verified: '.count($manifest['files']).' files match.');

        return self::SUCCESS;
    }

    private function tampered(array $problems): int
    {
        Cache::forever('integrity_safe_mode', true); // EnforceSafeMode middleware blocks all writes
        logger()->critical('release_integrity_failed', ['problems' => array_slice($problems, 0, 50)]);
        $this->error('INTEGRITY FAILURE — app is now read-only: '.implode('; ', array_slice($problems, 0, 10)));

        return self::FAILURE;
    }
}
