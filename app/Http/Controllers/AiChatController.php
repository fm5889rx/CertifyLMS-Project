<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Section;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * App\Http\Controllers\AiChatController
 *
 * 【S-A-02 新規構築】受講生用 Gemini AI チャットボットのすべての通信と画面遷移を完全統御するコントローラー。
 *
 * フロント JS アセット（chat-client.js）完全同調仕様：
 * 非同期 HTTP クライアントが厳格に要求するレスポンス JSON キー（user_message, assistant_message,
 * conversation）および LLM エラー発生時の HTTP ステータス 502 / upstream_status のパッキング構造に
 * 100% 完全にシンクロし、本番環境でのリアルタイム Gemini 同期応答とメッセージ残存フォールバックを大開通させます。
 */
class AiChatController extends Controller
{
    private string $apiKey;

    private string $model;

    private int $dailyLimit = 50;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', '');
        $this->model = config('services.gemini.model');
        $this->dailyLimit = (int) config('services.gemini.daily_limit');
    }

    /**
     * 会話一覧および初期画面の動的切り替え表示
     */
    public function index(Request $request): View
    {
        $conversations = AiChatConversation::where('user_id', auth()->id())
            ->orderBy('last_message_at', 'desc')
            ->get();

        if ($conversations->isEmpty()) {
            return view('ai-chat.empty-state', compact('conversations'));
        }

        $conversation = $conversations->first();
        $messages = $conversation->messages;

        return view('ai-chat.show', compact('conversation', 'conversations', 'messages'));
    }

    /**
     * 会話の新規作成 (ウィジェットからの非同期作成にも対応)
     */
    public function store(Request $request): View|RedirectResponse|JsonResponse
    {
        $user = auth()->user();
        $sectionId = $request->json('section_id') ?? $request->input('section_id');

        // 既存の同じ教材スレッドへの合流チェック
        if ($sectionId) {
            $existing = AiChatConversation::where('user_id', $user->id)
                ->where('section_id', $sectionId)
                ->first();

            if ($existing) {
                // 他メンバーのモーダルが使う可能性のあるすべてのキー名（content, first_message, message）から初回入力を取り込み
                $userContent = $request->input('content') ?? ($request->json('content') ?? ($request->input('first_message') ?? $request->input('message')));

                if (! empty($userContent)) {
                    $request->merge(['content' => $userContent]);

                    return $this->sendMessage($request, $existing);
                }

                if ($request->ajax() || $request->wantsJson() || $request->isJson()) {
                    return response()->json(['status' => 'success', 'conversation' => $existing], 200);
                }

                return redirect()->route('ai-chat.conversations.show', $existing->id);
            }
        }

        $section = $sectionId ? Section::find($sectionId) : null;
        $title = $section ? '【教材相談】'.$section->title : '新しい学習相談';

        // 1. 完璧な新規会話スレッドの永続化
        $conversation = AiChatConversation::create([
            'user_id' => $user->id,
            'section_id' => $sectionId,
            'title' => Str::limit($title, 50, ''),
            'auto_title_enabled' => (bool) ($request->json('auto_title_enabled') ?? $request->input('auto_title_enabled', true)),
            'last_message_at' => Carbon::now(),
        ]);

        // 飛び込んでくる可能性のあるすべてのキー名を抱き合わせでGrepする
        $userContent = $request->input('content')
            ?? ($request->json('content')
                ?? ($request->input('first_message')
                    ?? $request->input('message')));

        // 2. もし文字が実在していれば、リクエスト空間を正しい 'content' 型に統一して本丸へ射出
        if (! empty($userContent)) {
            $request->merge(['content' => $userContent]);

            return $this->sendMessage($request, $conversation);
        }

        // 万が一フロントが何も送ってこなかった場合、更地画面を返さず初期メッセージを自動生成
        if (empty($userContent)) {
            $fallbackPrompt = $section
                ? "教材「{$section->title}」について、理解を深めるための重要な要点を教えてください！"
                : "こんにちは！資格「{$user->qualification_name}」の効率の良い学習方法についてアドバイスをお願いします！";

            $request->merge(['content' => $fallbackPrompt]);

            return $this->sendMessage($request, $conversation);
        }

        if ($request->ajax() || $request->wantsJson() || $request->isJson()) {
            return response()->json(['status' => 'success', 'conversation' => $conversation], 201);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation->id);
    }

    /**
     * 会話の詳細（過去ログ）表示 / ウィジェットからの過去履歴復元(245行目付近)
     */
    public function show(Request $request, string $id): View|RedirectResponse|JsonResponse
    {
        $conversation = AiChatConversation::findOrFail($id);

        if ($request->has('content') && ! empty($request->input('content'))) {
            return $this->sendMessage($request, $conversation);
        }

        $conversations = AiChatConversation::where('user_id', auth()->id())
            ->orderBy('last_message_at', 'desc')
            ->get();

        // floating-widget.js の 「role」が「assistant」に自動マップされるため、
        // データベース上の「model」という文字列を、フロントの期待値である「assistant」へ動的変換して返却
        $messages = $conversation->messages->map(function ($msg) {
            return [
                'id' => $msg->id,
                'role' => $msg->role->value === 'model' ? 'assistant' : 'user',
                'status' => $msg->status->value,
                'content' => $msg->content,
                'response_time_ms' => $msg->response_time_ms,
                'output_tokens' => $msg->output_tokens,
                'created_at' => $msg->created_at->toISOString(),
            ];
        });

        // floating-widget.js（loadConversationHistory）から非同期 fetch が届いた場合は JSON を返却
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['messages' => $messages]);
        }

        $messagesOriginal = $conversation->messages;

        return view('ai-chat.show', compact('conversation', 'conversations', 'messagesOriginal'));
    }

    /**
     * 会話タイトル（見出し）の手動編集
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $conversation = AiChatConversation::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:100',
        ]);

        $conversation->update([
            'title' => $request->input('title'),
            'auto_title_enabled' => false,
        ]);

        return response()->json(['message' => '見出しを更新しました。', 'title' => $conversation->title]);
    }

    /**
     * 会話の削除
     */
    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $conversation = AiChatConversation::findOrFail($id);
        $conversation->delete();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => '会話を削除しました。']);
        }

        return redirect()->route('ai-chat.index')
            ->with('success', '会話を削除しました。');
    }

    /**
     * メッセージの送信 ＆ Gemini API 同期通信
     */
    public function sendMessage(Request $request, AiChatConversation $conversation): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        // 1. 非機能要件: 受講生1人あたりの日次レート制限チェック
        $todayMessageCount = AiChatMessage::whereHas('conversation', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->where('role', AiChatMessageRole::User)
            ->where('created_at', '>=', Carbon::today())
            ->count();

        if ($todayMessageCount >= $this->dailyLimit) {
            if ($request->ajax() || $request->wantsJson() || $request->isJson()) {
                return response()->json(['error' => "本日のAI相談回数の上限（{$this->dailyLimit}回）に達しました。明日再度お試しください。"], 429);
            }

            return redirect()->back()->withErrors(['error' => "本日のAI相談回数の上限（{$this->dailyLimit}回）に達しました。明日再度お試しください。"]);
        }

        // 非同期（POST JSON）と通常送信の両方から content を確実に吸引
        $userContent = $request->json('content') ?? $request->input('content');

        if (empty($userContent)) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => '内容が空です。'], 422);
            }

            return redirect()->route('ai-chat.conversations.show', $conversation->id);
        }

        // 2. 受講生の質問メッセージを先行保存 (失敗時も残す防衛線)
        $userMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'model_name' => $this->model,
            'content' => $userContent,
        ]);

        // 3. コンテキスト自動付与の組み立て (システムプロンプトのパッキング)
        $systemInstruction = "あなたは優秀な学習伴走AIアシスタントです。受講生の疑問を即座に解消し、学習継続率を高めてください。\n";
        if (! empty($user->qualification_name)) {
            $systemInstruction .= "【受講生情報】現在、この受講生は資格「{$user->qualification_name}」の合格を目指して勉強しています。この資格の文脈に沿った的確なアドバイスを行ってください。\n";
        }
        if ($conversation->section_id && $conversation->section) {
            $systemInstruction .= "【現在の教材文脈】現在、受講生は教材「{$conversation->section->title}」を閲覧中に詰まって質問しています。この教材の内容や文脈を前提として回答してください。\n";
        }

        // 直近の会話履歴の引き継ぎ (履歴件数制御: 直近10件に切り詰め)
        $historyMessages = AiChatMessage::where('ai_chat_conversation_id', $conversation->id)
            ->where('status', AiChatMessageStatus::Completed)
            ->orderBy('created_at', 'asc')
            ->take(9)
            ->get();

        // データベースから取得した会話履歴を配列化
        $contents = [];
        foreach ($historyMessages as $msg) {
            $contents[] = [
                'role' => $msg->role === AiChatMessageRole::User ? 'user' : 'model',
                'parts' => [['text' => $msg->content]],
            ];
        }

        // 最後の質問を配列に追加
        $contents[] = [
            'role' => AiChatMessageRole::User->value,
            'parts' => [['text' => $userMessage->content]],
        ];

        // 4. 【本番環境直撃】Gemini API エンドポイントへの同期通信リクエストを発射
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $startTime = microtime(true);

        try {
            // 💡 外部へのパケット発射とその成否チェック「だけ」をこのブロックで行います
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

            Log::info('$response', ['status' => $response->status(), 'body' => $response->json()]);

            $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->failed()) {
                throw new \Exception('Gemini API HTTP Error Status: '.$response->status());
            }

            // 通信成功時は、生のレスポンス配列を変数に受け止めて、速やかに try を脱出！
            $result = $response->json();
            Log::info('$result', ['body' => $result]);
            $aiResponseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '回答を取得できませんでした。';
            $candidatesTokens = $result['usageMetadata']['candidatesTokenCount'] ?? 0;

            // 💡 成功マークを立てる
            $apiStatus = AiChatMessageStatus::Completed;

        } catch (\Exception $e) {
            Log::error('Gemini APIの本番同期通信に失敗しました。', ['error' => $e->getMessage()]);

            $responseTimeMs = isset($startTime) ? (int) round((microtime(true) - $startTime) * 1000) : 0;
            $statusCode = $e->getCode() > 0 ? $e->getCode() : 500;

            // 8. 【物理層エラーロールの完全アジャスト】
            $aiMessage = AiChatMessage::create([
                'ai_chat_conversation_id' => $conversation->id,
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Error,
                'content' => '', // エラー時は本文を空文字にする
                'model_name' => $this->model,
                'error_detail' => $e->getMessage(), // 429や502のエラー文字を注入
                'response_time_ms' => $responseTimeMs,
            ]);

            $conversation->update(['last_message_at' => Carbon::now()]);

            // chat-client.js の要求インターフェース（502 / upstream_status）への完全適合返却
            if ($request->ajax() || $request->wantsJson() || $request->isJson()) {

                $userRoleValue = $userMessage->role->value;
                $userStatusValue = $userMessage->status->value;
                $assistantRoleValue = $aiMessage->role->value;
                $aiStatusValue = $aiMessage->status->value;

                return response()->json([
                    'status' => 'error',
                    'upstream_status' => $statusCode,
                    'user_message' => [
                        'id' => $userMessage->id,
                        'role' => $userRoleValue,
                        'content' => $userMessage->content,
                        'status' => $userStatusValue,
                        'created_at' => $userMessage->created_at->toISOString(),
                    ],
                    'assistant_message' => [
                        'id' => $aiMessage->id,
                        'role' => $assistantRoleValue,
                        'content' => 'AIからの応答取得に失敗しました。',
                        'status' => $aiStatusValue,
                        'created_at' => $aiMessage->created_at->toISOString(),
                    ],
                ], 502);
            }

            return redirect()->route('ai-chat.conversations.show', $conversation->id)->withErrors(['error' => 'AIの応答取得に失敗しました。']);
        }

        // ============================================================
        // 5. 安全な外側の世界：AIの応答データを Eloquent で永続化
        // ============================================================
        $aiMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => $apiStatus->value,
            'content' => $aiResponseText,
            'error_detail' => $apiStatus === AiChatMessageStatus::Error ? 'API通信失敗' : null,
            'model_name' => $this->model,
            'output_tokens' => $candidatesTokens,
            'response_time_ms' => $responseTimeMs,
        ]);

        // 6. 最終メッセージ時刻(last_message_at)の更新 ＆ タイトル自動要約生成
        $conversation->update(['last_message_at' => Carbon::now()]);

        if ($conversation->auto_title_enabled && count($contents) <= 2) {
            $conversation->update(['title' => Str::limit($userContent, 20, '...')]);
        }

        // 👑 7. chat-client.js の要求に同期させたレスポンスを生成
        if ($request->ajax() || $request->wantsJson() || $request->isJson()) {

            // アロー演算子（->value）を用いて、確実に生の文字列を抽出してシリアライズエラーを完全防止！
            $userRoleValue = $userMessage->role->value;
            $userStatusValue = $userMessage->status->value;
            $assistantRoleValue = $aiMessage->role->value;
            $aiStatusValue = $aiMessage->status->value;

            return response()->json([
                'status' => 'success',
                'user_message' => [
                    'id' => $userMessage->id,
                    'role' => $userRoleValue,
                    'content' => $userMessage->content,
                    'status' => $userStatusValue,
                    'created_at' => $userMessage->created_at->toISOString(),
                ],
                'assistant_message' => [
                    'id' => $aiMessage->id,
                    'role' => $assistantRoleValue,
                    'content' => $aiMessage->content,
                    'status' => $aiStatusValue,
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
            ]);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation->id)
            ->with('success', '質問を送信しました。');
    }
}
