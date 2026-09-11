<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AiChatConversation;
use Google\Service\CustomerEngagementSuite\Conversation;

/**
 * 会話のオーナー本人のみが操作できる防衛線（他受講生を403で遮断）
 */
class EnsureConversationOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. ルートパラメータから値（モデルオブジェクト、または生のID文字列）を取得
        $param = $request->route('conversation');

        if ($param) {
            // 2. 判別ロジック：もしすでにモデルオブジェクト（インスタンス）として解決されている場合
            if ($param instanceof AiChatConversation) {
                $conversation = $param;
            }
            // 3. 判別ロジック：もし生のID（ULID文字列）として飛んできた場合
            elseif (is_string($param)) {
                $conversation = AiChatConversation::find($param);
            }
            // それ以外（想定外の型）
            else {
                $conversation = null;
            }

            // 4. パラメータのパースに失敗していた時は 403 エラーを返す
            if (!$conversation) {
                abort(403, '指定された会話の解析に失敗しました。');
            }

            // 4. 安全なオーナーチェックの実行
            if ($conversation->user_id !== auth()->id()) {
                abort(403, 'この会話へのアクセス権限がありません。');
            }
        }

        return $next($request);
    }
}
