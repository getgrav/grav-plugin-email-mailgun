<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\EmailMailgun\Provider\MailgunProvider;
use PHPUnit\Framework\TestCase;

/**
 * A site whose Email plugin predates the inbound contract still gets the
 * provider it always had.
 *
 * `InboundCapable` only exists from Email 5.3, and PHP refuses to load a class
 * whose interface is missing, so the interface lives on an empty subclass the
 * plugin registers only when the interface is there. Checked in a separate
 * process with the inbound classes hidden, because once PHP has loaded (or
 * failed to load) a class in this one, it cannot be taken back. Putting
 * `InboundCapable` back on `MailgunProvider` makes this fail.
 */
final class ProviderWithoutInboundTest extends TestCase
{
    public function testTheProviderLoadsAndWorksWithoutTheInboundContract(): void
    {
        $contract = \dirname((string)(new \ReflectionClass(Provider::class))->getFileName());
        $script = \dirname(__DIR__, 2) . '/Support/without-inbound.php';

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($contract) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame(MailgunProvider::class . '|mailgun|provider|no-inbound', implode("\n", $output));
    }
}
