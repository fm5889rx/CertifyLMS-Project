<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAiChatEnabled
{
    /**
     * AI相談機能の全体有効化スイッチおよびロール・ステータスの厳格監査
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 【要件完全適合】config/ai-chat.php の「enabled」をダイレクトに参照
        if (! config('ai-chat.enabled', true)) {
            abort(404, 'AI相談機能は現在無効化されています。');
        }

        // AI相談を利用できるのは「学習中の受講生」のみ（他ロールは403）
        if (! auth()->check() || auth()->user()->role !== UserRole::Student) {
            abort(403, '受講生専用の機能です。');
        }

        if (auth()->user()->status !== UserStatus::InProgress) {
            abort(403, '現在受講中でないため、AI相談はご利用いただけません。');
        }

        return $next($request);
    }
}
