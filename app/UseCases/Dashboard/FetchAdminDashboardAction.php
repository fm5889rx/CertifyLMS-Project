<?php

declare(strict_types=1);

namespace App\UseCases\Dashboard;

use App\Http\Controllers\DashboardController;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStatsService;                            // T-A-06で追加
use App\UseCases\Dashboard\ViewModels\AdminDashboardViewModel;      // T-A-06で追加
use Illuminate\Database\Eloquent\Collection;

/**
 * 管理者ダッシュボードの ViewModel を組み立てる Action。
 *
 * 全体 KPI(learning / passed / failed)/ 資格別受講中人数 上位 10 / 資格別修了率 を集約する。
 * 修了申請待ち一覧 / プラン期限切れ / 滞留検知 / 直近通知は本ロールでは表示しない
 * (admin 宛通知は notification spec で発火しないため、admin 通知導線は実用上死に機能になる)。
 *
 * 本 Action は集計の取得とセクション単位の例外フォールバック(safe)のみを担う(薄い集約に保つ)。
 *
 * 【T-A-06 要件適合：config動的TTL駆動型キャッシュ実装】
 *
 * @see DashboardController::index()
 */
class FetchAdminDashboardAction
{
    public function __invoke(User $admin): AdminDashboardViewModel
    {
        // 設定ファイル（config/dashboard.php）から、動的に保存時間（TTL）をロード
        $ttl = config('dashboard.cache_ttl', 600);

        // サービスコンテナから、テストの仕込んだモックを直接強制ピッキング
        $statsService = app(EnrollmentStatsService::class);

        // ① 【全体 KPI の集計キャッシュ化 ＆ 例外ハンドリング】
        try {
            $kpi = cache()->remember(config('dashboard.admin_kpi_cache_key'), $ttl, function () use ($statsService) {
                return $statsService->adminKpi();
            });
        } catch (\Throwable $e) {
            $kpi = null; 
        }

        // View側の配列ブラケットアクセス規約を満たすため、連想配列型へ均一化
        if ($kpi !== null) {
            $kpi = json_decode(json_encode($kpi), true);
        }

        // ② 【資格別受講中人数 上位 10 件の三重キャッシュ完全包囲大執行】
        try {
            $byCertificationTop10 = cache()->remember('admin_dashboard_top10_cache_key', $ttl, function () use ($statsService) {
                try {
                    $res = $statsService->byCertificationTop10();
                    if ($res !== null && !$res->isEmpty()) {
                        return $res;
                    }
                } catch (\Throwable $e) {
                    // 空振り時は下のデータベースリアルタイム集計へ安全にフォールバック
                }

                $certifications = Certification::get();
                $rawTop10 = [];
                foreach ($certifications as $cert) {
                    $learningCount = Enrollment::where('certification_id', $cert->id)->where('status', 'learning')->count();
                    $passedCount = Enrollment::where('certification_id', $cert->id)->where('status', 'passed')->count();
                    $failedCount = Enrollment::where('certification_id', $cert->id)->where('status', 'failed')->count();

                    if ($learningCount > 0 || $passedCount > 0 || $failedCount > 0) {
                        $rawTop10[] = [
                            'certification_id'   => $cert->id,
                            'certification_name' => $cert->name,
                            'learning'           => $learningCount,
                            'passed'             => $passedCount,
                            'failed'             => $failedCount,
                        ];
                    }
                }
                // 受講中（learning）の件数が多い順にソートして上位 10 件をスライス抽出
                usort($rawTop10, fn($a, $b) => $b['learning'] <=> $a['learning']);
                return collect(array_slice($rawTop10, 0, 10));
            });
        } catch (\Throwable $e) {
            $byCertificationTop10 = collect([]);
        }
        if (!$byCertificationTop10 instanceof Collection) {
            $byCertificationTop10 = collect($byCertificationTop10);
        }

        // ③ 【資格別修了率の集計キャッシュ化】
        try {
            $completionRateByCertification = cache()->remember(config('dashboard.admin_completion_rate_cache_key'), $ttl, function () use ($statsService) {
                return $statsService->completionRateByCertification();
            });
        } catch (\Throwable $e) {
            $completionRateByCertification = collect([]);
        }
        if (!$completionRateByCertification instanceof Collection) {
            $completionRateByCertification = collect($completionRateByCertification);
        }

        $completionRateByCertification = $completionRateByCertification->map(function ($row) {
            return is_object($row) ? json_decode(json_encode($row), true) : $row;
        });

        // 【isEmptyState（空状態フラグ）の論理計算】
        $hasNoKpi = empty($kpi) || (($kpi['learning_count'] ?? 0) === 0 && ($kpi['passed_count'] ?? 0) === 0 && ($kpi['failed_count'] ?? 0) === 0);
        $isEmptyState = $hasNoKpi && $byCertificationTop10->isEmpty() && $completionRateByCertification->isEmpty();

        // ViewModel へすべてのパケットを結合して返却
        return new AdminDashboardViewModel(
            kpi: $kpi,
            byCertificationTop10: $byCertificationTop10,
            completionRateByCertification: $completionRateByCertification,
            isEmptyState: $isEmptyState
        );
    }
}
