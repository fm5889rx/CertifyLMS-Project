<?php

declare(strict_types=1);

namespace App\Providers;

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
        //
    }

    public function boot(): void
    {
        View::composer('layouts._partials.sidebar-*', SidebarBadgeComposer::class);
        View::composer('layouts._partials.topbar', NotificationBadgeComposer::class);
        View::composer('components.enrollment-switcher', EnrollmentSwitcherComposer::class);
        View::composer('learning.sections.show', SectionPageMetaComposer::class);

        /*＊
         * 質問掲示板関係のGate定義とポリシー登録
         */
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

        // ログインユーザーが管理者であれば、一律で「false」を返して処理を強制拒否（ブロック）する
        Gate::before(function ($user, string $ability) {

            if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
                // 管理者は「回答の投稿（create）」と「回答の編集（update）」の権限だけは絶対に持たせない
                if (in_array($ability, ['create', 'update'], true)) {
                    return false;
                }

                // 強制削除（delete）など、上記以外の管理権限はこれまで通り無条件で通過（true）させる
                return true;
            }
        });
    }
}
