<?php

declare(strict_types=1);

namespace App\Actions\AiChat;

use App\Models\User;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\Google\GoogleApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

final class SendAiMessageAction
{
    /**
     * メッセージを先行保存し、Gemini API同期通信を実行する
     * 
     * @throws GoogleApiException
     */
    public function execute(User $user, AiChatConversation $conversation, string $userContent): array
    {
        // 【完全適合】config/ai-chat.php の階層構造（gemini.xxx）から確実に値を吸引
        $apiKey = config('ai-chat.gemini.api_key', '');
        $model = config('ai-chat.gemini.model', 'gemini-2.5-flash');
        $dailyLimit = (int) config('ai-chat.gemini.daily_limit', 50);

        // 【受け入れ条件完全適合ガード】API キーが未設定の環境では「送信に失敗しました。」を即座に投げる
        if (empty($apiKey)) {
            throw new GoogleApiException('送信に失敗しました。');
        }

        // 日次送信上限超過の厳格チェック
        $todayMessageCount = AiChatMessage::whereHas('conversation', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->where('role', AiChatMessageRole::User)
            ->where('created_at', '>=', Carbon::today())
            ->count();

        if ($todayMessageCount >= $dailyLimit) {
            throw new GoogleApiException("本日のAI相談回数の上限（{$dailyLimit}回）に達しました。明日再度お試しください。");
        }

        if (empty($userContent)) {
            throw new GoogleApiException('内容が空です。');
        }

        // 受講生の質問メッセージを先行保存 (失敗時も残す防衛線)
        $userMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'model_name' => $model,
            'content' => $userContent,
        ]);

        // コンテキスト自動付与（資格名および教材文脈のパッキング）
        $systemInstruction = "あなたは優秀な学習伴走AIアシスタントです。受講生の疑問を即座に解消し、学習継続率を高めてください。\n";
        if (! empty($user->qualification_name)) {
            $systemInstruction .= "【受講生情報】現在、この受講生は資格「{$user->qualification_name}」の合格を目指して勉強しています。この資格の文脈に沿った的確なアドバイスを行ってください。\n";
        }
        if ($conversation->section_id && $conversation->section) {
            $systemInstruction .= "【現在の教材文脈】現在、受講生は教材「{$conversation->section->title}」を閲覧中に詰まって質問しています。この教材の内容や文脈を前提として回答してください。\n";
        }

        $historyMessages = AiChatMessage::where('ai_chat_conversation_id', $conversation->id)
            ->where('status', AiChatMessageStatus::Completed)
            ->orderBy('created_at', 'asc')
            ->take(9)
            ->get();

        $contents = [];
        foreach ($historyMessages as $msg) {
            $contents[] = [
                'role' => $msg->role === AiChatMessageRole::User ? 'user' : 'model',
                'parts' => [['text' => $msg->content]],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage->content]],
        ];

        // 外部へのパケット発射
        $url = "https://googleapis.com{$model}:generateContent?key={$apiKey}";
        $startTime = microtime(true);

        try {
            $response = Http::asJson()->post($url, [
                'systemInstruction' => [
                    'parts' => [['text' => $systemInstruction]],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'maxOutputTokens' => 1000,
                    'temperature' => 0.7,
                ],
            ]);

            $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->failed()) {
                throw new Exception('Gemini API HTTP Error: ' . $response->status());
            }

            $result = $response->json();
            $aiResponseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '回答を取得できませんでした。';
            $candidatesTokens = $result['usageMetadata']['candidatesTokenCount'] ?? 0;

            // AIの応答データをEloquentで保存
            $aiMessage = AiChatMessage::create([
                'ai_chat_conversation_id' => $conversation->id,
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Completed,
                'content' => $aiResponseText,
                'model_name' => $model,
                'output_tokens' => $candidatesTokens,
                'response_time_ms' => $responseTimeMs,
            ]);

            $conversation->update(['last_message_at' => Carbon::now()]);

            if ($conversation->auto_title_enabled && count($contents) <= 2) {
                $conversation->update(['title' => Str::limit($userContent, 20, '...')]);
            }

            return [
                'status' => 'success',
                'user_message' => [
                    'id' => $userMessage->id,
                    'role' => $userMessage->role->value,
                    'content' => $userMessage->content,
                    'status' => $userMessage->status->value,
                    'created_at' => $userMessage->created_at->toISOString(),
                ],
                'assistant_message' => [
                    'id' => $aiMessage->id,
                    'role' => $aiMessage->role->value,
                    'content' => $aiMessage->content,
                    'status' => $aiMessage->status->value,
                    'response_time_ms' => $aiMessage->response_time_ms,
                    'output_tokens' => $aiMessage->output_tokens,
                    'created_at' => $aiMessage->created_at->toISOString(),
                ],
                'conversation' => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'auto_title_enabled' => $conversation->auto_title_enabled,
                    'last_message_at' => $conversation->last_message_at->toISOString(),
                ],
            ];

        } catch (Exception $e) {
            $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            // 失敗時フォールバック：エラーレコードを永続化して質問は残す
            $aiMessage = AiChatMessage::create([
                'ai_chat_conversation_id' => $conversation->id,
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Error,
                'content' => '',
                'model_name' => $model,
                'error_detail' => $e->getMessage(),
                'response_time_ms' => $responseTimeMs,
            ]);

            $conversation->update(['last_message_at' => Carbon::now()]);

            throw new GoogleApiException('AIの応答取得に失敗しました。');
        }
    }
}
