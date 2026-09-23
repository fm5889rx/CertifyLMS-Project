<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\AiChatConversation
 *
 * 【S-A-02 新規構築】受講生と Gemini AI チャットボットとの「会話（スレッド）」を管理するモデル。
 *
 * 受講生(user_id)がどの教材(section_id)から相談を始めたかのコンテキスト状態を保持し、
 * 同じ教材から相談を始めた場合は会話が乱立しないよう、物理・アプリケーション層で制御を行う。
 * 会話の見出し(title)は、最初の質問内容に応じて AI が自動生成（要約）する。
 * 受講生が過去の相談履歴をすぐに見返せるよう、最終メッセージ送信時刻(last_message_at)でソートされる。
 */
class AiChatConversation extends Model
{
    use HasUlids;

    protected $table = 'ai_chat_conversations';

    protected $fillable = [
        'user_id',
        'section_id',
        'title',
        'auto_title_enabled',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'auto_title_enabled' => 'boolean',
    ];

    /**
     * オーナー（受講生）へのリレーション
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * メッセージ履歴へのリレーション
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'ai_chat_conversation_id')->orderBy('created_at', 'asc');
    }

    /**
     * 【Blade適合：その1】section リレーションを明示定義
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /**
     * 【Blade適合：その2】enrollment リレーション
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id', 'id')->withDefault();
    }

    /**
     * 【Blade適合：その3】
     * 上記の enrollment の中からさらに呼ばれる .certification チェーンの衝突を防ぐリレーション
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id', 'id')->withDefault();
    }
}
