<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use App\Services\InvitationTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;         // T-A-05で追加
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// 【T-A-05：ShouldQueue インターフェースの契約締結】
// クラスに implements ShouldQueue を明示指定することにより、
// Laravelのメールエンジンが送信処理を自動的にバックグラウンドのデータベースキューへと退避させます。
class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // 【T-A-05で追加】
    // 一時的な障害（メールサーバーの瞬断等）を想定した、自動リトライと段階的待機（バックオフ）のマウント
    public int $tries = 3;    // 最大3回リトライ

    public int $backoff = 30; // 失敗時は30秒待機してからリトライ

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invitation->email],
            subject: 'Certify LMS への招待',
        );
    }

    public function content(): Content
    {
        $url = app(InvitationTokenService::class)->generateUrl($this->invitation);

        return new Content(
            markdown: 'emails.invitation',
            with: [
                'invitation' => $this->invitation,
                'invitedBy' => $this->invitation->invitedBy,
                'roleLabel' => $this->invitation->role->label(),
                'expiresAt' => $this->invitation->expires_at,
                'url' => $url,
            ],
        );
    }
}
