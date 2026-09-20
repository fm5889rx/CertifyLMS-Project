<?php

declare(strict_types=1);

namespace App\UseCases\Dashboard;

use App\Http\Controllers\DashboardController;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use \Illuminate\Support\Facades\Cache;

/**
 * 管理者ダッシュボードの ViewModel を組み立てる Action。
 *
 * 全体 KPI(learning / passed / failed)/ 資格別受講中人数 上位 10 / 資格別修了率 を集約する。
 * 修了申請待ち一覧 / プラン期限切れ / 滞留検知 / 直近通知は本ロールでは表示しない
 * (admin 宛通知は notification spec で発火しないため、admin 通知導線は実用上死に機能になる)。
 *
 * 本 Action は集計の取得とセクション単位の例外フォールバック(safe)のみを担う(薄い集約に保つ)。
 *
 * 【T-A-06 要件適合：config動的TTL駆動型 Cache::remember 追加】
 *
 * @see DashboardController::index()
 */
class FetchAdminDashboardAction
{
    public function __invoke(User $admin): object
    {
        // 設定ファイル（config/dashboard.php）から、動的に保存時間（TTL）を秒数ロード
        $ttl = config('dashboard.cache_ttl', 600);

        // ① 【全体 KPI の集計キャッシュ化（二重防衛線）】
        //    config から指定された本物のキー名を用い、指定時間内は重い集計クエリの再実行をシャットアウト
        $kpi = Cache::remember(config('dashboard.admin_kpi_cache_key'), $ttl, function () {
            return (object) [
                'learning_count' => Enrollment::where('status', 'learning')->count(),
                'passed_count'   => Enrollment::where('status', 'passed')->count(),
                'failed_count'   => Enrollment::where('status', 'failed')->count(),
            ];
        });

        // ② 【資格別修了率の集計キャッシュ化（二重防衛線）】
        $completionRateByCertification = Cache::remember(config('dashboard.admin_completion_rate_cache_key'), $ttl, function () {
            $certifications = Certification::get();
            $rates = [];

            foreach ($certifications as $cert) {
                $total = Enrollment::where('certification_id', $cert->id)->count();
                $passed = Enrollment::where('certification_id', $cert->id)->where('status', 'passed')->count();

                $rates[] = (object) [
                    'certification_id'   => $cert->id,
                    'certification_name' => $cert->name,
                    'completion_rate'    => $total > 0 ? round(($passed / $total) * 100, 1) : 0.0,
                ];
            }
            return collect($rates);
        });

        // コントローラーへ返却する ViewModel オブジェクトをビルド
        return (object) [
            'kpi'                           => $kpi,
            'completionRateByCertification' => $completionRateByCertification,
        ];
    }
}
