<?php

return [
    // PEM public key that verifies release-manifest.json.sig (the private key lives only in CI secrets).
    'release_public_key' => env('RELEASE_PUBLIC_KEY', ''),
];
