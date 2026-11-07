<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdviserAssignment;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Read-only admin history: every proposal, student and faculty record, newest first.
 * Proposals and students can be separated by course (BSIT / BSIS) and section.
 * Faculty are not tied to a course, so their advisees are broken down per course instead.
 */
class HistoryController extends Controller
{
    private const TABS = ['proposals', 'students', 'faculty'];

    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $tab = $request->query('tab');
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'proposals';

        $courses = config('student_options.courses');
        $course = $this->option($request->query('course'), $courses);
        $section = $course === null ? null : $this->option($request->query('section'), config('student_options.sections'));
        if ($tab === 'faculty') {
            $course = $section = null;
        }

        $items = match ($tab) {
            'students' => $this->students($course, $section),
            'faculty' => $this->faculty($courses),
            default => $this->proposals($course, $section),
        };

        $usesCourseFilter = $tab !== 'faculty';

        return view('admin.history.index', [
            'tab' => $tab,
            'items' => $items->withQueryString(),
            'course' => $course,
            'section' => $section,
            'courses' => $courses,
            'courseCodes' => collect($courses)->mapWithKeys(fn (string $name): array => [$name => $this->code($name)])->all(),
            'tabTotals' => [
                'proposals' => ResearchProposal::count(),
                'students' => StudentProfile::count(),
                'faculty' => FacultyProfile::count(),
            ],
            'allCourseCount' => $usesCourseFilter ? $this->scopeQuery($tab)->count() : 0,
            'courseCounts' => $usesCourseFilter ? $this->counts($tab, 'course', null) : [],
            'sectionCounts' => $usesCourseFilter && $course !== null ? $this->counts($tab, 'section', $course) : [],
        ]);
    }

    private function proposals(?string $course, ?string $section)
    {
        return ResearchProposal::query()
            ->with([
                'studentProfile.user',
                'assignment' => fn ($assignments) => $assignments->where('status', AdviserAssignment::STATUS_ACTIVE),
                'assignment.facultyProfile.user',
            ])
            ->when($course !== null || $section !== null, fn ($query) => $query->whereHas('studentProfile', function ($students) use ($course, $section): void {
                if ($course !== null) {
                    $students->where('course', $course);
                }
                if ($section !== null) {
                    $students->where('section', $section);
                }
            }))
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    private function students(?string $course, ?string $section)
    {
        return StudentProfile::query()
            ->with('user')
            ->withCount('researchProposals')
            ->withExists(['researchProposals as has_active_adviser' => fn ($proposals) => $proposals
                ->whereHas('assignment', fn ($assignments) => $assignments->where('status', AdviserAssignment::STATUS_ACTIVE))])
            ->when($course !== null, fn ($query) => $query->where('course', $course))
            ->when($section !== null, fn ($query) => $query->where('section', $section))
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    private function faculty(array $courses)
    {
        $query = FacultyProfile::query()
            ->with('user')
            ->withCount('assignments')
            ->withCount(['assignments as active_assignments_count' => fn ($assignments) => $assignments->where('status', AdviserAssignment::STATUS_ACTIVE)]);

        // One column per configured course: active advisees from that course.
        foreach (array_values($courses) as $index => $course) {
            $query->withCount(['assignments as course_'.$index.'_count' => fn ($assignments) => $assignments
                ->where('status', AdviserAssignment::STATUS_ACTIVE)
                ->whereHas('researchProposal.studentProfile', fn ($students) => $students->where('course', $course))]);
        }

        return $query->latest('created_at')->latest('id')->paginate(self::PER_PAGE);
    }

    /** Base query joined to the student's course/section, used only for the filter counts. */
    private function scopeQuery(string $tab)
    {
        return $tab === 'proposals'
            ? DB::table('research_proposals')->join('student_profiles as s', 's.id', '=', 'research_proposals.student_profile_id')
            : DB::table('student_profiles as s');
    }

    /** @return array<string, int> */
    private function counts(string $tab, string $column, ?string $course): array
    {
        $options = config('student_options.'.($column === 'course' ? 'courses' : 'sections'));
        $query = $this->scopeQuery($tab)->whereIn('s.'.$column, $options);
        if ($column === 'section' && $course !== null) {
            $query->where('s.course', $course);
        }
        $rows = $query->selectRaw('s.'.$column.' as option_value, COUNT(*) as aggregate')
            ->groupBy('s.'.$column)
            ->pluck('aggregate', 'option_value');

        $counts = array_fill_keys($options, 0);
        foreach ($rows as $value => $count) {
            $counts[$value] = (int) $count;
        }

        return $counts;
    }

    private function option(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array(trim($value), $allowed, true) ? trim($value) : null;
    }

    /** "BS Information Technology" -> "BSIT", "BS Information Systems" -> "BSIS". */
    private function code(string $course): string
    {
        if (! str_starts_with($course, 'BS ')) {
            return $course;
        }
        $initials = collect(preg_split('/\s+/', trim(substr($course, 3)), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $word): string => strtoupper($word[0]))
            ->implode('');

        return 'BS'.$initials;
    }
}