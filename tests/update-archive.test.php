<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Two things an update has to get right before it can get anything else right:
 * ask GitHub for the archive in a way GitHub will answer, and put a file in the
 * place of another without there being a moment where neither is there.
 *
 * Both were wrong, and both were wrong invisibly. The Accept header was correct
 * for the one endpoint it was written for and refused outright by the endpoint
 * every asset-less release is fetched from, so the zipball fallback the update
 * documents had never once worked - including for CourseForge's own releases,
 * which publish no asset. And the swap deleted the file it was about to replace
 * whenever it ran on Windows, on the belief that rename() would not overwrite
 * there; when the rename then failed - which it always does on the script
 * running the update - the file was gone, and the rollback came back through
 * the same code and could not put it back.
 *
 * Neither is the sort of thing a reader spots. The header looks deliberate and
 * carries a comment explaining itself, and the delete looks like a platform
 * workaround. What follows pins the behaviour rather than the reasoning.
 */

use CourseForge\Update\Archive;
use CourseForge\Update\Release;

/** The Accept header Archive would send for one URL. */
function acceptFor(string $url): string
{
    $method = new ReflectionMethod(Archive::class, 'accept');

    return (string)$method->invoke(null, $url);
}

/** A scratch directory of this test's own, removed by the caller. */
function swapDirectory(): string
{
    $dir = sys_get_temp_dir() . '/cf-update-swap-' . bin2hex(random_bytes(4));
    Archive::ensureDirectory($dir);

    return $dir;
}

/* ------------------------------------------------------------ the download */

test('the zipball endpoint is not asked for an octet-stream, which is the header it refuses', static function (): void {
    // GitHub answers 415 here: "Unsupported 'Accept' header:
    // 'application/octet-stream'. Must accept 'application/json'."
    same('*/*', acceptFor('https://api.github.com/repos/ZcarecroW/CourseForge/zipball/v4.0.1'), 'zipball');
    same('*/*', acceptFor('https://api.github.com/repos/ZcarecroW/CourseForge/tarball/v4.0.1'), 'tarball');
});

test('a release asset asked for by its API URL still asks for the file rather than its description', static function (): void {
    same(
        'application/octet-stream',
        acceptFor('https://api.github.com/repos/ZcarecroW/CourseForge/releases/assets/12345'),
        'the asset API form is the one endpoint that needs it'
    );
});

test('a browser download URL and a checksum sidecar are not sent the asset header', static function (): void {
    same(
        '*/*',
        acceptFor('https://github.com/ZcarecroW/CourseForge/releases/download/v4.1.0/courseforge-4.1.0.zip'),
        'browser download URL'
    );
    same(
        '*/*',
        acceptFor('https://github.com/ZcarecroW/CourseForge/releases/download/v4.1.0/courseforge-4.1.0.zip.sha256'),
        'checksum sidecar'
    );
});

test('a release with no asset is fetched from the zipball, with a header the zipball accepts', static function (): void {
    $release = Release::fromApi([
        'tag_name' => 'v4.0.1',
        'assets' => [],
        'zipball_url' => 'https://api.github.com/repos/ZcarecroW/CourseForge/zipball/v4.0.1',
    ]);

    ok($release !== null, 'the release document must be readable');
    ok(!$release->hasAsset(), 'a release with an empty assets list has no asset');

    $url = $release->downloadUrl(false);
    same('https://api.github.com/repos/ZcarecroW/CourseForge/zipball/v4.0.1', $url, 'the fallback URL');
    same('*/*', acceptFor($url), 'the header that fallback is fetched with');
});

/* ---------------------------------------------------------------- the swap */

test('replacing the file this process is running leaves it on disk, and a rollback brings it back', static function (): void {
    // The regression this exists for is Windows-only, but the assertion holds
    // everywhere: on POSIX the rename succeeds, on Windows it is refused and
    // the bytes go into the existing file instead. What must never happen on
    // either is the file being absent afterwards.
    $dir = swapDirectory();

    try {
        $child = <<<'PHP'
        <?php
        require $argv[1];
        use CourseForge\Update\Archive;

        $self = __FILE__;
        $dir = dirname($self);

        // The backup is packed while the installation is whole, the way an
        // update takes one, and then the running script is swapped.
        Archive::pack($dir . '/backup.zip', $dir, [basename($self)]);
        Archive::copyFile($dir . '/replacement.php', $self);
        clearstatcache();
        echo 'swapped:' . (is_file($self) ? '1' : '0') . ':' . trim((string)file_get_contents($self)) . "\n";

        // And then put back, which is the rollback path - a different method,
        // the same problem.
        Archive::unpack($dir . '/backup.zip', $dir);
        clearstatcache();
        echo 'restored:' . (is_file($self) ? '1' : '0') . ':' . (str_contains((string)file_get_contents($self), 'THE-ORIGINAL') ? 'original' : 'other') . "\n";
        PHP;

        file_put_contents($dir . '/runner.php', $child . "\n// THE-ORIGINAL\n");
        file_put_contents($dir . '/replacement.php', "<?php\n// the new release\n");

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/runner.php')
            . ' ' . escapeshellarg(dirname(__DIR__) . '/src/Update/Archive.php');
        $output = (string)shell_exec($command . ' 2>&1');
        // The child's own stack trace, if it has one, is longer than the line a
        // failing test gets to print, and its first sentence is the whole story.
        $said = mb_substr(trim(preg_replace('/\s+/', ' ', $output) ?? ''), 0, 200);

        ok(str_contains($output, 'swapped:1:'), 'the running script must still exist after being swapped - said: ' . $said);
        ok(str_contains($output, 'restored:1:original'), 'the rollback must put the original bytes back - said: ' . $said);
    } finally {
        Archive::remove($dir);
    }
});

test('a replace that cannot happen leaves the target as it was and no rubbish beside it', static function (): void {
    $dir = swapDirectory();

    try {
        file_put_contents($dir . '/source.txt', 'NEW');
        // A directory cannot be renamed onto and cannot be written into, so
        // this is the failure branch with nothing to fall back to.
        Archive::ensureDirectory($dir . '/target');
        file_put_contents($dir . '/target/kept.txt', 'KEPT');

        $e = raises(
            static fn() => Archive::copyFile($dir . '/source.txt', $dir . '/target'),
            'replacing a directory must fail'
        );
        ok(str_contains($e->getMessage(), 'Could not replace'), 'the failure must say what it could not do');

        ok(is_dir($dir . '/target'), 'the target must survive a replace that failed');
        same('KEPT', (string)file_get_contents($dir . '/target/kept.txt'), 'and so must what was in it');
        same([], glob($dir . '/.cf-*.tmp') ?: [], 'the temporary file must not be left behind');
    } finally {
        Archive::remove($dir);
    }
});
