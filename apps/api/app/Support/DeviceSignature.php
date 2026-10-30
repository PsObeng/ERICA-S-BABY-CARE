<?php

namespace App\Support;

/**
 * Verifies offline events signed on the shop device with a non-extractable WebCrypto key
 * (ECDSA P-256 / SHA-256) — SEC-INT-052. WebCrypto produces raw r||s (64 bytes); OpenSSL expects DER.
 */
final class DeviceSignature
{
    public static function verify(string $publicKeyPem, string $message, string $signatureBase64): bool
    {
        $raw = base64_decode($signatureBase64, true);
        if ($raw === false || strlen($raw) !== 64) {
            return false;
        }
        $der = self::rawToDer($raw);

        return openssl_verify($message, $der, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Canonical message a device signs: stable key order, no whitespace. */
    public static function canonical(array $event): string
    {
        $payload = $event['payload'];
        self::ksortRecursive($payload);

        return implode('|', [$event['client_uuid'], $event['type'], $event['client_created_at'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }

    private static function rawToDer(string $raw): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || ord($b[0]) & 0x80) {
                $b = "\x00".$b;
            }

            return "\x02".chr(strlen($b)).$b;
        };
        $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".chr(strlen($seq)).$seq;
    }

    private static function ksortRecursive(array &$a): void
    {
        if (array_is_list($a)) {
            foreach ($a as &$v) {
                if (is_array($v)) {
                    self::ksortRecursive($v);
                }
            }

            return;
        }
        ksort($a);
        foreach ($a as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
    }
}
