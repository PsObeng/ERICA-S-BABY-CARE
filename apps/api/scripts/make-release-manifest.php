<?php

// Usage: php scripts/make-release-manifest.php <version>  > release-manifest.json
// Hashes every deployed PHP file (app, bootstrap, config, routes, database, public) — SEC-INT-033.

$root = dirname(__DIR__);
$files = [];
foreach (['app', 'bootstrap', 'config', 'routes', 'database', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = ltrim(substr($f->getPathname(), strlen($root)), '/');
        if (str_ends_with($rel, '.php') && ! str_starts_with($rel, 'bootstrap/cache/')) {
            $files[$rel] = hash_file('sha256', $f->getPathname());
        }
    }
}
ksort($files);
echo json_encode(['version' => $argv[1] ?? 'dev', 'built_at' => gmdate('c'), 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
