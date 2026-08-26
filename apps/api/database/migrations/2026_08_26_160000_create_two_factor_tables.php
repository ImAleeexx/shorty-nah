<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // One table for both kinds. They are the same thing to everything
            // that asks "does this account have a second factor".
            $table->string('type', 16);
            $table->string('name', 100);

            // TOTP only. Encrypted at rest: it is a shared secret, and anyone
            // holding it can mint codes indefinitely.
            $table->text('secret')->nullable();

            // WebAuthn only.
            $table->text('credential_id')->nullable();
            $table->text('public_key')->nullable();
            $table->unsignedBigInteger('sign_count')->default(0);

            // A factor is not usable until it has been proved once, which is
            // what stops a mistyped secret from locking an account out.
            $table->timestamp('confirmed_at')->nullable();

            // The last accepted time step. A code from this step or earlier is
            // a replay, whatever its arithmetic says.
            $table->unsignedBigInteger('last_timestep')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        Schema::create('recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Hashed like every other issued secret. Shown once at creation and
            // never recoverable afterwards.
            $table->string('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_codes');
        Schema::dropIfExists('two_factor_credentials');
    }
};
