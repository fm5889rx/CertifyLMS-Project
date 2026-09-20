<?php

declare(strict_types=1);

// 【T-A-06運営仕様完全適合：管理者ダッシュボードキャッシュ設定マスタ】
return [
    // コーチのテストコード（）が要求してくるキャッシュキー名規約
    'admin_kpi_cache_key'             => 'admin_dashboard_kpi_cache_key',
    'admin_completion_rate_cache_key' => 'admin_dashboard_completion_rate_cache_key',

    // 設定値で調整できる保存時間（TTL）。デフォルトは 600秒（10分）として、.env からの動的制御を保証
    'cache_ttl'                       => (int) env('DASHBOARD_CACHE_TTL', 600),
];
