<?php

use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Attach a means-of-verification file to a report, and return its id.
 */
function insertReportFile(int $reportId, string $path = 'report-files/2026/attendance-sheet.jpg'): int
{
    return ReportFile::factory()->create([
        'report_id' => $reportId,
        'path' => $path,
        'type' => 'image',
    ])->id;
}

/**
 * Insert a report submitted by a freshly created user, and return its id.
 */
function insertSubmittedReport(): int
{
    return insertReport(
        insertPlanIndicator(insertIndicator(insertResult()), 'yearly_plan', 1),
        User::factory()->create()->id,
    );
}

test('a verification file defaults to the date it was uploaded', function () {
    $file = DB::table('report_files')->find(insertReportFile(insertSubmittedReport()));

    expect($file->date)->toBe(DB::scalar('SELECT CURRENT_DATE'))
        ->and($file->path)->toBe('report-files/2026/attendance-sheet.jpg')
        ->and($file->type)->toBe('image');
});

test('a verification file can be backdated to the document it holds', function () {
    $id = DB::table('report_files')->insertGetId([
        'report_id' => insertSubmittedReport(),
        'path' => 'report-files/2025/signed-attendance.pdf',
        'date' => '2025-12-18',
        'type' => 'pdf',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('report_files')->find($id)->date)->toBe('2025-12-18');
});

test('deleting a report deletes its verification files', function () {
    $reportId = insertSubmittedReport();
    insertReportFile($reportId);

    DB::table('reports')->where('id', $reportId)->delete();

    $this->assertDatabaseCount('report_files', 0);
});

test('a verification file cannot be stored without a path', function () {
    DB::table('report_files')->insert([
        'report_id' => insertSubmittedReport(),
        'type' => 'image',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
