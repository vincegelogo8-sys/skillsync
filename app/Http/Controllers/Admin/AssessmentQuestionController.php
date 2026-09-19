<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssessmentQuestionRequest;
use App\Models\SkillsAssessmentAttempt;
use App\Models\SkillsAssessmentQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AssessmentQuestionController extends Controller
{
    public function index(): View
    {
        return view('admin.assessment-questions.index', ['questions' => SkillsAssessmentQuestion::latest('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('admin.assessment-questions.form', ['question' => new SkillsAssessmentQuestion]);
    }

    public function store(AssessmentQuestionRequest $request): RedirectResponse
    {
        SkillsAssessmentQuestion::create($request->validated());

        return redirect()->route('admin.assessment-questions.index')->with('status', 'Question added.');
    }

    public function edit(SkillsAssessmentQuestion $assessmentQuestion): View
    {
        return view('admin.assessment-questions.form', ['question' => $assessmentQuestion]);
    }

    public function update(AssessmentQuestionRequest $request, SkillsAssessmentQuestion $assessmentQuestion): RedirectResponse
    {
        $assessmentQuestion->update($request->validated());

        return redirect()->route('admin.assessment-questions.index')->with('status', 'Question updated.');
    }

    public function destroy(SkillsAssessmentQuestion $assessmentQuestion): RedirectResponse
    {
        $assessmentQuestion->delete();

        return redirect()->route('admin.assessment-questions.index')->with('status', 'Question removed. Existing attempts are preserved.');
    }

    public function results(): View
    {
        return view('admin.assessment-results.index', [
            'attempts' => SkillsAssessmentAttempt::with('facultyProfile.user')->whereNotNull('completed_at')
                ->latest('completed_at')->latest('id')->paginate(20),
        ]);
    }
}
