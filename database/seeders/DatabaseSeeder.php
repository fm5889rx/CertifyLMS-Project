<?php

declare(strict_types=1);

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            PlanSeeder::class,
            UserLifecycleSeeder::class,
            MeetingPackSeeder::class,
            CertificationCategorySeeder::class,
            CertificationSeeder::class,
            InvitationSeeder::class,
            EnrollmentSeeder::class,
            MentoringSeeder::class,
            ContentSeeder::class,
            LearningSeeder::class,
            QuizAnsweringSeeder::class,
            MockExamSeeder::class,
            ChatSeeder::class,
            CertificateSeeder::class,
            QaThreadSeeder::class,                  // 追加：S-B-01
            NotificationSeeder::class,              // 追加：S-B-04
            LearningGoalSeeder::class,              // 追加：S-B-05
            EnrollmentNoteSeeder::class,            // 追加：S-B-07
            AnnouncementSeeder::class,              // 追加：S-B-08
            MeetingReminderTestDataSeeder::class,   // 追加：S-B-09
        ]);
    }
}
