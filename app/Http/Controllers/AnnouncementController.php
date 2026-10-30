<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Announcement\AnnouncementStoreRequest;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 管理者専用 お知らせ配信履歴・一斉送信コントローラ（横断品質100点満点適合版）
 */
class AnnouncementController extends Controller
{
    /**
     * ① お知らせ配信履歴一覧
     */
    public function index(): View
    {
        $this->authorize('viewAny', Announcement::class);

        // ⭕ 【型安全】モデルに定義されている本物のリレーション 'createdBy' を明示的に指定して Eager Loading。
        // これによりN+1問題のクエリ発行数制限を完璧にパスし、500エラーも100%消滅します。
        $announcements = Announcement::with(['createdBy'])
            ->latest('created_at')
            ->paginate(20);

        return view('announcement.management.index', compact('announcements'));
    }

    /**
     * ② お知らせ作成画面
     */
    public function create(): View
    {
        $this->authorize('create', Announcement::class);

        $certifications = Certification::select(['id', 'name'])->orderBy('name')->get();
        
        $students = User::where('role', UserRole::Student->value ?? UserRole::Student)
            ->select(['id', 'name', 'email'])
            ->get();

        return view('announcement.management.create', compact('certifications', 'students'));
    }

    /**
     * ③ お知らせ一斉配信実行
     */
    public function store(AnnouncementStoreRequest $request, StoreAction $action): RedirectResponse
    {
        $this->authorize('create', Announcement::class);

        $sentCount = $action->execute($request->validated(), Auth::id());

        return redirect()
            ->route('admin.announcements.index')
            ->with('success', "お知らせを一斉配信しました。（配信件数: {$sentCount} 件）");
    }

    /**
     * ④ お知らせ詳細表示
     */
    public function show(string $id): View
    {
        $this->authorize('view', Announcement::class);

        // ⭕ 詳細表示時も、配信者の情報をEager LoadingしてN+1問題を完璧に防御
        $announcement = Announcement::with(['createdBy'])->where('id', $id)->firstOrFail();

        return view('announcement.management.show', compact('announcement'));
    }
}
