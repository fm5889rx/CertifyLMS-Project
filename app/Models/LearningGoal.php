<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningGoal extends Model
{
    use HasFactory;

    /**
     * 複数代入を許可する属性（ホワイトリスト）
     */
    protected $fillable = [
        'id',
        'enrollment_id',
        'title',
        'description',
        'target_date',
        'achieved_at',
    ];

    /**
     * 日付キャストの定義
     */
    protected $casts = [
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * 主キーが自動増分の数値（INT）ではなく「文字列（ULID）」であることを定義
     */
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * リレーション：親となる受講登録（Enrollment）への逆引き定義
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
