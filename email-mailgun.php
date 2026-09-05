<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Plugin;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailMailgun\Provider\MailgunProvider;
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
            'onEmailProviders'     => ['onEmailProviders', 0],
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

    public function onEmailEngines(Event $e): void
    {
        $engines = $e['engines'];
        $engines->mailgun = 'Mailgun';
    }

    /**
     * Everything Mailgun knows about itself, handed to whoever asked.
     *
     * The Email plugin fires this the first time anything on the site asks
     * about a provider. Older releases of it have no provider contract at all,
     * in which case this never fires — and the guard below is for the odd case
     * of something else firing an event of the same name, because a class whose
     * `implements` names an interface that is not installed is a fatal error
     * the moment it is autoloaded.
     */
    public function onEmailProviders(Event $e): void
    {
        if (!interface_exists(Provider::class)) {
            return;
        }

        $registry = $e['providers'] ?? null;
        if (!$registry instanceof ProviderRegistry) {
            return;
        }

        $registry->add(new MailgunProvider(
            (array)$this->config->get('plugins.email-mailgun', []),
            null,
            fn (string $key): bool => $this->rememberSigningKey($key),
        ));
    }

    public function onEmailTransportDsn(Event $e): void
    {
        $engine = $e['engine'];
        if ($engine === 'mailgun') {
            $options = (array)$this->config->get('plugins.email-mailgun');
            $transport = (string)($options['transport'] ?? 'api');

            switch ($transport) {
                case 'smtp':
                    $username = (string)($options['username'] ?? '');
                    $password = (string)($options['password'] ?? '');
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
                    $apiKey = (string)($options['api_key'] ?? '');
                    $domain = (string)($options['domain'] ?? '');
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
                $dsn .= (str_contains($dsn, '?') ? '&' : '?') . 'region=' . urlencode((string)$options['region']);
            }

            $e['dsn'] = $dsn;
            $e->stopPropagation();
        }
    }

    /**
     * Write the webhook signing key the setup button read back from Mailgun
     * into this plugin's own config.
     *
     * The key is a credential for verifying Mailgun's webhooks and it belongs
     * here, beside the sending credentials, rather than in whichever add-on
     * happens to be receiving the events. Saving it is the difference between
     * a merchant pressing one button and a merchant hunting through a profile
     * menu for a string that looks exactly like the API key they already
     * pasted and is not.
     *
     * Answers false rather than throwing when the file cannot be written, so a
     * read-only config directory costs the merchant one sentence about pasting
     * the key by hand rather than a failed setup.
     */
    protected function rememberSigningKey(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }

        try {
            $path = $this->grav['locator']->findResource('config://plugins/email-mailgun.yaml', true, true);
            if (!is_string($path)) {
                return false;
            }

            $file = CompiledYamlFile::instance($path);
            $content = (array)$file->content();
            $content['signing_key'] = $key;
            $file->save($content);
            $file->free();

            $this->config->set('plugins.email-mailgun.signing_key', $key);

            return true;
        } catch (\Throwable $e) {
            $this->grav['log']->warning('email-mailgun: the webhook signing key could not be saved: '
                . $e->getMessage());

            return false;
        }
    }
}
