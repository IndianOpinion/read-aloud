<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

use IndianOpinion\ReadAloud\Exception\ProviderConfigException;
use IndianOpinion\ReadAloud\Exception\TransientProviderException;

/**
 * Public-address check for the generic http adapter (PRD case 39).
 *
 * WordPress's own URL check looks at one IPv4 address and a short list of
 * private ranges. It lets through link-local addresses (cloud metadata
 * services live there), carrier-grade NAT, IPv6 unique-local and
 * link-local addresses, and IPv4 mapped into IPv6. This class resolves
 * every A and AAAA record of the host, refuses the request when any of
 * them is not a public unicast address, and returns the address the
 * request must be pinned to, so a second DNS answer at connect time
 * (DNS rebinding) cannot send the request somewhere else.
 */
final class EndpointGuard
{
    /**
     * Ranges that are never a public speech API: [network, prefix length].
     *
     * @var list<array{0: string, 1: int}>
     */
    private const DENIED = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
        ['::', 128],
        ['::1', 128],
        ['::', 96],
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['100::', 64],
        ['2001::', 23],
        ['2001:db8::', 32],
        ['2002::', 16],
        ['fc00::', 7],
        ['fe80::', 10],
        ['fec0::', 10],
        ['ff00::', 8],
    ];

    /** @var (callable(string): list<string>)|null */
    private static $resolver = null;

    /**
     * Replace DNS resolution (tests only; never makes a network call then).
     *
     * @internal
     * @param (callable(string): list<string>)|null $resolver
     */
    public static function useResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Check the endpoint host and return the address to pin the request to.
     *
     * @throws ProviderConfigException When any address of the host is not public.
     * @throws TransientProviderException When the host does not resolve.
     */
    public static function check(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new ProviderConfigException('The endpoint URL has no host.');
        }
        $host = strtolower(trim($host, '[]'));
        $addresses = self::resolve($host);
        if ($addresses === []) {
            throw new TransientProviderException('The endpoint host does not resolve.');
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                throw new ProviderConfigException('The endpoint address is refused (private, loopback, link-local or reserved).');
            }
        }

        return $addresses[0];
    }

    /** Whether an address is a public unicast address. */
    public static function isPublic(string $address): bool
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // IPv4 mapped into IPv6 (::ffff:a.b.c.d): judge the IPv4 address.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            $v4 = inet_ntop(substr($packed, 12));

            return $v4 !== false && self::isPublic($v4);
        }
        foreach (self::DENIED as [$network, $bits]) {
            $net = inet_pton($network);
            if ($net !== false && strlen($net) === strlen($packed) && self::inPrefix($packed, $net, $bits)) {
                return false;
            }
        }

        return true;
    }

    private static function inPrefix(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * Every A and AAAA address of a host (an IP literal resolves to itself).
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if (self::$resolver !== null) {
            return array_values(array_map('strval', (self::$resolver)($host)));
        }
        $out = [];
        // DNS lookups warn on failure; collect nothing instead of printing.
        set_error_handler(static fn (): bool => true);
        try {
            $v4 = gethostbynamel($host);
            if (is_array($v4)) {
                $out = $v4;
            }
            if (function_exists('dns_get_record')) {
                $records = dns_get_record($host, DNS_AAAA);
                foreach (is_array($records) ? $records : [] as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $out[] = $record['ipv6'];
                    }
                }
            }
        } finally {
            restore_error_handler();
        }

        return array_values(array_unique($out));
    }
}
