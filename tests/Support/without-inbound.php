<?php

declare(strict_types=1);

/**
 * Loads this plugin against an Email plugin that has the provider contract but
 * not the inbound one (5.0.9 to 5.2.x), by refusing to autoload anything under
 * `Providers\Inbound\`. Run in its own process by ProviderWithoutInboundTest,
 * because a class that failed to load cannot be unloaded again.
 *
 *     php without-inbound.php /path/to/grav-plugin-email/classes/Providers
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$contract = $argv[1] ?? '';

spl_autoload_register(static function (string $class) use ($contract): void {
    $prefix = 'Grav\\Plugin\\Email\\Providers\\';
    if (!str_starts_with($class, $prefix) || str_starts_with($class, $prefix . 'Inbound\\')) {
        return;
    }
    $file = $contract . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$provider = new \Grav\Plugin\EmailMailgun\Provider\MailgunProvider(['api_key' => 'x', 'signing_key' => 'y']);

// Works, not merely loads: the methods an Email 5.0.9 to 5.2 site calls.
$provider->capabilities();
$provider->reports();
$provider->domain();
$provider->setup();

echo get_class($provider), '|', $provider->key(), '|',
    $provider instanceof \Grav\Plugin\Email\Providers\Provider ? 'provider' : 'not-a-provider', '|',
    interface_exists(\Grav\Plugin\Email\Providers\Inbound\InboundCapable::class) ? 'inbound' : 'no-inbound';
