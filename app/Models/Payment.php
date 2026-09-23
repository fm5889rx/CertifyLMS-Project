<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    // ULIDの自動生成機構を Eloquent で扱うためのトレイト
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'meeting_pack_id',
        'amount',
        'quantity',
        'status',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
    ];

    /**
     * 面談パックマスタへのリレーション
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }
}
