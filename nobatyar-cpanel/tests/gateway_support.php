<?php
declare(strict_types=1);

/**
 * Test-only helpers (CLI). Never loaded by the application.
 * - test_gateway(): stores credentials and marks them verified, as if the admin's real test payment succeeded.
 * - zp(): wraps a closure fn(string $op, array $payload): array (Zarinpal JSON) into the transport signature.
 * - test_return(): runs the bank callback for the transaction that owns a given gateway token.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function test_gateway(string $name, array $config, bool $verified = true): void
{
    gateway_save($name, $config);
    if ($verified) {
        query("UPDATE payment_gateways SET enabled=1,verified_at=NOW(),verified_ref='TEST',verified_config_hash=config_hash WHERE name=?", [$name]);
    }
    setting_set('payment_default_gateway', $name);
}

function test_gateway_off(): void
{
    query('UPDATE payment_gateways SET enabled=0,verified_at=NULL,verified_config_hash=NULL');
    setting_set('payment_default_gateway', '');
    setting_set('payment_fallback_gateway', '');
}

function zp(callable $fn): callable
{
    return function (string $operation, string $url, $body) use ($fn) {
        $op = substr($operation, strpos($operation, '.') + 1);
        return ['http' => 200, 'body' => json_encode($fn($op, json_decode((string) $body, true)))];
    };
}

function test_return(string $token, array $input, ?callable $transport): array
{
    $id = (int) query('SELECT id FROM payment_transactions WHERE gateway_token=? ORDER BY id DESC LIMIT 1', [$token])->fetchColumn();
    return payment_return($id, $input, $transport);
}
