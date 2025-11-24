<?php return array(
    'root' => array(
        'name' => 'getgrav/email-mailgun',
        'pretty_version' => 'dev-develop',
        'version' => 'dev-develop',
        'reference' => '5bf50b6935f441b3b2326cde92acfb7ea0f1c19e',
        'type' => 'grav-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'getgrav/email-mailgun' => array(
            'pretty_version' => 'dev-develop',
            'version' => 'dev-develop',
            'reference' => '5bf50b6935f441b3b2326cde92acfb7ea0f1c19e',
            'type' => 'grav-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'psr/event-dispatcher' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'symfony/mailer' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'symfony/mailgun-mailer' => array(
            'pretty_version' => 'v5.4.35',
            'version' => '5.4.35.0',
            'reference' => 'fbb1f557f5da0d09bda2fa0fd3d415350f418295',
            'type' => 'symfony-mailer-bridge',
            'install_path' => __DIR__ . '/../symfony/mailgun-mailer',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
