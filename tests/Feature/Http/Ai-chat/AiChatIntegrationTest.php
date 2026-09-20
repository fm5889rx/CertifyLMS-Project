<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 【T-A-04 仕様完全適合】Gemini AIチャットボット本番仕様・全方位異常系・境界系統合Featureテスト。
 * 【物理層NOT NULL制約 ＆ 日次レート・空応答・プロンプト構造完全検証決定版：前半】
 */
class AiChatIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private AiChatConversation $conversation;

    protected function setUp(): void
    {
        // コンテナの完全起動
        parent::setUp();

        // 外部実通信の完全構造遮断命令
        Http::preventStrayRequests();

        // テスト間での Carbon テスト時刻の汚染を防ぐ時空同期
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Tokyo'));

        // 学習中の受講生を生成
        $this->student = User::factory()->create([
            'role'               => UserRole::Student,
            'status'             => UserStatus::InProgress,
        ]);

        // 認可監査用の会話スレッドを生成
        $this->conversation = AiChatConversation::create([
            'user_id'            => $this->student->id,
            'title'              => '本番検証用テスト相談スレッド',
            'auto_title_enabled' => true,
            'last_message_at'    => now(),
        ]);
    }

    protected function tearDown(): void
    {
        // 【Mockery メモリおよびデータベース接続の安全な解放】
        if (class_exists(\Mockery::class)) {
            \Mockery::close();
        }
        if (app()->bound('db')) {
            app('db')->disconnect();
        }
        Carbon::setTestNow([]);
        parent::tearDown();
    }

    /**
     * 1. index メソッドの正常系検証
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
     * 2. store ➡ sendMessage メソッドの正常系検証
     */
    public function test_メッセージ送信成功時にジェミニAPIと同期通信しフロント期待値のJSON構造を完璧に返却すること(): void
    {
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

        $response = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $this->conversation), [
                'content' => '教材の効率的な復習方法についてアドバイスをください。'
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'user_message' => ['id', 'role', 'content', 'status', 'created_at'],
                'assistant_message' => ['id', 'role', 'content', 'status', 'response_time_ms', 'output_tokens', 'created_at'],
                'conversation' => ['id', 'title', 'auto_title_enabled', 'last_message_at']
            ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::Assistant->value,
            'status'                  => AiChatMessageStatus::Completed->value,
            'content'                 => 'これは最新の自動テスト用の本物模擬アドバイス応答文です。',
        ]);
    }

    /**
     * 3. show メソッドの正常系検証（ウィジェット過去ログ復元への適合）
     */
    public function test_会話詳細に非同期アクセスした際にメッセージ内のモデル型文字列がフロント期待値のアシスタントへと動的変換されて返却されること(): void
    {
        // ハングアップ回避用の個別ダミーモックを設定
        Http::fake(['*' => Http::response([], 200)]);

        AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::Model,
            'status'                  => AiChatMessageStatus::Completed,
            'model_name'              => config('services.gemini.model'),
            'content'                 => '過去のアドバイス履歴です。',
        ]);

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
     * 4. update メソッドの正常系検証
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
    }

    /**
     * 5. destroy メソッドの正常系検証
     */
    public function test_不要になった会話スレッドを削除した際に紐づくメッセージ履歴も物理層から連動して一括消滅すること(): void
    {
        // ハングアップ回避用の個別ダミーモックを設定
        Http::fake(['*' => Http::response([], 200)]);

        $message = AiChatMessage::create([
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::User,
            'status'                  => AiChatMessageStatus::Completed,
            'model_name'              => config('services.gemini.model'),
            'content'                 => '消去されるセリフ',
        ]);

        $response = $this->actingAs($this->student)
            ->deleteJson(route('ai-chat.conversations.destroy', $this->conversation));

        $response->assertStatus(200);

        $this->assertDatabaseMissing('ai_chat_conversations', ['id' => $this->conversation->id]);
        $this->assertDatabaseMissing('ai_chat_messages', ['id' => $message->id]);
    }

    /**
     * 6. 【T-A-04 追加：Gemini 500系通信エラー検証】
     */
    public function test_ジェミニAPIが500系一時的エラーを返した際にコントローラーが502へアジャストして受講生メッセージのログを安全に残存フォールバック永続化すること(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Internal Server Error'], 500)
        ]);

        $response = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $this->conversation), [
                'content' => '通信エラー時の挙動をテストします。'
            ]);

        $response->assertStatus(502)
            ->assertJson([
                'status' => 'error',
                'assistant_message' => [
                    'content' => 'AIからの応答取得に失敗しました。',
                    'status'  => 'error'
                ]
            ]);

        // 物理層防衛線監査：通信が失敗しても、受講生の質問（ログ）はデータベースに確実に残っていること
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::User->value,
            'content'                 => '通信エラー時の挙動をテストします。',
        ]);
    }

    /**
     * 7. 【T-A-04 追加：Gemini 空応答・パーツ欠落境界値検証】
     */
    public function test_ジェミニAPIからの応答が空文字やパーツ欠落の不正な空応答であった場合もシステムが502エラーとして安全に検閲ハンドリングすること(): void
    {
        Http::fake([
            '://googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => []
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $this->conversation), [
                'content' => '空応答のテスト。'
            ]);

        $response->assertStatus(502);
    }

    /**
     * 8. 【T-A-04 追加：送信内容プロンプト構造検証】
     */
    public function test_ジェミニAPI送信時のプロンプト構造が他メンバーの指定したシステム指示および受講生情報文脈を完璧に内包してパッキングされていること(): void
    {
        Http::fake([
            '*' => function (Request $request) {
                $body = $request->data();
                $systemText = $body['systemInstruction']['parts']['text'] ?? '';

                $this->assertStringContainsString('クラウドアーキテクト資格', $systemText);
                $this->assertStringContainsString('あなたは優秀な学習伴走AIアシスタントです', $systemText);

                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'アドバイス文']]]
                    ]]
                ], 200);
            }
        ]);

        $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $this->conversation), [
                'content' => 'プロンプト構造検証。'
            ]);
    }

    /**
     * 9. 【T-A-04 追加：日次レート制限（429）超過境界値検証】
     */
    public function test_受講生の日次AI相談回数が設定された上限値に達した場合はGeminiへパケットを発射する前に429エラーで強制遮断すること(): void
    {
        // デフォルト制限値（50回分）のUserメッセージを一括で作成
        for ($i = 0; $i < 50; $i++) {
            AiChatMessage::create([
                'ai_chat_conversation_id' => $this->conversation->id,
                'role'                    => AiChatMessageRole::User,
                'status'                  => AiChatMessageStatus::Completed,
                'content'                 => "ダミー質問 {$i}",
                'created_at'              => now(), // Carbon::today()の判定を確実に満たします
            ]);
        }

        $response = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $this->conversation), [
                'content' => '制限を超えた51回目の質問。'
            ]);

        $response->assertStatus(429)
            ->assertJsonFragment([
                'error' => "本日のAI相談回数の上限（50回）に達しました。明日再度お試しください。"
            ]);
    }

    /**
     * 10. 【S-A-02追加要件適合検証】
     */
    public function test_環境変数で指定されたGeminiのモデル名がハードコーディングされずにデータベースのmodel_nameカラムへ動的に完全同期して永続化されること(): void
    {
        $configuredModel = config('services.gemini.model', 'gemini-2.5-flash');

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '環境変数連動テストの応答文']]]
                ]],
                'usageMetadata' => ['candidatesTokenCount' => 10]
            ], 200)
        ]);

        $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $this->conversation), [
                'content' => 'モデル名の環境変数連動テストです。'
            ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $this->conversation->id,
            'role'                    => AiChatMessageRole::Assistant->value,
            'model_name'              => $configuredModel,
        ]);
    }
}
