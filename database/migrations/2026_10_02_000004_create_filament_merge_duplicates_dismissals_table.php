<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_merge_duplicates_dismissals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('scope_id');
            // Canonical hash of the sorted typed pair, so the same two records
            // always produce the same dismissal regardless of order.
            $table->char('pair_hash', 64);
            $table->json('record_ids');
            // Signatures of both records' matching inputs. An unrelated address
            // update does not invalidate a dismissal; a change to a matching
            // input does.
            $table->json('signatures');
            $table->string('config_revision', 64);
            $table->string('definition_revision', 64);
            $table->string('actor_ref', 191);
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['scope_id', 'pair_hash'], 'merge_duplicates_dismissals_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filament_merge_duplicates_dismissals');
    }
};
