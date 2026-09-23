<?php

use App\Notifications\NotificationPresenter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rendered sentence of each notification in every interface language,
 * which inbox search matches instead of the raw `data` JSON (translation
 * keys, param names and a binary collation that ignored the words people
 * read). Written by NotificationGrouper on store and merge; existing rows
 * are filled here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('data');
        });

        $presenter = app(NotificationPresenter::class);

        DB::table('notifications')->select(['id', 'data'])->lazyById(200, 'id')->each(function (object $row) use ($presenter): void {
            DB::table('notifications')->where('id', $row->id)->update([
                'search_text' => $presenter->searchText((array) json_decode($row->data, true)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
