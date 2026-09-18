<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `TaskStatus::Blocked` was removed from the enum, so any row still
     * carrying 'blocked' would throw a ValueError the next time it's read
     * through the model. There's no "previous status" recorded for a blocked
     * task, so — matching the same reasoning already used for unblocking —
     * any such rows fall back to 'todo'.
     */
    public function up(): void
    {
        DB::table('tasks')->where('status', 'blocked')->update(['status' => 'todo']);
    }

    /**
     * The original status can't be recovered; nothing to reverse.
     */
    public function down(): void
    {
        //
    }
};
