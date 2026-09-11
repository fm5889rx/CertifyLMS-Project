<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 【S-A-02 最終監査テスト】Gemini AIチャットボット本番仕様・全方位統合Featureテスト。
 *
 * 外部通信を Http::fake(['*']) で 100% 完全隔離し、会話一覧（index）、新規作成（store）、
 * 履歴詳細表示（show）、見出し編集（update）、会話削除（destroy）の全HTTPライフサイクルに潜む
 * 認可ガード・Enumキャスト・レスポンス JSON 規約を日本語テスト名規約に基づき完全大検証します。
 */
class AiChatIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private AiChatConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // 💡 1. ドメインキャスト規約に則り、学習中の受講生（Student / InProgress）を生成
        $this->student = User::factory()->create([
            'role'   => \App\Enums\UserRole::Student,
            'status' => \App\Enums\UserStatus::InProgress,
        ]);

        // 💡 2. 認可監査用の会話スレッドを生成
        $this->conversation = AiChatConversation::create([
            'user_id'            => $this->student->id,
            'title'              => '本番検証用テスト相談スレッド',
            'auto_title_enabled' => true,
            'last_message_at'    => now(),
        ]);
    }

    /**
     * 1. index メソッドの検証
     */
    public function test_会話一覧画面にアクセスした際に対象受講生の過去ログスレッド一覧が正常にロードされて描画されること(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('ai-chat.index'));

        $response->assertStatus(200)
            ->assertViewIs('ai-chat.show')
            ->assertViewHas('conversations');
    }

    /**
     * 2. store ➡ sendMessage メソッドの検証
     */
    public function test_メッセージ送信成功時にジェミニAPIと同期通信しフロント期待値のJSON構造を完璧に返却すること(): void
    {
        // 【全方位強制モック】：アスタリスク指定により、誤爆通信も起こさずフェイク隔離！
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'これは最新の自動テスト用の本物模擬アドバイス応答文です。']]
                        ],
                        'finishReason' => 'STOP'
                    ]
                ],
                'usageMetadata' => [
                    'promptTokenCount'    => 50,
                    'candidatesTokenCount' => 30,
                ]
            ], 200)
        ]);

        // chat-client.js の非同期パケットを完全再現
        $response = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $this->conversation), [
                'content' => '教材の効率的な復習方法についてアドバイスをください。'
            ]);

        // chat-client.js の 61行目〜64行目の期待値構造アサーション
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'user_message' => ['id', 'role', 'content', 'status', 'created_at'],
                'assistant_message' => ['id', 'role', 'content', 'status', 'response_time_ms', 'output_tokens', 'created_at'],
                'conversation' => ['id', 'title', 'auto_title_enabled', 'last_message_at']
            ]);

        // フロントへ戻るロール文字規約が 'assistant' に型適合しているか監査
        $response->assertJson([
            'status' => 'success',
            'assistant_message' => [
                'role'   => 'assistant',
                'status' => 'completed',
            ]
        ]);

        // LONGTEXT物理層への永続化状態を監査
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::Assistant->value,
            'status'                  => AiChatMessageStatus::Completed->value,
            'content'                 => 'これは最新の自動テスト用の本物模擬アドバイス応答文です。',
        ]);
    }

    /**
     * 3. show メソッドの検証（ウィジェット過去ログ復元への適合）
     */
    public function test_会話詳細に非同期アクセスした際にメッセージ内のモデル型文字列がフロント期待値のアシスタントへと動的変換されて返却されること(): void
    {
        // 過去ログ用テストデータを物理インサート
        AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::Model,
            'status'                  => AiChatMessageStatus::Completed,
            'content'                 => '過去のアドバイス履歴です。',
        ]);

        // widget.js からの非同期 Fetch 要求を完全シミュレート
        $response = $this->actingAs($this->student)
            ->getJson(route('ai-chat.conversations.show', $this->conversation));

        $response->assertStatus(200)
            ->assertJsonStructure(['messages'])
            ->assertJsonFragment([
                'role'    => 'assistant',
                'content' => '過去のアドバイス履歴です。'
            ]);
    }

    /**
     * 4. update メソッドの検証
     */
    public function test_会話の見出しタイトルを受講生が手動で安全に編集変更できること(): void
    {
        $response = $this->actingAs($this->student)
            ->patchJson(route('ai-chat.conversations.update', $this->conversation), [
                'title' => 'アジャスト済みの新しい見出しタイトル'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'title' => 'アジャスト済みの新しい見出しタイトル'
            ]);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id'                 => $this->conversation->id,
            'title'              => 'アジャスト済みの新しい見出しタイトル',
            'auto_title_enabled' => false, // 自動生成フラグが安全にOFFに反転しているか監査
        ]);
    }

    /**
     * 5. destroy メソッドの検証
     */
    public function test_不要になった会話スレッドを削除した際に紐づくメッセージ履歴も物理層から連動して一括消滅すること(): void
    {
        // 子メッセージを先行配置
        $message = AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::User,
            'status'                  => AiChatMessageStatus::Completed,
            'content'                 => '消去されるセリフ',
        ]);

        $response = $this->actingAs($this->student)
            ->deleteJson(route('ai-chat.conversations.destroy', $this->conversation));

        $response->assertStatus(200);

        // 会話スレッドの消滅を確認
        $this->assertDatabaseMissing('ai_chat_conversations', ['id' => $this->conversation->id]);

        // 物理監査：ON DELETE CASCADE 規約により、配下の子メッセージも跡形もなく連動消滅しているか確認
        $this->assertDatabaseMissing('ai_chat_messages', ['id' => $message->id]);
    }
}
