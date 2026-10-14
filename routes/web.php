<?php

use App\Http\Controllers\EmailTwoFactorController;
use App\Http\Controllers\EmployeePickerOptionsController;
use App\Http\Controllers\MarkNotificationReadController;
use App\Http\Controllers\MarkTourSeenController;
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

    Route::livewire('home', 'pages::dashboard.index')->name('dashboard');

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
        ->can('proposals.view')
        ->name('proposals.show');

    Route::get('proposals/{proposal}/files/{media}/download', ProposalFileDownloadController::class)
        ->can('proposals.view')
        ->middleware('throttle:files')
        ->name('proposals.files.download');

    Route::get('proposals/{proposal}/files/{media}/preview', ProposalFilePreviewController::class)
        ->can('proposals.view')
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

    Route::livewire('transfers', 'pages::transfers.index')
        ->can('transfers.view')
        ->name('transfers.index');

    Route::livewire('reports', 'pages::reports.index')
        ->can('reports.view')
        ->name('reports.index');

    Route::livewire('reports/{report}', 'pages::reports.show')
        ->can('reports.view')
        ->name('reports.show');

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

    Route::livewire('admin/accounts', 'pages::admin.accounts')
        ->can('accounts.view')
        ->name('admin.accounts.index');
});

require __DIR__.'/settings.php';
