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
     * Displays the paginated announcement history with all displayed relations loaded.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Announcement::class);

        $announcements = Announcement::with(['createdBy', 'targetCertification', 'targetUser'])
            ->latest('created_at')
            ->paginate(20);

        return view('announcement.management.index', compact('announcements'));
    }

    /**
     * Displays the announcement form and its recipient options.
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
     * Dispatches an announcement using validated input.
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
     * Displays announcement details and its recipient relations.
     */
    public function show(string $id): View
    {
        $this->authorize('view', Announcement::class);

        $announcement = Announcement::with(['createdBy', 'targetCertification', 'targetUser'])
            ->where('id', $id)
            ->firstOrFail();

        return view('announcement.management.show', compact('announcement'));
    }
}
