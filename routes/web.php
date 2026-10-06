<?php

use App\Http\Controllers\AcceptedInvitationController;
use App\Http\Controllers\ActiveProjectController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\ArchivedBroadcastController;
use App\Http\Controllers\ArchivedProjectController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\BroadcastDeliveryController;
use App\Http\Controllers\BroadcastDeliveryRetryController;
use App\Http\Controllers\BroadcastDryRunController;
use App\Http\Controllers\BroadcastFilterController;
use App\Http\Controllers\BroadcastLatestResendController;
use App\Http\Controllers\BroadcastMappingController;
use App\Http\Controllers\BroadcastSendLatestController;
use App\Http\Controllers\BroadcastTransformController;
use App\Http\Controllers\BroadcastTransformPreviewController;
use App\Http\Controllers\ConfirmedPasswordController;
use App\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use App\Http\Controllers\ContentPreviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryRetryController;
use App\Http\Controllers\DestinationConnectionTestController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\DueProjectController;
use App\Http\Controllers\EnabledBroadcastController;
use App\Http\Controllers\FontStylesheetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ImmediateProjectRunController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberRoleController;
use App\Http\Controllers\OrganizationArchivedBroadcastController;
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
use App\Http\Controllers\ProjectSourcePreviewController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SourceConnectionTestController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TwoFactorAuthenticationController;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\TwoFactorRecoveryCodeController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\PreventAdminPageCaching;
use App\Http\Middleware\RecordAdminSignIn;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/up', HealthController::class)->name('health');

Route::get('/fonts.css', FontStylesheetController::class)->name('fonts.stylesheet');

Route::resource('invitation-acceptances', AcceptedInvitationController::class)
    ->only(['edit', 'update'])
    ->parameters(['invitation-acceptances' => 'token']);

Route::middleware('guest')->group(function (): void {
    Route::resource('users', UserController::class)
        ->only(['create', 'store'])
        ->middlewareFor('store', 'throttle:registration');

    Route::singleton('session', SessionController::class)
        ->creatable()
        ->only(['create', 'store'])
        ->middlewareFor('store', 'throttle:login');

    Route::singleton('two-factor-challenge', TwoFactorChallengeController::class)
        ->creatable()
        ->only(['create', 'store'])
        ->middlewareFor('store', 'throttle:two-factor');

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

    Route::get('confirm-password', [ConfirmedPasswordController::class, 'create'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmedPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.confirm.store');

    Route::singleton('profile', ProfileController::class)->only(['edit', 'update']);

    Route::singleton('profile/password', ProfilePasswordController::class)
        ->only(['edit', 'update'])
        ->names('profile.password');

    Route::singleton('profile/two-factor-authentication', TwoFactorAuthenticationController::class)
        ->creatable()
        ->only(['create', 'store', 'destroy'])
        ->names('profile.two-factor-authentication');

    Route::singleton('profile/confirmed-two-factor-authentication', ConfirmedTwoFactorAuthenticationController::class)
        ->creatable()
        ->only(['store'])
        ->names('profile.confirmed-two-factor-authentication');

    Route::singleton('profile/two-factor-recovery-codes', TwoFactorRecoveryCodeController::class)
        ->creatable()
        ->only(['store'])
        ->names('profile.two-factor-recovery-codes');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::resource('organizations', OrganizationController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor('show', SetCurrentOrganization::class);

    /*
     * Everything below belongs to one organization, and says so in its URL.
     * SetCurrentOrganization turns that id into the acting organization, and
     * scoped bindings keep a child of another organization out of reach.
     *
     * Product language: a broadcast is the top-level unit. Persistence still
     * uses the Project aggregate; route params stay {project}. Eloquent
     * Broadcast (source to destination) lives under /flows.
     */
    Route::prefix('organizations/{organization}')
        ->middleware(SetCurrentOrganization::class)
        ->scopeBindings()
        ->group(function (): void {
            Route::resource('broadcasts', ProjectController::class)
                ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
                ->parameters(['broadcasts' => 'project']);

            Route::get('archived-broadcasts', [OrganizationArchivedBroadcastController::class, 'index'])
                ->name('archived-broadcasts.index');

            Route::resource('broadcasts.runs', ProjectRunController::class)
                ->only(['index', 'show'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'index' => 'broadcast-runs.index',
                    'show' => 'broadcast-runs.show',
                ]);

            Route::resource('broadcasts.broadcast-dry-runs', BroadcastDryRunController::class)
                ->only(['store'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'store' => 'broadcast-dry-runs.store',
                ])
                ->middlewareFor('store', 'throttle:broadcast-dry-run');

            Route::singleton('broadcasts.latest-resend', BroadcastLatestResendController::class)
                ->creatable()
                ->only(['store'])
                ->parameters(['broadcasts' => 'project'])
                ->names(['store' => 'broadcast-latest-resends.store'])
                ->middlewareFor('store', 'throttle:broadcast-dry-run');

            Route::singleton('broadcasts.send-latest', BroadcastSendLatestController::class)
                ->creatable()
                ->only(['store'])
                ->parameters(['broadcasts' => 'project'])
                ->names(['store' => 'broadcast-send-latests.store'])
                ->middlewareFor('store', 'throttle:broadcast-dry-run');

            Route::singleton('broadcasts.schedule', ProjectScheduleController::class)
                ->only(['edit', 'update'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'edit' => 'broadcast-schedules.edit',
                    'update' => 'broadcast-schedules.update',
                ]);

            Route::match(['POST', 'PATCH'], 'broadcasts/{project}/schedule-preview', [ProjectSchedulePreviewController::class, 'store'])
                ->name('broadcast-schedule-previews.store');

            Route::singleton('broadcasts.due-broadcast', DueProjectController::class)
                ->creatable()
                ->only(['store'])
                ->parameters(['broadcasts' => 'project'])
                ->names(['store' => 'due-broadcasts.store'])
                ->middlewareFor('store', 'throttle:broadcast-dry-run');

            Route::singleton('broadcasts.immediate-broadcast-run', ImmediateProjectRunController::class)
                ->creatable()
                ->only(['store'])
                ->parameters(['broadcasts' => 'project'])
                ->names(['store' => 'immediate-broadcast-runs.store'])
                ->middlewareFor('store', 'throttle:broadcast-dry-run');

            Route::singleton('broadcasts.active-broadcast', ActiveProjectController::class)
                ->creatable()
                ->only(['store'])
                ->parameters(['broadcasts' => 'project'])
                ->names(['store' => 'active-broadcasts.store']);

            Route::singleton('broadcasts.paused-broadcast', PausedProjectController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'store' => 'paused-broadcasts.store',
                    'destroy' => 'paused-broadcasts.destroy',
                ]);

            Route::singleton('broadcasts.archived-broadcast', ArchivedProjectController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'store' => 'archived-broadcasts.store',
                    'destroy' => 'archived-broadcasts.destroy',
                ]);

            Route::resource('broadcasts.sources', ProjectSourceController::class)
                ->only(['create', 'store'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'create' => 'broadcast-sources.create',
                    'store' => 'broadcast-sources.store',
                ]);

            Route::resource('broadcasts.source-previews', ProjectSourcePreviewController::class)
                ->only(['store'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'store' => 'broadcast-source-previews.store',
                ])
                ->middlewareFor('store', 'throttle:source-preview');

            Route::resource('sources', SourceController::class)->only(['edit', 'update', 'destroy']);

            Route::match(['POST', 'PATCH'], 'sources/{source}/connection-tests', [SourceConnectionTestController::class, 'store'])
                ->name('source-connection-tests.store')
                ->middleware('throttle:source-connection-test');

            Route::resource('broadcasts.destinations', ProjectDestinationController::class)
                ->only(['create', 'store'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'create' => 'broadcast-destinations.create',
                    'store' => 'broadcast-destinations.store',
                ]);

            Route::resource('destinations', DestinationController::class)->only(['edit', 'update', 'destroy']);

            Route::match(['POST', 'PATCH'], 'destinations/{destination}/connection-tests', [DestinationConnectionTestController::class, 'store'])
                ->name('destination-connection-tests.store')
                ->middleware('throttle:destination-connection-test');

            Route::resource('broadcasts.pipelines', ProjectBroadcastController::class)
                ->only(['index', 'create', 'store'])
                ->parameters(['broadcasts' => 'project'])
                ->names([
                    'index' => 'broadcast-pipelines.index',
                    'create' => 'broadcast-pipelines.create',
                    'store' => 'broadcast-pipelines.store',
                ]);

            Route::resource('flows', BroadcastController::class)
                ->only(['edit', 'update'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'edit' => 'flows.edit',
                    'update' => 'flows.update',
                ]);

            Route::resource('flows.content-previews', ContentPreviewController::class)
                ->only(['store'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'store' => 'content-previews.store',
                ])
                ->middlewareFor('store', 'throttle:content-preview');

            Route::resource('flows.deliveries', BroadcastDeliveryController::class)
                ->only(['index'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'index' => 'broadcast-deliveries.index',
                ]);

            Route::singleton('flows.deliveries.retry', DeliveryRetryController::class)
                ->creatable()
                ->only(['store'])
                ->parameters(['flows' => 'broadcast'])
                ->names(['store' => 'delivery-retries.store']);

            Route::resource('flows.delivery-retries', BroadcastDeliveryRetryController::class)
                ->only(['store'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'store' => 'broadcast-delivery-retries.store',
                ]);

            Route::singleton('flows.filter', BroadcastFilterController::class)
                ->only(['edit', 'update'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'edit' => 'broadcast-filters.edit',
                    'update' => 'broadcast-filters.update',
                ]);

            Route::singleton('flows.transform', BroadcastTransformController::class)
                ->only(['edit', 'update'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'edit' => 'broadcast-transforms.edit',
                    'update' => 'broadcast-transforms.update',
                ]);

            Route::match(['POST', 'PATCH'], 'flows/{broadcast}/transform-preview', [BroadcastTransformPreviewController::class, 'store'])
                ->name('broadcast-transform-previews.store');

            Route::match(['PATCH', 'POST'], 'flows/{broadcast}/mapping', [BroadcastMappingController::class, 'update'])
                ->name('broadcast-mappings.update');

            Route::post('flows/{broadcast}/mapping-preview', [BroadcastMappingController::class, 'preview'])
                ->name('broadcast-mapping-previews.store')
                ->middleware('throttle:mapping-preview');

            Route::post('flows/{broadcast}/mapping-sample', [BroadcastMappingController::class, 'sample'])
                ->name('broadcast-mapping-samples.store')
                ->middleware('throttle:mapping-preview');

            Route::singleton('flows.enabled-flow', EnabledBroadcastController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'store' => 'enabled-flows.store',
                    'destroy' => 'enabled-flows.destroy',
                ]);

            Route::singleton('flows.archived-flow', ArchivedBroadcastController::class)
                ->creatable()
                ->only(['store', 'destroy'])
                ->parameters(['flows' => 'broadcast'])
                ->names([
                    'store' => 'archived-flows.store',
                    'destroy' => 'archived-flows.destroy',
                ]);

            Route::resource('members', MemberController::class)->only(['index', 'destroy']);

            Route::singleton('members.role', MemberRoleController::class)->only(['edit', 'update']);

            Route::resource('invitations', InvitationController::class)
                ->only(['store', 'destroy'])
                ->middlewareFor('store', 'throttle:invitation');

            Route::singleton('organization-settings', OrganizationSettingController::class)->only(['edit', 'update']);

            // Soft redirects from pre-#110 project URLs.
            Route::redirect('projects', 'broadcasts', 301);

            Route::get('projects/create', function (Organization $organization) {
                return redirect()->route('broadcasts.create', $organization, 301);
            });

            Route::get('projects/{project}', function (Organization $organization, Project $project) {
                return redirect()->route('broadcasts.show', [$organization, $project], 301);
            });

            Route::get('projects/{project}/edit', function (Organization $organization, Project $project) {
                return redirect()->route('broadcasts.edit', [$organization, $project], 301);
            });
        });
});

Route::middleware([
    'auth',
    EnsurePlatformAdmin::class,
    PreventAdminPageCaching::class,
    RequirePassword::using('password.confirm', (string) config('michicast.admin.password_timeout', 900)),
    RecordAdminSignIn::class,
])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', OverviewController::class)->name('overview');

    Route::resource('audit', AuditController::class)->only(['index']);

    /*
     * Admin writes share a per-user throttle. Apply throttle:admin on
     * store/update/destroy routes as they land in later issues.
     */
});
