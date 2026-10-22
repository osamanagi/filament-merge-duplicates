<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filament_merge_duplicates_scans', function (Blueprint $table) {
            $table->index(['state', 'heartbeat_at'], 'merge_duplicates_scans_state_heartbeat_index');
            $table->index(['state', 'finished_at'], 'merge_duplicates_scans_state_finished_index');
        });
    }

    public function down(): void
    {
        Schema::table('filament_merge_duplicates_scans', function (Blueprint $table) {
            $table->dropIndex('merge_duplicates_scans_state_heartbeat_index');
            $table->dropIndex('merge_duplicates_scans_state_finished_index');
        });
    }
};
