<?php

declare(strict_types=1);

/**
 * The plugin ships its own vendor directory, and that directory holds the
 * Symfony Mailgun bridge and nothing else — Symfony Mailer itself comes from
 * Grav at runtime, which is why the plugin's composer.json replaces it rather
 * than requiring it.
 *
 * Installing PHPUnit into that same directory would put development packages
 * into the released plugin, so the suite keeps its own composer.json and its
 * own vendor directory here under tests/ instead. Run `composer install -d
 * tests` once, then `phpunit` from the repository root.
 *
 * Nothing here boots Grav. These are unit tests of the plugin's own classes.
 */
$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "The test dependencies are not installed. Run: composer install -d tests\n");
    exit(1);
}

require $autoload;

/**
 * The provider contract lives in the Email plugin, so the suite has to be able
 * to find that plugin's classes.
 *
 * `EMAIL_PLUGIN_ROOT` says where; without it, two ordinary layouts are tried —
 * the plugin sitting beside this one, which is how a Grav site has them, and
 * the plugin sitting beside the directory holding this one, which is how a git
 * worktree under a `_wt` directory has them.
 */
$emailRoot = getenv('EMAIL_PLUGIN_ROOT');

$candidates = $emailRoot === false || trim($emailRoot) === ''
    ? [\dirname(__DIR__, 2) . '/grav-plugin-email', \dirname(__DIR__, 3) . '/grav-plugin-email']
    : [rtrim(trim($emailRoot), '/')];

$email = null;
foreach ($candidates as $candidate) {
    if (is_dir($candidate . '/classes/Providers')) {
        $email = $candidate;
        break;
    }
}

if ($email === null) {
    fwrite(STDERR, sprintf(
        "The Email plugin's provider contract could not be found. Looked in:\n  %s\n"
        . "Set EMAIL_PLUGIN_ROOT to the grav-plugin-email checkout.\n",
        implode("\n  ", $candidates)
    ));
    exit(1);
}

spl_autoload_register(static function (string $class) use ($email): void {
    if (!str_starts_with($class, 'Grav\\Plugin\\Email\\')) {
        return;
    }

    $path = $email . '/classes/' . str_replace('\\', '/', substr($class, \strlen('Grav\\Plugin\\Email\\'))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
