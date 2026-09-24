<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

use Grav\Plugin\Email\Providers\Inbound\InboundCapable;

/**
 * {@see MailgunProvider}, declaring that it can receive mail.
 *
 * Empty on purpose. `InboundCapable` exists only in Email 5.3 and later, and
 * PHP refuses to load a class whose interface it cannot find, so the
 * declaration lives here rather than on the provider. The plugin registers
 * this class only after `interface_exists()` has said the interface is there;
 * on an older Email plugin it registers {@see MailgunProvider} and this file
 * is never loaded. `inbound()` itself is inherited.
 */
final class MailgunInboundProvider extends MailgunProvider implements InboundCapable
{
}
