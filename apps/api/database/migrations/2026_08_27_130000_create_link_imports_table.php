<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_imports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('queued');

            // A dry run reports every row's outcome and writes no links. It is
            // the same code path otherwise, because an import that behaves
            // differently when rehearsed is not a rehearsal.
            $table->boolean('dry_run')->default(false);

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            // The submitted rows and, once processed, each row's outcome. Kept
            // whole so the operator can download a result file that carries their
            // own input beside what happened to it.
            $table->jsonb('rows');

            $table->text('failure')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_imports');
    }
};
