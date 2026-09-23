<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\Meeting\MeetingPackRequest;
use App\Models\MeetingPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminMeetingPackController extends Controller
{
    /**
     * 一覧表示 (キーワード検索 + 状態フィルタ + ページネーション)
     */
    public function index(Request $request): View
    {
        $keyword = $request->input('keyword', '');
        $status = $request->input('status', '');

        $query = MeetingPack::query();

        // 1. キーワード検索（パック名）
        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%'.$request->input('keyword').'%');
        }

        // 2. 状態フィルタ（本物の小文字バリューでクエリを投げます）
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // 3. 並び順（sort_order昇順）
        $plans = $query->orderBy('sort_order', 'asc')->paginate(10)->withQueryString();

        return view('meeting-pack.management.index', compact('plans', 'keyword', 'status'));
    }

    public function create(): View
    {
        return view('meeting-pack.management.create');
    }

    /**
     * 新規作成 (初期状態は下書き: draft)
     */
    public function store(MeetingPackRequest $request): RedirectResponse
    {
        // 新規保存するデータを構成
        // バリデーションチェック後のデータを取り出す
        $data = $request->validated();
        $data['id'] = (string) Str::ulid();
        $data['status'] = MeetingPackStatus::Draft->value; // 'draft' をセット
        $adminId = Auth::id();
        $data['created_by_user_id'] = $adminId;
        $data['updated_by_user_id'] = $adminId;

        MeetingPack::create($data);

        return redirect()->route('admin.meeting-packs.index')
            ->with('success', '面談パックを下書きとして新設しました。');
    }

    /**
     * 詳細表示 (基本情報 + メタ情報)
     */
    public function show(string $id): View
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();

        // スコープ外ですが、Blade側のエラーを防ぐため空の履歴をマウント
        $histories = [];

        return view('meeting-pack.management.show', compact('plan', 'histories'));
    }

    public function edit(string $id): View
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();

        return view('meeting-pack.management.edit', compact('plan'));
    }

    /**
     * 基本情報の編集更新（状態はこのフォームでは変更しない）
     */
    public function update(MeetingPackRequest $request, string $id): RedirectResponse
    {
        //        $pack = MeetingPack::where('id', $id)->firstOrFail();
        $plan = MeetingPack::where('id', $id)->firstOrFail();
        $data['updated_by_user_id'] = Auth::id();

        //        $pack->update($request->validated());
        $plan->update(array_merge($request->validated(), [
            'updated_by_user_id' => Auth::id(),
        ]));

        return redirect()->route('admin.meeting-packs.show', $plan->id)
            ->with('success', '面談パックの基本情報を更新しました。');
    }

    /**
     * 物理削除 (※重要：公開中 published の面談パックは監査のため削除不可)
     */
    public function destroy(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();

        // Enumオブジェクト同士、または本物の小文字値 'published' で厳格にガード！
        if ($plan->status === MeetingPackStatus::Published || $plan->status === 'published') {
            return redirect()->back()
                ->with('danger', '公開中の面談パックは購入履歴の整合性を守るため削除できません。アーカイブしてください。');
        }

        $plan->delete();

        return redirect()->route('admin.meeting-packs.index')
            ->with('danger', '面談パックを物理削除しました。');
    }

    /**
     * 状態遷移: 公開する (下書き → 公開中 'published')
     */
    public function publish(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();

        $plan->update(['status' => MeetingPackStatus::Published->value]);

        return redirect()->back()->with('success', '面談パックを公開しました（販売開始）。');
    }

    /**
     * 状態遷移: アーカイブする (公開中 → アーカイブ 'archived')
     */
    public function archive(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();

        $plan->update(['status' => MeetingPackStatus::Archived->value]);

        return redirect()->back()->with('danger', '面談パックをアーカイブしました（販売終了）。');
    }

    /**
     * 状態遷移: 下書きに戻す、または復帰 (アーカイブ → 下書き 'draft')
     */
    public function unarchive(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();

        $plan->update(['status' => MeetingPackStatus::Draft->value]);

        return redirect()->back()->with('success', '面談パックを下書き状態に戻しました。');
    }
}
