<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAiChatEnabled
{
    /**
     * AI相談機能の全体有効化スイッチおよび受講生ロールの監査
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. 機能全体を無効化するスイッチ（.envの環境変数 AI_CHAT_ENABLED をON/OFF）
        if (! config('services.gemini.enabled', true)) {
            abort(404, 'AI相談機能は現在無効化されています。');
        }

        // 2. AI相談を利用できるのは「学習中の受講生」のみ（コーチや管理者は403）
        // ※ここでは既存システム仕様に合わせ、auth()->user()がCoachやAdminでないことを厳格に判定
        if (! auth()->check() || auth()->user()->role !== UserRole::Student) {
            abort(403, '受講生専用の機能です。');
        }

        // 既存の受講生ステータスが「学習中（in_progress）」であるかも同時に指差し確認
        if (auth()->user()->status !== UserStatus::InProgress) {
            abort(403, '現在受講中でないため、AI相談はご利用いただけません。');
        }

        return $next($request);
    }
}
