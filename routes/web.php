<?php

use App\Http\Controllers\SwitchLocaleController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('locale', SwitchLocaleController::class)->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard.index')->name('dashboard');

    Route::livewire('executive-summary', 'pages::executive-summary.index')
        ->name('executive-summary.index');

    Route::livewire('strategies', 'pages::strategies.index')
        ->name('strategies.index');

    Route::livewire('strategies/{strategy}', 'pages::strategies.show')
        ->name('strategies.show');

    Route::livewire('programs', 'pages::programs.index')
        ->name('programs.index');

    Route::livewire('programs/{program}', 'pages::programs.show')
        ->name('programs.show');

    Route::livewire('yearly-plans', 'pages::yearly-plans.index')
        ->name('yearly-plans.index');

    Route::livewire('yearly-plans/{yearlyPlan}', 'pages::yearly-plans.show')
        ->name('yearly-plans.show');

    Route::livewire('projects', 'pages::projects.index')
        ->name('projects.index');

    Route::livewire('projects/{project}', 'pages::projects.show')
        ->name('projects.show');

    Route::livewire('risks', 'pages::risks.index')
        ->name('risks.index');

    Route::livewire('milestones', 'pages::milestones.index')
        ->name('milestones.index');

    Route::livewire('results', 'pages::results.index')
        ->name('results.index');

    Route::livewire('indicators', 'pages::indicators.index')
        ->name('indicators.index');

    Route::livewire('targets', 'pages::targets.index')
        ->name('targets.index');

    Route::livewire('tasks', 'pages::tasks.index')
        ->name('tasks.index');

    Route::livewire('tasks/{task}', 'pages::tasks.show')
        ->name('tasks.show');

    Route::livewire('proposals', 'pages::proposals.index')
        ->name('proposals.index');

    Route::livewire('proposals/{proposal}', 'pages::proposals.show')
        ->name('proposals.show');

    Route::livewire('donors', 'pages::donors.index')
        ->name('donors.index');

    Route::livewire('donors/{donor}', 'pages::donors.show')
        ->name('donors.show');

    Route::livewire('funding-agreements', 'pages::funding-agreements.index')
        ->name('funding-agreements.index');

    Route::livewire('funding-agreements/{donorProposal}', 'pages::funding-agreements.show')
        ->name('funding-agreements.show');

    Route::livewire('transfers', 'pages::transfers.index')
        ->name('transfers.index');

    Route::livewire('reports', 'pages::reports.index')
        ->name('reports.index');

    Route::livewire('reports/{report}', 'pages::reports.show')
        ->name('reports.show');

    Route::livewire('admin/users', 'pages::admin.users')
        ->can('viewAny', User::class)
        ->name('admin.users.index');

    Route::livewire('admin/cities', 'pages::admin.cities')
        ->name('admin.cities.index');

    Route::livewire('admin/accounts', 'pages::admin.accounts')
        ->name('admin.accounts.index');
});

require __DIR__.'/settings.php';
