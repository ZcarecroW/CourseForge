<?php
declare(strict_types=1);

namespace CourseForge\Support;

use PDO;
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
    public const SCHEMA_VERSION = 6;

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
        if (!is_dir(CF_DATA) && !@mkdir(CF_DATA, 0770, true) && !is_dir(CF_DATA)) {
            throw new RuntimeException('The data directory is missing and could not be created: ' . CF_DATA);
        }
        if (!is_writable(CF_DATA)) {
            throw new RuntimeException('The data directory is not writable by PHP: ' . CF_DATA);
        }

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

    /** Runs $work inside a transaction, rolling back on any throwable. */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
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

        // The pending-only index predates the worker's `working` state, and an
        // index carries no data, so replacing it outright is safe.
        $pdo->exec('DROP INDEX IF EXISTS idx_batch_items_one_pending');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_batch_items_active
                        ON batch_items(page_id) WHERE status IN ('pending', 'working')");

        // Version 6: accounts, and a run that knows which quality pass it is.
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
        self::ensureColumn($pdo, 'batch_items', 'pass', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'batch_items', 'usage', "TEXT NOT NULL DEFAULT '{}'");

        if (self::schemaVersion($pdo) < 3) {
            self::upgradeToV3($pdo);
        }
        // Versions 4, 5 and 6 only add tables and columns, which the statements
        // above have already made - there is no data to move.
        if (self::schemaVersion($pdo) < self::SCHEMA_VERSION) {
            self::setMeta($pdo, 'schema_version', (string)self::SCHEMA_VERSION);
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

    /** SQLite has no `ADD COLUMN IF NOT EXISTS`, so check first. */
    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::hasColumn($pdo, $table, $column)) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
