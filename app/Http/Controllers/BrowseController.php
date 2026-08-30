<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;      // 追加：B-B-03
use App\Enums\EnrollmentStatus;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\UseCases\Learning\IndexAction;
use App\UseCases\Learning\ShowChapterAction;
use App\UseCases\Learning\ShowEnrollmentAction;
use App\UseCases\Learning\ShowPartAction;
use App\UseCases\Learning\ShowSectionAction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 受講生向け教材ブラウジング Controller。（B-B-03による修正->B-B-09による修正版）
 * /learning(empty-state)→/learning/enrollments/{enrollment}→/learning/parts/{part}→
 * /learning/chapters/{chapter}→/learning/sections/{section} の 5 階層動線を提供する。
 *
 * Section 閲覧時の LearningSession 自動開始は StartLearningSession ミドルウェアが、
 * AI 相談ウィジェット向けの Section 文脈 (pageMeta) は SectionPageMetaComposer が担う。
 */
class BrowseController extends Controller
{
    public function index(IndexAction $action): View
    {
        return view('learning.index', $action(auth()->user()));
    }

    public function showEnrollment(Enrollment $enrollment, Request $request, ShowEnrollmentAction $action): View
    {
        $this->authorize('view', $enrollment);

        // B-B-03で追加：
        // 受講登録（Enrollment）画面自体でも、紐づく資格が「公開中（Published）」ではない場合、
        // 受講生には目次すら見せず、期待値通り「404 Not Found」にする
        if (!$enrollment->certification || $enrollment->certification->status !== CertificationStatus::Published) {
            abort(404);
        }

        $tab = $request->query('tab') === 'quizzes' ? 'quizzes' : 'contents';

        return view('learning.enrollments.show', $action($enrollment, $tab));
    }

    public function showPart(Part $part, ShowPartAction $action): View
    {
        // B-B-03で追加：
        // 親である資格（certification）が、「公開中（Published）」ではない場合は
        // 受講生には1文字も教材を読ませず404でシャットアウトする
        if (!$part->certification || $part->certification->status !== CertificationStatus::Published) {
            abort(404);
        }

        // B-B-09で追加：
        // ログイン中の受講生本人が、このPartの親資格に対して「受講登録」を保持しているかチェックし、
        // 未登録の資格の教材を直リンクで盗み見ようとした不純なアクセスは、403 Forbidden で遮断する
        $hasValidEnrollment = Enrollment::query()
            ->where('user_id', auth()->id())
            ->where('certification_id', $part->certification_id)
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->exists();

        if (!$hasValidEnrollment) {
            abort(403, 'この資格の教材を閲覧するには、有効な受講登録が必要です。');
        }

        return view('learning.parts.show', $action($part, auth()->user()));
    }

    public function showChapter(Chapter $chapter, ShowChapterAction $action): View
    {
        // B-B-03で追加：
        // Chapter ➡ Part ➡ Certificationへとリレーションの鎖を遡り、公開停止時は404でガード
        $certification = $chapter->part?->certification;
        if (!$certification || $certification->status !== CertificationStatus::Published) {
            abort(404);
        }

        // B-B-09で追加：
        // ログイン中の受講生本人が、このChapterの属する親資格に対して受講登録を保持しているかでブロック
        $hasValidEnrollment = Enrollment::query()
            ->where('user_id', auth()->id())
            ->where('certification_id', $chapter->part?->certification_id)
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->exists();

        if (!$hasValidEnrollment) {
            abort(403, 'この資格の教材を閲覧するには、有効な受講登録が必要です。');
        }

        return view('learning.chapters.show', $action($chapter, auth()->user()));
    }

    public function showSection(Section $section, ShowSectionAction $action): View
    {
        // B-B-03で追加：
        // Section ➡ Chapter ➡ Part ➡ Certificationへと3階層を遡り、公開停止時は404でガード
        $certification = $section->chapter?->part?->certification;
        if (!$certification || $certification->status !== CertificationStatus::Published) {
            abort(404);
        }

        // B-B-09で追加：
        // 最末端の Section 読了動線であっても、親資格への受講登録状況を遡ってブロック
        $hasValidEnrollment = Enrollment::query()
            ->where('user_id', auth()->id())
            ->where('certification_id', $certification->id)
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->exists();

        if (!$hasValidEnrollment) {
            abort(403, 'この資格の教材を閲覧するには、有効な受講登録が必要です。');
        }

        return view('learning.sections.show', $action($section, auth()->user()));
    }
}
