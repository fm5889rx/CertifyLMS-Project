<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QaThreadStatus;
use App\Models\Answer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUlids; // ULIDを使用するためのトレイトをインポート
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;
    use HasUlids;  // ULIDを使用するためのトレイトを追加

    protected $fillable = [
        'user_id',
        'certification_id',
        'title',
        'body',
        'status',
        'resolved_at',
    ];

    protected $keyType = 'string'; // ULIDは文字列として扱う
    public $incrementing = false; // 自動インクリメントを無効化

    protected $casts = [
        'status' => QaThreadStatus::class, // Enumをキャスト
        'resolved_at' => 'datetime', // resolved_atをCarbonインスタンスとして扱う
    ];

    // 1つの質問は、特定のユーザーのもの
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // 1つの質問は、複数の回答を持つ
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    // １つの質問は、特定の資格（certification）に紐づく
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class, 'certification_id');
    }

    // blade用：１つの質問は、複数の回答を持つ
    public function replies(): HasMany
    {
        return $this->hasMany(Answer::class, 'question_id');
    }
}
