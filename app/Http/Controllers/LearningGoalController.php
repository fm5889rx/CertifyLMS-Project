<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LearningGoal\LearningGoalRequest;
use App\Models\Enrollment;
use App\Models\LearningGoal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LearningGoalController extends Controller
{
    /**
     * 目標の追加
     */
    public function store(LearningGoalRequest $request, string $enrollmentId): RedirectResponse
    {
        $enrollment = Enrollment::where('id', $enrollmentId)->firstOrFail();

        // 厳格な本人認可チェック
        if ((string) $enrollment->user_id !== (string) Auth::id()) {
            abort(403, '個人目標を追加する権限がありません。');
        }

        $data = $request->validated();

        LearningGoal::create([
            'id' => (string) Str::ulid(),
            'enrollment_id' => $enrollmentId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'target_date' => $data['target_date'],
            'achieved_at' => null,
        ]);

        // 【仕様準拠】受講登録詳細画面へリダイレクト
        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', '目標を追加しました。'); // 文言完全一致
    }

    /**
     * 目標の編集画面の表示
     */
    public function edit(string $id): View
    {
        $goal = LearningGoal::where('id', $id)->firstOrFail();
        $enrollment = Enrollment::where('id', $goal->enrollment_id)->firstOrFail();

        // 受講生本人以外（コーチ・管理者含む）はアクセス不可
        if ((string) $enrollment->user_id !== (string) Auth::id()) {
            abort(403, 'この目標を編集する権限がありません。');
        }

        return view('enrollment-goal.edit', compact('goal'));
    }

    /**
     * 目標の編集実行
     */
    public function update(LearningGoalRequest $request, string $id): RedirectResponse
    {
        $goal = LearningGoal::where('id', $id)->firstOrFail();
        $enrollment = Enrollment::where('id', $goal->enrollment_id)->firstOrFail();

        if ((string) $enrollment->user_id !== (string) Auth::id()) {
            abort(403, 'この目標を更新する権限がありません。');
        }

        $goal->update($request->validated());

        // 【仕様準拠】受講登録詳細画面へリダイレクト
        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を更新しました。'); // 文言完全一致
    }

    /**
     * 目標の削除
     */
    public function destroy(string $id): RedirectResponse
    {
        $goal = LearningGoal::where('id', $id)->firstOrFail();
        $enrollment = Enrollment::where('id', $goal->enrollment_id)->firstOrFail();

        if ((string) $enrollment->user_id !== (string) Auth::id()) {
            abort(403, 'この目標を削除する権限がありません。');
        }

        $enrollmentId = $goal->enrollment_id;
        $goal->delete(); // 通常の論理または物理削除

        // 【仕様準拠】受講登録詳細画面へリダイレクト
        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', '目標を削除しました。'); // 文言完全一致
    }

    /**
     * 達成マークの付与 (POST)
     */
    public function achieve(string $id): RedirectResponse
    {
        $goal = LearningGoal::where('id', $id)->firstOrFail();
        $enrollment = Enrollment::where('id', $goal->enrollment_id)->firstOrFail();

        if ((string) $enrollment->user_id !== (string) Auth::id()) {
            abort(403, '達成マークを付与する権限がありません。');
        }

        $goal->update(['achieved_at' => now()]);

        // 【仕様準拠】受講登録詳細画面へリダイレクト
        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を達成済にしました。'); // 文言完全一致
    }

    /**
     * 達成マークの解除 (DELETE)
     */
    public function unachieve(string $id): RedirectResponse
    {
        $goal = LearningGoal::where('id', $id)->firstOrFail();
        $enrollment = Enrollment::where('id', $goal->enrollment_id)->firstOrFail();

        if ((string) $enrollment->user_id !== (string) Auth::id()) {
            abort(403, '達成マークを解除する権限がありません。');
        }

        $goal->update(['achieved_at' => null]);

        // 【仕様準拠】受講登録詳細画面へリダイレクト
        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を未達成に戻しました。'); // 文言完全一致
    }
}
