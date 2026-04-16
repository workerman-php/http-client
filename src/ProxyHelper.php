<?php

namespace Workerman\Http;

use InvalidArgumentException;
use Workerman\Connection\AsyncTcpConnection;

class ProxyHelper
{
    public static function setConnectionProxy(AsyncTcpConnection $connection, array $context): void
    {
        $proxy = self::parseProxy($context['http']['proxy'] ?? '');
        if (!$proxy) {
            return;
        }

        if ($proxy['scheme'] === 'socks5') {
            $connection->proxySocks5 = $proxy['connect_endpoint'];
            return;
        }

        if ($proxy['scheme'] === 'http') {
            $connection->proxyHttp = $proxy['connect_endpoint'];
            $connection->proxyAuthorization = $proxy['authorization_header'];
        }
    }

    public static function addressKey(string $address, string $proxyString): string
    {
        if ($proxyString === '') {
            return $address;
        }

        return $address . '#proxy:' . hash('sha256', $proxyString);
    }

    public static function applyProxyToContext(array $context, string $proxyString): array
    {
        if ($proxyString !== '') {
            $context['http']['proxy'] = $proxyString;
        }
        return $context;
    }

    public static function parseProxy(string $proxyString): ?array
    {
        $proxyString = trim($proxyString);
        if ($proxyString === '') {
            return null;
        }

        $proxy = parse_url($proxyString);
        if (!$proxy || empty($proxy['host'])) {
            throw new InvalidArgumentException(
                "Invalid proxy url: $proxyString. Expected formats like http://user:pass@host:port or socks5://host:port"
            );
        }

        $scheme = strtolower($proxy['scheme'] ?? '');
        if ($scheme === 'https') {
            throw new InvalidArgumentException(
                'https:// proxies are not supported. Please use an http:// proxy or socks5:// proxy instead.'
            );
        }

        if (!in_array($scheme, ['http', 'socks5'], true)) {
            throw new InvalidArgumentException("Unsupported proxy scheme: $scheme");
        }

        if ($scheme === 'socks5' && (isset($proxy['user']) || isset($proxy['pass']))) {
            throw new InvalidArgumentException('Authenticated socks5 proxies are not supported by this client.');
        }

        $host = $proxy['host'];
        $port = $proxy['port'] ?? self::defaultPortForScheme($scheme);
        $user = rawurldecode((string)($proxy['user'] ?? ''));
        $pass = rawurldecode((string)($proxy['pass'] ?? ''));

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'connect_endpoint' => self::buildEndpoint(self::resolveHost($host), $port),
            'authorization_header' => ($user !== '' || $pass !== '') ? 'Basic ' . base64_encode($user . ':' . $pass) : '',
        ];
    }

    private static function defaultPortForScheme(string $scheme): int
    {
        return match ($scheme) {
            'http' => 80,
            'socks5' => 1080,
            default => 0,
        };
    }

    private static function resolveHost(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        if (str_contains($host, ':')) {
            return $host;
        }

        $resolved = gethostbyname($host);
        return ($resolved !== $host) ? $resolved : $host;
    }

    private static function buildEndpoint(string $host, int $port): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return $host . ':' . $port;
    }

}