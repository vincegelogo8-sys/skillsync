<?php

namespace App\Services;

use Illuminate\Http\Request;
use RuntimeException;

class EmailLinkGenerator
{
    public function __construct(private Request $request) {}

    /** Build a named-route URL using a cellphone-reachable application host. */
    public function route(string $name, mixed $parameters = []): string
    {
        $path = route($name, $parameters, absolute: false);

        return rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');
    }

    public function baseUrl(): string
    {
        $override = config('app.email_base_url');

        if (is_string($override) && trim($override) !== '') {
            $baseUrl = $this->validatedBaseUrl($override);

            if ($baseUrl === null) {
                throw new RuntimeException('SKILLSYNC_EMAIL_BASE_URL must be a valid URL and must not use a loopback or wildcard host.');
            }

            return $baseUrl;
        }

        $requestHost = $this->request->getHost();

        if ($this->isPrivateIpv4($requestHost)) {
            return $this->origin($requestHost, $this->request->getScheme(), $this->request->getPort());
        }

        foreach ($this->localIpv4Addresses() as $address) {
            if ($this->isPrivateIpv4($address)) {
                return $this->origin($address, $this->request->getScheme(), $this->applicationPort());
            }
        }

        $serverAddress = $this->request->server('SERVER_ADDR');

        if (is_string($serverAddress) && $this->isPrivateIpv4($serverAddress)) {
            return $this->origin($serverAddress, $this->request->getScheme(), $this->applicationPort());
        }

        $applicationUrl = config('app.url');
        $fallback = is_string($applicationUrl) ? $this->validatedBaseUrl($applicationUrl) : null;

        if ($fallback !== null) {
            return $fallback;
        }

        throw new RuntimeException('No cellphone-reachable SKILLSYNC URL is available. Set SKILLSYNC_EMAIL_BASE_URL in .env to the laptop LAN URL, including its port.');
    }

    private function localIpv4Addresses(): array
    {
        $hostname = gethostname();

        if (! is_string($hostname) || $hostname === '') {
            return [];
        }

        $addresses = gethostbynamel($hostname);

        return is_array($addresses) ? array_values(array_unique($addresses)) : [];
    }

    private function applicationPort(): int
    {
        $port = $this->request->getPort();
        $scheme = $this->request->getScheme();

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $applicationUrl = config('app.url');
            $configuredPort = is_string($applicationUrl) ? parse_url($applicationUrl, PHP_URL_PORT) : null;

            if (is_int($configuredPort)) {
                return $configuredPort;
            }
        }

        return $port;
    }

    private function origin(string $host, string $scheme, int $port): string
    {
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port === $defaultPort ? '' : ':'.$port);
    }

    private function validatedBaseUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || $this->isLoopbackOrWildcard($parts['host'])) {
            return null;
        }

        return rtrim($url, '/');
    }

    private function isLoopbackOrWildcard(string $host): bool
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));

        if ($host === 'localhost' || $host === 'localhost.localdomain' || str_ends_with($host, '.localhost') || $host === '0.0.0.0' || $host === '::') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '127.') || $host === '0.0.0.0';
        }

        // Reject shorthand numeric IPv4 forms such as 127.1, which browsers
        // may still interpret as a loopback destination.
        if (preg_match('/^[0-9.]+$/', $host) === 1) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($host);
            if ($packed === str_repeat("\0", 15)."\1" || $packed === str_repeat("\0", 16)) {
                return true;
            }

            if (is_string($packed) && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                return $this->isLoopbackOrWildcard(inet_ntop(substr($packed, 12)));
            }
        }

        return false;
    }

    private function isPrivateIpv4(string $host): bool
    {
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return str_starts_with($host, '10.')
            || str_starts_with($host, '192.168.')
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host) === 1;
    }
}
