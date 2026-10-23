<?php

namespace App\Modules\Shared;

use App\Models\User;
use App\Modules\Shared\Console\BackupDatabase;
use App\Modules\Shared\Console\GenerateDocs;
use App\Modules\Shared\Console\Heartbeat;
use App\Modules\Shared\Console\RestoreCheck;
use App\Modules\Shared\Events\ApprovalRequested;
use App\Modules\Shared\Events\DocumentApproved;
use App\Modules\Shared\Events\DocumentRejected;
use App\Modules\Shared\Http\Livewire\ApprovalPanel;
use App\Modules\Shared\Http\Livewire\Comments;
use App\Modules\Shared\Http\Livewire\NotificationBell;
use App\Modules\Shared\Imports\ImporterRegistry;
use App\Modules\Shared\Listeners\NotifyApprovers;
use App\Modules\Shared\Listeners\NotifySubmitter;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\ImportBatch;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Support\BlueprintMacros;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Foundation module: tenancy, numbering, approvals, comments, audit, settings and the admin UI.
 * Later modules (Masters, CRM, Sales) build on the contracts and traits defined here.
 */
class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: state is reset for every request / queued job (Octane-safe).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(NumberSeriesService::class);
        $this->app->scoped(ApprovalService::class);

        // Importers are registered by the modules that own them; the registry itself holds no request state.
        $this->app->singleton(ImporterRegistry::class);
    }

    public function boot(): void
    {
        BlueprintMacros::register();

        // Polymorphic columns store short aliases, never class names (smaller, refactor-safe, not user-guessable).
        // Later modules add their models here as they are built.
        Relation::enforceMorphMap([
            'user' => User::class,
            'company' => Company::class,
            'location' => Location::class,
            'number_series' => NumberSeries::class,
            'approval' => Approval::class,
            'approval_level' => ApprovalLevel::class,
            'comment' => Comment::class,
            'import_batch' => ImportBatch::class,
            'role' => Role::class,
            'permission' => Permission::class,
        ]);

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // No Gate::before bypass for anyone: Super Admin is a normal role that holds every permission explicitly
        // (see RolePermissionSeeder), so its reach is visible in the matrix and every override is audited.

        // One password policy for every place a password is set.
        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers()
            ->when($this->app->isProduction(), fn (Password $rule) => $rule->uncompromised()));

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateDocs::class, Heartbeat::class, BackupDatabase::class, RestoreCheck::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('system:heartbeat')->everyMinute();

            // A nightly backup, and a weekly proof that one restores. Off until the installation turns it on (BACKUP_ENABLED=true), so a laptop is not filled with dumps.
            if (config('formezy.backup.enabled')) {
                $schedule->command('formezy:backup')->dailyAt('02:15')->withoutOverlapping();
                $schedule->command('formezy:backup --verify')->weeklyOn(0, '03:00')->withoutOverlapping();
            }
        });

        $this->registerEvents();
        $this->registerUi();
    }

    private function registerEvents(): void
    {
        Event::listen(ApprovalRequested::class, NotifyApprovers::class);
        Event::listen(DocumentApproved::class, NotifySubmitter::class);
        Event::listen(DocumentRejected::class, NotifySubmitter::class);

        // saveQuietly: a sign-in is not an audit-worthy edit of the user record.
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }

    private function registerUi(): void
    {
        Livewire::component('shared.notification-bell', NotificationBell::class);
        Livewire::component('shared.approval-panel', ApprovalPanel::class);
        Livewire::component('shared.comments', Comments::class);

        Blade::componentNamespace('App\\Modules\\Shared\\View\\Components', 'shared');

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
