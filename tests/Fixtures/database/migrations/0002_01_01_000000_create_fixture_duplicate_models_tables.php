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

            // A boolean field, so the value rendering has something narrower
            // than a string to format.
            $table->boolean('verified')->nullable();

            // A date field and a backed-enum field, the other two shapes a
            // declared field can hold and have to be rendered as text.
            $table->timestamp('verified_at')->nullable();
            $table->string('status')->nullable();

            // A JSON column with no cast: a field stored here cannot be merged
            // by comparison, whichever way it was declared.
            $table->json('payload')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // A table with an ordinary (non-unique) index as well as its
            // unique ones: a scan of the indexes must not treat every index as
            // a uniqueness constraint.
            $table->index('tenant_id');
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

            // An ordinary index alongside it, so the collision scan has to skip
            // a non-unique index rather than assume uniqueness.
            $table->index('body');
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
