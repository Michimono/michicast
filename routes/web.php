<?php

use App\Http\Controllers\AcceptedInvitationController;
use App\Http\Controllers\ActiveProjectController;
use App\Http\Controllers\ArchivedProjectController;
use App\Http\Controllers\CurrentOrganizationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberRoleController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationSettingController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PasswordResetLinkController;
use App\Http\Controllers\PausedProjectController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePasswordController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectScheduleController;
use App\Http\Controllers\ProjectSchedulePreviewController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/up', HealthController::class)->name('health');

Route::resource('invitation-acceptances', AcceptedInvitationController::class)
    ->only(['edit', 'update'])
    ->parameters(['invitation-acceptances' => 'token']);

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

        Route::resource('projects', ProjectController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);

        Route::singleton('projects.schedule', ProjectScheduleController::class)
            ->only(['edit', 'update'])
            ->names([
                'edit' => 'project-schedules.edit',
                'update' => 'project-schedules.update',
            ]);

        Route::match(['POST', 'PATCH'], 'projects/{project}/schedule-preview', [ProjectSchedulePreviewController::class, 'store'])
            ->name('project-schedule-previews.store');

        Route::singleton('projects.active-project', ActiveProjectController::class)
            ->creatable()
            ->only(['store'])
            ->names(['store' => 'active-projects.store']);

        Route::singleton('projects.paused-project', PausedProjectController::class)
            ->creatable()
            ->only(['store', 'destroy'])
            ->names([
                'store' => 'paused-projects.store',
                'destroy' => 'paused-projects.destroy',
            ]);

        Route::singleton('projects.archived-project', ArchivedProjectController::class)
            ->creatable()
            ->only(['store', 'destroy'])
            ->names([
                'store' => 'archived-projects.store',
                'destroy' => 'archived-projects.destroy',
            ]);

        Route::resource('members', MemberController::class)->only(['index', 'destroy']);

        Route::singleton('members.role', MemberRoleController::class)->only(['edit', 'update']);

        Route::resource('invitations', InvitationController::class)->only(['store', 'destroy']);

        Route::singleton('organization-settings', OrganizationSettingController::class)->only(['edit', 'update']);
    });
});
