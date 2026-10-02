<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_merge_duplicates_scans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('scope_id');
            $table->ulid('generation_id');
            $table->string('config_revision', 64);
            $table->string('state', 16);
            // Keyset cursor only: offset paging is never used as the resume
            // mechanism because concurrent writes would shift the window.
            $table->string('cursor', 191)->nullable();
            $table->json('counters')->nullable();
            // Sanitized error code only. Raw SQL, bindings and secrets never
            // reach this column.
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['scope_id', 'state'], 'merge_duplicates_scans_scope_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filament_merge_duplicates_scans');
    }
};
