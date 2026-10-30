<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Downloads a public image over https into a local file. Every address a host resolves to must be public, the
 * connection is pinned to the address that was checked (so DNS cannot be switched in between), redirects are
 * followed by hand and checked the same way, and the download stops as soon as it passes the size limit.
 */
class RemoteImageDownloader
{
    public const int MAX_REDIRECTS = 3;

    public const int TIMEOUT_SECONDS = 10;

    private const int READ_BYTES = 8192;

    /**
     * Addresses that are not on the public internet: private, loopback, link-local, shared, reserved, documentation,
     * benchmarking, multicast and translation ranges for IPv4 and IPv6.
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
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Downloads the image into $targetPath and returns the file name from the last address's path.
     *
     * @throws RemoteImageException
     */
    public function download(string $url, string $targetPath, int $maxBytes): string
    {
        $uri = $this->checkedUri($url);

        for ($redirects = 0; ; $redirects++) {
            $response = $this->request($uri);

            if ($response->redirect()) {
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new RemoteImageException(sprintf('source_url redirects more than %d times, so nothing was downloaded.', self::MAX_REDIRECTS));
                }

                $location = $response->header('Location');

                if ($location === '') {
                    throw new RemoteImageException(sprintf('%s answered with a redirect without a Location, so nothing was downloaded.', $uri->getHost()));
                }

                $uri = $this->checkedUri((string) UriResolver::resolve($uri, new Uri($location)));

                continue;
            }

            if (! $response->successful()) {
                throw new RemoteImageException(sprintf('%s answered HTTP %d for source_url, so nothing was downloaded.', $uri->getHost(), $response->status()));
            }

            $this->store($response->toPsrResponse()->getBody(), $targetPath, $maxBytes, (int) $response->header('Content-Length'));

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
    private function request(UriInterface $uri): Response
    {
        $host = trim($uri->getHost(), '[]');
        $address = $this->publicAddress($host);
        $pinned = str_contains($address, ':') ? sprintf('[%s]', $address) : $address;

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'curl' => [
                    CURLOPT_RESOLVE => [sprintf('%s:443:%s', $host, $pinned)],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ],
            ])
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->accept('image/*')
                ->get((string) $uri);
        } catch (ConnectionException) {
            throw new RemoteImageException(sprintf('%s could not be reached within %d seconds, so nothing was downloaded.', $host, self::TIMEOUT_SECONDS));
        }

        return $response;
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

    public static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, self::BLOCKED_RANGES);
    }

    /**
     * @throws RemoteImageException
     */
    private function store(StreamInterface $body, string $targetPath, int $maxBytes, int $contentLength): void
    {
        if ($maxBytes > 0 && $contentLength > $maxBytes) {
            throw $this->tooLarge($maxBytes);
        }

        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            throw new RemoteImageException('The image could not be stored temporarily, so nothing was uploaded. Try again later.');
        }

        $written = 0;

        try {
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
}
