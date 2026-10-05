<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\AppSettingsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single source of truth for whether and how the MCP server (AI assistants) is usable.
 *
 * Two switches must both be on: the operator switch MCP_ENABLED (environment; lets self-hosters
 * and the SaaS platform keep the feature out of sight entirely) and the administrator switch in
 * the application settings. Everything MCP-related in the UI is hidden unless the respective
 * switch is on. The host allowlist is maintained by administrators in the settings as well.
 */
class McpSettings
{
    /**
     * Always allowed: a DNS rebinding attack carries the attacker's host name, never these, and
     * local tools on the same machine then work without any configuration.
     */
    public const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private const HOST_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    public function __construct(
        private readonly AppSettingsService $appSettingsService,
        #[Autowire('%mcp_enabled%')]
        private readonly bool $operatorEnabled,
        #[Autowire('%env(bool:MCP_ALLOW_INSECURE_HTTP)%')]
        private readonly bool $insecureHttpAllowed,
    ) {
    }

    /** The operator permits MCP: the settings page is shown and administrators may switch it on. */
    public function isAvailable(): bool
    {
        return $this->operatorEnabled;
    }

    /** MCP is switched on by operator and administrator: the endpoint answers and MCP UI is shown. */
    public function isActive(): bool
    {
        return $this->operatorEnabled && $this->appSettingsService->getSettings()->isMcpEnabled();
    }

    /** AI assistants may create reservations (the token still needs reservations:write). */
    public function isWriteAllowed(): bool
    {
        return $this->isActive() && $this->appSettingsService->getSettings()->isMcpWriteEnabled();
    }

    /** Plain HTTP from non-loopback clients was explicitly permitted (LAN installations without TLS). */
    public function isInsecureHttpAllowed(): bool
    {
        return $this->insecureHttpAllowed;
    }

    /**
     * Host names the administrator entered (without the implicit loopback names).
     *
     * @return list<string>
     */
    public function getConfiguredHosts(): array
    {
        return $this->appSettingsService->getSettings()->getMcpAllowedHosts();
    }

    /**
     * Host names (lowercase, without port) the MCP endpoint answers for; protects against DNS
     * rebinding. Loopback names plus the configured ones.
     *
     * @return list<string>
     */
    public function getAllowedHosts(): array
    {
        return array_values(array_unique([...self::LOOPBACK_HOSTS, ...$this->getConfiguredHosts()]));
    }

    public function isHostAllowed(string $host): bool
    {
        return \in_array(strtolower($host), $this->getAllowedHosts(), true);
    }

    /**
     * Parses the administrator's input: one host per line (commas and spaces work as well). A pasted
     * URL is reduced to its host name; ports are dropped. Loopback names are left out because they
     * are always allowed.
     *
     * @return array{hosts: list<string>, invalid: list<string>}
     */
    public static function parseHosts(string $input): array
    {
        $hosts = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $input, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $host = self::normalizeHost($entry);
            if (null === $host) {
                $invalid[] = $entry;
            } elseif (!\in_array($host, self::LOOPBACK_HOSTS, true)) {
                $hosts[] = $host;
            }
        }

        return ['hosts' => array_values(array_unique($hosts)), 'invalid' => $invalid];
    }

    /**
     * Host name of an entry such as "Fewohbee.example.com", "https://fewohbee.example.com/mcp" or
     * "host:8443"; null if it is no valid host name, IPv4 or bracketed IPv6 address.
     */
    public static function normalizeHost(string $entry): ?string
    {
        $entry = strtolower(trim($entry));
        if (str_contains($entry, '://')) {
            $entry = (string) parse_url($entry, \PHP_URL_HOST);
        }
        $entry = rtrim($entry, '/.');

        if (str_starts_with($entry, '[')) {
            $end = strpos($entry, ']');
            $ip = false !== $end ? substr($entry, 1, $end - 1) : '';

            return false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) ? '['.$ip.']' : null;
        }

        $entry = explode(':', explode('/', $entry, 2)[0], 2)[0];
        if (false !== filter_var($entry, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return $entry;
        }

        return 1 === preg_match(self::HOST_PATTERN, $entry) ? $entry : null;
    }
}
