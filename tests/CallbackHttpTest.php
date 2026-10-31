<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Runs the actual built entry point before proprietary WHMCS bootstrap, with no session. */
final class CallbackHttpTest extends TestCase
{
    public function testStatelessPostRedirectDoesNotBootstrapWhmcsOrUseSubmittedFields(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $root = dirname(__DIR__) . '/build/release/modules/gateways/lunixi';
        self::assertFileExists($root . '/return.php');
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            for ($i = 0; $i < 30; ++$i) {
                $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if (is_resource($ready)) { fclose($ready); break; } usleep(100000);
            }
            foreach ([['invoiceid=999&status=CAPTURED&redirect=https://attacker.example', 303], ['', 303], [str_repeat('x', 1048577), 413]] as [$body, $expected]) {
                $curl = curl_init('http://' . $address . '/return.php?invoiceid=31');
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Origin: https://pay.lunixi.com', 'Sec-Fetch-Site: cross-site']]);
                $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
                self::assertSame($expected, $status);
                if ($expected === 303) {
                    self::assertStringContainsString('Location: return.php?invoiceid=31', $response);
                    self::assertStringNotContainsString('Set-Cookie:', $response);
                    self::assertSame('', explode("\r\n\r\n", $response, 2)[1]);
                }
                self::assertStringNotContainsString('999', $response);
            }
        } finally { proc_terminate($process); proc_close($process); }
    }
}
