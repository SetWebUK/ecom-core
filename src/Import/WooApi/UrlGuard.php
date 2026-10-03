<?php

namespace Pine\Commerce\Import\WooApi;

/**
 * SSRF guard for every request the WooCommerce API importer makes (API calls and image downloads): only http(s)
 * URLs, and – unless config commerce.woo_api.allow_private_hosts is true (local testing) – only hosts whose every
 * resolved address is public: no loopback, private, link-local, carrier-grade NAT, multicast or reserved ranges
 * (IPv4, IPv6 and IPv4-mapped IPv6). Returns the address to connect to, so the request can be pinned to it (no DNS
 * rebinding between the check and the connection).
 */
class UrlGuard
{
    /** @var (callable(string):list<string>)|null test hook: host => addresses */
    public static $resolver = null;

    /**
     * @return array{scheme:string, host:string, port:int, ip:?string}
     *
     * @throws WooApiException
     */
    public static function check(string $url, bool $allowPrivate): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new WooApiException('Only http:// and https:// addresses can be used.', 'blocked', null, $url);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WooApiException('The address must not contain a user name or password.', 'blocked', null, self::redact($url));
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if (! $ips) {
            throw new WooApiException("The host {$host} could not be found (DNS).", 'network', null, $host);
        }
        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! self::isPublic($ip)) {
                    throw new WooApiException("{$host} points to a private or reserved address ({$ip}) – only public shops can be imported "
                        .'(set commerce.woo_api.allow_private_hosts for local testing).', 'blocked', null, $host);
                }
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => filter_var($host, FILTER_VALIDATE_IP) ? null : $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // IPv4-mapped / -compatible IPv6 (::ffff:127.0.0.1) – check the embedded IPv4 address
        if (preg_match('/^(?:0*:)*(?:ffff:)?(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['192.0.0.0', 24], ['192.0.2.0', 24],
                ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        $first = ord($bin[0]);
        // ::/128, ::1, fc00::/7 (unique local), fe80::/10 (link-local), ff00::/8 (multicast), 2001:db8::/32 (documentation)
        if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15)."\1" || ($first & 0xFE) === 0xFC || ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)
            || $first === 0xFF || str_starts_with(bin2hex($bin), '20010db8')) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    protected static function resolve(string $host): array
    {
        if (self::$resolver) {
            return array_values((self::$resolver)($host));
        }
        $ips = [];
        foreach ((@dns_get_record($host, DNS_A | DNS_AAAA) ?: []) as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));
        if (! $ips) {
            $ips = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    /** The URL without credentials and query string (for logs and messages). */
    public static function redact(string $url): string
    {
        $p = parse_url($url);
        if (! $p || empty($p['host'])) {
            return '(invalid URL)';
        }

        return ($p['scheme'] ?? 'http').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '');
    }
}
