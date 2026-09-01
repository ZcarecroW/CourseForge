<?php
declare(strict_types=1);

namespace CourseForge\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The single SQLite connection plus the schema.
 *
 * Migrations are idempotent: `CREATE TABLE IF NOT EXISTS` for the shape,
 * `ensureColumn()` for anything added later, and one guarded upgrade step that
 * lifts a CourseForge 2.x database into the version 3 detail system.
 */
final class Db
{
    public const SCHEMA_VERSION = 8;

    private static ?PDO $pdo = null;

    public static function file(): string
    {
        return CF_DATA . '/app.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        // Creates the directory when it is missing and writes the deny file
        // when that is missing, which is not the same thing: PHP creates the
        // directory itself on a fresh install, and what it creates is empty.
        DataDirectory::ensure();

        $pdo = new PDO('sqlite:' . self::file(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 15000');

        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    /** @param array<int,mixed> $args */
    public static function run(string $sql, array $args = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    /** @param array<int,mixed> $args @return array<int,array<string,mixed>> */
    public static function rows(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }

    /** @param array<int,mixed> $args @return array<string,mixed>|null */
    public static function row(string $sql, array $args = []): ?array
    {
        $row = self::run($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    /**
     * Runs $work inside a transaction, rolling back on any throwable.
     *
     * The transaction is a writer from its very first statement, which is not
     * what PDO's own beginTransaction() gives you. PDO issues a bare BEGIN, and
     * a bare BEGIN is DEFERRED: the transaction takes a read snapshot when it
     * first reads and only asks for the write lock when it first writes. In WAL
     * mode that upgrade is not allowed to wait. If anything at all has committed
     * since the snapshot was taken, SQLite answers SQLITE_BUSY straight away
     * without ever consulting the busy handler - so `busy_timeout` is silently
     * skipped for exactly the transactions that read before they write, while a
     * transaction that happens to write first waits the full fifteen seconds and
     * succeeds. With the cron worker committing page claims and page bodies
     * every few seconds, that is a race a read-then-write loses several times a
     * minute, and it surfaces as an unexplained "database is locked" rather than
     * as anything a caller can retry.
     *
     * BEGIN IMMEDIATE takes the write lock up front, where waiting is allowed.
     * Every transaction in CourseForge writes, so it is the default and the only
     * kind anything asks for today. `deferred` is kept for the case it is
     * genuinely right - a transaction that only ever reads and wants a
     * consistent snapshot, which has no business holding the write lock against
     * the rest of the installation while it does so.
     */
    public static function transaction(callable $work, string $kind = 'immediate'): mixed
    {
        $begin = match (strtolower($kind)) {
            'immediate' => 'BEGIN IMMEDIATE',
            'deferred' => 'BEGIN DEFERRED',
            default => throw new RuntimeException('Unknown transaction kind "' . $kind . '".'),
        };

        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }

        // Begun and ended through exec() rather than PDO's own methods, which
        // have no way to ask for a kind. inTransaction() still answers correctly
        // either way - the SQLite driver reads it from the connection rather
        // than from a flag of its own - so the nesting guard above is unaffected.
        $pdo->exec($begin);
        try {
            $result = $work();
            $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            // A COMMIT that failed usually leaves the transaction open, but not
            // always; rolling back what is no longer there would replace the
            // error worth reading with one that is not.
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /* ------------------------------------------------------------- schema */

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS meta (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS login_attempts (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ip       TEXT NOT NULL,
                username TEXT,
                ok       INTEGER NOT NULL DEFAULT 0,
                ts       INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts(ip, ts);
            -- The account counter asks a different question - "how often has
            -- this name been guessed at, from anywhere?" - and the index above
            -- cannot answer it, because it is led by ip. Without this one that
            -- question is a full scan of a table every failed sign-in adds a
            -- row to. NOCASE because every query against the column compares
            -- that way, and an index built with another collation is one SQLite
            -- will not use.
            CREATE INDEX IF NOT EXISTS idx_attempts_user ON login_attempts(username COLLATE NOCASE, ts);

            CREATE TABLE IF NOT EXISTS profiles (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                username   TEXT NOT NULL,
                name       TEXT NOT NULL,
                data       TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_profiles_user ON profiles(username);

            CREATE TABLE IF NOT EXISTS projects (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                username        TEXT NOT NULL,
                profile_id      INTEGER,
                name            TEXT NOT NULL,
                topic           TEXT NOT NULL DEFAULT '',
                structure_md    TEXT NOT NULL DEFAULT '',
                book_title      TEXT NOT NULL DEFAULT '',
                book_desc       TEXT NOT NULL DEFAULT '',
                settings        TEXT NOT NULL DEFAULT '{}',
                bs_instance_id  TEXT NOT NULL DEFAULT '',
                shelf_id        INTEGER,
                shelf_name      TEXT NOT NULL DEFAULT '',
                book_id         INTEGER,
                book_slug       TEXT NOT NULL DEFAULT '',
                book_url        TEXT NOT NULL DEFAULT '',
                pushed_hash     TEXT NOT NULL DEFAULT '',
                auto_tags       INTEGER NOT NULL DEFAULT 0,
                tag_pool        TEXT NOT NULL DEFAULT '',
                tag_pool_strict INTEGER NOT NULL DEFAULT 0,
                created_at      INTEGER NOT NULL,
                updated_at      INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_projects_user ON projects(username, updated_at);

            CREATE TABLE IF NOT EXISTS chapters (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                idx         INTEGER NOT NULL,
                title       TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                settings    TEXT NOT NULL DEFAULT '{}',
                bs_id       INTEGER,
                bs_slug     TEXT NOT NULL DEFAULT '',
                bs_url      TEXT NOT NULL DEFAULT '',
                pushed_hash TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_chapters_project ON chapters(project_id, idx);

            CREATE TABLE IF NOT EXISTS pages (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id    INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                chapter_id    INTEGER NOT NULL REFERENCES chapters(id) ON DELETE CASCADE,
                idx           INTEGER NOT NULL,
                title         TEXT NOT NULL,
                content       TEXT NOT NULL DEFAULT '',
                extra_context TEXT NOT NULL DEFAULT '',
                settings      TEXT NOT NULL DEFAULT '{}',
                status        TEXT NOT NULL DEFAULT 'pending',
                error         TEXT NOT NULL DEFAULT '',
                bs_id         INTEGER,
                bs_slug       TEXT NOT NULL DEFAULT '',
                bs_url        TEXT NOT NULL DEFAULT '',
                pushed_hash   TEXT NOT NULL DEFAULT '',
                updated_at    INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_pages_project ON pages(project_id);
            CREATE INDEX IF NOT EXISTS idx_pages_chapter ON pages(chapter_id, idx);

            -- Where a course publishes. One row per BookStack instance it is
            -- pushed to, so a course can live in several wikis at once.
            --
            -- The book belongs to the target rather than to the course,
            -- because there is one book per wiki and they are different books:
            -- different ids, different slugs, different URLs, and different
            -- fingerprints, since a page carries links to its own wiki and so
            -- is not byte-identical across two of them.
            --
            -- `instance_id` names an entry in the owner's profile, which is
            -- where the credentials are. It is unique per course: a second row
            -- for the same wiki would be a second book in it, which is a
            -- duplicate rather than a destination.
            CREATE TABLE IF NOT EXISTS publish_targets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                instance_id TEXT NOT NULL,
                idx         INTEGER NOT NULL DEFAULT 0,
                enabled     INTEGER NOT NULL DEFAULT 1,
                shelf_id    INTEGER,
                shelf_name  TEXT NOT NULL DEFAULT '',
                book_id     INTEGER,
                book_slug   TEXT NOT NULL DEFAULT '',
                book_url    TEXT NOT NULL DEFAULT '',
                pushed_hash TEXT NOT NULL DEFAULT '',
                created_at  INTEGER NOT NULL DEFAULT 0,
                updated_at  INTEGER NOT NULL DEFAULT 0
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_publish_targets_one ON publish_targets(project_id, instance_id);
            CREATE INDEX IF NOT EXISTS idx_publish_targets_project ON publish_targets(project_id, idx);

            -- What one chapter or one page became in one wiki. The columns are
            -- the ones chapters and pages have carried since 2.x; they are here
            -- once per target instead of once per item.
            --
            -- Real foreign keys rather than an entity_type column, so a deleted
            -- chapter or page takes its published identities with it and no
            -- pruning pass has to remember to run. Exactly one of the two is
            -- ever set, which is what the two partial indexes below say.
            CREATE TABLE IF NOT EXISTS publish_items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                target_id   INTEGER NOT NULL REFERENCES publish_targets(id) ON DELETE CASCADE,
                chapter_id  INTEGER REFERENCES chapters(id) ON DELETE CASCADE,
                page_id     INTEGER REFERENCES pages(id) ON DELETE CASCADE,
                bs_id       INTEGER,
                bs_slug     TEXT NOT NULL DEFAULT '',
                bs_url      TEXT NOT NULL DEFAULT '',
                pushed_hash TEXT NOT NULL DEFAULT ''
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_publish_items_chapter
                ON publish_items(target_id, chapter_id) WHERE chapter_id IS NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS idx_publish_items_page
                ON publish_items(target_id, page_id) WHERE page_id IS NOT NULL;
            -- The three cascades need an index each, led by the column being
            -- followed. The two above are partial, and SQLite will not seek a
            -- partial index for a foreign-key check - so without these, every
            -- deleted chapter, page or destination scans this table for the
            -- whole installation. On a course being rewritten, that is once per
            -- row removed.
            CREATE INDEX IF NOT EXISTS idx_publish_items_target ON publish_items(target_id);
            CREATE INDEX IF NOT EXISTS idx_publish_items_by_chapter ON publish_items(chapter_id);
            CREATE INDEX IF NOT EXISTS idx_publish_items_by_page ON publish_items(page_id);

            CREATE TABLE IF NOT EXISTS tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                username   TEXT NOT NULL,
                name       TEXT NOT NULL,
                value      TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_tags_user_name ON tags(username, name COLLATE NOCASE);

            CREATE TABLE IF NOT EXISTS tag_links (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                tag_id      INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                entity_type TEXT NOT NULL,
                entity_id   INTEGER NOT NULL,
                inherit     INTEGER NOT NULL DEFAULT 0,
                auto        INTEGER NOT NULL DEFAULT 0,
                enabled     INTEGER NOT NULL DEFAULT 1
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_tag_links_unique ON tag_links(tag_id, entity_type, entity_id);
            CREATE INDEX IF NOT EXISTS idx_tag_links_project ON tag_links(project_id);

            CREATE TABLE IF NOT EXISTS batch_jobs (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                username     TEXT NOT NULL,
                project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                profile_id   INTEGER,
                slot         TEXT NOT NULL DEFAULT 'page',
                provider     TEXT NOT NULL DEFAULT '',
                ai_id        TEXT NOT NULL DEFAULT '',
                model        TEXT NOT NULL DEFAULT '',
                remote_id    TEXT NOT NULL DEFAULT '',
                remote_state TEXT NOT NULL DEFAULT '',
                remote_ref   TEXT NOT NULL DEFAULT '',
                status       TEXT NOT NULL DEFAULT 'submitted',
                error        TEXT NOT NULL DEFAULT '',
                counts       TEXT NOT NULL DEFAULT '{}',
                created_at   INTEGER NOT NULL,
                updated_at   INTEGER NOT NULL,
                polled_at    INTEGER NOT NULL DEFAULT 0,
                finished_at  INTEGER NOT NULL DEFAULT 0,
                expires_at   INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_batch_jobs_project ON batch_jobs(project_id, created_at);
            CREATE INDEX IF NOT EXISTS idx_batch_jobs_open ON batch_jobs(username, status);

            CREATE TABLE IF NOT EXISTS batch_items (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id    INTEGER NOT NULL REFERENCES batch_jobs(id) ON DELETE CASCADE,
                page_id   INTEGER NOT NULL,
                custom_id TEXT NOT NULL,
                title     TEXT NOT NULL DEFAULT '',
                status    TEXT NOT NULL DEFAULT 'pending',
                error     TEXT NOT NULL DEFAULT ''
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_batch_items_custom ON batch_items(job_id, custom_id);
            CREATE INDEX IF NOT EXISTS idx_batch_items_page ON batch_items(page_id);

            -- A page may be claimed by at most one run at a time. This is what
            -- makes both queueing and the cron worker safe: two requests that
            -- both pass the "already queued?" check cannot both write the
            -- reservation, because the second insert violates this index.
            CREATE UNIQUE INDEX IF NOT EXISTS idx_batch_items_active
                ON batch_items(page_id) WHERE status IN ('pending', 'working');

            -- Cron leases. A worker slot is a row here with an expiry, so a tick
            -- that is killed half way through - by a host time limit, a restart,
            -- anything - frees its slot by itself once the lease runs out.
            CREATE TABLE IF NOT EXISTS locks (
                name  TEXT PRIMARY KEY,
                until INTEGER NOT NULL,
                owner TEXT NOT NULL DEFAULT ''
            );

            -- Connected Claude clients. Only the hash is stored: a token is
            -- shown once, when it is created, and cannot be recovered after.
            CREATE TABLE IF NOT EXISTS mcp_clients (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                username     TEXT NOT NULL,
                name         TEXT NOT NULL DEFAULT 'Claude',
                token_hash   TEXT NOT NULL,
                created_at   INTEGER NOT NULL,
                last_used_at INTEGER NOT NULL DEFAULT 0,
                uses         INTEGER NOT NULL DEFAULT 0
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_mcp_token ON mcp_clients(token_hash);
            CREATE INDEX IF NOT EXISTS idx_mcp_user ON mcp_clients(username);

            -- Continuations that have been redeemed, so they cannot be again.
            --
            -- A Multi Round-Trip Request pauses by ending the client's call and
            -- handing back a signed blob; the client returns it with the answer.
            -- The signature stops forgery and the expiry bounds the window, but
            -- neither makes the blob single-use - only a record of redemption
            -- does, and the protocol requires the server to enforce that.
            --
            -- The id is the primary key rather than a column checked before
            -- insert, so two clients racing the same continuation cannot both
            -- be told yes. Rows are swept on the next redemption; the table
            -- holds only what has not yet expired.
            CREATE TABLE IF NOT EXISTS mcp_continuations (
                jti        TEXT PRIMARY KEY,
                expires_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_continuations_exp ON mcp_continuations(expires_at);

            -- Accounts. CourseForge 3.x kept a single administrator in
            -- data/users.json; 4.0 has roles, so they belong in a table with
            -- everything else that can be edited from the application itself.
            CREATE TABLE IF NOT EXISTS users (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                username             TEXT NOT NULL,
                display_name         TEXT NOT NULL DEFAULT '',
                password_hash        TEXT NOT NULL,
                role                 TEXT NOT NULL DEFAULT 'user',
                disabled             INTEGER NOT NULL DEFAULT 0,
                must_change_password INTEGER NOT NULL DEFAULT 0,
                created_at           INTEGER NOT NULL,
                updated_at           INTEGER NOT NULL,
                last_login_at        INTEGER NOT NULL DEFAULT 0,
                created_by           TEXT NOT NULL DEFAULT ''
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_users_name ON users(username COLLATE NOCASE);

            -- Invite codes. Only the hash is here; the code itself lives in
            -- INVITE-CODE.txt on the file system and nowhere else.
            CREATE TABLE IF NOT EXISTS invites (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                code_hash  TEXT NOT NULL,
                role       TEXT NOT NULL DEFAULT 'admin',
                file_path  TEXT NOT NULL DEFAULT '',
                issued_by  TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL DEFAULT 0,
                max_uses   INTEGER NOT NULL DEFAULT 1,
                uses       INTEGER NOT NULL DEFAULT 0,
                used_at    INTEGER NOT NULL DEFAULT 0,
                used_by    TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_invites_open ON invites(used_at, expires_at);

            -- What was done to the installation rather than to a course. An
            -- install with several accounts has to be able to answer "who
            -- deleted that?" and "when did this update run?".
            CREATE TABLE IF NOT EXISTS audit_log (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                ts      INTEGER NOT NULL,
                actor   TEXT NOT NULL DEFAULT '',
                action  TEXT NOT NULL,
                subject TEXT NOT NULL DEFAULT '',
                detail  TEXT NOT NULL DEFAULT '',
                ip      TEXT NOT NULL DEFAULT '',
                source  TEXT NOT NULL DEFAULT 'web'
            );
            CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts);

            -- Every update this installation has attempted, so a failed one is
            -- visible in the application rather than only in a server log.
            CREATE TABLE IF NOT EXISTS update_history (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at   INTEGER NOT NULL,
                finished_at  INTEGER NOT NULL DEFAULT 0,
                from_version TEXT NOT NULL DEFAULT '',
                to_version   TEXT NOT NULL DEFAULT '',
                channel      TEXT NOT NULL DEFAULT 'stable',
                status       TEXT NOT NULL DEFAULT 'running',
                trigger      TEXT NOT NULL DEFAULT 'manual',
                actor        TEXT NOT NULL DEFAULT '',
                backup_path  TEXT NOT NULL DEFAULT '',
                log          TEXT NOT NULL DEFAULT '',
                error        TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_update_history_ts ON update_history(started_at);
            SQL
        );

        // Columns introduced after a table already existed in the wild (2.x installs).
        self::ensureColumn($pdo, 'projects', 'settings', "TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($pdo, 'projects', 'book_slug', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'projects', 'book_url', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'projects', 'auto_tags', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'projects', 'tag_pool', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'projects', 'tag_pool_strict', 'INTEGER NOT NULL DEFAULT 0');
        // What a client found when it went and looked the topic up, and when.
        // Stored on the course rather than on a page because it is the course
        // that is about WordPress: every page wants the same set of facts, and
        // researching them once is the difference between one search pass and
        // one per page.
        self::ensureColumn($pdo, 'projects', 'research_md', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'projects', 'research_at', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'projects', 'research_source', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'chapters', 'settings', "TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($pdo, 'chapters', 'bs_slug', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'chapters', 'bs_url', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'pages', 'settings', "TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($pdo, 'pages', 'bs_slug', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'pages', 'bs_url', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'tag_links', 'auto', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'tag_links', 'enabled', 'INTEGER NOT NULL DEFAULT 1');

        // Version 5 turned a "batch job" into a generation run that may be
        // served either by a provider's queue or by CourseForge's own cron
        // worker. The tables kept their names - there is live data in them and a
        // rename buys nothing - but a run now records which of the two it is.
        self::ensureColumn($pdo, 'batch_jobs', 'mode', "TEXT NOT NULL DEFAULT 'batch'");
        self::ensureColumn($pdo, 'batch_items', 'attempts', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'batch_items', 'started_at', 'INTEGER NOT NULL DEFAULT 0');

        // Who holds a `working` item, for the same reason `locks` has an owner:
        // a claim can be taken away from a worker that is still running - its
        // lease expires, cron gives the page to somebody else - and the worker
        // that lost it has no way to know. Without a token to compare, a settle
        // arriving hours late matches on state alone and yanks the page back
        // from the worker legitimately writing it. An item claimed by an older
        // release carries the empty string, which reads as "no owner recorded"
        // and behaves exactly as it did before.
        self::ensureColumn($pdo, 'batch_items', 'owner', "TEXT NOT NULL DEFAULT ''");

        // The pending-only index predates the worker's `working` state, and an
        // index carries no data, so replacing it outright is safe.
        $pdo->exec('DROP INDEX IF EXISTS idx_batch_items_one_pending');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_batch_items_active
                        ON batch_items(page_id) WHERE status IN ('pending', 'working')");

        // Version 6: accounts, a run that knows which quality pass it is, and
        // the second of a batch's two deadlines. `expires_at` is when the
        // provider stops running whatever is still queued - a day, two on
        // Gemini. `results_expire_at` is when it stops letting the finished
        // answers be downloaded, which is weeks later and a different number on
        // every provider. One column cannot hold both, and reading one as the
        // other is how a whole course is left sitting at a provider until it is
        // deleted for good.
        self::ensureColumn($pdo, 'mcp_clients', 'scopes', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'mcp_clients', 'expires_at', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'mcp_clients', 'note', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'batch_jobs', 'pass', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'batch_jobs', 'passes', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'batch_jobs', 'parent_id', 'INTEGER');
        self::ensureColumn($pdo, 'batch_jobs', 'label', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'batch_jobs', 'options', "TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($pdo, 'batch_jobs', 'usage', "TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($pdo, 'batch_jobs', 'created_by', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'batch_jobs', 'results_expire_at', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'batch_items', 'pass', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'batch_items', 'usage', "TEXT NOT NULL DEFAULT '{}'");

        // When somebody other than the account holder last set this password -
        // an administrator creating the account, or resetting it. That is the
        // moment every MCP connection made before it stops being trustworthy,
        // because the whole point of a reset is to cut off whoever was holding
        // the old credential, and a token minted under it would otherwise
        // outlive the password it was made with.
        //
        // It has to be its own column: `updated_at` moves for a display-name
        // change too, so comparing against that would revoke every connection
        // on the installation the first time anybody tidied up their profile.
        //
        // Zero is what an account that predates this column carries, and it
        // reads as "never recorded, so revoke nothing" - an installation
        // upgrading keeps every connection it had until an administrator
        // actually resets a password.
        self::ensureColumn($pdo, 'users', 'password_reset_at', 'INTEGER NOT NULL DEFAULT 0');

        // An invite that is worth several accounts is one row with a counter
        // rather than several rows, because the plain code lives in exactly one
        // file and a second open row would be a code nobody can read.
        //
        // The defaults are what make this invisible to an installation that
        // upgrades: every invite it already has is worth one account and has
        // been used zero times, which is precisely what it meant before the
        // columns existed.
        self::ensureColumn($pdo, 'invites', 'max_uses', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'invites', 'uses', 'INTEGER NOT NULL DEFAULT 0');

        // A row that was genuinely redeemed before the counter existed would
        // otherwise read as "0 of 1 used" for ever. The excluded names are the
        // ones the application writes when it closes a row administratively
        // rather than because somebody spent it - and this runs on every
        // request, not once, so an invite an administrator revokes today has to
        // be on the list as surely as one superseded in 4.2 does.
        $pdo->exec(
            "UPDATE invites SET uses = 1 "
            . "WHERE uses = 0 AND used_at > 0 "
            . "AND used_by NOT IN ('', 'superseded', 'file lost', 'revoked')"
        );

        if (self::schemaVersion($pdo) < 3) {
            self::upgradeToV3($pdo);
        }
        // Versions 4, 5, 6 and 7 only add tables and columns, which the
        // statements above have already made - there is no data to move.
        if (self::schemaVersion($pdo) < 8) {
            self::upgradeToV8($pdo);
        }
        if (self::schemaVersion($pdo) < self::SCHEMA_VERSION) {
            self::setMeta($pdo, 'schema_version', (string)self::SCHEMA_VERSION);
        }
    }

    /**
     * CourseForge 4.6 and earlier published a course to exactly one BookStack
     * instance, and kept that instance, its shelf and everything the push had
     * created in columns on the course, on every chapter and on every page.
     * Version 8 has a row per destination instead, so the one destination each
     * course already has becomes its first target.
     *
     * The old columns are not dropped. They go on being written as a mirror of
     * whichever target is first, which is what keeps every reader that has not
     * been taught about targets - the course list, the page badges, the link
     * index - answering correctly, and what lets an installation roll back to
     * 4.6 without losing the book it had.
     *
     * Only a course that actually has an instance chosen gets a target: one
     * that never picked a destination has nothing to carry across, and would
     * otherwise gain a row pointing at "".
     */
    private static function upgradeToV8(PDO $pdo): void
    {
        $now = time();
        $insertTarget = $pdo->prepare(
            'INSERT OR IGNORE INTO publish_targets
                 (project_id, instance_id, idx, enabled, shelf_id, shelf_name,
                  book_id, book_slug, book_url, pushed_hash, created_at, updated_at)
             VALUES (?,?,0,1,?,?,?,?,?,?,?,?)'
        );
        $insertItem = $pdo->prepare(
            'INSERT OR IGNORE INTO publish_items (target_id, chapter_id, page_id, bs_id, bs_slug, bs_url, pushed_hash)
             VALUES (?,?,?,?,?,?,?)'
        );
        $findTarget = $pdo->prepare('SELECT id FROM publish_targets WHERE project_id = ? AND instance_id = ?');
        $findItems = [];
        foreach (['chapters', 'pages'] as $table) {
            $findItems[$table] = $pdo->prepare(
                "SELECT id, bs_id, bs_slug, bs_url, pushed_hash FROM {$table}
                  WHERE project_id = ? AND (bs_id IS NOT NULL OR pushed_hash <> '')"
            );
        }

        $projects = $pdo->query("SELECT * FROM projects WHERE TRIM(bs_instance_id) <> ''")->fetchAll();
        foreach ($projects as $project) {
            $projectId = (int)$project['id'];
            $insertTarget->execute([
                $projectId,
                (string)$project['bs_instance_id'],
                $project['shelf_id'] === null ? null : (int)$project['shelf_id'],
                (string)$project['shelf_name'],
                $project['book_id'] === null ? null : (int)$project['book_id'],
                (string)$project['book_slug'],
                (string)$project['book_url'],
                (string)$project['pushed_hash'],
                $now,
                $now,
            ]);

            $findTarget->execute([$projectId, (string)$project['bs_instance_id']]);
            $targetId = (int)($findTarget->fetch()['id'] ?? 0);
            if ($targetId <= 0) {
                continue;
            }

            // A row is worth carrying across when the push left anything on it
            // at all. bs_id alone is not the test: an item can hold a
            // fingerprint from a push whose id was later cleared, and dropping
            // the fingerprint would silently re-send it.
            foreach (['chapters', 'pages'] as $table) {
                $items = $findItems[$table];
                $items->execute([$projectId]);
                foreach ($items->fetchAll() as $item) {
                    $insertItem->execute([
                        $targetId,
                        $table === 'chapters' ? (int)$item['id'] : null,
                        $table === 'pages' ? (int)$item['id'] : null,
                        $item['bs_id'] === null ? null : (int)$item['bs_id'],
                        (string)$item['bs_slug'],
                        (string)$item['bs_url'],
                        (string)$item['pushed_hash'],
                    ]);
                }
            }
        }
    }

    /**
     * CourseForge 2.x stored one tri-state `anki` column per level. Version 3
     * keeps every content detail in a single JSON `settings` column, so the old
     * flag is lifted into settings.features.anki exactly once.
     */
    private static function upgradeToV3(PDO $pdo): void
    {
        foreach (['projects', 'chapters', 'pages'] as $table) {
            if (!self::hasColumn($pdo, $table, 'anki')) {
                continue;
            }
            $rows = $pdo->query("SELECT id, anki, settings FROM {$table} WHERE anki <> 0")->fetchAll();
            $update = $pdo->prepare("UPDATE {$table} SET settings = ? WHERE id = ?");
            foreach ($rows as $row) {
                $settings = json_decode((string)($row['settings'] ?? '{}'), true);
                $settings = is_array($settings) ? $settings : [];
                if (isset($settings['features']['anki'])) {
                    continue; // already migrated
                }
                // Projects only ever stored 0/1; chapters and pages also stored -1.
                $settings['features']['anki'] = max(-1, min(1, (int)$row['anki']));
                $update->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$row['id']]);
            }
        }
    }

    private static function schemaVersion(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $stmt->execute(['schema_version']);
        $row = $stmt->fetch();
        return $row === false ? 0 : (int)$row['value'];
    }

    private static function setMeta(PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $col) {
            if (strcasecmp((string)$col['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * SQLite has no `ADD COLUMN IF NOT EXISTS`, so check first.
     *
     * The check and the ALTER are two statements, and the first requests
     * after an upgrade arrive together: two workers can both find the column
     * missing and both try to add it. The second gets "duplicate column name",
     * which is the column existing - the state this method is here to reach -
     * and not a reason to answer a person's request with a 500.
     */
    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::hasColumn($pdo, $table, $column)) {
            return;
        }
        try {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column') === false || !self::hasColumn($pdo, $table, $column)) {
                throw $e;
            }
        }
    }
}
