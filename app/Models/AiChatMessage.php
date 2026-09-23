<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatMessage extends Model
{
    use HasUlids;

    protected $table = 'ai_chat_messages';

    protected $fillable = [
        'ai_chat_conversation_id',
        'role',
        'status',
        'content',
        'error_detail',
        'response_time_ms',
        'output_tokens',
        'model_name',
    ];

    protected $casts = [
        // 文字列ではなく、Enumクラスへ自動キャスト
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'ai_chat_conversation_id');
    }
}
