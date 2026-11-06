<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ $role === 'faculty' ? __('Assigned Students') : __('Adviser Assignments') }}</h1></x-slot>

    <div class="py-12"><section class="mx-auto max-w-7xl space-y-5 bg-white p-6 shadow sm:rounded-lg">
        @if (session('status') === 'adviser-assigned')<p role="status" class="ui-alert text-sm text-green-700">{{ __('Request accepted.') }}</p>@endif

        @if ($role === 'student')
            <p class="text-sm text-gray-600">{{ __('Adviser Assignments') }}</p>
            <div class="divide-y divide-gray-200">
                @forelse ($assignments as $assignment)
                    <article class="py-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <h3 class="font-semibold text-gray-900 break-words">{{ $assignment->researchProposal->title }}</h3>
                            <x-status-badge :status="$assignment->status" />
                        </div>
                        <p class="mt-2 text-sm text-gray-700">
                            {{ __('Adviser') }}: {{ $assignment->facultyProfile->user->name }}
                            @if (method_exists($assignment->facultyProfile->user, 'isDeactivated') && $assignment->facultyProfile->user->isDeactivated()) <x-status-badge status="inactive">{{ __('Inactive') }}</x-status-badge> @endif
                        </p>
                        <p class="mt-2 text-sm text-gray-700">{{ __('Approved by') }}: {{ $assignment->approver?->name ?? __('Former account') }}</p>
                        <p class="mt-2 text-xs text-gray-600">{{ __('Assigned') }} {{ $assignment->assigned_at->format('Y-m-d H:i T') }}</p>
                        <a href="{{ route('student.proposals.show', $assignment->researchProposal) }}" class="mt-3 inline-block text-sm text-indigo-600 underline">{{ __('View proposal') }}</a>
                    </article>
                @empty
                    <x-empty-state title="{{ __('No adviser assignments.') }}" />
                @endforelse
            </div>
            <div>{{ $assignments->links() }}</div>
        @else
            @if ($role === 'faculty')
                <p class="text-lg font-semibold text-gray-900">
                    {{ $capacity['load'] }} {{ __('of') }} {{ $capacity['limit'] }} {{ __('students') }}
                    @if ($capacity['is_full']) <x-status-badge status="full">FULL</x-status-badge> @endif
                </p>
            @endif

            <nav aria-label="{{ __('Assignment view') }}" class="flex flex-wrap gap-3 border-b border-gray-200 pb-3">
                @if ($role === 'admin')
                    <a href="{{ route('admin.assignments.index', array_filter([...$filters, 'view' => 'grouped'])) }}"
                       @if ($viewMode === 'grouped') aria-current="page" @endif
                       class="button {{ $viewMode === 'grouped' ? 'button-primary' : 'button-secondary' }}">{{ __('Grouped by adviser') }}</a>
                    <a href="{{ route('admin.assignments.index', array_filter([...$filters, 'view' => 'list'])) }}"
                       @if ($viewMode === 'list') aria-current="page" @endif
                       class="button {{ $viewMode === 'list' ? 'button-primary' : 'button-secondary' }}">{{ __('Flat list') }}</a>
                @else
                    <a href="{{ route('faculty.assignments.index', array_filter([...$filters, 'view' => 'list'])) }}"
                       @if ($viewMode === 'list') aria-current="page" @endif
                       class="button {{ $viewMode === 'list' ? 'button-primary' : 'button-secondary' }}">{{ __('List') }}</a>
                    <a href="{{ route('faculty.assignments.index', array_filter([...$filters, 'view' => 'sections'])) }}"
                       @if ($viewMode === 'sections') aria-current="page" @endif
                       class="button {{ $viewMode === 'sections' ? 'button-primary' : 'button-secondary' }}">{{ __('Sections') }}</a>
                @endif
            </nav>

            <x-course-section-filter
                :route="$role === 'admin' ? 'admin.assignments.index' : 'faculty.assignments.index'"
                :course="$filters['course']"
                :section="$filters['section']"
                :course-counts="$courseCounts"
                :section-counts="$sectionCounts"
                :all-course-count="$allCourseCount"
                :query-params="[...$queryParams, 'view' => $viewMode]"
            />

            <form method="GET" action="{{ route($role.'.assignments.index') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="view" value="{{ $viewMode }}">
                @foreach (['course', 'section', 'sort', 'dir'] as $key)
                    @if (($queryParams[$key] ?? null) !== null)
                        <input type="hidden" name="{{ $key }}" value="{{ $queryParams[$key] }}">
                    @endif
                @endforeach
                <div>
                    <x-input-label for="q" :value="__('Search')" />
                    <input id="q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                </div>
                <button type="submit" class="button button-secondary">{{ __('Apply') }}</button>
                <a href="{{ route($role.'.assignments.index', ['view' => $viewMode]) }}" class="text-sm text-indigo-600 underline">{{ __('Clear') }}</a>
            </form>

            <x-sort-controls
                :route="$role === 'admin' ? 'admin.assignments.index' : 'faculty.assignments.index'"
                :options="$sortOptions"
                :sort="$sorting['sort']"
                :dir="$sorting['dir']"
                :query-params="[...$queryParams, 'view' => $viewMode]"
            />

            @if ($role === 'admin' && $viewMode === 'grouped')
                @if ($advisers->getCollection()->contains(fn (array $adviser) => $adviser['assignments']->isNotEmpty()))
                    <div class="flex flex-wrap gap-3 pb-3">
                        <button type="button" class="button button-secondary" data-accordion-expand-all hidden>{{ __('Expand all') }}</button>
                        <button type="button" class="button button-secondary" data-accordion-collapse-all hidden>{{ __('Collapse all') }}</button>
                    </div>
                @endif
                <div class="divide-y divide-gray-200">
                    @forelse ($advisers as $adviser)
                        @php
                            $faculty = $adviser['faculty'];
                        @endphp
                        @php
                            $load = $adviser['capacity']['load'];
                            $limit = $adviser['capacity']['limit'];
                        @endphp
                        @if ($adviser['assignments']->isEmpty())
                            <div class="py-5">
                                <div class="adviser-summary adviser-summary-empty">
                                    <span class="adviser-name">{{ $faculty->name }}
                                        @if (method_exists($faculty, 'isDeactivated') && $faculty->isDeactivated()) <x-status-badge status="inactive">{{ __('Inactive') }}</x-status-badge> @endif
                                    </span>
                                    <span class="adviser-department">{{ $adviser['profile']?->department ?? '—' }}</span>
                                    <span class="adviser-load">{{ $load }} {{ __('of') }} {{ $limit }} {{ __('students') }}</span>
                                </div>
                                <p class="mt-3 text-sm text-gray-600">{{ __('No students assigned yet.') }}</p>
                            </div>
                        @else
                            <details class="adviser-group py-5" @if ($adviser['hasStudentFilter']) open @endif>
                                <summary class="adviser-summary">
                                    <span class="adviser-name">{{ $faculty->name }}
                                        @if (method_exists($faculty, 'isDeactivated') && $faculty->isDeactivated()) <x-status-badge status="inactive">{{ __('Inactive') }}</x-status-badge> @endif
                                    </span>
                                    <span class="adviser-department">{{ $adviser['profile']?->department ?? '—' }}</span>
                                    <span class="adviser-load">{{ $load }} {{ __('of') }} {{ $limit }} {{ __('students') }}</span>
                                    <svg class="adviser-chevron" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                        <path d="m7 4 6 6-6 6" />
                                    </svg>
                                </summary>
                                <div class="mt-4 overflow-x-auto">
                                    <table class="table-readable w-full text-left text-sm">
                                        <thead><tr class="border-b border-gray-200">
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Student') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Student No.') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Course') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Year') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Section') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Research topic') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Assigned date') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Approved by') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Proposal') }}</th>
                                            <th scope="col" class="cell-nowrap px-3 py-2">{{ __('Status') }}</th>
                                        </tr></thead>
                                        <tbody>
                                            @foreach ($adviser['assignments'] as $assignment)
                                                @php
                                                    $studentProfile = $assignment->researchProposal->studentProfile;
                                                @endphp
                                                <tr class="border-b border-gray-100">
                                                    <td class="cell-name px-3 py-2">
                                                        {{ $studentProfile?->user?->name ?? __('Former account') }}
                                                        @if ($studentProfile?->user && method_exists($studentProfile->user, 'isDeactivated') && $studentProfile->user->isDeactivated()) <x-status-badge status="inactive">{{ __('Inactive') }}</x-status-badge> @endif
                                                    </td>
                                                    <td class="cell-nowrap px-3 py-2">{{ $studentProfile?->student_number ?? '—' }}</td>
                                                    <td class="px-3 py-2">{{ $studentProfile?->course ?? '—' }}</td>
                                                    <td class="cell-nowrap px-3 py-2">{{ config('student_options.year_levels')[$studentProfile?->year_level] ?? '—' }}</td>
                                                    <td class="cell-nowrap px-3 py-2">{{ $studentProfile?->section ?? '—' }}</td>
                                                    <td class="cell-topic px-3 py-2">{{ $assignment->researchProposal->title }}</td>
                                                    <td class="cell-nowrap px-3 py-2">{{ $assignment->assigned_at?->format('Y-m-d') }}</td>
                                                    <td class="cell-name px-3 py-2">{{ $assignment->approver?->name ?? __('Former account') }}</td>
                                                    <td class="cell-nowrap px-3 py-2"><a class="text-indigo-600 underline" href="{{ route('admin.proposals.show', $assignment->researchProposal) }}">{{ __('View proposal') }}</a></td>
                                                    <td class="cell-nowrap px-3 py-2"><x-status-badge :status="$assignment->status" /></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    @empty
                        <x-empty-state title="{{ __('No adviser assignments.') }}" />
                    @endforelse
                </div>
                {{ $advisers->links() }}
            @elseif ($role === 'admin')
                <div class="divide-y divide-gray-200">
                    @forelse ($assignments as $assignment)
                        @php
                            $studentProfile = $assignment->researchProposal->studentProfile;
                        @endphp
                        <article class="py-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <h2 class="font-semibold text-gray-900">{{ $assignment->researchProposal->title }}</h2>
                                <x-status-badge :status="$assignment->status" />
                            </div>
                            <p class="mt-2 text-sm text-gray-700">{{ __('Adviser') }}: {{ $assignment->facultyProfile->user->name }}
                                @if (method_exists($assignment->facultyProfile->user, 'isDeactivated') && $assignment->facultyProfile->user->isDeactivated()) <x-status-badge status="inactive">{{ __('Inactive') }}</x-status-badge> @endif
                            </p>
                            <p class="mt-1 text-sm text-gray-600">{{ __('Student') }}: {{ $studentProfile?->user?->name ?? __('Former account') }}
                                @if ($studentProfile?->user && method_exists($studentProfile->user, 'isDeactivated') && $studentProfile->user->isDeactivated()) <x-status-badge status="inactive">{{ __('Inactive') }}</x-status-badge> @endif
                                · {{ $studentProfile?->student_number ?? '—' }} · {{ $studentProfile?->course ?? '—' }} · {{ config('student_options.year_levels')[$studentProfile?->year_level] ?? '—' }} · {{ $studentProfile?->section ?? '—' }}
                            </p>
                            <p class="mt-1 text-xs text-gray-600">{{ __('Assigned') }} {{ $assignment->assigned_at?->format('Y-m-d H:i T') }} · {{ __('Approved by') }} {{ $assignment->approver?->name ?? __('Former account') }}</p>
                            <a href="{{ route('admin.proposals.show', $assignment->researchProposal) }}" class="mt-2 inline-block text-sm text-indigo-600 underline">{{ __('View proposal') }}</a>
                        </article>
                    @empty
                        <x-empty-state title="{{ __('No adviser assignments.') }}" />
                    @endforelse
                </div>
                {{ $assignments->links() }}
            @else
                @php
                    $sectionGroups = $viewMode === 'sections'
                        ? $assignments->getCollection()->groupBy(fn ($assignment) => ($assignment->researchProposal->studentProfile?->course ?? '—').'|'.($assignment->researchProposal->studentProfile?->section ?? '—'))
                        : collect(['' => $assignments->getCollection()]);
                @endphp
                @if ($assignments->isEmpty())
                    <div class="divide-y divide-gray-200">
                        <x-empty-state title="{{ __('No adviser assignments.') }}" />
                    </div>
                @else
                    <div class="divide-y divide-gray-200">
                        @foreach ($sectionGroups as $groupKey => $groupAssignments)
                            @if ($viewMode === 'sections')
                                @php
                                    [$courseHeading, $sectionHeading] = explode('|', $groupKey, 2);
                                @endphp
                                <h2 class="pt-5 font-semibold text-gray-900">{{ $courseHeading }} · {{ __('Section') }} {{ $sectionHeading }}</h2>
                            @endif
                            @foreach ($groupAssignments as $assignment)
                                @php
                                    $studentProfile = $assignment->researchProposal->studentProfile;
                                @endphp
                                <article class="py-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <h3 class="font-semibold text-gray-900">{{ $studentProfile?->user?->name ?? __('Former account') }}
                                            @if ($studentProfile?->user && method_exists($studentProfile->user, 'isDeactivated') && $studentProfile->user->isDeactivated()) <x-status-badge status="inactive">{{ __('Inactive') }}</x-status-badge> @endif
                                        </h3>
                                        <x-status-badge :status="$assignment->status" />
                                    </div>
                                    <p class="mt-1 text-sm text-gray-600">{{ __('Student No.') }}: {{ $studentProfile?->student_number ?? '—' }} · {{ __('Course') }}: {{ $studentProfile?->course ?? '—' }} · {{ __('Year') }}: {{ config('student_options.year_levels')[$studentProfile?->year_level] ?? '—' }} · {{ __('Section') }}: {{ $studentProfile?->section ?? '—' }}</p>
                                    <p class="mt-1 text-sm text-gray-700">{{ __('Research topic') }}: {{ $assignment->researchProposal->title }}</p>
                                    <p class="mt-1 text-xs text-gray-600">{{ __('Assigned') }} {{ $assignment->assigned_at?->format('Y-m-d H:i T') }}</p>
                                    @if ($assignment->status === \App\Models\AdviserAssignment::STATUS_ACTIVE)
                                        <a href="{{ route('faculty.assignments.proposal', $assignment) }}" class="mt-2 inline-block text-sm text-indigo-600 underline">{{ __('View proposal') }}</a>
                                    @endif
                                </article>
                            @endforeach
                        @endforeach
                    </div>
                @endif
                {{ $assignments->links() }}
            @endif
        @endif
    </section></div>
</x-app-layout>
