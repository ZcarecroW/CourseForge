<?php
declare(strict_types=1);

namespace CourseForge\Update;

use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Getting a release out of GitHub and onto the disk, intact.
 *
 * Three things happen here and each of them has a way of going quietly wrong,
 * which is why they are separate steps with their own reporting rather than one
 * convenient method.
 *
 * The download goes straight to a file handle. A CourseForge release is a few
 * megabytes and a fork's could be far more; reading a response into a string
 * and then writing it out doubles the peak memory for no benefit at all, and
 * the memory limit on a shared host is the first thing an update would hit.
 *
 * The verification is honest about itself. Not every release publishes a
 * digest, and an update that refused to install without one would make the
 * feature useless for most forks - so a missing checksum is allowed through and
 * the log says so in as many words. A digest that was published and could not
 * be read is a third case and is logged as itself, because "there was none" and
 * "there was one and we never saw it" are different facts about the same
 * download. What is never allowed through is a checksum that is published and
 * does not match.
 *
 * The extraction assumes nothing about what is inside. A GitHub zipball has a
 * single generated top-level directory named after the repository and the
 * commit - `ZcarecroW-CourseForge-a1b2c3d/` - which no caller can predict, so
 * the directory is found by looking rather than by guessing. Entry names are
 * checked before anything is written: a zip may contain `../../etc/passwd` and
 * a library that extracts it faithfully is doing what it was asked to.
 *
 * One archive is written here rather than read: the backup an update takes of
 * the version it is replacing. It lives with the rest of the zip handling for
 * the same reason the extraction does - this is the one class in the package
 * that knows how an archive is opened and closed.
 */
final class Archive
{
    /** A release download is allowed to take a while; a stalled one is not. */
    private const DOWNLOAD_TIMEOUT = 1800;

    private const CONNECT_TIMEOUT = 30;

    /** Nothing outside GitHub is ever fetched, whatever a release document says. */
    private const ALLOWED_HOSTS = [
        'github.com',
        'api.github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    /**
     * Fetches the release archive into $directory and returns the path to it.
     *
     * @param callable(string):void $log
     */
    public static function download(Release $release, string $token, string $directory, callable $log): string
    {
        $url = $release->downloadUrl($token !== '');
        if ($url === '') {
            throw new RuntimeException('That release publishes neither an archive nor a zipball, so there is nothing to install.');
        }

        self::ensureDirectory($directory);
        $target = $directory . '/' . $release->fileName();
        if (is_file($target)) {
            @unlink($target); // a half-finished download from an earlier attempt
        }

        $log($release->hasAsset()
            ? 'Downloading the release asset ' . $release->assetName
                . ($release->assetSize > 0 ? ' (' . self::size($release->assetSize) . ')' : '') . '.'
            : 'No release asset was published, so GitHub\'s generated zipball of ' . $release->tag . ' is used instead.');

        self::fetch($url, $token, $target);

        $size = (int)@filesize($target);
        if ($size <= 0) {
            @unlink($target);
            throw new RuntimeException('The download produced an empty file.');
        }
        // A truncated transfer is the failure this catches: cURL reports 200 and
        // the file just stops early, which unzips into a plausible-looking half
        // of a release.
        if ($release->assetSize > 0 && $size !== $release->assetSize) {
            @unlink($target);
            throw new RuntimeException(
                'The download is ' . self::size($size) . ' but GitHub said the asset is '
                . self::size($release->assetSize) . ', so it did not arrive in full.'
            );
        }
        $log('Downloaded ' . self::size($size) . ' to ' . basename($target) . '.');

        return $target;
    }

    /**
     * Checks the archive against whatever digest the release published.
     *
     * Returns true when a checksum was found and matched, false when there was
     * nothing to check against. Throws when there was something to check
     * against and it did not match.
     *
     * The sidecar goes through the same reader as the release notes, because
     * they pose the same problem. A `sha256sum`-style file lists one line per
     * asset and ours is rarely the first of them, so the line that names our
     * archive has to be found; taking the first digest in the file refuses a
     * perfectly good update for every release that publishes more than one
     * artefact.
     *
     * A digest that was published and could not be read is not the same thing
     * as no digest at all, and the two are logged as the two different facts
     * they are. The archive is still installed either way - refusing every
     * update over an unreachable sidecar would cost more than it saves - but
     * nobody is told it was checked when it was not.
     *
     * @param callable(string):void $log
     */
    public static function verify(string $file, Release $release, string $token, callable $log): bool
    {
        $expected = '';
        $source = '';
        $unread = '';

        $checksumUrl = $release->checksumDownloadUrl($token !== '');
        if ($checksumUrl !== '') {
            $name = basename((string)parse_url($checksumUrl, PHP_URL_PATH) ?: 'checksum');
            try {
                $expected = Release::checksumInText(self::fetch($checksumUrl, $token, null), $release->assetName);
                $unread = $expected !== ''
                    ? ''
                    : 'a checksum was published but the ' . $name . ' file names no SHA-256 for '
                        . $release->assetName . '; the archive was NOT verified.';
                $source = 'the published ' . $name . ' file';
            } catch (Throwable $e) {
                $unread = 'a checksum was published but could not be fetched; the archive was NOT verified ('
                    . $e->getMessage() . ').';
            }
        }
        if ($expected === '' && $release->bodyChecksum !== '') {
            $expected = $release->bodyChecksum;
            $source = 'the release notes';
            $unread = '';
        }

        if ($expected === '') {
            $log($unread === ''
                ? 'Checksum: SKIPPED - this release publishes no SHA-256, neither as an asset nor in its notes. '
                    . 'The download itself came over TLS from GitHub.'
                : 'Checksum: NOT VERIFIED - ' . $unread);
            return false;
        }

        $actual = hash_file('sha256', $file);
        if ($actual === false) {
            throw new RuntimeException('The downloaded archive could not be read back for checksumming.');
        }
        if (!hash_equals($expected, strtolower($actual))) {
            // Deleted rather than left in staging, because the sentence below
            // says it was and because an archive that failed its checksum is
            // the one file on this server nobody should be able to open later.
            @unlink($file);
            throw new RuntimeException(
                'The download does not match the SHA-256 in ' . $source . '. Expected ' . substr($expected, 0, 12)
                . '..., got ' . substr($actual, 0, 12) . '... The file has been discarded.'
            );
        }

        $log('Checksum: verified against ' . $source . ' (SHA-256 ' . substr($actual, 0, 12) . '...).');

        return true;
    }

    /**
     * Unpacks $file into $into and returns the directory the release actually
     * lives in - which is usually one level down.
     *
     * @param callable(string):void $log
     */
    public static function extract(string $file, string $into, callable $log): string
    {
        self::remove($into);
        self::ensureDirectory($into);

        if (self::isTarball($file)) {
            self::extractTar($file, $into, $log);
        } else {
            self::extractZip($file, $into, $log);
        }
        self::assertNothingEscaped($into);

        $root = self::singleRoot($into);
        if ($root !== null) {
            $log('The archive has one top-level directory, ' . basename($root) . '; that is the release.');
            return $root;
        }
        $log('The archive has no single top-level directory, so its contents are the release.');

        return $into;
    }

    /* ---------------------------------------------------------- file system */

    /**
     * Every file below $root, as paths relative to it, with forward slashes.
     *
     * Directories are not listed. An update copies files and creates the
     * directories it needs on the way, so an empty directory in a release is
     * silently dropped - a release that ships one is describing a place for
     * something rather than shipping something.
     *
     * @return array<int,string> sorted, so a log reads in a sensible order
     */
    public static function listFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $prefix = strlen(rtrim($root, '/\\')) + 1;
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $files[] = str_replace('\\', '/', substr($entry->getPathname(), $prefix));
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** Deletes a file or a whole directory. True when nothing is left behind. */
    public static function remove(string $path): bool
    {
        if (is_link($path) || (is_file($path) && !is_dir($path))) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return true; // already gone
        }

        $ok = true;
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($walk as $entry) {
            /** @var SplFileInfo $entry */
            $ok = ($entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname())) && $ok;
        }

        return @rmdir($path) && $ok;
    }

    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the directory ' . $directory . '.');
        }
    }

    /**
     * Copies one file, creating the directory it belongs in.
     *
     * The copy goes to a temporary name in the destination directory and is
     * then renamed over the target, for the same reason Support\Json writes
     * that way: a process that dies mid-copy leaves the old file whole rather
     * than half of the new one. On Windows rename() will not overwrite, so
     * there the old file is removed first - which is exactly the window the
     * atomic move exists to close, and is unavoidable on that platform.
     */
    public static function copyFile(string $source, string $target): void
    {
        self::ensureDirectory(dirname($target));

        $temporary = dirname($target) . '/.cf-' . bin2hex(random_bytes(4)) . '.tmp';
        if (!@copy($source, $temporary)) {
            @unlink($temporary);
            throw new RuntimeException('Could not copy ' . basename($source) . ' into ' . dirname($target) . '.');
        }

        if (DIRECTORY_SEPARATOR === '\\' && is_file($target)) {
            @unlink($target);
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Could not replace ' . $target . '.');
        }
    }

    /**
     * A path in the one form two paths can be compared in on this platform.
     *
     * Windows paths differ in case and mean the same directory; POSIX ones
     * differ in case and do not.
     */
    public static function comparable(string $path): string
    {
        $normalised = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalised) : $normalised;
    }

    /* ------------------------------------------------------- archives we write */

    /**
     * Packs files into a new zip and closes it.
     *
     * Every name is added from the tree it is read out of, and ZipArchive does
     * not read any of them until close() - so the caller must not touch a
     * single one of those files until this method has returned. That is the
     * constraint the backup is built around: it is written whole, before the
     * swap begins, rather than grown as files are replaced.
     *
     * @param array<int,string> $relatives paths under $root, with forward slashes
     * @param array<string,string> $extra name => content, for anything that has no file behind it
     */
    public static function pack(string $file, string $root, array $relatives, array $extra = []): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP\'s "zip" extension is not enabled on this server, so no backup can be written.');
        }

        self::ensureDirectory(dirname($file));
        @unlink($file);

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create the archive ' . $file . '.');
        }

        try {
            foreach ($relatives as $relative) {
                if (!$zip->addFile($root . '/' . $relative, $relative)) {
                    throw new RuntimeException('Could not put ' . $relative . ' into ' . basename($file) . '.');
                }
            }
            foreach ($extra as $name => $content) {
                if (!$zip->addFromString($name, $content)) {
                    throw new RuntimeException('Could not put ' . $name . ' into ' . basename($file) . '.');
                }
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($file);
            throw $e;
        }

        if (!$zip->close()) {
            @unlink($file);
            throw new RuntimeException(
                'The archive ' . basename($file) . ' could not be written. A file it was reading may have changed underneath it.'
            );
        }
    }

    /**
     * Unpacks a zip into $into, one entry at a time, and returns what it wrote.
     *
     * Entry by entry rather than through extractTo(), because the caller needs
     * to decide about each name - a backup holds files a restore must not put
     * back - and because each file is then written the way copyFile() writes
     * one, through a temporary name, so an interrupted restore leaves whole
     * files rather than half of one.
     *
     * @param callable(string):bool|null $accept answers for each entry name
     * @return array<int,string>
     */
    public static function unpack(string $file, string $into, ?callable $accept = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP\'s "zip" extension is not enabled on this server, so the archive cannot be read.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($file, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException('The archive ' . basename($file) . ' could not be opened (code ' . (int)$opened . ').');
        }

        $written = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                self::assertSafeEntry($name);
                if ($accept !== null && !$accept($name)) {
                    continue;
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('The entry ' . $name . ' could not be read out of ' . basename($file) . '.');
                }
                self::receive($stream, $into . '/' . $name);
                $written[] = $name;
            }
        } finally {
            $zip->close();
        }

        return $written;
    }

    /** One entry's contents out of a zip, or null when it is not in there. */
    public static function readEntry(string $file, string $name): ?string
    {
        if (!class_exists(ZipArchive::class) || !is_file($file)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return null;
        }
        $content = $zip->getFromName($name);
        $zip->close();

        return $content === false ? null : $content;
    }

    /* -------------------------------------------------------------- internals */

    /**
     * Writes an open stream to $target, through a temporary name.
     *
     * @param resource $source closed here, whatever happens
     */
    private static function receive($source, string $target): void
    {
        self::ensureDirectory(dirname($target));

        $temporary = dirname($target) . '/.cf-' . bin2hex(random_bytes(4)) . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            fclose($source);
            throw new RuntimeException('Could not write into ' . dirname($target) . '.');
        }

        $copied = @stream_copy_to_stream($source, $handle);
        fclose($source);
        fclose($handle);

        if ($copied === false) {
            @unlink($temporary);
            throw new RuntimeException('Could not write ' . basename($target) . '.');
        }
        if (DIRECTORY_SEPARATOR === '\\' && is_file($target)) {
            @unlink($target);
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Could not replace ' . $target . '.');
        }
    }

    /**
     * The one entry at the top of an extracted archive, if there is exactly one
     * and it is a directory.
     */
    private static function singleRoot(string $directory): ?string
    {
        $entries = [];
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            $entries[] = $entry;
            if (count($entries) > 1) {
                return null;
            }
        }

        return count($entries) === 1 && $entries[0]->isDir() ? $entries[0]->getPathname() : null;
    }

    /** @param callable(string):void $log */
    private static function extractZip(string $file, string $into, callable $log): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'This release is a zip and PHP\'s "zip" extension is not enabled on this server, so it cannot be '
                . 'unpacked. Enable ext-zip, or install the update by hand. (PharData cannot read a zip archive, '
                . 'so there is no fallback for this.)'
            );
        }

        $zip = new ZipArchive();
        $opened = $zip->open($file, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException('The downloaded zip could not be opened (code ' . (int)$opened . ').');
        }

        try {
            $count = $zip->numFiles;
            if ($count === 0) {
                throw new RuntimeException('The downloaded zip is empty.');
            }
            for ($i = 0; $i < $count; $i++) {
                self::assertSafeEntry((string)$zip->getNameIndex($i));
            }
            if (!$zip->extractTo($into)) {
                throw new RuntimeException('The zip could not be unpacked into ' . $into . '.');
            }
            $log('Unpacked ' . $count . ' entr' . ($count === 1 ? 'y' : 'ies') . ' from the zip.');
        } finally {
            $zip->close();
        }
    }

    /**
     * The gzipped-tar path.
     *
     * Only here because a fork may well build a .tar.gz, and PharData reads one
     * with no extra work. It is not an alternative to ext-zip: PharData cannot
     * read a zip at all, whatever its name suggests.
     *
     * Two views of the same archive are checked, because neither of them sees
     * everything. The headers list every entry the archive claims to hold,
     * including the ones that point outside it; PharData's tree is what
     * extractTo() will actually write, under the names it has decided on.
     *
     * @param callable(string):void $log
     */
    private static function extractTar(string $file, string $into, callable $log): void
    {
        if (!class_exists(PharData::class)) {
            throw new RuntimeException(
                'This release is a .tar.gz and PHP\'s "phar" extension is not enabled on this server, so it cannot '
                . 'be unpacked. Enable ext-phar, or install the update by hand.'
            );
        }

        $names = self::tarEntryNames($file);
        foreach ($names as $name) {
            self::assertSafeEntry($name);
        }

        try {
            $archive = new PharData($file);
            foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                /** @var SplFileInfo $entry */
                self::assertSafeEntry(self::insideArchive($file, $entry->getPathname()));
            }
            $archive->extractTo($into, null, true);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('The downloaded .tar.gz could not be unpacked: ' . $e->getMessage());
        }
        $log('Unpacked ' . count($names) . ' entr' . (count($names) === 1 ? 'y' : 'ies') . ' from the .tar.gz.');
    }

    /**
     * The names a tar itself claims to contain.
     *
     * Read out of the archive's own 512-byte headers rather than through
     * PharData, because PharData's iterator cannot reach an entry whose name
     * leads out of the archive: measured on a three-entry tar carrying two
     * traversal names, the iterator yielded one entry and extractTo() then
     * wrote three files. The headers are what extractTo() itself acts on, so
     * they are what a guard has to read.
     *
     * gzopen() reads an uncompressed tar as happily as a compressed one, so
     * both spellings of the format need only this one path.
     *
     * @return array<int,string>
     */
    private static function tarEntryNames(string $file): array
    {
        $handle = @gzopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The downloaded .tar.gz could not be opened for reading.');
        }

        $names = [];
        try {
            while (true) {
                $header = gzread($handle, 512);
                if (!is_string($header) || strlen($header) < 512) {
                    break;
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                if (trim($name, " \0") === '') {
                    break; // the pair of empty blocks that ends a tar
                }

                $size = (int)octdec(trim(substr($header, 124, 12), " \0"));
                $type = substr($header, 156, 1);
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $padding = (512 - $size % 512) % 512;

                // A GNU long-name record and a pax header both carry the real
                // name of the entry that follows as their body, so those bodies
                // are the names to check. Everything else is skipped over
                // without being read, which keeps a large archive out of memory.
                if (in_array($type, ['L', 'K', 'x', 'X', 'g'], true)) {
                    if ($size > 65536) {
                        throw new RuntimeException('The .tar.gz carries a file name header of ' . $size
                            . ' bytes, which no file system could hold. Refusing to unpack it.');
                    }
                    $body = $size > 0 ? (string)gzread($handle, $size) : '';
                    if ($padding > 0) {
                        gzseek($handle, $padding, SEEK_CUR);
                    }
                    if ($type === 'L' || $type === 'K') {
                        $names[] = rtrim($body, "\0");
                    } elseif (preg_match('/\d+ path=([^\n]*)\n/', $body, $m) === 1) {
                        $names[] = $m[1];
                    }
                    continue;
                }

                if ($size + $padding > 0) {
                    gzseek($handle, $size + $padding, SEEK_CUR);
                }
                $names[] = ($prefix !== '' ? $prefix . '/' : '') . $name;
            }
        } finally {
            gzclose($handle);
        }

        if ($names === []) {
            throw new RuntimeException('The downloaded .tar.gz holds no entries at all.');
        }

        return $names;
    }

    /**
     * The path an entry has inside the archive, taken off the phar:// URL the
     * iterator hands out.
     *
     * getFilename() is the base name, which cannot hold a slash or a `..` -
     * a guard reading it is looking at the one part of a name that is never
     * capable of pointing anywhere.
     */
    private static function insideArchive(string $file, string $pathname): string
    {
        $path = str_replace('\\', '/', $pathname);
        if (str_starts_with($path, 'phar://')) {
            $path = substr($path, 7);
        }

        $candidates = [str_replace('\\', '/', $file)];
        $real = realpath($file);
        if ($real !== false) {
            $candidates[] = str_replace('\\', '/', $real);
        }
        foreach ($candidates as $candidate) {
            if (str_starts_with($path, $candidate . '/')) {
                return substr($path, strlen($candidate) + 1);
            }
        }

        // The URL was not built from the path we opened. Falling back to what
        // follows the archive's own name keeps the check useful rather than
        // turning a path this method failed to read into a false alarm.
        $marker = '/' . basename($candidates[0]) . '/';
        $at = strrpos($path, $marker);

        return $at === false ? basename($path) : substr($path, $at + strlen($marker));
    }

    /**
     * Refuses anything that landed outside the extraction directory.
     *
     * The last line rather than the first. The entry names have been checked
     * already; this catches what a name cannot express - a link in the archive
     * whose target leads out of the tree - and it does not depend on either
     * library having behaved the way the checks above assume. realpath() is the
     * whole point of it, because that is what resolves a link into the place it
     * really goes.
     */
    private static function assertNothingEscaped(string $into): void
    {
        $root = realpath($into);
        if ($root === false) {
            throw new RuntimeException('The directory ' . $into . ' went missing while the release was unpacked into it.');
        }
        $root = self::comparable($root);

        foreach (self::listFiles($into) as $relative) {
            $real = realpath($into . '/' . $relative);
            if ($real === false || !str_starts_with(self::comparable($real), $root . '/')) {
                throw new RuntimeException(
                    'The archive put ' . $relative . ' outside the directory it was unpacked into. '
                    . 'Refusing to install it.'
                );
            }
        }
    }

    /**
     * Refuses an archive entry that would write outside the extraction
     * directory.
     *
     * Every name either library will act on is checked before a byte is
     * written, and neither library's own normalisation is relied on: a release
     * archive comes from the internet, and the cost of being wrong about what a
     * library does is an arbitrary file on the server overwritten by an update
     * that was supposed to be routine. ZipArchive hands its names over plainly;
     * a tar has to have its headers read by hand, for the reason set out on
     * tarEntryNames().
     */
    private static function assertSafeEntry(string $name): void
    {
        $normalised = str_replace('\\', '/', $name);

        $bad = $normalised === ''
            || str_starts_with($normalised, '/')
            || preg_match('#^[A-Za-z]:#', $normalised) === 1
            || $normalised === '..'
            || str_starts_with($normalised, '../')
            || str_contains($normalised, '/../')
            || str_ends_with($normalised, '/..')
            || str_contains($normalised, "\0");

        if ($bad) {
            throw new RuntimeException('The archive contains an entry that points outside itself: "' . $name . '". Refusing to unpack it.');
        }
    }

    private static function isTarball(string $file): bool
    {
        $name = strtolower($file);

        return str_ends_with($name, '.tar.gz') || str_ends_with($name, '.tgz');
    }

    /**
     * GETs a URL, either into a file or into a string.
     *
     * @param string|null $target a path to write to, or null to return the body
     * @return string the body when $target is null, otherwise an empty string
     */
    private static function fetch(string $url, string $token, ?string $target): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is not enabled on this server.');
        }
        self::assertGitHubUrl($url);

        $headers = [
            'User-Agent: CourseForge/' . CF_VERSION . ' (+PHP ' . PHP_VERSION . ')',
            // The API form of an asset URL only hands over the file itself when
            // this is asked for; without it GitHub returns the asset's JSON
            // description, which would unzip into nothing.
            'Accept: application/octet-stream',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $handle = null;
        if ($target !== null) {
            $handle = @fopen($target, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Could not open ' . $target . ' for writing.');
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::DOWNLOAD_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // GitHub answers an asset URL with a redirect to its storage host.
            // cURL drops the Authorization header by itself when a redirect
            // changes host, which is what makes following one safe here.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FAILONERROR => false,
        ]);
        if ($handle !== null) {
            curl_setopt($ch, CURLOPT_FILE, $handle);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // PHP 8 frees the handle; curl_close() is deprecated in 8.5

        if ($handle !== null) {
            fclose($handle);
        }

        if ($errno !== 0 || $status < 200 || $status >= 300) {
            if ($target !== null) {
                @unlink($target);
            }
            throw new RuntimeException(
                $errno !== 0
                    ? 'The download failed: ' . $error . ' (errno ' . $errno . ').'
                    : 'The download failed: GitHub answered ' . $status . '.'
            );
        }

        return is_string($body) ? $body : '';
    }

    /**
     * Refuses to fetch anything that is not GitHub.
     *
     * The URLs come out of a JSON document from the network, and a repository
     * that has been tampered with could name any host it liked. The download is
     * the one place in CourseForge where a remote answer is written to disk and
     * then executed, so the host is checked rather than assumed.
     */
    private static function assertGitHubUrl(string $url): void
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        $allowed = in_array($host, self::ALLOWED_HOSTS, true) || str_ends_with($host, '.githubusercontent.com');
        if ($scheme !== 'https' || !$allowed) {
            throw new RuntimeException('That release points its download at ' . ($host !== '' ? $host : 'nowhere') . ', which is not GitHub. Refusing to fetch it.');
        }
    }

    /** A byte count as something a person reads, for the log lines. */
    public static function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' kB';
        }

        return $bytes . ' bytes';
    }
}
