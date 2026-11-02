<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Enrollment\IndexManagementRequest;
use App\Http\Requests\Enrollment\StoreRequest;
use App\Http\Requests\Enrollment\UpdateExamDateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\LearningGoal;
use App\Services\Learning\LearningProgressService;
use App\UseCases\Enrollment\DestroyAction;
use App\UseCases\Enrollment\IndexAction;
use App\UseCases\Enrollment\IndexManagementAction;
use App\UseCases\Enrollment\ResumeAction;
use App\UseCases\Enrollment\ShowAction;
use App\UseCases\Enrollment\StoreAction;
use App\UseCases\Enrollment\UpdateExamDateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Displays and manages enrollment workflows for students and staff.
 */
class EnrollmentController extends Controller
{
    /**
     * Displays the student's own enrollments or a staff-filtered enrollment listing.
     */
    public function index(
        IndexManagementRequest $request,
        IndexAction $action,
        IndexManagementAction $managementAction,
    ): View {
        $user = $request->user();

        if ($user->role === UserRole::Student) {
            return view('enrollment.index', [
                'enrollments' => $action($user),
            ]);
        }

        return view('enrollment.index', $managementAction($user, $request->validated()));
    }

    /**
     * Displays enrollment details, progress, goals, notes, and admin status history.
     */
    public function show(
        Enrollment $enrollment,
        ShowAction $action,
        LearningProgressService $progressService,
    ): View {
        $this->authorize('view', $enrollment);

        $action($enrollment);

        $user = auth()->user();
        $progress = null;

        if (in_array($user->role, [UserRole::Coach, UserRole::Admin], true)) {
            $enrollment->loadMissing(['user']);
            $progress = $progressService->summarizeProgress($enrollment);
        }

        if ($user->role === UserRole::Admin) {
            $enrollment->loadMissing([
                'statusLogs' => fn ($statusLogQuery) => $statusLogQuery
                    ->with('changedBy')
                    ->orderByDesc('changed_at'),
            ]);
        }

        $goals = LearningGoal::where('enrollment_id', $enrollment->id)
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('target_date', 'asc')
            ->get();

        $notes = EnrollmentNote::where('enrollment_id', $enrollment->id)
            ->with('user')
            ->latest('created_at')
            ->get();

        return view('enrollment.show', [
            'enrollment' => $enrollment,
            'progress' => $progress,
            'learningGoals' => $goals,
            'goals' => $goals,
            'enrollmentNotes' => $notes,
            'notes' => $notes,
        ]);
    }

    /**
     * Creates an enrollment for the authenticated student.
     */
    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $enrollment = $action(auth()->user(), $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '受講登録が完了しました。');
    }

    /**
     * Removes an enrollment after policy authorization.
     */
    public function destroy(Enrollment $enrollment, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $enrollment);

        $action($enrollment);

        return redirect()
            ->route('enrollments.index')
            ->with('success', '受講登録を解除しました。');
    }

    /**
     * Resumes an eligible enrollment.
     */
    public function resume(Enrollment $enrollment, ResumeAction $action): RedirectResponse
    {
        $this->authorize('resume', $enrollment);

        $action($enrollment, auth()->user());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '学習を再開しました。');
    }

    /**
     * 受講生本人による目標受験日の設定 / 変更。認可は UpdateExamDateRequest が
     * EnrollmentPolicy::updateExamDate (admin or 本人、status != Passed) に委譲する。
     */
    public function updateExamDate(
        Enrollment $enrollment,
        UpdateExamDateRequest $request,
        UpdateExamDateAction $action,
    ): RedirectResponse {
        $action($enrollment, $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '目標受験日を更新しました。');
    }
}
