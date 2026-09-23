<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementTargetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'title',
        'body',
        'target_type',
        'target_id',
        'dispatched_count',
        'created_by_user_id',
        'dispatched_at',
    ];

    /**
     * Enumオブジェクト ＆ Carbon日付オブジェクトのキャスト定義
     */
    protected $casts = [
        'target_type' => AnnouncementTargetType::class,
        'dispatched_at' => 'datetime',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * 配信者（管理者）へのリレーション
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * ターゲット資格マスターへのリレーション
     */
    public function targetCertification(): BelongsTo
    {
        return $this->belongsTo(Certification::class, 'target_id');
    }

    /**
     * 💡ターゲット個別ユーザーへのリレーション
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }
}
