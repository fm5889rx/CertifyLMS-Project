<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentNote extends Model
{
    use HasFactory;

    // テーブル名を他メンバーのマイグレーション（enrollment_notes）に同期
    protected $table = 'enrollment_notes';

    /**
     * 複数代入を許可する属性
     */
    protected $fillable = [
        'id',
        'enrollment_id',
        'user_id', // 作成したコーチまたは管理者のID
        'body',
    ];

    /**
     * 主キーが自動増分の数値（INT）ではなく「文字列（ULID）」であることを明示
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * リレーション：このメモを執筆したユーザーへの逆引き定義
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * リレーション：親となる受講登録（Enrollment）への逆引き定義
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
