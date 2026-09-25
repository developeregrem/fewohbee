<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds absolute URLs that leave the installation, e.g. links handed to guests.
 *
 * Such links must not depend on the host a staff member happens to use, and they are also
 * rendered by cron jobs, where Symfony only knows DEFAULT_URI (often still http://localhost).
 * The base address is therefore resolved in this order:
 *
 * 1. PUBLIC_BASE_URI — preset by a hosting provider, not editable in the UI;
 * 2. the "public address" in the general settings;
 * 3. DEFAULT_URI, unless it points to a local host.
 *
 * Without any of them no public URL can be built and callers get null, so no link to a
 * machine the guest cannot reach is ever sent out.
 */
class PublicUrlService
{
    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(default::PUBLIC_BASE_URI)%')]
        private readonly ?string $environmentBaseUrl = null,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private readonly ?string $defaultUri = null,
    ) {
    }

    /** Whether the hosting environment dictates the address (the settings field is read-only then). */
    public function isPresetByEnvironment(): bool
    {
        return null !== self::normalize($this->environmentBaseUrl);
    }

    /** Effective base address without trailing slash, or null if none is known. */
    public function getBaseUrl(): ?string
    {
        $environment = self::normalize($this->environmentBaseUrl);
        if (null !== $environment) {
            return $environment;
        }

        $configured = self::normalize($this->settingsService->getSettings()->getPublicBaseUrl());
        if (null !== $configured) {
            return $configured;
        }

        $default = self::normalize($this->defaultUri);

        return null !== $default && !self::isLocalAddress($default) ? $default : null;
    }

    /**
     * Absolute URL of a route under the public base address, or null if no address is known.
     *
     * @param array<string, mixed> $parameters
     */
    public function generate(string $route, array $parameters = []): ?string
    {
        $baseUrl = $this->getBaseUrl();
        if (null === $baseUrl) {
            return null;
        }

        // The router prefixes the base path of the current request (e.g. an installation in a
        // sub-directory); the configured address already contains it.
        $path = $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        $contextBase = $this->urlGenerator->getContext()->getBaseUrl();
        if ('' !== $contextBase && str_starts_with($path, $contextBase)) {
            $path = substr($path, \strlen($contextBase));
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * Normalized "scheme://host[:port][/path]" or null for anything that is not a plain
     * http(s) address (credentials, query strings and fragments are rejected).
     */
    public static function normalize(?string $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');
        if ('' === $url) {
            return null;
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || '' === $parts['host']
        ) {
            return null;
        }

        $normalized = $scheme.'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $normalized .= ':'.$parts['port'];
        }

        return $normalized.rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Why guests might not be able to open links under this address: "local" (see
     * isLocalAddress()), "insecure" (plain http) or null if nothing looks wrong.
     */
    public static function reachabilityWarning(string $url): ?string
    {
        if (self::isLocalAddress($url)) {
            return 'local';
        }

        return str_starts_with(strtolower($url), 'http://') ? 'insecure' : null;
    }

    /**
     * Heuristic for addresses guests on the internet cannot reach: localhost, host names
     * without a dot, *.local/*.localhost and private or reserved IP addresses.
     */
    public static function isLocalAddress(string $url): bool
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $host = trim($host, '[]');
        if ('' === $host || 'localhost' === $host || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
        }

        return !str_contains($host, '.');
    }
}
