<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Downloads a public image over https into a local file. Every address a host resolves to must be public, the
 * connection is pinned to the address that was checked (so DNS cannot be switched in between), redirects are
 * followed by hand and checked the same way, and the download stops as soon as it passes the size limit or the time
 * limit.
 *
 * The request always goes through Guzzle's curl handler, without a proxy: the stream handler and a proxy would both
 * resolve the host name again, which would undo the pinning.
 */
class RemoteImageDownloader
{
    public const int MAX_REDIRECTS = 3;

    public const int TIMEOUT_SECONDS = 10;

    private const int READ_BYTES = 8192;

    /**
     * IPv6 is only allowed in the global unicast range; IPv4 everywhere outside BLOCKED_RANGES.
     *
     * @var list<string>
     */
    private const array GLOBAL_IPV6 = [
        '2000::/3',
    ];

    /**
     * Addresses that are not on the public internet: private, loopback, link-local, shared, reserved, documentation,
     * benchmarking, multicast and translation ranges.
     *
     * @var list<string>
     */
    private const array BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Downloads the image into $targetPath and returns the file name from the last address's path.
     *
     * @throws RemoteImageException
     */
    public function download(string $url, string $targetPath, int $maxBytes): string
    {
        try {
            return $this->follow($this->checkedUri($url), $targetPath, $maxBytes, microtime(true) + self::TIMEOUT_SECONDS);
        } catch (RemoteImageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new RemoteImageException('The download from source_url failed, so nothing was uploaded. Check the address, or send the file as content_base64.');
        }
    }

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && ! IpUtils::checkIp($address, self::GLOBAL_IPV6)) {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, self::BLOCKED_RANGES);
    }

    /**
     * @throws RemoteImageException
     */
    private function follow(UriInterface $uri, string $targetPath, int $maxBytes, float $deadline): string
    {
        for ($redirects = 0; ; $redirects++) {
            $response = $this->request($uri, $maxBytes, $deadline);

            if ($response->redirect()) {
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new RemoteImageException(sprintf('source_url redirects more than %d times, so nothing was downloaded.', self::MAX_REDIRECTS));
                }

                $location = $response->header('Location');

                if ($location === '') {
                    throw new RemoteImageException(sprintf('%s answered with a redirect without a Location, so nothing was downloaded.', $uri->getHost()));
                }

                try {
                    $next = UriResolver::resolve($uri, new Uri($location));
                } catch (InvalidArgumentException) {
                    throw new RemoteImageException(sprintf('%s redirected to an address that is not valid, so nothing was downloaded.', $uri->getHost()));
                }

                $uri = $this->checkedUri((string) $next);

                continue;
            }

            if (! $response->successful()) {
                throw new RemoteImageException(sprintf('%s answered HTTP %d for source_url, so nothing was downloaded.', $uri->getHost(), $response->status()));
            }

            $this->store($response->toPsrResponse()->getBody(), $targetPath, $maxBytes);

            return basename(rawurldecode($uri->getPath()));
        }
    }

    /**
     * @throws RemoteImageException
     */
    private function checkedUri(string $url): UriInterface
    {
        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException) {
            throw new RemoteImageException('source_url is not a valid address.');
        }

        if (strtolower($uri->getScheme()) !== 'https') {
            throw new RemoteImageException('source_url must be a public https:// address.');
        }

        if ($uri->getUserInfo() !== '') {
            throw new RemoteImageException('source_url may not contain a user name or password.');
        }

        if (! in_array($uri->getPort(), [null, 443], true)) {
            throw new RemoteImageException('source_url must use the standard https port 443.');
        }

        if ($uri->getHost() === '') {
            throw new RemoteImageException('source_url has no host.');
        }

        return $uri->withFragment('');
    }

    /**
     * @throws RemoteImageException
     */
    private function request(UriInterface $uri, int $maxBytes, float $deadline): Response
    {
        $host = trim($uri->getHost(), '[]');
        $address = $this->publicAddress($host);
        $secondsLeft = $deadline - microtime(true);

        if ($secondsLeft <= 0) {
            throw $this->timedOut($host);
        }

        $tooLarge = false;

        try {
            $response = Http::setHandler(new CurlHandler)
                ->withOptions([
                    'allow_redirects' => false,
                    'proxy' => '',
                    'curl' => $this->pinned($host, $address) + [
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_PROGRESSFUNCTION => function (mixed $handle, int $downloadTotal, int $downloaded) use ($maxBytes, &$tooLarge): int {
                            $tooLarge = $maxBytes > 0 && max($downloadTotal, $downloaded) > $maxBytes;

                            return $tooLarge ? 1 : 0;
                        },
                    ],
                ])
                ->connectTimeout((int) max(1, ceil($secondsLeft)))
                ->timeout((int) max(1, ceil($secondsLeft)))
                ->accept('image/*')
                ->get((string) $uri);
        } catch (Throwable $exception) {
            if ($tooLarge) {
                throw $this->tooLarge($maxBytes);
            }

            if ($exception instanceof ConnectionException) {
                throw $this->timedOut($host);
            }

            throw $exception;
        }

        if ($maxBytes > 0 && (int) $response->header('Content-Length') > $maxBytes) {
            throw $this->tooLarge($maxBytes);
        }

        return $response;
    }

    /**
     * Pins the host name to the checked address, so curl does not resolve it again. An address in the URL needs no
     * pinning.
     *
     * @return array<int, list<string>>
     */
    private function pinned(string $host, string $address): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return [
            CURLOPT_RESOLVE => [sprintf('%s:443:%s', $host, str_contains($address, ':') ? sprintf('[%s]', $address) : $address)],
        ];
    }

    /**
     * @throws RemoteImageException
     */
    private function publicAddress(string $host): string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new RemoteImageException(sprintf('The host %s of source_url does not resolve.', $host));
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new RemoteImageException(sprintf('source_url points to %s, which is not a public address, so nothing was downloaded.', $host));
            }
        }

        return $addresses[0];
    }

    /**
     * @throws RemoteImageException
     */
    private function store(StreamInterface $body, string $targetPath, int $maxBytes): void
    {
        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            throw new RemoteImageException('The image could not be stored temporarily, so nothing was uploaded. Try again later.');
        }

        $written = 0;

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (! $body->eof()) {
                $chunk = $body->read(self::READ_BYTES);
                $written += strlen($chunk);

                if ($maxBytes > 0 && $written > $maxBytes) {
                    throw $this->tooLarge($maxBytes);
                }

                fwrite($target, $chunk);
            }
        } finally {
            fclose($target);
            $body->close();
        }
    }

    private function tooLarge(int $maxBytes): RemoteImageException
    {
        return new RemoteImageException(sprintf('The image at source_url is larger than %d kB, so the download was stopped and nothing was uploaded.', intdiv($maxBytes, 1024)));
    }

    private function timedOut(string $host): RemoteImageException
    {
        return new RemoteImageException(sprintf('%s could not be reached or did not answer within %d seconds, so nothing was downloaded.', $host, self::TIMEOUT_SECONDS));
    }
}
