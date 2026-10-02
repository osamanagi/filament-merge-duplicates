<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_merge_duplicates_previews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('operation_id');
            $table->ulid('scope_id');
            $table->string('definition_id', 191);
            $table->string('panel_id', 191);
            // Typed string actor reference: audit and previews must stay
            // readable without dereferencing a host user row.
            $table->string('actor_ref', 191);
            $table->char('payload_hash', 64);
            // Encrypted merge plan. The browser only ever receives the opaque
            // operation ID.
            $table->text('plan_payload');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique('operation_id', 'merge_duplicates_previews_operation_unique');
            $table->index('expires_at', 'merge_duplicates_previews_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filament_merge_duplicates_previews');
    }
};
