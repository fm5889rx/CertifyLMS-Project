<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AiChat\SendAiMessageAction;
use App\Actions\AiChat\StoreConversationAction;
use App\Exceptions\Google\GoogleApiException;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Handles AI chat HTTP requests.
 */
class AiChatController extends Controller
{
    /**
     * Displays the authenticated user's conversations and most recent thread.
     */
    public function index(): View
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
     * Creates or resumes a conversation and optionally sends its initial message.
     */
    public function store(
        Request $request,
        StoreConversationAction $storeAction,
        SendAiMessageAction $sendAction,
    ): View|RedirectResponse|JsonResponse {
        $user = $request->user();
        $sectionId = $request->json('section_id') ?? $request->input('section_id');
        $content = $this->messageContent($request);
        $result = $storeAction->execute($user, [
            'section_id' => $sectionId,
            'auto_title_enabled' => $request->json('auto_title_enabled')
                ?? $request->input('auto_title_enabled', true),
        ]);
        $conversation = $result['conversation'];

        if ($result['is_merged'] && empty($content)) {
            if ($this->expectsJson($request)) {
                return response()->json(['status' => 'success', 'conversation' => $conversation], 200);
            }

            return redirect()->route('ai-chat.conversations.show', $conversation->id);
        }

        if (empty($content)) {
            $section = $sectionId ? Section::find($sectionId) : null;
            $content = $section
                ? "教材「{$section->title}」について、理解を深めるための重要な要点を教えてください！"
                : "こんにちは！資格「{$user->qualification_name}」の効率の良い学習方法についてアドバイスをお願いします！";
        }

        return $this->dispatchMessage($request, $sendAction, $conversation, $content);
    }

    /**
     * Displays a conversation or returns its message history as JSON.
     */
    public function show(Request $request, string $id, SendAiMessageAction $sendAction): View|RedirectResponse|JsonResponse
    {
        $conversation = AiChatConversation::findOrFail($id);

        if ($request->has('content') && ! empty($request->input('content'))) {
            return $this->dispatchMessage($request, $sendAction, $conversation, (string) $request->input('content'));
        }

        $conversations = AiChatConversation::where('user_id', auth()->id())
            ->orderBy('last_message_at', 'desc')
            ->get();

        $messages = $conversation->messages->map(function ($message) {
            return [
                'id' => $message->id,
                'role' => $message->role->value === 'model' ? 'assistant' : 'user',
                'status' => $message->status->value,
                'content' => $message->content,
                'response_time_ms' => $message->response_time_ms,
                'output_tokens' => $message->output_tokens,
                'created_at' => $message->created_at->toISOString(),
            ];
        });

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['messages' => $messages]);
        }

        $messagesOriginal = $conversation->messages;

        return view('ai-chat.show', compact('conversation', 'conversations', 'messagesOriginal'));
    }

    /**
     * Updates a conversation title and disables automatic title generation.
     */
    public function update(UpdateConversationRequest $request, string $id): JsonResponse
    {
        $conversation = AiChatConversation::findOrFail($id);

        $conversation->update([
            'title' => $request->validated('title'),
            'auto_title_enabled' => false,
        ]);

        return response()->json(['message' => '見出しを更新しました。', 'title' => $conversation->title]);
    }

    /**
     * Deletes a conversation and returns JSON or a browser redirect.
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
     * Sends a message and maps AI service results to the HTTP response.
     */
    public function sendMessage(
        Request $request,
        AiChatConversation $conversation,
        SendAiMessageAction $action,
    ): JsonResponse|RedirectResponse {
        try {
            $result = $action->execute($request->user(), $conversation, (string) $request->input('content', ''));
        } catch (GoogleApiException $exception) {
            if ($this->expectsJson($request)) {
                return response()->json(['error' => $exception->getMessage()], $exception->getCode() ?: 502);
            }

            if ($exception->getCode() === 422) {
                return redirect()->route('ai-chat.conversations.show', $conversation->id);
            }

            return redirect()->back()->withErrors(['error' => $exception->getMessage()]);
        }

        if ($this->expectsJson($request)) {
            return response()->json($result, $result['status'] === 'error' ? 502 : 200);
        }

        if ($result['status'] === 'error') {
            return redirect()->route('ai-chat.conversations.show', $conversation->id)
                ->withErrors(['error' => 'AIの応答取得に失敗しました。']);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation->id)
            ->with('success', '質問を送信しました。');
    }

    /**
     * Dispatches a message through the shared send-message endpoint.
     */
    private function dispatchMessage(
        Request $request,
        SendAiMessageAction $action,
        AiChatConversation $conversation,
        string $content,
    ): JsonResponse|RedirectResponse {
        $request->merge(['content' => $content]);

        return $this->sendMessage($request, $conversation, $action);
    }

    /**
     * Reads message content from supported request payload keys.
     */
    private function messageContent(Request $request): ?string
    {
        $content = $request->json('content')
            ?? $request->input('content')
            ?? $request->input('first_message')
            ?? $request->input('message');

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * Determines whether the request expects a JSON response.
     */
    private function expectsJson(Request $request): bool
    {
        return $request->ajax() || $request->wantsJson() || $request->isJson();
    }
}
