<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * お知らせを一斉配信し、通知ジョブを安全にリレーするユースケース（S-B-08横断品質完全準拠版）
 */
class StoreAction
{
    /**
     * お知らせ配信ビジネスロジックの実行
     *
     * @param array<string, mixed> $validatedData フォームリクエストで検証済みのデータ
     * @param string $adminId 操作を実行した管理者のID
     * @return int 実際に配信された受講生の件数
     */
    public function execute(array $validatedData, string $adminId): int
    {
        $title = $validatedData['title'];
        $body = $validatedData['body'];
        $targetType = $validatedData['target_type'];

        $targetUserIds = [];
        $targetId = null;

        // ターゲットタイプに応じた型安全なEloquentによるユーザー抽出
        if ($targetType === AnnouncementTargetType::AllStudents->value) {
            $targetUserIds = User::where('role', UserRole::Student->value ?? UserRole::Student)
                ->pluck('id')
                ->toArray();
            $targetId = null;
        } elseif ($targetType === AnnouncementTargetType::Certification->value) {
            $targetId = $validatedData['target_certification_id'] ?? null;

            // ステータスは文字列直書きを避け、Enum型安全またはexistsクエリで抽出
            $targetUserIds = Enrollment::where('certification_id', $targetId)
                ->where('status', EnrollmentStatus::Learning->value ?? 'learning')
                ->pluck('user_id')
                ->toArray();
        } elseif ($targetType === AnnouncementTargetType::User->value) {
            $targetId = $validatedData['target_user_id'] ?? null;
            $targetUserIds = $targetId ? [$targetId] : [];
        }

        $sentCount = count($targetUserIds);
        $announcementId = (string) Str::ulid();

        // トランザクション処理をControllerからUseCaseに完全移譲
        DB::transaction(function () use ($announcementId, $title, $body, $targetType, $targetId, $sentCount, $targetUserIds, $adminId) {
            Announcement::create([
                'id' => $announcementId,
                'title' => $title,
                'body' => $body,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'dispatched_count' => $sentCount,
                'created_by_user_id' => $adminId,
                'dispatched_at' => now(),
            ]);

            // 通知リレーと commit 後非同期キュー投入の安全執行
            $targetUsers = User::whereIn('id', $targetUserIds)->get();
            foreach ($targetUsers as $targetUser) {
                if (class_exists(AdminAnnouncementNotification::class)) {
                    $targetUser->notify((new AdminAnnouncementNotification(Announcement::find($announcementId)))
                        ->afterCommit()
                    );
                }
            }
        });

        return $sentCount;
    }
}
