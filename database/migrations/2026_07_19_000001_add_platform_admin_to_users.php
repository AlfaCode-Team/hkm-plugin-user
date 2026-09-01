<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — mark platform administrators on the CENTRAL `users` table.
 *
 * Owned by the User plugin because `users` is User's table: a column on it is
 * User's to define, even though the privilege it records is consumed by the
 * control plane. No other plugin or project should alter this table.
 *
 * WHY A COLUMN AND NOT A ROLE
 * ---------------------------
 * A platform administrator can enumerate every tenant and read data inside any
 * of them. That is the single highest privilege on the platform, so the flag
 * that grants it must live OUTSIDE the tenant model entirely — a tenant-scoped
 * role or policy edit must never be able to reach it.
 *
 * Casbin (`casbin_rule`) stays the right tool for finer-grained permissions
 * WITHIN the admin surface (who may delete a tenant vs. only view one). This
 * column is the outer gate those finer rules sit behind.
 *
 * A platform admin is deliberately NOT a tenant: it holds no row in `tenants`
 * and no membership in `user_tenants`. Making the control plane a tenant would
 * put it inside the resource it administers — it could suspend or delete its
 * own database, and a tenant-DB outage would take down the tool needed to
 * diagnose that outage.
 *
 * SCOPE: central `users` only. Tenant databases never carry this column; a
 * tenant DB is not a place where platform privilege can be granted.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->table('users', static function ($t) {
            // Default 0: every existing and future account is a normal user
            // until explicitly promoted. Privilege is never acquired by
            // accident, only by a deliberate write.
            $t->boolean('is_platform_admin')->default(0)
                ->comment('1 = platform (super) administrator — full cross-tenant access');

            // Partial-ish lookup: the admin list is a handful of rows in a table
            // that may hold millions, so the index keeps "who are the admins?"
            // cheap without scanning.
            $t->index(['is_platform_admin'], 'idx_users_platform_admin');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Drop the INDEX, then the column underneath it. Both, in that order.
        //
        // This used to drop the column alone, because LetMigrate reordered an
        // ALTER's clauses to put column drops FIRST: on MySQL the column drop
        // silently took its single-column index with it, so the DROP INDEX that
        // followed failed with "Can't DROP INDEX … check that it exists" and the
        // rollback aborted half-done. Omitting the index drop was the only shape
        // that survived that ordering.
        //
        // It survived on MySQL only. Every other engine refuses the column drop
        // while an index still references it — SQLite answers "error in index
        // idx_users_platform_admin after drop column: no such column" — so this
        // rollback could not run anywhere else, in the direction nobody exercises
        // until they uninstall the plugin.
        //
        // LetMigrate now compiles drops dependents-first (foreign keys, then
        // indexes, then columns), so the correct declaration is also the portable
        // one. EXECUTED green on SQLite (`hkm ground migrate`: 10 applied, rolled
        // back clean). On MySQL, PostgreSQL and SQL Server the emitted SQL was
        // read rather than run — each compiles to DROP INDEX followed by DROP
        // COLUMN in its own dialect — because no server for those was reachable
        // here. Run `hkm ground migrate` against them before trusting the
        // rollback on those engines.
        $schema->table('users', static function ($t) {
            $t->dropIndex('idx_users_platform_admin');
            $t->dropColumn('is_platform_admin');
        });
    }
};
