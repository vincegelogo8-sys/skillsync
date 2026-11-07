@php
    $tz = config('app.display_timezone', 'Asia/Manila');
    $when = fn ($date) => $date?->copy()->timezone($tz)->format('M j, Y g:i A');
    $tabs = ['proposals' => __('Research Proposals'), 'students' => __('Students'), 'faculty' => __('Faculty')];
    $tabUrl = fn (string $name) => route('admin.history.index', ['tab' => $name]);
@endphp
<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ __('History') }}</h1></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
                <p class="text-sm text-gray-600">{{ __('Every proposal, student and faculty record in the system, newest first.') }}</p>

                <nav aria-label="{{ __('History sections') }}" class="flex flex-wrap gap-4 border-b border-gray-200 pb-3">
                    @foreach ($tabs as $name => $label)
                        <a href="{{ $tabUrl($name) }}" @if ($tab === $name) aria-current="page" @endif
                           class="text-sm {{ $tab === $name ? 'font-semibold text-gray-900 underline' : 'text-indigo-600 underline' }}">
                            {{ $label }} ({{ $tabTotals[$name] }})
                        </a>
                    @endforeach
                </nav>

                @if ($tab !== 'faculty')
                    <x-course-section-filter
                        route="admin.history.index"
                        :course="$course"
                        :section="$section"
                        :course-counts="$courseCounts"
                        :section-counts="$sectionCounts"
                        :all-course-count="$allCourseCount"
                        :query-params="['tab' => $tab]"
                    />
                @else
                    <p class="text-sm text-gray-600">{{ __('Faculty are not assigned to a course. Active advisees are counted per course instead.') }}</p>
                @endif

                <div class="overflow-x-auto">
                    <table class="table-readable w-full text-left text-sm">
                        <caption class="sr-only">{{ $tabs[$tab] }}</caption>
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-700">
                                @if ($tab === 'proposals')
                                    <th scope="col" class="px-4 py-2">{{ __('Title') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Student') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Course') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Section') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Adviser') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Uploaded') }}</th>
                                @elseif ($tab === 'students')
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Name') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Email') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Student Number') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Course') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Year') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Section') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Proposals') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Adviser') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Status') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Registered') }}</th>
                                @else
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Name') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Email') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Department') }}</th>
                                    @foreach ($courses as $courseName)
                                        <th scope="col" class="cell-nowrap px-2 py-2">{{ $courseCodes[$courseName] }} {{ __('advisees') }}</th>
                                    @endforeach
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Active load') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('All assignments') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Status') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Registered') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $row)
                                <tr class="align-top">
                                    @if ($tab === 'proposals')
                                        @php($student = $row->studentProfile)
                                        <td class="cell-topic px-4 py-3"><a href="{{ route('admin.proposals.show', $row) }}" class="font-medium text-indigo-600 underline break-words">{{ $row->title }}</a></td>
                                        <td class="cell-name px-4 py-3">{{ $student?->user?->name ?? '—' }}</td>
                                        <td class="cell-nowrap px-2 py-3">{{ $student ? ($courseCodes[$student->course] ?? $student->course) : '—' }}</td>
                                        <td class="cell-nowrap px-2 py-3">{{ $student?->section ?? '—' }}</td>
                                        <td class="cell-name px-4 py-3">{{ $row->assignment?->facultyProfile?->user?->name ?? __('None') }}</td>
                                        <td class="cell-nowrap px-4 py-3"><time datetime="{{ $row->created_at->toIso8601String() }}">{{ $when($row->created_at) }}</time></td>
                                    @elseif ($tab === 'students')
                                        <td class="cell-name px-4 py-3 font-medium text-gray-900">{{ $row->user->name }}</td>
                                        <td class="cell-email px-4 py-3">{{ $row->user->email }}</td>
                                        <td class="cell-nowrap px-4 py-3">{{ $row->student_number }}</td>
                                        <td class="cell-nowrap px-2 py-3">{{ $courseCodes[$row->course] ?? $row->course }}</td>
                                        <td class="cell-nowrap px-2 py-3">{{ $row->year_level }}</td>
                                        <td class="cell-nowrap px-2 py-3">{{ $row->section }}</td>
                                        <td class="cell-nowrap px-2 py-3 tabular-nums">{{ $row->research_proposals_count }}</td>
                                        <td class="cell-nowrap px-2 py-3">{{ $row->has_active_adviser ? __('Assigned') : __('None') }}</td>
                                        <td class="cell-nowrap px-2 py-3"><x-status-badge :status="$row->user->isDeactivated() ? 'deactivated' : 'active'" /></td>
                                        <td class="cell-nowrap px-4 py-3"><time datetime="{{ $row->created_at->toIso8601String() }}">{{ $when($row->created_at) }}</time></td>
                                    @else
                                        <td class="cell-name px-4 py-3 font-medium text-gray-900">{{ $row->user->name }}</td>
                                        <td class="cell-email px-4 py-3">{{ $row->user->email }}</td>
                                        <td class="px-4 py-3">{{ $row->department }}</td>
                                        @foreach ($courses as $index => $courseName)
                                            <td class="cell-nowrap px-2 py-3 tabular-nums">{{ $row->{'course_'.$index.'_count'} }}</td>
                                        @endforeach
                                        <td class="cell-nowrap px-2 py-3 tabular-nums">{{ $row->active_assignments_count }} / {{ $row->advisory_limit }}</td>
                                        <td class="cell-nowrap px-2 py-3 tabular-nums">{{ $row->assignments_count }}</td>
                                        <td class="cell-nowrap px-2 py-3"><x-status-badge :status="$row->user->isDeactivated() ? 'deactivated' : 'active'" /></td>
                                        <td class="cell-nowrap px-4 py-3"><time datetime="{{ $row->created_at->toIso8601String() }}">{{ $when($row->created_at) }}</time></td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="12" class="px-4 py-6"><x-empty-state title="{{ __('No records found.') }}" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $items->links() }}
            </section>
        </div>
    </div>
</x-app-layout>