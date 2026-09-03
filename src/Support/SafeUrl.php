<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * Whether an address somebody typed is one this server should make a request to.
 *
 * Profiles carry addresses that CourseForge calls on the user's behalf: the
 * AI provider, the BookStack instance. Both are meant to be typed by a person
 * who runs those things, and a local model server on the same machine is an
 * ordinary case - so a loopback address is not refused. What is refused is
 * everything that is never a provider: a scheme other than http or https,
 * which would let cURL read a file or talk to a mail port; an address with
 * no host; and the cloud metadata service, which answers on a link-local
 * address with the credentials of the machine and is the one target every
 * server-side request has to be kept away from.
 */
final class SafeUrl
{
    /**
     * @param string $what the field, for the sentence: "the base URL of the AI account"
     * @throws HttpException when the address is not one to call
     */
    public static function assertCallable(string $url, string $what): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

        if ($parts === false || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw HttpException::unprocessable(
                ucfirst($what) . ' has to be a full address starting with http:// or https://, such as '
                . 'https://api.example.com/v1.'
            );
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw HttpException::unprocessable(
                ucfirst($what) . ' must not carry a user name or password. Credentials go in their own field.'
            );
        }
        if (self::isMetadataAddress($host)) {
            throw HttpException::unprocessable(
                ucfirst($what) . ' points at the cloud metadata service, which is not a provider.'
            );
        }
    }

    /**
     * Every address a profile document carries, checked before it is stored.
     *
     * @param array<string,mixed> $data the profile data as sent
     */
    public static function assertProfile(array $data): void
    {
        foreach ((array)($data['ai'] ?? []) as $account) {
            if (is_array($account) && trim((string)($account['base_url'] ?? '')) !== '') {
                self::assertCallable(
                    (string)$account['base_url'],
                    'the base URL of the AI account "' . (string)($account['name'] ?? '') . '"'
                );
            }
        }
        foreach ((array)($data['bookstack'] ?? []) as $instance) {
            if (is_array($instance) && trim((string)($instance['base_url'] ?? '')) !== '') {
                self::assertCallable(
                    (string)$instance['base_url'],
                    'the base URL of the BookStack instance "' . (string)($instance['name'] ?? '') . '"'
                );
            }
        }
    }

    /**
     * The link-local range cloud providers answer metadata on, as a literal or
     * as the names the well-known ones resolve it under.
     */
    public static function isMetadataAddress(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if (in_array($host, ['metadata.google.internal', 'metadata', 'instance-data', 'fd00:ec2::254'], true)) {
            return true;
        }
        $packed = @inet_pton($host);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            // 169.254.0.0/16, as a literal in any of the spellings PHP accepts.
            return str_starts_with(bin2hex($packed), 'a9fe');
        }
        // fe80::/10 as IPv6, and the mapped form of the range above.
        $hex = bin2hex($packed);
        return str_starts_with($hex, 'fe8') || str_starts_with($hex, 'fe9') || str_starts_with($hex, 'fea')
            || str_starts_with($hex, 'feb') || str_starts_with($hex, '00000000000000000000ffffa9fe');
    }

    /**
     * Whether a host name has the shape of one, for the addresses this
     * installation hands out about itself.
     *
     * `Host` is a request header, which is to say a string a client chose. A
     * URL built from it is only as trustworthy as that string, so anything
     * that is not a host name, an IP literal, or either with a port is
     * refused - and the caller falls back to something it knows.
     */
    public static function isHostShaped(string $host): bool
    {
        return $host !== ''
            && strlen($host) <= 255
            && preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)(:\d{1,5})?$/i', $host) === 1;
    }
}
