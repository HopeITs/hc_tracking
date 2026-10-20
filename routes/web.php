<?php

use App\Http\Controllers\DonorReportExportController;
use App\Http\Controllers\DonorReportFileDownloadController;
use App\Http\Controllers\DonorReportPrintController;
use App\Http\Controllers\EmailTwoFactorController;
use App\Http\Controllers\EmployeePickerOptionsController;
use App\Http\Controllers\MarkNotificationReadController;
use App\Http\Controllers\MarkTourSeenController;
use App\Http\Controllers\MonthlyReportFileDownloadController;
use App\Http\Controllers\OpenNotificationController;
use App\Http\Controllers\ProjectFileDownloadController;
use App\Http\Controllers\ProposalFileDownloadController;
use App\Http\Controllers\ProposalFilePreviewController;
use App\Http\Controllers\ReportFileDownloadController;
use App\Http\Controllers\ReportFilePreviewController;
use App\Http\Controllers\SwitchLocaleController;
use App\Http\Controllers\TaskFileDownloadController;
use App\Http\Controllers\TaskFilePreviewController;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\RedirectProposalToStory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'))->name('home');

Route::post('locale', SwitchLocaleController::class)->middleware('throttle:locale')->name('locale.update');

// Second login step for people who turned on email codes (after the HR password).
Route::middleware('guest')->controller(EmailTwoFactorController::class)->group(function () {
    Route::get('email-two-factor-challenge', 'create')->name('email-two-factor.login');
    Route::post('email-two-factor-challenge', 'store')->middleware('throttle:two-factor')->name('email-two-factor.login.store');
    Route::post('email-two-factor-challenge/resend', 'resend')->middleware('throttle:email-two-factor-resend')->name('email-two-factor.resend');
});

Route::middleware(['auth', EnsureTwoFactorIsEnabled::class])->group(function () {
    Route::get('employees/picker-options', EmployeePickerOptionsController::class)->name('employees.picker-options');
    Route::post('tour/seen', MarkTourSeenController::class)->middleware('throttle:locale')->name('tour.seen');

    Route::livewire('home', 'pages::desk.index')->name('dashboard');

    // Open to everyone: the page lists only the viewer's own reviewer rows.
    Route::livewire('my-reviews', 'pages::reviews.index')->name('reviews.index');

    Route::livewire('executive-summary', 'pages::executive-summary.index')
        ->can('executive-summary.view')
        ->name('executive-summary.index');

    Route::livewire('calendar', 'pages::calendar.index')
        ->can('calendar.view')
        ->name('calendar.index');

    Route::livewire('strategies', 'pages::strategies.index')
        ->can('strategies.view')
        ->name('strategies.index');

    Route::livewire('strategies/{strategy}', 'pages::strategies.show')
        ->can('strategies.view')
        ->name('strategies.show');

    Route::livewire('programs', 'pages::programs.index')
        ->can('programs.view')
        ->name('programs.index');

    Route::livewire('programs/{program}', 'pages::programs.show')
        ->can('programs.view')
        ->name('programs.show');

    Route::livewire('annual-plans', 'pages::annual-plans.index')
        ->can('annual-plans.view')
        ->name('annual-plans.index');

    Route::livewire('annual-plans/{annualPlan}', 'pages::annual-plans.show')
        ->can('annual-plans.view')
        ->name('annual-plans.show');

    Route::livewire('projects', 'pages::projects.index')
        ->can('projects.view')
        ->name('projects.index');

    Route::livewire('projects/{project}', 'pages::projects.show')
        ->can('projects.view')
        ->name('projects.show');

    Route::get('projects/{project}/files/{media}/download', ProjectFileDownloadController::class)
        ->can('projects.view')
        ->middleware('throttle:files')
        ->name('projects.files.download');

    Route::livewire('timeline', 'pages::timeline.index')
        ->can('projects.view')
        ->name('timeline.index');

    Route::livewire('risks', 'pages::risks.index')
        ->can('risks.view')
        ->name('risks.index');

    Route::livewire('milestones', 'pages::milestones.index')
        ->can('milestones.view')
        ->name('milestones.index');

    Route::livewire('milestones/{milestone}', 'pages::milestones.show')
        ->can('milestones.view')
        ->name('milestones.show');

    Route::livewire('results', 'pages::results.index')
        ->can('results.view')
        ->name('results.index');

    Route::livewire('indicator-library', 'pages::indicator-library.index')
        ->can('indicator-library.view')
        ->name('indicator-library.index');

    Route::livewire('indicators', 'pages::indicators.index')
        ->can('indicators.view')
        ->name('indicators.index');

    Route::livewire('targets', 'pages::targets.index')
        ->can('targets.view')
        ->name('targets.index');

    Route::livewire('tasks', 'pages::tasks.index')
        ->can('tasks.view')
        ->name('tasks.index');

    Route::livewire('department-tasks', 'pages::department-tasks.index')
        ->can('tasks.view')
        ->name('department-tasks.index');

    Route::livewire('tasks/{task}', 'pages::tasks.show')
        ->can('tasks.view')
        ->name('tasks.show');

    Route::get('tasks/{task}/files/{taskAttachment}/download', TaskFileDownloadController::class)
        ->can('tasks.view')
        ->middleware('throttle:files')
        ->name('tasks.files.download');

    Route::get('tasks/{task}/files/{taskAttachment}/preview', TaskFilePreviewController::class)
        ->can('tasks.view')
        ->middleware('throttle:files')
        ->name('tasks.files.preview');

    Route::livewire('proposals', 'pages::proposals.index')
        ->can('proposals.view')
        ->name('proposals.index');

    Route::livewire('proposals/{proposal}', 'pages::proposals.show')
        ->can('view', 'proposal')
        ->middleware(RedirectProposalToStory::class)
        ->name('proposals.show');

    Route::get('proposals/{proposal}/files/{media}/download', ProposalFileDownloadController::class)
        ->can('view', 'proposal')
        ->middleware('throttle:files')
        ->name('proposals.files.download');

    Route::get('proposals/{proposal}/files/{media}/preview', ProposalFilePreviewController::class)
        ->can('view', 'proposal')
        ->middleware('throttle:files')
        ->name('proposals.files.preview');

    Route::livewire('donors', 'pages::donors.index')
        ->can('donors.view')
        ->name('donors.index');

    Route::livewire('donors/{donor}', 'pages::donors.show')
        ->can('donors.view')
        ->name('donors.show');

    Route::livewire('funding-agreements', 'pages::funding-agreements.index')
        ->can('funding-agreements.view')
        ->name('funding-agreements.index');

    Route::livewire('funding-agreements/{donorProposal}', 'pages::funding-agreements.show')
        ->can('funding-agreements.view')
        ->name('funding-agreements.show');

    Route::livewire('donor-reports/compose', 'pages::donor-reports.compose')
        ->can('reports.aggregate')
        ->name('donor-reports.compose');

    Route::livewire('donor-reports/{donorReport}', 'pages::donor-reports.show')
        ->whereNumber('donorReport')
        ->can('view', 'donorReport')
        ->name('donor-reports.show');

    Route::get('donor-reports/{donorReport}/print', DonorReportPrintController::class)
        ->whereNumber('donorReport')
        ->can('view', 'donorReport')
        ->middleware('throttle:files')
        ->name('donor-reports.print');

    Route::get('donor-reports/{donorReport}/export', DonorReportExportController::class)
        ->whereNumber('donorReport')
        ->can('view', 'donorReport')
        ->middleware('throttle:files')
        ->name('donor-reports.export');

    Route::get('donor-reports/{donorReport}/files/{media}/download', DonorReportFileDownloadController::class)
        ->whereNumber(['donorReport', 'media'])
        ->middleware('throttle:files')
        ->name('donor-reports.files.download');

    // Open to everyone: the page lists only the reports the viewer is responsible for.
    Route::livewire('reporting-duties', 'pages::reporting-duties.index')
        ->name('reporting-duties.index');

    Route::livewire('transfers', 'pages::transfers.index')
        ->can('transfers.view')
        ->name('transfers.index');

    Route::livewire('reports', 'pages::reports.index')
        ->can('reports.view')
        ->name('reports.index');

    Route::livewire('reports/monthly-matrix', 'pages::reports.monthly-matrix')
        ->can('reports.view')
        ->name('reports.monthly-matrix');

    Route::livewire('reports/{report}', 'pages::reports.show')
        ->can('reports.view')
        ->name('reports.show');

    Route::livewire('monthly-reports', 'pages::monthly-reports.index')
        ->can('reports.view')
        ->name('monthly-reports.index');

    // The page authorizes MonthlyReportPolicy::view() in mount().
    Route::livewire('monthly-reports/{monthlyReport}', 'pages::monthly-reports.show')
        ->can('reports.view')
        ->name('monthly-reports.show');

    // The file loads through $monthlyReport->files() (contracts §13.1 item 4).
    Route::get('monthly-reports/{monthlyReport}/files/{monthlyReportFile}/download', MonthlyReportFileDownloadController::class)
        ->whereNumber('monthlyReportFile')
        ->can('reports.view')
        ->middleware('throttle:files')
        ->name('monthly-reports.files.download');

    Route::get('reports/{report}/files/{reportFile}/download', ReportFileDownloadController::class)
        ->can('reports.view')
        ->middleware('throttle:files')
        ->name('reports.files.download');

    Route::get('reports/{report}/files/{reportFile}/preview', ReportFilePreviewController::class)
        ->can('reports.view')
        ->middleware('throttle:files')
        ->name('reports.files.preview');

    Route::livewire('admin/notification-settings', 'pages::admin.notification-settings')
        ->can('notification-settings.manage')
        ->name('admin.notification-settings.edit');

    Route::livewire('activity', 'pages::activity.index')
        ->can('activity-log.view')
        ->name('activity.index');

    Route::livewire('notifications-settings', 'pages::notifications.settings')
        ->name('notification-settings.edit');

    Route::livewire('notifications', 'pages::notifications.index')
        ->name('notifications.index');

    Route::get('notifications/{notification}/open', OpenNotificationController::class)
        ->name('notifications.open');

    Route::post('notifications/{notification}/read', MarkNotificationReadController::class)
        ->name('notifications.read');

    Route::livewire('admin/users', 'pages::admin.users')
        ->can('viewAny', User::class)
        ->name('admin.users.index');

    Route::livewire('admin/cities', 'pages::admin.cities')
        ->can('cities.view')
        ->name('admin.cities.index');

    Route::livewire('admin/sectors', 'pages::admin.sectors')
        ->can('sectors.view')
        ->name('admin.sectors.index');

    Route::livewire('admin/evidence-types', 'pages::admin.evidence-types')
        ->can('evidence-types.manage')
        ->name('admin.evidence-types.index');

    Route::livewire('admin/narrative-questions', 'pages::admin.narrative-questions')
        ->can('narrative-questions.manage')
        ->name('admin.narrative-questions.index');

    Route::livewire('report-templates', 'pages::report-templates.index')
        ->can('report-templates.manage')
        ->name('report-templates.index');

    Route::livewire('admin/accounts', 'pages::admin.accounts')
        ->can('accounts.view')
        ->name('admin.accounts.index');
});

require __DIR__.'/settings.php';
