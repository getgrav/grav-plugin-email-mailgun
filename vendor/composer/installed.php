<?php return array(
    'root' => array(
        'name' => 'getgrav/email-mailgun',
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'reference' => NULL,
        'type' => 'grav-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'getgrav/email-mailgun' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'reference' => NULL,
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
            'pretty_version' => 'v5.4.7',
            'version' => '5.4.7.0',
            'reference' => 'a261eb5145bd9456a9c68445eac1553c4a75d392',
            'type' => 'symfony-mailer-bridge',
            'install_path' => __DIR__ . '/../symfony/mailgun-mailer',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
