<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_merge_duplicates_scopes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('definition_id', 191);
            $table->string('definition_revision', 64);
            $table->char('scope_hash', 64);
            $table->string('connection', 64);
            $table->string('model_alias', 191);
            $table->ulid('current_generation_id')->nullable();
            $table->timestamps();

            // No nullable column participates in this index, because NULL
            // uniqueness differs between MySQL and PostgreSQL.
            $table->unique(['definition_id', 'scope_hash'], 'merge_duplicates_scopes_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filament_merge_duplicates_scopes');
    }
};
