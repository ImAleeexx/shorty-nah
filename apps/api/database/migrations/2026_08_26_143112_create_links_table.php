<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();

            // Case-sensitive: the slug alphabet uses both cases, so Abc and abc
            // are different links.
            $table->string('slug', 64);

            $table->text('destination');

            // Null means follow the instance default, so changing that default
            // moves every link that never chose for itself.
            $table->string('redirect_mode')->nullable();

            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('max_clicks')->nullable();

            // Maintained by reconciliation against the event store; the hot path
            // increments a Redis counter instead of writing here.
            $table->unsignedBigInteger('click_count')->default(0);

            $table->timestamp('disabled_at')->nullable();
            $table->string('referrer_policy')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Soft deleted so a report can still name the link after it is gone:
            // click events outlive the link and would otherwise lose their
            // context.
            $table->softDeletes();

            // Slugs are unique per domain, not globally, which is what lets two
            // domains each serve their own /launch.
            $table->unique(['domain_id', 'slug']);

            $table->index(['created_by', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
