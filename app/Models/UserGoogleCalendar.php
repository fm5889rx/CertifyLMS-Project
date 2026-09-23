<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Google Calendar 連携用モデル（S-A-01追加）
 */
class UserGoogleCalendar extends Model
{
    use HasFactory, HasUlids;

    /**
     * このモデルが扱うテーブルの名前(S-A-01追加)
     * Laravelのデフォルトの命名規則では、モデル名の複数形がテーブル名として使用されますが、
     * 今回は「user_google_calendar」という名前のテーブルを使用するため、明示的に指定しています。
     *
     * @var string
     */
    protected $table = 'user_google_calendar';

    /**
     * tableの主キーの名前(S-A-01追加)
     *
     * @var string
     */
    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'google_email',
        'calendar_id',
        'connected_at',
        'access_token',
        'refresh_token',
    ];

    /**
     * tableの主キーが自動増分でないことを示す(S-A-01追加)
     *
     * @var array
     */
    public $incrementing = false;

    /**
     * tableの主キーの型を指定する(S-A-01追加)
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * 日付型のカラムを指定する(S-A-01追加)
     *
     * @var array
     */
    protected $casts = [
        'connected_at' => 'datetime',
    ];

    /**
     * Google Calendar 連携情報を持つユーザーを取得するリレーション(S-A-01追加)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**----------------------------------------------------
     * Google Calendar 連携状に関するメソッド（S-A-01追加）
     *---------------------------------------------------*/
    /**
     * Google Calendar 連携状態を確認する
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return ! is_null($this->connected_at);
    }

    /**
     * Google Calendar 連携を解除する
     */
    public function disconnect(): void
    {
        $this->connected_at = null;
        $this->access_token = null;
        $this->refresh_token = null;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する
     *
     * @param string $accessToken
     * @param string $refreshToken
     */
    public function connect(string $accessToken, string $refreshToken): void
    {
        $this->connected_at = now();
        $this->access_token = $accessToken;
        $this->refresh_token = $refreshToken;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する（トークンのみ）
     *
     * @param string $accessToken
     * @param string $refreshToken
     */
    public function updateTokens(string $accessToken, string $refreshToken): void
    {
        $this->access_token = $accessToken;
        $this->refresh_token = $refreshToken;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する（メールアドレスのみ）
     *
     * @param string $googleEmail
     */
    public function updateGoogleEmail(string $googleEmail): void
    {
        $this->google_email = $googleEmail;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する（カレンダーIDのみ）
     *
     * @param string $calendarId
     */
    public function updateCalendarId(string $calendarId): void
    {
        $this->calendar_id = $calendarId;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する（連携日時のみ）
     *
     * @param \DateTimeInterface $connectedAt
     */
    public function updateConnectedAt(\DateTimeInterface $connectedAt): void
    {
        $this->connected_at = $connectedAt;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する（アクセストークンのみ）
     *
     * @param string $accessToken
     */
    public function updateAccessToken(string $accessToken): void
    {
        $this->access_token = $accessToken;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する（リフレッシュトークンのみ）
     *
     * @param string $refreshToken
     */
    public function updateRefreshToken(string $refreshToken): void
    {
        $this->refresh_token = $refreshToken;
        $this->save();
    }

    /**
     * Google Calendar 連携情報を更新する（アクセストークン、リフレッシュトークン、連携日時）
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @param \DateTimeInterface $connectedAt
     */
    public function updateTokensAndConnectedAt(string $accessToken, string $refreshToken, \DateTimeInterface $connectedAt): void
    {
        $this->access_token = $accessToken;
        $this->refresh_token = $refreshToken;
        $this->connected_at = $connectedAt;
        $this->save();
    }
}
