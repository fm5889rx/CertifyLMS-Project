<?php

declare(strict_types=1);

/**
 * config/ai-chat.php
 *
 * 【S-A-02 新規構築】AIチャットボット（Gemini API連携）全体の有効化スイッチおよび共通構成定義ファイル。
 * 他メンバーの提供済みBladeテンプレート（app.blade / widget.blade）内の config('ai-chat.xxx') 規約と
 * データベース・コントローラー層を .env の環境変数を経由して シンクロさせます。
 */
return [

    // app.blade.php が求めている「機能ON/OFFスイッチ」
    'enabled' => (bool) env('AI_CHAT_ENABLED', true),

    // Gemini API に関する固有のパッキング構造
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),

        // component/ai-chat/floating-widget.blade.php が求めている「使用モデル名」
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

        // 1日の使用回数
        'daily_limit' => env('GEMINI_DAILY_LIMIT', 50),
    ],

];
