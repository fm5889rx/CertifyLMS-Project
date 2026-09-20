<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Announcement\AnnouncementStoreRequest;
use App\Models\Announcement;
use App\Models\User;
use App\Models\Enrollment;
use App\Enums\UserRole;
use App\Enums\AnnouncementTargetType;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    /**
     * 管理者権限チェック門番（正攻法：Enumオブジェクトで扱うことを徹底！）
     */
    private function checkAdmin(): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // 管理者以外は403エラーでブロック
        if (!$user || $user->role !== UserRole::Admin) {
            abort(403, 'この操作は管理者のみに許可されています。');
        }
    }

    public function index(): View
    {
        $this->checkAdmin();

        // 最新順にソートしてページネーション
        $announcements = Announcement::orderBy('created_at', 'desc')->paginate(20);

        return view('announcement.management.index', compact('announcements'));
    }

    public function create(): View
    {
        $this->checkAdmin();

        $certifications = DB::table('certifications')->select('id', 'name')->get();
        $students = User::where('role', UserRole::Student)->select('id', 'name', 'email')->get();

        return view('announcement.management.create', compact('certifications', 'students'));
    }

    public function store(AnnouncementStoreRequest $request): RedirectResponse
    {
        $this->checkAdmin();

        $title = $request->input('title');
        $body = $request->input('body');
        $targetType = $request->input('target_type');

        $targetUserIds = [];
        $targetId = null;   // データベースの target_id カラムに格納する共通変数

        // Bladeから送られてくる2つの独立したキー（certification_id / user_id）を、
        // ターゲットタイプに合わせて、1つの物理カラム「target_id」へ集約
        if ($targetType === AnnouncementTargetType::AllStudents->value) {
            $targetUserIds = User::where('role', UserRole::Student)->pluck('id')->toArray();
            $targetId = null;
        } elseif ($targetType === AnnouncementTargetType::Certification->value) {
            // 資格指定フォームから送られてきた本物のIDを取得
            $targetId = $request->input('target_certification_id');

            $targetUserIds = Enrollment::where('certification_id', $targetId)
                ->where('status', 'learning')
                ->pluck('user_id')
                ->toArray();
        } elseif ($targetType === AnnouncementTargetType::User->value) {
            // ユーザー指定フォームから送られてきた本物のIDを取得
            $targetId = $request->input('target_user_id');

            $targetUserIds = [$targetId];
        }

        $sentCount = count($targetUserIds);
        $announcementId = (string) Str::ulid();
        $adminId = Auth::id();

        DB::transaction(function () use ($announcementId, $title, $body, $targetType, $targetId, $sentCount, $targetUserIds, $adminId) {

            Announcement::create([
                'id'                 => $announcementId,
                'title'              => $title,
                'body'               => $body,
                'target_type'        => $targetType,
                'target_id'          => $targetId, // 統合された正しい物理IDが格納される
                'dispatched_count'   => $sentCount,
                'created_by_user_id' => $adminId,
                'dispatched_at'      => now(),
            ]);

            // 通知基盤への一斉リレー
            // 【T-A-05追加：データ確定（トランザクション commit）後キュー投入の完全執行】
            // データベースへのインサートが commit されて確定した「直後」にのみ、
            // 非同期通知ジョブを安全にキューへと投入する。
            $targetUsers = User::whereIn('id', $targetUserIds)->get();
            foreach ($targetUsers as $targetUser) {
                $targetUser->notify(new AdminAnnouncementNotification(Announcement::find($announcementId))
                    ->afterCommit()
                );
            }
        });

        return redirect()->route('admin.announcements.index')
            ->with('success', "お知らせを一斉配信しました。（配信件数: {$sentCount} 件）");
    }

    public function show(string $id): View
    {
        $this->checkAdmin();

        $announcement = Announcement::where('id', $id)->firstOrFail();

        return view('announcement.management.show', compact('announcement'));
    }
}
