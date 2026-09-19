<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AssessmentQuestionController;
use App\Http\Controllers\AdviserAssignmentController;
use App\Http\Controllers\AdviserRequestController;
use App\Http\Controllers\AdvisoryThresholdController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacultyCompetencyController;
use App\Http\Controllers\FacultyExpertiseController;
use App\Http\Controllers\FacultyPreferenceController;
use App\Http\Controllers\FacultyProfileController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ResearchProposalController;
use App\Http\Controllers\SkillsAssessmentController;
use App\Http\Controllers\StudentProfileController;
use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use Illuminate\Support\Facades\Route;

Route::model(
    'facultyProfile',
    FacultyProfile::class
);

Route::model(
    'expertise',
    FacultyExpertise::class
);

Route::get('/', function () {
    return view('welcome');
});

Route::get(
    '/dashboard',
    DashboardController::class
)
    ->middleware('auth')
    ->name('dashboard');

/*
|--------------------------------------------------------------------------
| Student Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'role:student',
])
    ->prefix('student')
    ->name('student.')
    ->group(function () {
        Route::get(
            '/dashboard',
            [DashboardController::class, 'show']
        )
            ->name('dashboard');

        Route::get(
            '/requests',
            [AdviserRequestController::class, 'index']
        )
            ->name('requests.index');

        Route::get(
            '/assignments',
            [AdviserAssignmentController::class, 'index']
        )
            ->name('assignments.index');

        Route::post(
            '/proposals/{proposal}/requests/{facultyProfile}',
            [AdviserRequestController::class, 'store']
        )
            ->name('requests.store');

        Route::patch(
            '/requests/{adviserRequest}/cancel',
            [AdviserRequestController::class, 'cancel']
        )
            ->name('requests.cancel');

        Route::get(
            '/profile',
            [StudentProfileController::class, 'edit']
        )
            ->name('profile.edit');

        Route::patch(
            '/profile',
            [StudentProfileController::class, 'update']
        )
            ->name('profile.update');

        /*
        |--------------------------------------------------------------------------
        | Research Proposals
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/proposals',
            [ResearchProposalController::class, 'index']
        )
            ->name('proposals.index');

        Route::get(
            '/proposals/create',
            [ResearchProposalController::class, 'create']
        )
            ->name('proposals.create');

        Route::post(
            '/proposals',
            [ResearchProposalController::class, 'store']
        )
            ->name('proposals.store');

        Route::get(
            '/proposals/{proposal}',
            [ResearchProposalController::class, 'show']
        )
            ->name('proposals.show');

        Route::get(
            '/proposals/{proposal}/download',
            [ResearchProposalController::class, 'download']
        )
            ->name('proposals.download');

        Route::post(
            '/proposals/{proposal}/extract',
            [ResearchProposalController::class, 'extract']
        )
            ->name('proposals.extract');

        /*
         * NEW:
         * Re-extract the existing uploaded document.
         */
        Route::post(
            '/proposals/{proposal}/refresh-extraction',
            [
                ResearchProposalController::class,
                'refreshExtraction',
            ]
        )
            ->name(
                'proposals.refresh-extraction'
            );

        Route::post(
            '/proposals/{proposal}/analyze',
            [ResearchProposalController::class, 'analyze']
        )
            ->name('proposals.analyze');

        /*
         * NEW:
         * Delete student's own proposal.
         */
        Route::delete(
            '/proposals/{proposal}',
            [ResearchProposalController::class, 'destroy']
        )
            ->name('proposals.destroy');

        Route::get(
            '/proposals/{proposal}/recommendations',
            [RecommendationController::class, 'index']
        )
            ->name('recommendations.index');

        Route::post(
            '/proposals/{proposal}/recommendations',
            [RecommendationController::class, 'generate']
        )
            ->name('recommendations.generate');
    });

/*
|--------------------------------------------------------------------------
| Faculty Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'role:faculty',
])
    ->prefix('faculty')
    ->name('faculty.')
    ->group(function () {
        Route::get(
            '/dashboard',
            [DashboardController::class, 'show']
        )
            ->name('dashboard');

        Route::get(
            '/requests',
            [AdviserRequestController::class, 'index']
        )
            ->name('requests.index');

        Route::get(
            '/assignments',
            [AdviserAssignmentController::class, 'index']
        )
            ->name('assignments.index');

        Route::patch(
            '/requests/{adviserRequest}/decline',
            [AdviserRequestController::class, 'decline']
        )
            ->name('requests.decline');

        Route::get(
            '/profile',
            [FacultyProfileController::class, 'edit']
        )
            ->name('profile.edit');

        Route::patch(
            '/profile',
            [FacultyProfileController::class, 'update']
        )
            ->name('profile.update');

        Route::get(
            '/expertise',
            [FacultyExpertiseController::class, 'index']
        )
            ->name('expertise.index');

        Route::post(
            '/expertise',
            [FacultyExpertiseController::class, 'store']
        )
            ->name('expertise.store');

        Route::get(
            '/expertise/{expertise}/edit',
            [FacultyExpertiseController::class, 'edit']
        )
            ->name('expertise.edit');

        Route::patch(
            '/expertise/{expertise}',
            [FacultyExpertiseController::class, 'update']
        )
            ->name('expertise.update');

        Route::delete(
            '/expertise/{expertise}',
            [FacultyExpertiseController::class, 'destroy']
        )
            ->name('expertise.destroy');

        Route::get(
            '/preferences',
            [FacultyPreferenceController::class, 'index']
        )
            ->name('preferences.index');

        Route::patch(
            '/preferences',
            [FacultyPreferenceController::class, 'update']
        )
            ->name('preferences.update');

        Route::get(
            '/assessment',
            [SkillsAssessmentController::class, 'index']
        )
            ->name('assessment.index');

        Route::post(
            '/assessment',
            [SkillsAssessmentController::class, 'start']
        )
            ->name('assessment.start');

        Route::get(
            '/assessment/{attempt}',
            [SkillsAssessmentController::class, 'show']
        )
            ->name('assessment.show');

        Route::post(
            '/assessment/{attempt}',
            [SkillsAssessmentController::class, 'submit']
        )
            ->name('assessment.submit');
    });

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'role:admin',
])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get(
            '/dashboard',
            [DashboardController::class, 'show']
        )
            ->name('dashboard');

        Route::get(
            '/requests',
            [AdviserRequestController::class, 'index']
        )
            ->name('requests.index');

        Route::get(
            '/assignments',
            [AdviserAssignmentController::class, 'index']
        )
            ->name('assignments.index');

        Route::post(
            '/requests/{adviserRequest}/approve',
            [AdviserAssignmentController::class, 'approve']
        )
            ->name('requests.approve');

        Route::patch(
            '/requests/{adviserRequest}/decline',
            [AdviserRequestController::class, 'decline']
        )
            ->name('requests.decline');

        Route::get(
            '/advisory-limits',
            [AdvisoryThresholdController::class, 'index']
        )
            ->name('advisory-limits.index');

        Route::get(
            '/faculty/{facultyProfile}/advisory-limit',
            [AdvisoryThresholdController::class, 'edit']
        )
            ->name('advisory-limits.edit');

        Route::patch(
            '/faculty/{facultyProfile}/advisory-limit',
            [AdvisoryThresholdController::class, 'update']
        )
            ->name('advisory-limits.update');

        Route::get(
            '/accounts',
            [AccountController::class, 'index']
        )
            ->name('accounts.index');

        Route::get(
            '/accounts/create',
            [AccountController::class, 'create']
        )
            ->name('accounts.create');

        Route::post(
            '/accounts',
            [AccountController::class, 'store']
        )
            ->name('accounts.store');

        Route::get(
            '/expertise',
            [FacultyExpertiseController::class, 'facultyList']
        )
            ->name('expertise.faculty');

        Route::get(
            '/competencies',
            [FacultyCompetencyController::class, 'index']
        )
            ->name('competencies.index');

        /*
        |--------------------------------------------------------------------------
        | Admin Proposal Review
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/proposals',
            [ResearchProposalController::class, 'index']
        )
            ->name('proposals.index');

        Route::get(
            '/proposals/{proposal}',
            [ResearchProposalController::class, 'show']
        )
            ->name('proposals.show');

        Route::get(
            '/proposals/{proposal}/download',
            [ResearchProposalController::class, 'download']
        )
            ->name('proposals.download');

        Route::post(
            '/proposals/{proposal}/extract',
            [ResearchProposalController::class, 'extract']
        )
            ->name('proposals.extract');

        /*
         * Admin can also refresh extraction.
         */
        Route::post(
            '/proposals/{proposal}/refresh-extraction',
            [
                ResearchProposalController::class,
                'refreshExtraction',
            ]
        )
            ->name(
                'proposals.refresh-extraction'
            );

        Route::post(
            '/proposals/{proposal}/analyze',
            [ResearchProposalController::class, 'analyze']
        )
            ->name('proposals.analyze');

        Route::get(
            '/proposals/{proposal}/recommendations',
            [RecommendationController::class, 'index']
        )
            ->name('recommendations.index');

        Route::post(
            '/proposals/{proposal}/recommendations',
            [RecommendationController::class, 'generate']
        )
            ->name('recommendations.generate');

        Route::resource(
            'assessment-questions',
            AssessmentQuestionController::class
        )
            ->parameters([
                'assessment-questions' =>
                    'assessmentQuestion',
            ])
            ->except('show');

        Route::get(
            '/assessment-results',
            [AssessmentQuestionController::class, 'results']
        )
            ->name(
                'assessment-results.index'
            );

        Route::get(
            '/faculty/{facultyProfile}/competency',
            [FacultyCompetencyController::class, 'edit']
        )
            ->name('competencies.edit');

        Route::patch(
            '/faculty/{facultyProfile}/competency',
            [FacultyCompetencyController::class, 'update']
        )
            ->name('competencies.update');

        Route::get(
            '/faculty/{facultyProfile}/expertise',
            [FacultyExpertiseController::class, 'index']
        )
            ->name('expertise.index');

        Route::post(
            '/faculty/{facultyProfile}/expertise',
            [FacultyExpertiseController::class, 'store']
        )
            ->name('expertise.store');

        Route::get(
            '/faculty/{facultyProfile}/expertise/{expertise}/edit',
            [FacultyExpertiseController::class, 'edit']
        )
            ->name('expertise.edit');

        Route::patch(
            '/faculty/{facultyProfile}/expertise/{expertise}',
            [FacultyExpertiseController::class, 'update']
        )
            ->name('expertise.update');

        Route::delete(
            '/faculty/{facultyProfile}/expertise/{expertise}',
            [FacultyExpertiseController::class, 'destroy']
        )
            ->name('expertise.destroy');
    });

/*
|--------------------------------------------------------------------------
| Account Profile
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->group(function () {
        Route::get(
            '/profile',
            [ProfileController::class, 'edit']
        )
            ->name('profile.edit');

        Route::patch(
            '/profile',
            [ProfileController::class, 'update']
        )
            ->name('profile.update');

        Route::delete(
            '/profile',
            [ProfileController::class, 'destroy']
        )
            ->name('profile.destroy');
    });

require __DIR__.'/auth.php';