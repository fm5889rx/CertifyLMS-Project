<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Answer;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View as IlluminateView;

class AdminQaBoardController extends Controller
{
    /**
     * GET /admin/qa-board
     * 管理者用 質問スレッド一覧画面
     */
    public function index(): IlluminateView
    {
        // 管理者であることを確認（念のための二重チェック）
        // ※Userモデルに isAdmin() が実装されている、またはPolicyが登録されている前提
        
        // 最新順にスレッドを取得（N+1問題を防ぐため投稿者ユーザー情報もEager Loading）
        $questions = Question::with('user')->latest()->paginate(20);

        // 管理者専用のBladeにデータを渡して表示（bladeファイル名は qa-board.admin.index など任意で調整してください）
        return view('admin.qa-board.index', compact('questions'));
    }

    /**
     * GET /admin/qa-board/{question}
     * 管理者用 質問スレッド詳細画面
     */
    public function show(Question $question): IlluminateView
    {
        // 質問に紐づく回答一覧と、それぞれの回答者をまとめて取得
        $question->load('answers.user', 'user');

        return view('admin.qa-board.show', compact('question'));
    }

    /**
     * DELETE /admin/qa-board/{question}
     * 管理者権限による 質問スレッドの強制削除
     */
    public function destroy(Question $question): RedirectResponse
    {
        // QuestionPolicy の forceDelete メソッドを使って、現在のログインユーザーが管理者か認可チェック
        $this->authorize('forceDelete', $question);

        // データベースから削除（マイグレーションのcascade設定により紐づく回答も自動消去されます）
        $question->delete();

        return redirect()->route('admin.qa-board.index')
            ->with('success', '管理者権限により、質問スレッドを削除しました。');
    }

    /**
     * DELETE /admin/qa-board/{question}/replies/{reply}
     * 管理者権限による 特定の回答の強制削除
     */
    public function destroyReply(Question $question, Answer $reply): redirectResponse
    {
        // AnswerPolicy の forceDelete メソッドを使って、現在のログインユーザーが管理者か認可チェック
        $this->authorize('forceDelete', $reply);

        // 回答を削除
        $reply->delete();

        return back()->with('success', '管理者権限により、不適切な回答を削除しました。');
    }
}
