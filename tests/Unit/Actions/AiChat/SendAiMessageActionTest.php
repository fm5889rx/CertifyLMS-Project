<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\AiChat;

use App\Actions\AiChat\SendAiMessageAction;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\UserRole;
use App\Exceptions\Google\GoogleApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SendAiMessageActionTest extends TestCase
{
    use RefreshDatabase;

    private SendAiMessageAction $action;

    private User $student;

    private AiChatConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.gemini.api_key' => 'mock-api-key']);
        config(['ai-chat.gemini.model' => 'gemini-2.5-flash']);
        config(['ai-chat.gemini.daily_limit' => 2]);

        $this->action = new SendAiMessageAction;

        $this->student = User::factory()->create(['role' => UserRole::Student]);
        $this->conversation = AiChatConversation::create([
            'user_id' => $this->student->id,
            'title' => 'テスト会話',
            'auto_title_enabled' => true,
            'last_message_at' => now(),
        ]);
    }

    /**
     * @test
     */
    public function test_geminiの_a_p_iキーが未設定の環境では送信が即座に拒絶され指定された日本語案内が返されること(): void
    {
        config(['ai-chat.gemini.api_key' => '']);
        Http::fake();

        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('送信に失敗しました。');

        $this->action->execute($this->student, $this->conversation, '質問があります');
        Http::assertNothingSent();
    }

    /**
     * @test
     */
    public function test_受講生の日次送信上限に達した場合はメッセージ送信がブロックされること(): void
    {
        $currentConfigModel = config('ai-chat.gemini.model');
        $modelName = ! empty($currentConfigModel) ? (string) $currentConfigModel : 'gemini-2.5-flash';

        AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'content' => '質問1',
            'model_name' => $modelName,
        ]);
        AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'content' => '質問2',
            'model_name' => $modelName,
        ]);

        Http::fake();

        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('本日のAI相談回数の上限（2回）に達しました。明日再度お試しください。');

        $this->action->execute($this->student, $this->conversation, '3回目の質問');
        Http::assertNothingSent();
    }

    public function test_successful_send_persists_the_assistant_response_and_uses_the_configured_gemini_endpoint(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '模擬回答']],
                    ],
                ]],
                'usageMetadata' => ['candidatesTokenCount' => 17],
            ]),
        ]);

        $result = $this->action->execute($this->student, $this->conversation, '質問です');

        $this->assertSame('success', $result['status']);
        $this->assertSame('模擬回答', $result['assistant_message']['content']);
        $this->assertSame(17, $result['assistant_message']['output_tokens']);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $this->conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => '模擬回答',
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
        ) && $request->data()['contents'][0]['parts'][0]['text'] === '質問です');
    }

    public function test_upstream_failure_persists_the_user_message_and_returns_a_retryable_error_result(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'temporary upstream failure'], 503),
        ]);

        $result = $this->action->execute($this->student, $this->conversation, '質問です');

        $this->assertSame('error', $result['status']);
        $this->assertSame(503, $result['upstream_status']);
        $this->assertSame('AIからの応答取得に失敗しました。', $result['assistant_message']['content']);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $this->conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '質問です',
        ]);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $this->conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
        ]);
    }
}
