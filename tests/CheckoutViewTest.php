<?php

declare(strict_types=1);

use Lunixi\WHMCS\CheckoutView;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\CheckoutSession;
use PHPUnit\Framework\TestCase;

final class CheckoutViewTest extends TestCase
{
    public function testActualViewAndBrowserAssetPassAnObjectAndContainUntrustedText(): void
    {
        $session = new CheckoutSession('pi-1', '</script><script>unexpected()</script>');
        $html = CheckoutView::render($session, 'tr', ['pay' => '<img onerror="unexpected()">', 'error' => 'Failed', 'invoice' => 'Invoice'], 'https://shop.example/viewinvoice.php?id=31');
        self::assertStringNotContainsString('<img onerror=', $html);
        self::assertStringNotContainsString('<script>unexpected()', $html);
        $process = proc_open(['node', __DIR__ . '/browser-config.cjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $html); fclose($pipes[0]);
        self::assertSame('embedded checkout mounted', trim(stream_get_contents($pipes[1])));
        self::assertSame('', stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
    }
}
