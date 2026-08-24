<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\HttpException;

/**
 * Who is asking, and what they are allowed to see.
 *
 * Everything a user owns - courses, profiles, tags, connected clients - carries
 * their username in a column. An administrator is allowed to see and manage all
 * of it, so instead of a second set of queries, every scoped query asks the
 * actor for its `WHERE` fragment: `username = ?` for a normal account and a
 * tautology for an administrator.
 *
 * Two separate ideas are deliberately kept apart:
 *
 *   - the actor       - who is making the request, and what they may reach;
 *   - the owner       - whose data a given row is, which is what related
 *                       lookups must use. A profile referenced by somebody
 *                       else's course belongs to that course's owner, not to
 *                       the administrator who happens to be looking at it.
 *
 * So authorisation reads the actor and data access reads `$row['username']`.
 * Nothing else may consult the session.
 */
final class Actor
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';

    private function __construct(
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $role,
    ) {
    }

    public static function make(string $username, string $displayName, string $role): self
    {
        return new self($username, $displayName === '' ? $username : $displayName, self::normaliseRole($role));
    }

    /** An actor bound to one account, used by cron and the command line. */
    public static function system(string $username = ''): self
    {
        return new self($username, $username === '' ? 'CourseForge' : $username, self::ROLE_ADMIN);
    }

    public static function normaliseRole(string $role): string
    {
        return strtolower(trim($role)) === self::ROLE_ADMIN ? self::ROLE_ADMIN : self::ROLE_USER;
    }

    public static function isRole(string $role): bool
    {
        return in_array(strtolower(trim($role)), [self::ROLE_ADMIN, self::ROLE_USER], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** Throws unless this actor is an administrator. */
    public function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw HttpException::forbidden('This needs an administrator account.');
        }
    }

    /** True when the actor owns the row, or is an administrator. */
    public function mayReach(string $owner): bool
    {
        return $this->isAdmin() || strcasecmp($owner, $this->username) === 0;
    }

    public function requireReach(string $owner, string $what = 'this'): void
    {
        if (!$this->mayReach($owner)) {
            throw HttpException::forbidden('You do not have access to ' . $what . '.');
        }
    }

    /**
     * The `WHERE` fragment and its parameters for a table owned per user.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public function scope(string $column = 'username'): array
    {
        if ($this->isAdmin()) {
            return ['1 = 1', []];
        }
        return [$column . ' = ?', [$this->username]];
    }

    /** @return array{username:string,display_name:string,role:string,is_admin:bool} */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'display_name' => $this->displayName,
            'role' => $this->role,
            'is_admin' => $this->isAdmin(),
        ];
    }
}
