<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Give every program without a slug one of its own.
     *
     * The D-68 import left `slug` NULL on programs HR no longer had (orphans
     * are never touched) and empty on programs whose HR slug was blank, so
     * `route('programs.show', $program)` had no key and the Results framework
     * page failed with "Missing parameter: program" (error FOYX8YKJ).
     *
     * The slug is built the way HasSlug builds it — from `name_en`, then the
     * code, then the id — and suffixed until unique. Existing slugs are never
     * changed: a stable URL matters more than one that matches the name.
     */
    public function up(): void
    {
        $taken = DB::table('programs')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->pluck('slug')
            ->flip()
            ->all();

        $missing = DB::table('programs')
            ->where(fn ($query) => $query->whereNull('slug')->orWhere('slug', ''))
            ->orderBy('id')
            ->get(['id', 'name_en', 'code']);

        foreach ($missing as $program) {
            $base = Str::slug((string) $program->name_en)
                ?: Str::slug((string) $program->code)
                ?: 'program-'.$program->id;

            $slug = $base;
            $suffix = 2;

            while (isset($taken[$slug])) {
                $slug = $base.'-'.$suffix++;
            }

            $taken[$slug] = true;

            DB::table('programs')->where('id', $program->id)->update(['slug' => $slug]);
        }
    }

    /**
     * The filled slugs are real URLs by now; nothing is taken back.
     */
    public function down(): void {}
};
