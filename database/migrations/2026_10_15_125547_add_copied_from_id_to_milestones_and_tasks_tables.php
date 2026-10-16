<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const Tables = ['milestones' => 'milestones_copied_from_id_foreign', 'tasks' => 'tasks_copied_from_id_foreign'];

    /**
     * Where a renewal's milestone or task was copied from (T10). A self
     * foreign key that clears when the source is deleted, so a renewal
     * outlives the proposal it renewed.
     */
    public function up(): void
    {
        foreach (self::Tables as $table => $foreignKey) {
            if (Schema::hasColumn($table, 'copied_from_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                $blueprint->unsignedBigInteger('copied_from_id')->nullable();

                $blueprint->foreign('copied_from_id', $foreignKey)
                    ->references('id')->on($blueprint->getTable())->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::Tables, true) as $table => $foreignKey) {
            if (! Schema::hasColumn($table, 'copied_from_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                $blueprint->dropForeign($foreignKey);
                $blueprint->dropColumn('copied_from_id');
            });
        }
    }
};
