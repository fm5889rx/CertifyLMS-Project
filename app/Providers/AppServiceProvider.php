<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Stripe\PrepareCheckoutAction;
use App\Actions\Stripe\ProcessStripeWebhookAction;
use App\Models\Answer;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Policies\AnswerPolicy;
use App\Policies\QaReplyPolicy;
use App\Policies\QaThreadPolicy;
use App\View\Composers\EnrollmentSwitcherComposer;
use App\View\Composers\NotificationBadgeComposer;
use App\View\Composers\SectionPageMetaComposer;
use App\View\Composers\SidebarBadgeComposer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PrepareCheckoutAction::class, fn () => new PrepareCheckoutAction);
        $this->app->singleton(ProcessStripeWebhookAction::class, fn () => new ProcessStripeWebhookAction);
    }

    public function boot(): void
    {
        View::composer('layouts._partials.sidebar-*', SidebarBadgeComposer::class);
        View::composer('layouts._partials.topbar', NotificationBadgeComposer::class);
        View::composer('components.enrollment-switcher', EnrollmentSwitcherComposer::class);
        View::composer('learning.sections.show', SectionPageMetaComposer::class);

        Gate::define('is-student', function ($user) {
            return $user->isStudent();
        });
        Gate::define('is-coach', function ($user) {
            return $user->isCoach();
        });
        Gate::define('is-admin', function ($user) {
            return $user->isAdmin();
        });
        Gate::define('is-student-or-coach', function ($user) {
            return $user->isStudent() || $user->isCoach();
        });
        Gate::define('is-student-or-admin', function ($user) {
            return $user->isStudent() || $user->isAdmin();
        });

        Gate::policy(QaThread::class, QaThreadPolicy::class);
        Gate::policy(QaReply::class, QaReplyPolicy::class);
        Gate::policy(Answer::class, AnswerPolicy::class);

        Gate::before(function ($user, string $ability, array $arguments) {
            if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
                $target = $arguments[0] ?? null;
                if ($target instanceof Answer || $target === Answer::class) {
                    if (in_array($ability, ['create', 'update'], true)) {
                        return false;
                    }
                }

                return true;
            }
        });
    }
}
