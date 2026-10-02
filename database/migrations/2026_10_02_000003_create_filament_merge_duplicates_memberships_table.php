<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_merge_duplicates_memberships', function (Blueprint $table) {
            $table->id();
            $table->ulid('generation_id');
            $table->string('rule_id', 191);
            // HMAC of the encoded typed tuple scoped by definition, rule,
            // revision and key version. The raw matched value is never stored.
            $table->char('digest', 64);
            // Record IDs are typed strings so bigint-as-string, UUID and ULID
            // keys stay lossless.
            $table->string('record_id', 191);
            $table->string('record_id_type', 16);
            $table->timestamps();

            // One membership per record per rule per generation.
            $table->unique(['generation_id', 'rule_id', 'record_id'], 'merge_duplicates_memberships_record_unique');
            // Bucket identity. Pairs are never materialised, so a shared
            // generic value cannot produce quadratic rows.
            $table->index(['generation_id', 'rule_id', 'digest'], 'merge_duplicates_memberships_bucket_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filament_merge_duplicates_memberships');
    }
};
