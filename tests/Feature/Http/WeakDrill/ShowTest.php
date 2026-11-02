<?php

declare(strict_types=1);

namespace Tests\Feature\Http\WeakDrill;

use App\Enums\AnswerSource;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\QuestionCategory;
use App\Models\Section;
use App\Models\SectionQuestion;
use App\Models\SectionQuestionAnswer;
use App\Models\SectionQuestionAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_lists_only_published_visible_questions_with_student_attempts(): void
    {
        [$student, $enrollment, $category, $question, $nextQuestion] = $this->buildScenario();
        $attempt = SectionQuestionAttempt::factory()
            ->forUser($student)
            ->forQuestion($question)
            ->withAttempts(2, 1)
            ->create();
        SectionQuestion::factory()
            ->forSection($question->section)
            ->forCategory($category)
            ->draft()
            ->create(['order' => 3]);

        $this->actingAs($student)
            ->get(route('quiz.drills.category', [$enrollment, $category]))
            ->assertOk()
            ->assertViewHas('questions', function ($questions) use ($question, $nextQuestion, $attempt): bool {
                return $questions->modelKeys() === [$question->id, $nextQuestion->id]
                    && $questions->first()->sectionQuestionAttempts->sole()->is($attempt);
            });
    }

    public function test_question_shows_the_next_published_question_and_current_attempt(): void
    {
        [$student, $enrollment, $category, $question, $nextQuestion] = $this->buildScenario();
        $attempt = SectionQuestionAttempt::factory()
            ->forUser($student)
            ->forQuestion($question)
            ->withAttempts(3, 2)
            ->create();

        $this->actingAs($student)
            ->get(route('quiz.drills.question', [$enrollment, $category, $question]))
            ->assertOk()
            ->assertViewHas('nextId', $nextQuestion->id)
            ->assertViewHas('attempt', fn ($viewAttempt): bool => $viewAttempt->is($attempt));
    }

    public function test_result_shows_the_correct_option_attempt_and_next_question(): void
    {
        [$student, $enrollment, $category, $question, $nextQuestion] = $this->buildScenario();
        $correctOption = $question->options()->where('is_correct', true)->firstOrFail();
        $attempt = SectionQuestionAttempt::factory()
            ->forUser($student)
            ->forQuestion($question)
            ->withAttempts(1, 1)
            ->lastIs(true)
            ->create();
        $answer = SectionQuestionAnswer::factory()
            ->forUser($student)
            ->forQuestion($question)
            ->forOption($correctOption)
            ->source(AnswerSource::WeakDrill)
            ->correct()
            ->create();

        $this->actingAs($student)
            ->get(route('quiz.drills.result', [$enrollment, $category, $question, $answer]))
            ->assertOk()
            ->assertViewHas('correctOption', fn ($option): bool => $option->is($correctOption))
            ->assertViewHas('attempt', fn ($viewAttempt): bool => $viewAttempt->is($attempt))
            ->assertViewHas('nextId', $nextQuestion->id);
    }

    /**
     * @return array{0: User, 1: Enrollment, 2: QuestionCategory, 3: SectionQuestion, 4: SectionQuestion}
     */
    private function buildScenario(): array
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->state(['status' => EnrollmentStatus::Learning->value])
            ->create();
        $part = Part::factory()->for($certification)->create(['status' => ContentStatus::Published->value]);
        $chapter = Chapter::factory()->for($part)->create(['status' => ContentStatus::Published->value]);
        $section = Section::factory()->for($chapter)->create(['status' => ContentStatus::Published->value]);
        $category = QuestionCategory::factory()->for($certification)->create();
        $question = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->published()
            ->withOptions(3)
            ->create(['order' => 1]);
        $nextQuestion = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->published()
            ->withOptions(3)
            ->create(['order' => 2]);

        return [$student, $enrollment, $category, $question, $nextQuestion];
    }
}
