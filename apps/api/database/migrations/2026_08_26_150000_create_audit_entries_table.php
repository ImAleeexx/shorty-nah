<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();

            // Nullable and paired with a copy of the address: an entry must
            // outlive the account it describes, and deleting a user must not
            // erase what that user did.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();

            $table->string('action', 64)->index();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();

            // Derived, never the address itself. Stable across a day so entries
            // from one source can be correlated, and useless for identifying
            // anyone without the application key.
            $table->string('source_hash', 64)->nullable();

            $table->json('context')->nullable();
            $table->timestamp('created_at')->index();
        });

        // The enforcement is the missing grant, not a guard in application code.
        // A guard can be bypassed by the next person who writes a query; a
        // revoked privilege cannot.
        //
        // Run on the schema builder's own connection rather than the ambient
        // default. Migrations are applied as the owning role, and PostgreSQL
        // treats a REVOKE issued by a non-owner as a warning rather than an
        // error — so getting this wrong removes nothing and says nothing.
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $application = config('database.connections.pgsql.username');

        if (! is_string($application) || $application === '') {
            return;
        }

        $role = '"'.str_replace('"', '""', $application).'"';

        $connection->statement("REVOKE UPDATE, DELETE, TRUNCATE ON audit_entries FROM {$role}");

        // Asserted rather than assumed, for the same reason: a silent no-op here
        // leaves an audit log anyone can rewrite, and nothing else would notice.
        $remaining = $connection->select(
            'SELECT privilege_type FROM information_schema.role_table_grants '
            ."WHERE table_name = 'audit_entries' AND grantee = ? "
            ."AND privilege_type IN ('UPDATE', 'DELETE', 'TRUNCATE')",
            [$application],
        );

        if ($remaining !== []) {
            throw new RuntimeException(
                'The audit table is still writable by '.$application
                .'. Migrations must run as the owning role (--database=pgsql_owner).'
            );
        }
    }

    public function down(): void
    {
        // Nothing to restore: the owning role never lost the privilege, only the
        // application's role did, and the table is dropped by its owner.

        Schema::dropIfExists('audit_entries');
    }
};
