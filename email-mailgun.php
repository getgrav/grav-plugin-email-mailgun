<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class EmailMailgunPlugin
 * @package Grav\Plugin
 */
class EmailMailgunPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines'       => ['onEmailEngines', 0],
            'onEmailTransportDsn'  => ['onEmailTransportDsn', 0],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e)
    {
        $engines = $e['engines'];
        $engines->mailgun = 'Mailgun';
    }

    public function onEmailTransportDsn(Event $e)
    {
        $engine = $e['engine'];
        if ($engine === 'mailgun') {
            $options = $this->config->get('plugins.email-mailgun');
            $transport = $options['transport'] ?? 'api';

            switch ($transport) {
                case 'smtp':
                    $username = $options['username'] ?? '';
                    $password = $options['password'] ?? '';
                    if (empty($username) || empty($password)) {
                        throw new \RuntimeException('Mailgun SMTP transport requires both username and password.');
                    }
                    $dsn = sprintf(
                        'mailgun+smtp://%s:%s@default',
                        urlencode($username),
                        urlencode($password)
                    );
                    break;

                case 'https':
                case 'api':
                default:
                    $apiKey = $options['api_key'] ?? '';
                    $domain = $options['domain'] ?? '';
                    if (empty($apiKey)) {
                        throw new \RuntimeException('Mailgun API/HTTPS transport requires an api_key.');
                    }
                    if (empty($domain)) {
                        throw new \RuntimeException('Mailgun API/HTTPS transport requires a domain.');
                    }
                    $dsn = sprintf(
                        'mailgun+%s://%s:%s@default',
                        $transport,
                        urlencode($apiKey),
                        urlencode($domain)
                    );
                    break;
            }

            if (!empty($options['region'])) {
                $dsn .= (str_contains($dsn, '?') ? '&' : '?') . 'region=' . urlencode($options['region']);
            }

            $e['dsn'] = $dsn;
            $e->stopPropagation();
        }
    }
}
