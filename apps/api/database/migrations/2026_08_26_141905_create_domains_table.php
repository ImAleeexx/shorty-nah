<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            // Hosts are stored lowercased; DNS is case-insensitive and a
            // case-varying duplicate would be a second domain serving the same
            // traffic.
            $table->string('host')->unique();

            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();

            // Shown to the operator so they can prove control, and never used as
            // a credential.
            $table->string('verification_token', 64);

            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_failure')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
