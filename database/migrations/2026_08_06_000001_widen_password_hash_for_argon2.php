<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;

/**
 * User — widen `users.password_hash` so it can hold a non-bcrypt hash.
 *
 * THE DEFECT
 * ----------
 * The column was created as CHAR(60), with a comment reasoning that this
 * "matches bcrypt's fixed output exactly (no wasted space, no truncation
 * risk)". That is true of bcrypt and only bcrypt. But the HashingPort adapter
 * (Plugins\Crypto\Infrastructure\PasswordHasher), its Provider docblock, and
 * Auth's AuthServiceContract all advertise Argon2id as a supported algorithm —
 * and an Argon2id hash is roughly 97 characters.
 *
 * So a deployment that took the documentation at its word and selected
 * Argon2id wrote a 97-character hash into a 60-character column. On MySQL in
 * non-strict mode that is silently truncated to 60 characters; the INSERT
 * succeeds, registration reports success, and password_verify() then fails
 * forever against the mutilated hash. Every account created that way is
 * permanently unable to log in, with no error anywhere to explain why. In
 * strict mode it is louder — the write errors — but the schema still forbids
 * the algorithm the code says you may use.
 *
 * The column also silently constrains any future migration to a longer hash
 * (scrypt, a peppered construction, a versioned prefix).
 *
 * THE FIX
 * -------
 * VARCHAR(255). Wide enough for Argon2id, scrypt and anything PHP's password_*
 * API is likely to produce, and — because VARCHAR stores only the bytes used —
 * a 60-character bcrypt hash still occupies 60 bytes plus a one-byte length
 * prefix. The "no wasted space" argument for CHAR(60) costs one byte per row
 * and buys a class of silent, unrecoverable data loss.
 *
 * CHAR → VARCHAR also fixes a subtler issue: MySQL right-pads CHAR values and
 * strips that padding on read, so a hash that was truncated to exactly 60
 * characters is indistinguishable from a valid one.
 *
 * SAFETY
 * ------
 * Widening is non-destructive: every existing 60-character bcrypt hash is
 * copied across unchanged and keeps verifying. No backfill, no re-hashing, no
 * downtime beyond the ALTER itself.
 *
 * SCOPE: central `users` only.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if (self::isSqlite($schema)) {
            return;
        }

        $schema->table('users', static function ($t) {
            // modifyColumn()'s own docblock shows `$c->string(255)`, but
            // ColumnDefinition has no type methods and its $type is readonly —
            // the type must come from the constructor, as a DDL fragment. The
            // callback's argument is therefore discarded and a fresh definition
            // returned. (Blueprint::string() is just addColumn($n, "VARCHAR(n)").)
            $t->modifyColumn('password_hash', static fn() =>
                (new ColumnDefinition('password_hash', 'VARCHAR(255)'))
                    ->notNull()
                    ->comment('password_* hash — bcrypt (60) or argon2id (~97); width allows either'));
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (self::isSqlite($schema)) {
            return;
        }

        // Narrowing back to CHAR(60) is only lossless while every stored hash
        // is bcrypt. If the deployment has since switched to Argon2id, this
        // rollback TRUNCATES those hashes and locks out the affected accounts
        // irreversibly — the plaintext is gone, so they cannot be rebuilt.
        //
        // The down() is provided because a migration without one cannot be
        // rolled back at all, but do not run it against a database that has
        // ever hashed with a non-bcrypt algorithm. Check first:
        //
        //   SELECT COUNT(*) FROM users WHERE password_hash NOT LIKE '$2%';
        //
        // A non-zero count means this rollback will destroy credentials.
        $schema->table('users', static function ($t) {
            $t->modifyColumn('password_hash', static fn() =>
                (new ColumnDefinition('password_hash', 'CHAR(60)'))
                    ->notNull()
                    ->comment('bcrypt — always 60 chars'));
        });
    }

    /**
     * SQLite is skipped, for two independent reasons.
     *
     * 1. There is nothing to fix. SQLite does not enforce declared column
     *    lengths at all — CHAR(60) has TEXT affinity and stores a string of
     *    any length. The truncation this migration exists to prevent cannot
     *    happen there, so the ALTER would be a pure no-op.
     *
     * 2. Running it would be actively dangerous. SQLite has no MODIFY COLUMN,
     *    so LetMigrate emulates modifyColumn() with the recreate-table pattern:
     *    build __tmp_<table>, copy the data across, DROP the original, rename.
     *    It builds that temp table from Blueprint::getColumns() — the ADDED
     *    columns. A blueprint that only modifies a column adds none, so the
     *    temp table is created empty and the copy list is empty, while the
     *    DROP TABLE still stands. Best case the invalid CREATE aborts the run;
     *    worst case, in a migration that also adds a column, the recreate
     *    succeeds with only that column and every other column in `users` —
     *    ids, emails, password hashes — is dropped with the table.
     *
     * So the guard is not a workaround for a limitation; it is refusing to
     * exercise a destructive code path to achieve nothing. Reported upstream
     * against LetMigrate (SQLiteGrammar::compileRecreateTable /
     * SchemaBuilder::table) — remove this guard once modifyColumn() carries
     * the full table definition on SQLite.
     */
    private static function isSqlite(SchemaBuilderInterface $schema): bool
    {
        return stripos($schema->getDriver()->getName(), 'sqlite') !== false;
    }
};
