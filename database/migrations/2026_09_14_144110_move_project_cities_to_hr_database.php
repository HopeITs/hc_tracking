<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cities are owned by the HR application (hr_db.hchr_cities). projects.city_id
     * now holds an HR city id, which no foreign key can enforce across databases,
     * so the constraint and the local cities table are dropped. Existing rows are
     * not remapped: the data at this point is demo data, reseeded against HR.
     *
     * A fresh install never had the local table or the constraint (the
     * create_cities migration was removed), so both are only dropped when found.
     */
    public function up(): void
    {
        $hasCityForeignKey = collect(Schema::getForeignKeys('projects'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['city_id']);

        if ($hasCityForeignKey) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropForeign(['city_id']);
            });
        }

        Schema::dropIfExists('cities');
    }

    /**
     * Reverse the migrations.
     *
     * Fails if projects reference city ids that do not exist in the recreated
     * table, which is every project stored against an HR city.
     */
    public function down(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('city_id')->references('id')->on('cities')->restrictOnDelete();
        });
    }
};
