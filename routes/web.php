<?php

use App\Http\Controllers\AcceptedInvitationController;
use App\Http\Controllers\ActiveProjectController;
use App\Http\Controllers\ArchivedBroadcastController;
use App\Http\Controllers\ArchivedDestinationController;
use App\Http\Controllers\ArchivedProjectController;
use App\Http\Controllers\ArchivedSourceController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\BroadcastDeliveryController;
use App\Http\Controllers\BroadcastDryRunController;
use App\Http\Controllers\BroadcastFilterController;
use App\Http\Controllers\BroadcastTransformController;
use App\Http\Controllers\BroadcastTransformPreviewController;
use App\Http\Controllers\ContentPreviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\DueProjectController;
use App\Http\Controllers\EnabledBroadcastController;
use App\Http\Controllers\EnabledDestinationController;
use App\Http\Controllers\EnabledSourceController;
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
use App\Http\Controllers\ProjectBroadcastController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDestinationController;
use App\Http\Controllers\ProjectRunController;
use App\Http\Controllers\ProjectScheduleController;
use App\Http\Controllers\ProjectSchedulePreviewController;
use App\Http\Controllers\ProjectSourceController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SourceController;
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

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::resource('organizations', OrganizationController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor('show', SetCurrentOrganization::class);

    /*
     * Everything below belongs to one organization, and says so in its URL.
     * SetCurrentOrganization turns that id into the acting organization, and
     * scoped bindings keep a child of another organization out of reach.
     */
    Route::prefix('organizations/{organization}')
        ->middleware(SetCurrentOrganization::class)
        ->scopeBindings()
        ->group(function (): void {
            Route::resource('projects', ProjectController::class)
                ->only(['index', 'create', 'store', 'show', 'edit', 'update']);

            Route::resource('projects.runs', ProjectRunController::class)
                ->only(['index', 'show'])
                ->names([
                    'index' => 'project-runs.index',
                    'show' => 'project-runs.show',
                ]);

            Route::resource('projects.broadcast-dry-runs', BroadcastDryRunController::class)
                ->only(['store'])
                ->names([
                    'store' => 'broadcast-dry-runs.store',
                ])
                ->middlewareFor('store', 'throttle:broadcast-dry-run');

            Route::singleton('projects.schedule', ProjectScheduleController::class)
                ->only(['edit', 'update'])
                ->names([
                    'edit' => 'project-schedules.edit',
                    'update' => 'project-schedules.update',
                ]);

            Route::match(['POST', 'PATCH'], 'projects/{project}/schedule-preview', [ProjectSchedulePreviewController::class, 'store'])
                ->name('project-schedule-previews.store');

            Route::singleton('projects.due-project', DueProjectController::class)
                ->creatable()
                ->only(['store'])
                ->names(['store' => 'due-projects.store']);

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

            Route::resource('projects.sources', ProjectSourceController::class)
                ->only(['index', 'create', 'store'])
                ->names([
                    'index' => 'project-sources.index',
                    'create' => 'project-sources.create',
                    'store' => 'project-sources.store',
                ]);

            Route::resource('sources', SourceController::class)->only(['edit', 'update']);

            Route::singleton('sources.enabled-source', EnabledSourceController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->names([
                    'store' => 'enabled-sources.store',
                    'destroy' => 'enabled-sources.destroy',
                ]);

            Route::singleton('sources.archived-source', ArchivedSourceController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->names([
                    'store' => 'archived-sources.store',
                    'destroy' => 'archived-sources.destroy',
                ]);

            Route::resource('projects.destinations', ProjectDestinationController::class)
                ->only(['index', 'create', 'store'])
                ->names([
                    'index' => 'project-destinations.index',
                    'create' => 'project-destinations.create',
                    'store' => 'project-destinations.store',
                ]);

            Route::resource('destinations', DestinationController::class)->only(['edit', 'update']);

            Route::singleton('destinations.enabled-destination', EnabledDestinationController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->names([
                    'store' => 'enabled-destinations.store',
                    'destroy' => 'enabled-destinations.destroy',
                ]);

            Route::singleton('destinations.archived-destination', ArchivedDestinationController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->names([
                    'store' => 'archived-destinations.store',
                    'destroy' => 'archived-destinations.destroy',
                ]);

            Route::resource('projects.broadcasts', ProjectBroadcastController::class)
                ->only(['index', 'create', 'store'])
                ->names([
                    'index' => 'project-broadcasts.index',
                    'create' => 'project-broadcasts.create',
                    'store' => 'project-broadcasts.store',
                ]);

            Route::resource('broadcasts', BroadcastController::class)->only(['edit', 'update']);

            Route::resource('broadcasts.content-previews', ContentPreviewController::class)
                ->only(['store'])
                ->names([
                    'store' => 'content-previews.store',
                ])
                ->middlewareFor('store', 'throttle:content-preview');

            Route::resource('broadcasts.deliveries', BroadcastDeliveryController::class)
                ->only(['index'])
                ->names([
                    'index' => 'broadcast-deliveries.index',
                ]);

            Route::singleton('broadcasts.filter', BroadcastFilterController::class)
                ->only(['edit', 'update'])
                ->names([
                    'edit' => 'broadcast-filters.edit',
                    'update' => 'broadcast-filters.update',
                ]);

            Route::singleton('broadcasts.transform', BroadcastTransformController::class)
                ->only(['edit', 'update'])
                ->names([
                    'edit' => 'broadcast-transforms.edit',
                    'update' => 'broadcast-transforms.update',
                ]);

            Route::match(['POST', 'PATCH'], 'broadcasts/{broadcast}/transform-preview', [BroadcastTransformPreviewController::class, 'store'])
                ->name('broadcast-transform-previews.store');

            Route::singleton('broadcasts.enabled-broadcast', EnabledBroadcastController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->names([
                    'store' => 'enabled-broadcasts.store',
                    'destroy' => 'enabled-broadcasts.destroy',
                ]);

            Route::singleton('broadcasts.archived-broadcast', ArchivedBroadcastController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->names([
                    'store' => 'archived-broadcasts.store',
                    'destroy' => 'archived-broadcasts.destroy',
                ]);

            Route::resource('members', MemberController::class)->only(['index', 'destroy']);

            Route::singleton('members.role', MemberRoleController::class)->only(['edit', 'update']);

            Route::resource('invitations', InvitationController::class)->only(['store', 'destroy']);

            Route::singleton('organization-settings', OrganizationSettingController::class)->only(['edit', 'update']);
        });
});
