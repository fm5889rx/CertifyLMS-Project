<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCertificate extends Model
{
    use HasUlids; // 主キーの自動ULIDインジェクションのためのトレイト

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'enrollment_id',
        'pdf_path',
    ];

    /**
     * 修了証を所有する受講生（Userモデル）へのリレーション
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 元となった受講登録（Enrollmentモデル）へのリレーション
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
