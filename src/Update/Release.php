<?php
declare(strict_types=1);

namespace CourseForge\Update;

/**
 * One GitHub release, reduced to the eight facts an update actually needs.
 *
 * The GitHub release document is large and mostly about people - who authored
 * the tag, who uploaded which asset, how many times each was downloaded. None
 * of that survives the trip into CourseForge, because everything that is kept
 * here is also written into the meta table as the cached answer to "is there a
 * new version", and a cache should hold the answer rather than the paperwork
 * around it.
 *
 * Two decisions are worth stating plainly.
 *
 * The first is that an attached asset is preferred over the zipball. GitHub
 * will build a zipball of any tag on demand, which is convenient and slightly
 * treacherous: its single top-level directory is named after the commit that
 * happened to be tagged, it contains everything in the repository including the
 * things a release is not supposed to ship, and there is nothing to check it
 * against. A release built on purpose and uploaded as `courseforge-4.1.0.zip`
 * is a fixed artefact that can carry a checksum beside it. The zipball stays as
 * the fallback, because a fork that never uploads an asset should still be
 * updatable.
 *
 * The second is that versions are compared as strings through version_compare
 * after a leading "v" is removed. Tags are written both ways in the wild -
 * `v4.1.0` and `4.1.0` are the same release - and CF_VERSION never carries the
 * prefix, so the comparison has to happen on normalised text or an installation
 * on 4.1.0 will cheerfully offer itself v4.1.0 forever.
 */
final class Release
{
    /** Assets that are a checksum or a signature rather than the release itself. */
    private const SIDECAR = ['sha256', 'sha512', 'md5', 'asc', 'sig', 'txt'];

    private function __construct(
        public readonly string $tag,
        public readonly string $version,
        public readonly string $name,
        public readonly string $body,
        public readonly int $publishedAt,
        public readonly bool $prerelease,
        public readonly string $zipballUrl,
        public readonly string $assetName,
        public readonly string $assetUrl,
        public readonly string $assetApiUrl,
        public readonly int $assetSize,
        public readonly string $checksumUrl,
        public readonly string $checksumApiUrl,
        public readonly string $bodyChecksum,
    ) {
    }

    /**
     * Reads one entry of the GitHub releases API.
     *
     * Returns null for anything that is not installable: a draft, or a release
     * with no tag at all. A draft is visible to a token that can see the
     * repository but its assets are not downloadable in the ordinary way, so
     * offering one as an update would only produce a confusing failure later.
     *
     * @param array<string,mixed> $json
     */
    public static function fromApi(array $json): ?self
    {
        $tag = trim((string)($json['tag_name'] ?? ''));
        if ($tag === '' || ($json['draft'] ?? false) === true) {
            return null;
        }

        $body = (string)($json['body'] ?? '');
        $assets = is_array($json['assets'] ?? null) ? $json['assets'] : [];

        $archive = self::pickArchive($assets);
        $checksum = $archive === null ? null : self::pickChecksum($assets, (string)$archive['name']);

        return new self(
            tag: $tag,
            version: self::normalise($tag),
            name: trim((string)($json['name'] ?? '')) ?: $tag,
            body: $body,
            publishedAt: (int)strtotime((string)($json['published_at'] ?? $json['created_at'] ?? '')),
            prerelease: ($json['prerelease'] ?? false) === true,
            zipballUrl: (string)($json['zipball_url'] ?? ''),
            assetName: (string)($archive['name'] ?? ''),
            assetUrl: (string)($archive['browser_download_url'] ?? ''),
            assetApiUrl: (string)($archive['url'] ?? ''),
            assetSize: (int)($archive['size'] ?? 0),
            checksumUrl: (string)($checksum['browser_download_url'] ?? ''),
            checksumApiUrl: (string)($checksum['url'] ?? ''),
            bodyChecksum: self::checksumInText($body, (string)($archive['name'] ?? '')),
        );
    }

    /**
     * Rebuilds a release from the meta-table cache.
     *
     * The stored shape is whatever toArray() last wrote, which may have been
     * written by an older version of this class - so every field is read
     * defensively and a document that has lost its tag is treated as no cache
     * at all rather than as a broken release.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $tag = trim((string)($data['tag'] ?? ''));
        if ($tag === '') {
            return null;
        }

        return new self(
            tag: $tag,
            version: self::normalise((string)($data['version'] ?? $tag)),
            name: (string)($data['name'] ?? $tag),
            body: (string)($data['body'] ?? ''),
            publishedAt: (int)($data['published_at'] ?? 0),
            prerelease: (bool)($data['prerelease'] ?? false),
            zipballUrl: (string)($data['zipball_url'] ?? ''),
            assetName: (string)($data['asset_name'] ?? ''),
            assetUrl: (string)($data['asset_url'] ?? ''),
            assetApiUrl: (string)($data['asset_api_url'] ?? ''),
            assetSize: (int)($data['asset_size'] ?? 0),
            checksumUrl: (string)($data['checksum_url'] ?? ''),
            checksumApiUrl: (string)($data['checksum_api_url'] ?? ''),
            bodyChecksum: (string)($data['body_checksum'] ?? ''),
        );
    }

    /* ---------------------------------------------------------- comparison */

    /** A tag as a version: no leading "v", no surrounding whitespace. */
    public static function normalise(string $version): string
    {
        $v = trim($version);
        if ($v !== '' && ($v[0] === 'v' || $v[0] === 'V')) {
            $v = substr($v, 1);
        }
        return trim($v);
    }

    /** -1, 0 or 1, on normalised strings. */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalise($a), self::normalise($b));
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        return self::compare($candidate, $current) > 0;
    }

    public function isNewerThan(string $current): bool
    {
        return self::isNewer($this->version, $current);
    }

    /* ------------------------------------------------------------ download */

    public function hasAsset(): bool
    {
        return $this->assetName !== '' && ($this->assetUrl !== '' || $this->assetApiUrl !== '');
    }

    /**
     * Where to fetch the archive from.
     *
     * With a token the API URL is used, because that is the only form that
     * works for a private fork; GitHub answers it with a redirect to storage
     * once `Accept: application/octet-stream` is sent. Without a token the
     * browser URL is both simpler and one redirect shorter.
     */
    public function downloadUrl(bool $authenticated): string
    {
        if (!$this->hasAsset()) {
            return $this->zipballUrl;
        }
        if ($authenticated && $this->assetApiUrl !== '') {
            return $this->assetApiUrl;
        }
        return $this->assetUrl !== '' ? $this->assetUrl : $this->assetApiUrl;
    }

    public function checksumDownloadUrl(bool $authenticated): string
    {
        if ($authenticated && $this->checksumApiUrl !== '') {
            return $this->checksumApiUrl;
        }
        return $this->checksumUrl !== '' ? $this->checksumUrl : $this->checksumApiUrl;
    }

    /** True when the download is a gzipped tar rather than a zip. */
    public function isTarball(): bool
    {
        $name = strtolower($this->assetName);
        return str_ends_with($name, '.tar.gz') || str_ends_with($name, '.tgz');
    }

    /** The file name the download is saved under, always derived from the tag. */
    public function fileName(): string
    {
        return $this->slug() . ($this->isTarball() ? '.tar.gz' : '.zip');
    }

    /**
     * A directory name for this tag, safe on every file system.
     *
     * A tag is arbitrary text that ends up as a path, so everything outside a
     * conservative set is folded to a hyphen - and a tag made entirely of those
     * characters still has to produce a usable name rather than an empty one.
     */
    public function slug(): string
    {
        $safe = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->tag), '-.');

        return $safe !== '' ? $safe : 'release';
    }

    /* --------------------------------------------------------------- output */

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tag' => $this->tag,
            'version' => $this->version,
            'name' => $this->name,
            'body' => $this->body,
            'published_at' => $this->publishedAt,
            'prerelease' => $this->prerelease,
            'zipball_url' => $this->zipballUrl,
            'asset_name' => $this->assetName,
            'asset_url' => $this->assetUrl,
            'asset_api_url' => $this->assetApiUrl,
            'asset_size' => $this->assetSize,
            'checksum_url' => $this->checksumUrl,
            'checksum_api_url' => $this->checksumApiUrl,
            'body_checksum' => $this->bodyChecksum,
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The asset that looks most like the release itself.
     *
     * A name beginning with "courseforge" wins outright, because that is what
     * the project's own build produces. Failing that, any archive that is not a
     * checksum or a signature will do - a fork that names its build after
     * itself should still work.
     *
     * @param array<int,mixed> $assets
     * @return array<string,mixed>|null
     */
    private static function pickArchive(array $assets): ?array
    {
        $fallback = null;

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = strtolower(trim((string)($asset['name'] ?? '')));
            if ($name === '' || ($asset['state'] ?? 'uploaded') !== 'uploaded') {
                continue;
            }
            if (in_array(pathinfo($name, PATHINFO_EXTENSION), self::SIDECAR, true)) {
                continue;
            }
            $isArchive = str_ends_with($name, '.zip')
                || str_ends_with($name, '.tar.gz')
                || str_ends_with($name, '.tgz');
            if (!$isArchive) {
                continue;
            }
            if (str_starts_with($name, 'courseforge')) {
                return $asset;
            }
            $fallback ??= $asset;
        }

        return $fallback;
    }

    /**
     * The checksum published beside an archive, if there is one.
     *
     * Two shapes are recognised: `courseforge-4.1.0.zip.sha256` sitting next to
     * the archive, and a lone `SHA256SUMS`-style file. The first is preferred
     * because it names exactly one artefact and cannot be misread.
     *
     * @param array<int,mixed> $assets
     * @return array<string,mixed>|null
     */
    private static function pickChecksum(array $assets, string $archiveName): ?array
    {
        $wanted = strtolower($archiveName) . '.sha256';
        $fallback = null;

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = strtolower(trim((string)($asset['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            if ($name === $wanted) {
                return $asset;
            }
            if (str_ends_with($name, '.sha256') || str_contains($name, 'sha256sum')) {
                $fallback ??= $asset;
            }
        }

        return $fallback;
    }

    /**
     * The SHA-256 for one archive, out of whatever text carries it.
     *
     * Release notes and a `sha256sum` sidecar are the same problem in two
     * costumes: several digests, one line each, and the line for our archive is
     * rarely the first. So the line that names the archive is found first, and
     * a bare digest is accepted only when nothing better was found and there is
     * exactly one of them in the text - two would be ambiguous, and a wrong
     * checksum is worse than none, because it fails an update that would
     * otherwise have worked.
     *
     * Public because Archive reads a sidecar with it. One matcher, so a
     * maintainer who writes the digest in the notes and a maintainer who
     * uploads it as a file are read by the same rules.
     */
    public static function checksumInText(string $body, string $archiveName): string
    {
        if (trim($body) === '') {
            return '';
        }

        if ($archiveName !== '') {
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                if (stripos($line, $archiveName) === false) {
                    continue;
                }
                if (preg_match('/\b([a-f0-9]{64})\b/i', $line, $m) === 1) {
                    return strtolower($m[1]);
                }
            }
        }

        if (preg_match_all('/\b([a-f0-9]{64})\b/i', $body, $all) === 1) {
            return strtolower($all[1][0]);
        }

        return '';
    }
}
