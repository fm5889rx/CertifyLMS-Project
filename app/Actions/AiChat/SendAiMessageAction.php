<?php

declare(strict_types=1);

namespace App\Actions\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\Google\GoogleApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SendAiMessageAction
{
    /**
     * メッセージを先行保存し、Gemini API同期通信を実行する
     *
     * @return array{
     *     status: string,
     *     user_message: array<string, mixed>,
     *     assistant_message: array<string, mixed>,
     *     conversation: array<string, mixed>,
     *     upstream_status?: int
     * }
     *
     * @throws GoogleApiException When the API key is missing, the daily limit is reached, or the message is empty.
     */
    public function execute(User $user, AiChatConversation $conversation, string $userContent): array
    {
        $apiKey = (string) config('ai-chat.gemini.api_key', config('services.gemini.api_key', ''));
        $model = (string) config('ai-chat.gemini.model', config('services.gemini.model', 'gemini-2.5-flash'));
        $dailyLimit = (int) config('ai-chat.gemini.daily_limit', config('services.gemini.daily_limit', 50));

        if (empty($apiKey)) {
            throw new GoogleApiException('送信に失敗しました。');
        }

        $todayMessageCount = AiChatMessage::whereHas('conversation', function ($conversationQuery) use ($user) {
            $conversationQuery->where('user_id', $user->id);
        })
            ->where('role', AiChatMessageRole::User)
            ->where('created_at', '>=', Carbon::today())
            ->count();

        if ($todayMessageCount >= $dailyLimit) {
            throw new GoogleApiException(
                "本日のAI相談回数の上限（{$dailyLimit}回）に達しました。明日再度お試しください。",
                429,
            );
        }

        if (empty($userContent)) {
            throw new GoogleApiException('内容が空です。', 422);
        }

        $userMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'model_name' => $model,
            'content' => $userContent,
        ]);

        $systemInstruction = "あなたは優秀な学習伴走AIアシスタントです。受講生の疑問を即座に解消し、学習継続率を高めてください。\n";
        if (! empty($user->qualification_name)) {
            $systemInstruction .= "【受講生情報】現在、この受講生は資格「{$user->qualification_name}」の合格を目指して勉強しています。この資格の文脈に沿った的確なアドバイスを行ってください。\n";
        }
        if ($conversation->section_id && $conversation->section) {
            $systemInstruction .= "【現在の教材文脈】現在、受講生は教材「{$conversation->section->title}」を閲覧中に詰まって質問しています。この教材の内容や文脈を前提として回答してください。\n";
        }

        $historyMessages = AiChatMessage::query()
            ->where('ai_chat_conversation_id', $conversation->id)
            ->where('status', AiChatMessageStatus::Completed)
            ->where('id', '!=', $userMessage->id)
            ->orderByDesc('created_at')
            ->take(9)
            ->get()
            ->reverse()
            ->values();

        $contents = $historyMessages->map(fn (AiChatMessage $message): array => [
            'role' => $message->role === AiChatMessageRole::User ? 'user' : 'model',
            'parts' => [['text' => $message->content]],
        ])->all();

        $contents[] = [
            'role' => AiChatMessageRole::User->value,
            'parts' => [['text' => $userMessage->content]],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
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
                throw new Exception('Gemini API HTTP Error: '.$response->status(), $response->status());
            }

            $result = $response->json();
            $aiResponseText = is_array($result)
                ? ($result['candidates'][0]['content']['parts'][0]['text'] ?? null)
                : null;
            if (! is_string($aiResponseText) || $aiResponseText === '') {
                throw new Exception('Gemini API returned an empty response.', 502);
            }

            $candidatesTokens = is_array($result)
                ? ($result['usageMetadata']['candidatesTokenCount'] ?? 0)
                : 0;

            $aiMessage = AiChatMessage::create([
                'ai_chat_conversation_id' => $conversation->id,
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Completed,
                'content' => $aiResponseText,
                'model_name' => $model,
                'output_tokens' => $candidatesTokens,
                'response_time_ms' => $responseTimeMs,
            ]);

            $conversation->last_message_at = Carbon::now();

            if ($conversation->auto_title_enabled && $historyMessages->isEmpty()) {
                $conversation->title = Str::limit($userContent, 20, '...');
            }

            $conversation->save();

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

        } catch (Exception $exception) {
            Log::error('Gemini API request failed.', ['error' => $exception->getMessage()]);

            $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            $aiMessage = AiChatMessage::create([
                'ai_chat_conversation_id' => $conversation->id,
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Error,
                'content' => '',
                'model_name' => $model,
                'error_detail' => $exception->getMessage(),
                'response_time_ms' => $responseTimeMs,
            ]);

            $conversation->update(['last_message_at' => Carbon::now()]);

            return [
                'status' => 'error',
                'upstream_status' => $exception->getCode() > 0 ? $exception->getCode() : 500,
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
                    'content' => 'AIからの応答取得に失敗しました。',
                    'status' => $aiMessage->status->value,
                    'created_at' => $aiMessage->created_at->toISOString(),
                ],
            ];
        }
    }
}
