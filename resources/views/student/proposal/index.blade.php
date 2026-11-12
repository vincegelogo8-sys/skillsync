
<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800">
            {{ __('Research Proposals') }}
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">

                {{-- HEADER --}}
                <div class="flex flex-wrap items-center justify-between gap-4">
                    @unless ($admin)
                        <a
                            href="{{ route('student.proposals.create') }}"
                            class="button button-primary"
                        >
                            {{ __('Upload Proposal') }}
                        </a>
                    @endunless
                </div>

                <x-input-error :messages="$errors->get('upload')" class="mt-6" />

                {{-- DELETE SUCCESS --}}
                @if (session('status') === 'proposal-deleted')
                    <div
                        role="status"
                        class="ui-alert mt-6 rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-700"
                    >
                        {{ __('Research proposal deleted successfully.') }}
                    </div>
                @endif

                {{-- ADMIN FILTERS --}}
                @if ($admin)
                    @php
                        $filterParams = [
                            ...$filters,
                            'sort' => $sorting['sort'],
                            'dir' => $sorting['dir'],
                        ];
                    @endphp

                    <div class="mt-6 space-y-4">
                        <x-course-section-filter
                            route="admin.proposals.index"
                            :course="$filters['course']"
                            :section="$filters['section']"
                            :course-counts="$courseCounts"
                            :section-counts="$sectionCounts"
                            :all-course-count="$allCourseCount"
                            :query-params="$filterParams"
                        />

                        <form
                            method="GET"
                            action="{{ route('admin.proposals.index') }}"
                            class="flex flex-wrap items-end gap-3"
                        >
                            @foreach (['course', 'section', 'sort', 'dir'] as $key)
                                @if (($filterParams[$key] ?? null) !== null)
                                    <input
                                        type="hidden"
                                        name="{{ $key }}"
                                        value="{{ $filterParams[$key] }}"
                                    >
                                @endif
                            @endforeach

                            <div>
                                <x-input-label for="adviser" :value="__('Adviser')" />

                                <select
                                    id="adviser"
                                    name="adviser"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="">{{ __('All advisers') }}</option>

                                    <option
                                        value="none"
                                        @selected($filters['adviser'] === 'none')
                                    >
                                        {{ __('No adviser yet') }}
                                    </option>

                                    @foreach ($facultyOptions as $faculty)
                                        <option
                                            value="{{ $faculty->id }}"
                                            @selected($filters['adviser'] === (string) $faculty->id)
                                        >
                                            {{ $faculty->user->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <button type="submit" class="button button-secondary">
                                {{ __('Apply') }}
                            </button>
                        </form>

                        <x-sort-controls
                            route="admin.proposals.index"
                            :options="$sortOptions"
                            :sort="$sorting['sort']"
                            :dir="$sorting['dir']"
                            :query-params="$filterParams"
                        />
                    </div>
                @endif

                {{-- PROPOSALS --}}
                <div class="mt-4 divide-y divide-gray-200">
                    @forelse ($proposals as $proposal)
                        <article class="py-5">

                            <div class="flex flex-wrap items-start justify-between gap-4">

                                {{-- PROPOSAL INFORMATION --}}
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-medium text-gray-900 break-words">
                                        {{ $proposal->title }}
                                    </h3>

                                    @if ($admin)
                                        @php($studentProfile = $proposal->studentProfile)

                                        <p class="mt-1 text-sm text-gray-600 break-words">
                                            {{ $studentProfile?->user?->name ?? __('Former account') }}

                                            @if (
                                                $studentProfile?->user
                                                && method_exists($studentProfile->user, 'isDeactivated')
                                                && $studentProfile->user->isDeactivated()
                                            )
                                                <x-status-badge status="inactive">
                                                    {{ __('Inactive') }}
                                                </x-status-badge>
                                            @endif

                                            · {{ $studentProfile?->student_number ?? '—' }}
                                            · {{ $studentProfile?->course ?? '—' }}
                                            · {{ config('student_options.year_levels')[$studentProfile?->year_level] ?? '—' }}
                                            · {{ $studentProfile?->section ?? '—' }}
                                        </p>

                                        <p class="mt-1 text-sm text-gray-600">
                                            {{ __('Adviser') }}:

                                            @if ($proposal->assignment?->facultyProfile?->user)
                                                {{ $proposal->assignment->facultyProfile->user->name }}
                                            @else
                                                <x-status-badge status="neutral">
                                                    {{ __('No adviser yet') }}
                                                </x-status-badge>
                                            @endif
                                        </p>
                                    @endif

                                    <p class="mt-1 text-sm text-gray-600">
                                        {{ strtoupper($proposal->file_type) }}
                                        · {{ __('Uploaded') }}
                                        {{ $proposal->created_at->format('Y-m-d H:i T') }}
                                    </p>

                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ __('Status') }}:
                                        <x-status-badge :status="$proposal->status" />
                                    </p>
                                </div>

                                {{-- ACTIONS --}}
                                <div class="flex flex-wrap items-center gap-3">
                                    @if ($admin)
                                        <a
                                            href="{{ route('admin.proposals.show', $proposal) }}"
                                            class="button button-secondary"
                                        >
                                            {{ __('View') }}
                                        </a>
                                    @else
                                        <form
                                            method="POST"
                                            action="{{ route('student.proposals.destroy', $proposal) }}"
                                            data-confirm="Are you sure you want to permanently delete this proposal? The uploaded document and its related analysis and recommendation data will also be deleted."
                                            data-confirm-title="Delete proposal?"
                                            data-confirm-button="Yes, delete"
                                            data-confirm-tone="danger"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <button
                                                type="submit"
                                                class="button button-danger"
                                                @disabled($proposal->assignment)
                                            >
                                                {{ __('Delete') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            {{-- STUDENT EXTRACTED TEXT --}}
                            @unless ($admin)
                                <div class="mt-5">
                                    <details class="rounded-lg border border-gray-200">
                                        <summary class="cursor-pointer bg-gray-50 px-4 py-3 font-medium text-indigo-700 hover:bg-gray-100">
                                            {{ __('View Extracted Proposal') }}
                                        </summary>

                                        <div class="border-t border-gray-200 p-4">
                                            @if (
                                                $proposal->analysis
                                                && filled($proposal->analysis->extracted_text)
                                            )
                                                <div
                                                    class="max-h-96 overflow-y-auto overflow-x-hidden rounded-md border border-gray-200 bg-white p-4 text-sm leading-7 text-gray-800 whitespace-pre-wrap break-words"
                                                    tabindex="0"
                                                    aria-label="Extracted proposal text"
                                                >{{ $proposal->analysis->extracted_text }}</div>
                                            @else
                                                <p class="text-sm text-gray-600">
                                                    {{ __('No extracted text is available for this proposal yet.') }}
                                                </p>

                                                <a
                                                    href="{{ route('student.proposals.show', $proposal) }}"
                                                    class="mt-2 inline-block text-sm text-indigo-600 underline"
                                                >
                                                    {{ __('Open proposal to extract document text') }}
                                                </a>
                                            @endif
                                        </div>
                                    </details>
                                </div>
                            @endunless

                        </article>
                    @empty
                        <x-empty-state title="{{ __('No proposals uploaded.') }}" />
                    @endforelse
                </div>

                {{-- PAGINATION --}}
                <div class="mt-4">
                    {{ $proposals->links() }}
                </div>

            </section>
        </div>
    </div>
</x-app-layout>
