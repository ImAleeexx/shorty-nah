<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            // Stored lowercased so "Launch" and "launch" are one tag rather than
            // two that look identical in a filter list.
            $table->string('name', 64)->unique();
            $table->timestamps();
        });

        Schema::create('link_tag', function (Blueprint $table): void {
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['link_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_tag');
        Schema::dropIfExists('tags');
    }
};
