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

        // 受講生からのアクセスは一律で403エラーで排除
        if ($user->isStudent()) {
            abort(403, '受講生は業務メモを追加できません。');
        }

        $enrollment = Enrollment::where('id', $enrollmentId)->firstOrFail();

        // EnrollmentNoteを新規作成する
        EnrollmentNote::create([
            'id' => (string) Str::ulid(),
            'enrollment_id' => $enrollmentId,
            'user_id' => $user->id,
            'body' => $request->input('body'),
        ]);

        // フラッシュメッセージを出して前画面に戻る
        return redirect()->back()->with('success', '受講生メモを追加しました。');
    }

    /**
     * 受講生メモの編集画面表示（専用ページ）
     */
    public function edit(string $id): View
    {
        $note = EnrollmentNote::where('id', $id)->firstOrFail();

        /** @var User $user */
        $user = Auth::user();

        // ロールが受講生なら403エラーを返して排除
        if ($user->isStudent()) {
            abort(403, '権限がありません。');
        }

        // 管理者ではない、かつ自分が書いたメモではない場合は403で排除
        if (! $user->isAdmin() && (string) $note->user_id !== (string) $user->id) {
            abort(403, '他人が作成したメモを編集する権限がありません。');
        }

        // bladeに引き渡し
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

        // ロールが受講生なら403エラーで排除
        if ($user->isStudent()) {
            abort(403, '権限がありません。');
        }

        // ロールが管理者でない、かつ自分以外がアクセスした時は403エラーで排除
        if (! $user->isAdmin() && (string) $note->user_id !== (string) $user->id) {
            abort(403, '他人が作成したメモを編集する権限がありません。');
        }

        // 入力したメモを上書き更新
        $note->update([
            'body' => $request->input('body'),
        ]);

        // フラッシュメッセージを出して前画面に戻る
        return redirect()->back()->with('success', '受講生メモを更新しました。');
    }

    /**
     * 受講生メモの物理削除（履歴は残さない）
     */
    public function destroy(string $id): RedirectResponse
    {
        $note = EnrollmentNote::where('id', $id)->firstOrFail();

        /** @var User $user */
        $user = Auth::user();

        // 受講生は403エラーで排除
        if ($user->isStudent()) {
            abort(403, '権限がありません。');
        }

        // ロールが管理者でない、かつメモの所有者が自分以外の場合403エラーで排除
        if (! $user->isAdmin() && (string) $note->user_id !== (string) $user->id) {
            abort(403, '他人が作成したメモを削除する権限がありません。');
        }

        // ノートを削除
        $note->delete();

        // フラッシュメッセージを出して前画面に戻る
        return redirect()->back()->with('danger', '受講生メモを削除しました。');
    }
}
