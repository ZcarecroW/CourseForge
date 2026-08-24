<?php
/**
 * That the data directory guards itself.
 *
 * `data/app.sqlite` holds every password hash on the installation, and the file
 * that stops it being fetched over HTTP used to be guaranteed only by being
 * present in the release. It turned out there were three ways to end up without
 * it - PHP creating the directory itself, an update that deliberately never
 * writes into `data/`, and a deploy tool that skipped the directory because the
 * rest of its contents belong to the server. The third of those happened, on a
 * real host, and the installation check is what found it.
 *
 * So the guarantee is code now, and this is what keeps it honest.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Support\DataDirectory;
use CourseForge\Support\Db;

$guard = CF_DATA . '/.htaccess';

test('the deny file is there once anything has opened the database', function () use ($guard) {
    Db::pdo();
    ok(is_file($guard), 'opening the database ensures the data directory is guarded');
});

test('a deleted deny file is written again', function () use ($guard) {
    ok(unlink($guard), 'removed it');
    ok(!is_file($guard), 'and it is gone');

    // guard() rather than ensure(), because ensure() answers once per process
    // on purpose - one stat per request, not one per query.
    ok(DataDirectory::guard(), 'guard() reports that it wrote one');
    ok(is_file($guard), 'and the file is back');
});

test('what it writes denies everything, both ways round', function () use ($guard) {
    $written = (string)file_get_contents($guard);

    same(DataDirectory::content(), $written, 'the file matches the constant it is written from');
    ok(str_contains($written, 'Require all denied'), 'the modern directive is present');
    ok(str_contains($written, 'Deny from all'), 'and the fallback for a server without mod_authz_core');
    ok(
        str_contains($written, 'IfModule mod_authz_core.c') && str_contains($written, 'IfModule !mod_authz_core.c'),
        'each directive is guarded by the module that understands it, so neither is a 500 on the other server'
    );
});

test('an existing file is left exactly as it is', function () use ($guard) {
    // Somebody may have tightened it further, or added a rule of their own.
    // Rewriting it on every request would quietly undo that.
    file_put_contents($guard, "# edited by hand\nRequire all denied\n");
    ok(DataDirectory::guard(), 'guard() is satisfied');
    same("# edited by hand\nRequire all denied\n", (string)file_get_contents($guard), 'and changed nothing');

    file_put_contents($guard, DataDirectory::content());
});

test('the shipped copy and the constant say the same thing', function () {
    $shipped = CF_ROOT . '/data/.htaccess';

    // The release carries the file and the code carries a constant. Two answers
    // to one question is how an installation ends up subtly protected in one
    // place and not the other.
    ok(is_file($shipped), 'the release ships data/.htaccess');
    same(
        DataDirectory::content(),
        (string)file_get_contents($shipped),
        'and it is byte-identical to what the code would write'
    );
});

test('the tool that deploys over FTP sends it', function () {
    // The bug this whole file exists for: data/ is skipped because it is the
    // server's, and the one file in it that is not went with it.
    $source = (string)file_get_contents(CF_ROOT . '/tools/deploy.php');

    ok(str_contains($source, 'SEND_ANYWAY'), 'the deploy tool has an exception list');
    ok(str_contains($source, "'data/.htaccess'"), 'and data/.htaccess is on it');
});

test('it can say whether the directory is somewhere a web server would serve', function () {
    // The test run puts CF_DATA in the system temporary directory, which is the
    // arrangement the manual recommends and which needs no .htaccess at all.
    ok(!DataDirectory::isUnderDocumentRoot(), 'a data directory outside the install is reported as outside');
});
