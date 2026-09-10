<?php

use App\Http\Controllers\CurrentOrganizationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberRoleController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PasswordResetLinkController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePasswordController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/up', HealthController::class)->name('health');

Route::middleware('guest')->group(function (): void {
    Route::resource('users', UserController::class)->only(['create', 'store']);

    Route::singleton('session', SessionController::class)
        ->creatable()
        ->only(['create', 'store'])
        ->middlewareFor('store', 'throttle:login');

    Route::resource('password-reset-links', PasswordResetLinkController::class)
        ->only(['create', 'store'])
        ->middlewareFor('store', 'throttle:password-reset');

    Route::resource('passwords', PasswordController::class)
        ->only(['edit', 'update'])
        ->parameters(['passwords' => 'token'])
        ->middlewareFor('update', 'throttle:password-reset');
});

Route::middleware('auth')->group(function (): void {
    Route::singleton('session', SessionController::class)
        ->destroyable()
        ->only(['destroy']);

    Route::singleton('profile', ProfileController::class)->only(['edit', 'update']);

    Route::singleton('profile/password', ProfilePasswordController::class)
        ->only(['edit', 'update'])
        ->names('profile.password');

    Route::resource('organizations', OrganizationController::class)->only(['create', 'store']);

    Route::singleton('current-organization', CurrentOrganizationController::class)->only(['update']);

    Route::middleware(SetCurrentOrganization::class)->group(function (): void {
        Route::view('/dashboard', 'dashboard')->name('dashboard');

        Route::resource('members', MemberController::class)->only(['index']);

        Route::singleton('members.role', MemberRoleController::class)->only(['edit', 'update']);
    });
});
