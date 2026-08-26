<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        if (DB::getDriverName() === 'pgsql') {
            $application = config('database.connections.pgsql.username');

            if (is_string($application) && $application !== '') {
                DB::statement(sprintf(
                    'REVOKE UPDATE, DELETE, TRUNCATE ON audit_entries FROM %s',
                    '"'.str_replace('"', '""', $application).'"',
                ));
            }
        }
    }

    public function down(): void
    {
        // Nothing to restore: the owning role never lost the privilege, only the
        // application's role did, and the table is dropped by its owner.

        Schema::dropIfExists('audit_entries');
    }
};
