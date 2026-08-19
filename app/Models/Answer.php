<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids; // ULIDを使用するためのトレイトをインポート
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    use HasFactory;
    use HasUlids;  // ULIDを使用するためのトレイトを追加

    protected $keyType = 'string'; // 主キーの型を文字列に設定
    public $incrementing = false;  // 主キーの自動増分を無効化

    protected $fillable = [
        'question_id',
        'user_id',
        'body',
        'is_best',
    ];

    protected $casts = [
        'is_best' => 'boolean',
    ];

    // 1つの回答は、特定のユーザーのもの
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // 1つの回答は、特定の質問（QaThread）に紐づく
    public function question(): BelongsTo
    {
        return $this->belongsTo(QaThread::class, 'question_id');
    }

    // 1つの回答は、特定の質問（QaThread）に紐づく
    public function thread(): BelongsTo
    {
        return $this->belongsTo(QaThread::class, 'question_id');
    }

    /**
     * 追加：Bladeの「$reply->qa_thread_id」に100%対応するためのアクセサ
     * データベースにある「question_id」の値を、Bladeが求める「qa_thread_id」として返す
     */
    public function getQaThreadIdAttribute(): string
    {
        return (string) ($this->question_id ?? '');
    }
}
