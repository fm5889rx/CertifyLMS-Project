<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingReminderLog extends Model
{
    use HasFactory;

    /**
     * 一意なテーブル名を明示的にマウント（ステップ②のマイグレーションと完全同期）
     */
    protected $table = 'meeting_reminder_logs';

    /**
     * 複数代入を許可する属性（ホワイトリスト：最初から完璧にパッキング！）
     */
    protected $fillable = [
        'id',
        'meeting_id',
        'window', // 'eve' または 'one_hour_before'
    ];

    /**
     * 主キーが自動増分の数値（INT）ではなく「文字列（ULID）」であることを定義
     */
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * 拡張リレーション：この配信ログが紐づく親の面談モデル（Meeting）への逆引き定義
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }
}
