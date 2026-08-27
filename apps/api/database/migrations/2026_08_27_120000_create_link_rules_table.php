<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('link_id')->constrained()->cascadeOnDelete();

            // Explicit rather than implied by insertion order: rules are
            // first-match-wins, so an operator reading the list top to bottom
            // must be able to say where a visitor lands, and reordering must not
            // depend on primary keys.
            $table->unsignedSmallInteger('position');

            $table->string('kind', 32);
            $table->string('value', 255);
            $table->text('destination');

            $table->timestamps();

            $table->unique(['link_id', 'position']);
            $table->index(['link_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_rules');
    }
};
