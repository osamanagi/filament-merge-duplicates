<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_merge_duplicates_merges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('operation_id');
            $table->ulid('scope_id');
            // Retirement identity deliberately excludes definition and panel
            // IDs so that a second definition for the same model and ownership
            // domain cannot make a retired record mergeable again.
            $table->char('retirement_domain', 64);
            $table->string('source_id', 191);
            $table->string('source_id_type', 16);
            $table->string('survivor_id', 191);
            $table->string('survivor_id_type', 16);
            $table->string('actor_ref', 191);
            $table->string('definition_revision', 64);
            // Encrypted, allowlisted audit payload. Never all model attributes,
            // never raw matched values.
            $table->text('audit_payload');
            $table->timestamp('committed_at');
            $table->timestamps();

            $table->unique('operation_id', 'merge_duplicates_merges_operation_unique');
            // Terminal ledger constraint: also the idempotency and
            // double-execution guard.
            $table->unique(['retirement_domain', 'source_id_type', 'source_id'], 'merge_duplicates_merges_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filament_merge_duplicates_merges');
    }
};
