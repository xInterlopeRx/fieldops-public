<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerAttachment;
use App\Models\CustomerEmailAddress;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Observers\BusinessActivityObserver;
use App\Observers\OutlookContactObserver;
use App\Policies\TimeEntryPolicy;
use App\Services\QueueWorkerStatus;
use App\Services\SmtpBootstrapConfiguration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $smtpConfiguration = $this->app->make(SmtpBootstrapConfiguration::class);
        $smtpConfigurationLoaded = false;
        if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
            $smtpConfigurationLoaded = $smtpConfiguration->applyStored();
        }

        Vite::prefetch(concurrency: 3);
        Gate::policy(TimeEntry::class, TimeEntryPolicy::class);

        $queueWorkerStatus = $this->app->make(QueueWorkerStatus::class);
        Queue::looping(function (Looping $event) use ($queueWorkerStatus, $smtpConfiguration, &$smtpConfigurationLoaded): void {
            $queueWorkerStatus->recordLooping($event);

            if (! $smtpConfigurationLoaded) {
                $smtpConfigurationLoaded = $smtpConfiguration->applyStored();
            }
        });

        RateLimiter::for('graph-onboarding-target-save', fn (Request $request): Limit => $this->graphOnboardingLimit($request, 5, 1, 10));
        RateLimiter::for('graph-onboarding-read-check', fn (Request $request): Limit => $this->graphOnboardingLimit($request, 3, 1, 15));
        RateLimiter::for('graph-onboarding-linkback', fn (Request $request): Limit => $this->graphOnboardingLimit($request, 3, 60, 3));
        RateLimiter::for('graph-onboarding-attestation', fn (Request $request): Limit => $this->graphOnboardingLimit($request, 5, 60, 10));
        RateLimiter::for('graph-onboarding-canary-create', fn (Request $request): Limit => $this->graphOnboardingLimit($request, 2, 60, 2));
        RateLimiter::for('graph-onboarding-canary-maintenance', fn (Request $request): Limit => $this->graphOnboardingLimit($request, 5, 60, 10));
        RateLimiter::for('graph-onboarding-finalize', fn (Request $request): Limit => $this->graphOnboardingLimit($request, 3, 60, 5));

        $observer = $this->app->make(BusinessActivityObserver::class);

        foreach ([
            Customer::class,
            CustomerAddress::class,
            Site::class,
            Project::class,
            WorkOrder::class,
            Visit::class,
            Contact::class,
            CustomerAttachment::class,
            CustomerEmailAddress::class,
            ProjectAttachment::class,
            WorkOrderAttachment::class,
        ] as $model) {
            $model::observe($observer);
        }

        Contact::observe($this->app->make(OutlookContactObserver::class));
    }

    private function graphOnboardingLimit(Request $request, int $productionAttempts, int $productionDecayMinutes, int $localAttempts): Limit
    {
        $isLocal = $this->app->environment('local');

        return Limit::perMinutes(
            $isLocal ? 1 : $productionDecayMinutes,
            $isLocal ? $localAttempts : $productionAttempts,
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
