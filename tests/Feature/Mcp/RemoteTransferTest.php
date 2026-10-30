<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use J87NL\Cms\Media\Remote\DownloadLimit;
use J87NL\Cms\Media\Remote\LimitedWriteFilter;
use J87NL\Cms\Media\Remote\TransferOptions;

/**
 * Starts php -S on a free loopback port with the slow stream fixture; null when that is not possible here.
 *
 * @return array{0: resource, 1: int}|null
 */
function startSlowStreamServer(): ?array
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0');

    if ($socket === false) {
        return null;
    }

    $port = (int) parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    $process = proc_open([PHP_BINARY, '-S', sprintf('127.0.0.1:%d', $port), dirname(__DIR__, 2).'/fixtures/slow-stream.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);

    if (! is_resource($process)) {
        return null;
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if ($probe !== false) {
            fclose($probe);

            return [$process, $port];
        }

        usleep(50_000);
    }

    proc_terminate($process);

    return null;
}

it('stops a real transfer promptly at the size limit and keeps the file within it', function (): void {
    $server = startSlowStreamServer();

    if (is_null($server)) {
        $this->markTestSkipped('No loopback test server could be started.');
    }

    [$process, $port] = $server;
    LimitedWriteFilter::register();
    $limit = new DownloadLimit(100 * 1024);
    $path = (string) tempnam(sys_get_temp_dir(), 'transfer');
    $sink = fopen($path, 'w+b');
    stream_filter_append($sink, LimitedWriteFilter::NAME, STREAM_FILTER_WRITE, $limit);
    $stopped = false;
    $started = microtime(true);

    $options = TransferOptions::build($sink, [], $limit->maxBytes, function () use (&$stopped): void {
        $stopped = true;
    }) + ['timeout' => 10];

    try {
        TransferOptions::send(fn () => (new Client(['handler' => HandlerStack::create(new CurlHandler)]))->get(sprintf('http://127.0.0.1:%d/', $port), $options));
        $failure = null;
    } catch (Throwable $exception) {
        $failure = $exception;
    } finally {
        fclose($sink);
        proc_terminate($process);
    }

    expect($failure)->not->toBeNull()
        ->and($stopped)->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(filesize($path))->toBeLessThanOrEqual($limit->maxBytes);

    unlink($path);
});

it('swallows only the two Guzzle 7 progress deprecations while a request runs', function (): void {
    $seen = [];
    set_error_handler(function (int $level, string $message) use (&$seen): bool {
        $seen[] = $message;

        throw new ErrorException($message, 0, $level);
    });

    try {
        $result = TransferOptions::send(function (): string {
            @trigger_error('Passing CURLOPT_NOPROGRESS (43) in the "curl" request option is deprecated', E_USER_DEPRECATED);
            @trigger_error('Passing CURLOPT_PROGRESSFUNCTION (20056) in the "curl" request option is deprecated', E_USER_DEPRECATED);

            return 'sent';
        }, 7);

        expect($result)->toBe('sent')
            ->and($seen)->toBe([])
            ->and(fn () => TransferOptions::send(fn () => trigger_error('Something else is deprecated', E_USER_DEPRECATED), 7))
            ->toThrow(ErrorException::class, 'Something else is deprecated');
    } finally {
        restore_error_handler();
    }
});
