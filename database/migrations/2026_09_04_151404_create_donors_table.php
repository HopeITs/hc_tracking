<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Funders, with the profile needed to contact and classify them.
     *
     * `name` is deliberately NOT unique: two country offices of one funder may
     * share a name. See .ai/rules/database-migrations.md.
     *
     * Every profile column is nullable. A funder can legitimately be recorded
     * from a grant letter alone, with no named contact and no classification
     * yet, and a NOT NULL `type` with a default would invent one.
     *
     * `country` is a plain VARCHAR rather than an ISO code column: the ERD
     * carries no country table and inventing one here is out of scope.
     */
    public function up(): void
    {
        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 50)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('country', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donors');
    }
};
