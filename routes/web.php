<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('executive-summary', 'pages::executive-summary.index')
        ->name('executive-summary.index');

    Route::livewire('strategies', 'pages::strategies.index')
        ->name('strategies.index');

    Route::livewire('programs', 'pages::programs.index')
        ->name('programs.index');

    Route::livewire('yearly-plans', 'pages::yearly-plans.index')
        ->name('yearly-plans.index');

    Route::livewire('admin/users', 'pages::admin.users')
        ->can('viewAny', User::class)
        ->name('admin.users.index');

    Route::livewire('admin/cities', 'pages::admin.cities')
        ->name('admin.cities.index');
});

require __DIR__.'/settings.php';
