<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentNote\EnrollmentNoteRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EnrollmentNoteController extends Controller
{
    /**
     * 受講生メモの追加実行
     */
    public function store(EnrollmentNoteRequest $request, string $enrollmentId): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isStudent()) {
            abort(403, '受講生は業務メモを追加できません。');
        }

        Enrollment::where('id', $enrollmentId)->firstOrFail();

        EnrollmentNote::create([
            'id' => (string) Str::ulid(),
            'enrollment_id' => $enrollmentId,
            'user_id' => $user->id,
            'body' => $request->input('body'),
        ]);

        // 【仕様準拠】受講登録詳細画面へ明示的にリダイレクト
        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', 'メモを追加しました。'); // 文言完全一致
    }

    /**
     * 受講生メモの編集画面表示（専用ページ）
     */
    public function edit(string $id): View
    {
        $note = EnrollmentNote::where('id', $id)->firstOrFail();

        /** @var User $user */
        $user = Auth::user();

        if ($user->isStudent()) {
            abort(403, '権限がありません。');
        }

        if (! $user->isAdmin() && (string) $note->user_id !== (string) $user->id) {
            abort(403, '他人が作成したメモを編集する権限がありません。');
        }

        return view('enrollment-note.edit', compact('note'));
    }

    /**
     * 受講生メモの更新実行
     */
    public function update(EnrollmentNoteRequest $request, string $id): RedirectResponse
    {
        $note = EnrollmentNote::where('id', $id)->firstOrFail();

        /** @var User $user */
        $user = Auth::user();

        if ($user->isStudent()) {
            abort(403, '権限がありません。');
        }

        if (! $user->isAdmin() && (string) $note->user_id !== (string) $user->id) {
            abort(403, '他人が作成したメモを編集する権限がありません。');
        }

        $note->update([
            'body' => $request->input('body'),
        ]);

        // 【仕様準拠】編集画面にループさせず、元の受講登録詳細画面へ戻す
        return redirect()
            ->route('enrollments.show', $note->enrollment_id)
            ->with('success', 'メモを更新しました。'); // 文言完全一致
    }

    /**
     * 受講生メモの物理削除（履歴は残さない）
     */
    public function destroy(string $id): RedirectResponse
    {
        $note = EnrollmentNote::where('id', $id)->firstOrFail();

        /** @var User $user */
        $user = Auth::user();

        if ($user->isStudent()) {
            abort(403, '権限がありません。');
        }

        if (! $user->isAdmin() && (string) $note->user_id !== (string) $user->id) {
            abort(403, '他人が作成したメモを削除する権限がありません。');
        }

        $enrollmentId = $note->enrollment_id;
        $note->delete();

        // 【仕様準拠】元の受講登録詳細画面へ戻す
        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', 'メモを削除しました。'); // 文言完全一致
    }
}
