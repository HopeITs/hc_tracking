<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns that name the person accountable for a record.
     *
     * @var array<string, string>
     */
    private array $assignedPeople = [
        'programs' => 'manager_id',
        'yearly_plans' => 'manager_id',
        'projects' => 'user_id',
        'milestones' => 'responsible_user_id',
        'tasks' => 'assigned_to',
    ];

    /**
     * Run the migrations.
     *
     * Managers, project owners, milestone owners and task assignees are HR
     * employees (hr_db.hchr_employees), so these columns now hold HR employee
     * ids. No foreign key can reach across databases, so the constraints to users
     * are dropped. Who did something (created_by, uploaded_by, recorded_by, report
     * and update authors) stays a tracking user. Existing rows are not remapped:
     * the data at this point is demo data, reseeded against HR.
     */
    public function up(): void
    {
        foreach ($this->assignedPeople as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropForeign([$column]);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Fails while any of these columns holds an employee id with no matching user.
     */
    public function down(): void
    {
        foreach ($this->assignedPeople as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->foreign($column)->references('id')->on('users')->restrictOnDelete();
            });
        }
    }
};
