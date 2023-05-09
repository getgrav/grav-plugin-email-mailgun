<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit193891c70a1b04144dd917450da786be
{
    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Symfony\\Component\\Mailer\\Bridge\\Mailgun\\' => 40,
        ),
        'G' => 
        array (
            'Grav\\Plugin\\EmailMailgun\\' => 25,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Symfony\\Component\\Mailer\\Bridge\\Mailgun\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/mailgun-mailer',
        ),
        'Grav\\Plugin\\EmailMailgun\\' => 
        array (
            0 => __DIR__ . '/../..' . '/classes',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'Grav\\Plugin\\EmailMailgunPlugin' => __DIR__ . '/../..' . '/email-mailgun.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit193891c70a1b04144dd917450da786be::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit193891c70a1b04144dd917450da786be::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit193891c70a1b04144dd917450da786be::$classMap;

        }, null, ClassLoader::class);
    }
}