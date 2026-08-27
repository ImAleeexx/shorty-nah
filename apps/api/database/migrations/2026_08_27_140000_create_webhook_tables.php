<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('name', 80);
            $table->text('url');

            // Which events this endpoint wants. A list rather than a boolean per
            // event, so adding an event later needs no migration.
            $table->jsonb('events');

            // Encrypted, not hashed — and that is a real departure from how
            // every other issued secret in this instance is stored. A token, an
            // invitation and a recovery code are all *verified*: the plaintext
            // arrives and is compared, so a one-way hash is enough and is
            // strictly better. A signing secret is different in kind. This
            // instance has to compute an HMAC with it on every delivery, which a
            // hash cannot do. Encryption at rest is the strongest treatment
            // available for a key that must be used rather than checked: a
            // database dump alone yields nothing without APP_KEY.
            $table->text('secret');

            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();

            $table->string('event', 64);
            $table->jsonb('payload');

            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            // Read newest-first per endpoint, and pruned by age.
            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
