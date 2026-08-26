<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            // Keys come from a registry, never from request input, so the key is
            // the natural primary key.
            $table->string('key')->primary();

            // Sensitive values are stored encrypted, which makes them longer than
            // their plaintext and unsuitable for a short column.
            $table->text('value')->nullable();

            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
