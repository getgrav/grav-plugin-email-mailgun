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
            $dsn = "mailgun+{$transport}://";
            if ($options['transport'] === 'smtp') {
                $dsn .= urlencode($options['username'] ?? '') .":".urlencode($options['password'] ?? '');
            } else {
                $dsn .= urlencode($options['api_key'] ?? '') .":".urlencode($options['domain'] ?? '');
            }
            $dsn .= "@default";
            $e['dsn'] = $dsn;
            $e->stopPropagation();
        }
    }
}
