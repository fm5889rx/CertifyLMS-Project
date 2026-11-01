<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\AiChat;

use App\Actions\AiChat\SendAiMessageAction;
use App\Models\User;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Enums\AiChatMessageRole;
use App\Exceptions\Google\GoogleApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // 💡 【重要】テスト実行空間の設定値を「最優先」で確実にモックバインド
        config(['ai-chat.gemini.api_key' => 'mock-api-key']);
        config(['ai-chat.gemini.model' => 'gemini-2.5-flash']);
        config(['ai-chat.gemini.daily_limit' => 2]);

        // 設定値が完全にメモリ上に展開された後にActionをインスタンス化
        $this->action = new SendAiMessageAction();
        
        $this->student = User::factory()->create(['role' => \App\Enums\UserRole::Student]);
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
    public function test_GeminiのA_P_Iキーが未設定の環境では送信が即座に拒絶され指定された日本語案内が返されること(): void
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
        // 💡 【重要】環境変数に依存せず、config()の現在値を型安全に抽出
        // 💡 万が一空文字だった場合もテーブル制約を満たすようフォールバックを配置してカバレッジを最大安全化
        $currentConfigModel = config('ai-chat.gemini.model');
        $modelName = !empty($currentConfigModel) ? (string) $currentConfigModel : 'gemini-2.5-flash';

        AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role' => AiChatMessageRole::User,
            'status' => \App\Enums\AiChatMessageStatus::Completed,
            'content' => '質問1',
            'model_name' => $modelName,
        ]);
        AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role' => AiChatMessageRole::User,
            'status' => \App\Enums\AiChatMessageStatus::Completed,
            'content' => '質問2',
            'model_name' => $modelName,
        ]);

        Http::fake();

        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('本日のAI相談回数の上限（2回）に達しました。明日再度お試しください。');

        $this->action->execute($this->student, $this->conversation, '3回目の質問');
        Http::assertNothingSent();
    }
}
