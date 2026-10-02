<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('reference')->nullable();
            $table->string('external_ref')->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('display_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fixture_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('body')->nullable();
            $table->timestamps();
            // A trashed child is the case the declared soft-delete option decides.
            $table->softDeletes();

            // A composite unique index that two transferred children can
            // collide on, which the planner must block rather than resolve.
            $table->unique(['contact_id', 'body']);
        });

        Schema::create('fixture_inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }
};
