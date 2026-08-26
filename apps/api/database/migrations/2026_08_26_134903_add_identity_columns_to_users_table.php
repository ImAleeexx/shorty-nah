<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Exposed in URLs and payloads so the integer key never is; a
            // sequential id would let anyone enumerate the account list.
            $table->ulid('public_id')->nullable()->unique()->after('id');

            $table->string('role')->default(Role::Member->value)->after('email');

            // Sessions issued before a password change stop being accepted, which
            // needs the instant the password last changed.
            $table->timestamp('password_changed_at')->nullable();

            // Sensitive operations require recent authentication.
            $table->timestamp('last_authenticated_at')->nullable();

            $table->timestamp('disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'public_id',
                'role',
                'password_changed_at',
                'last_authenticated_at',
                'disabled_at',
            ]);
        });
    }
};
