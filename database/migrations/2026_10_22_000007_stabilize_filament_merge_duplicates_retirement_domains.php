<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementLedgerMigrator;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection()->getName();

        // MySQL does not roll back DDL. A failed data conversion leaves the
        // marker in place, and rerunning the migration can safely resume.
        if (! Schema::hasColumn('filament_merge_duplicates_merges', 'retirement_domain_version')) {
            Schema::table('filament_merge_duplicates_merges', function (Blueprint $table): void {
                $table->unsignedTinyInteger('retirement_domain_version')->default(1)
                    ->index('merge_duplicates_merges_domain_version_index');
            });
        }

        app(RetirementLedgerMigrator::class)->migrate($connection);

        Schema::table('filament_merge_duplicates_merges', function (Blueprint $table): void {
            $table->unsignedTinyInteger('retirement_domain_version')->default(2)->change();
        });
    }

    public function down(): void
    {
        // Old code cannot resolve stable domains. Dropping the marker with any
        // ledger data present would make a rollback silently unsafe.
        if (DB::table('filament_merge_duplicates_merges')->exists()) {
            throw new RuntimeException('Retirement-domain migration cannot be rolled back while merge ledger entries exist. Keep this migration and deploy compatible code.');
        }

        Schema::table('filament_merge_duplicates_merges', function (Blueprint $table): void {
            $table->dropIndex('merge_duplicates_merges_domain_version_index');
            $table->dropColumn('retirement_domain_version');
        });
    }
};
