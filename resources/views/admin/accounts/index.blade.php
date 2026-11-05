<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Student and Faculty Accounts') }}</h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-6">
                <a href="{{ route('admin.accounts.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 rounded-md text-sm font-semibold text-white hover:bg-gray-700">
                    {{ __('Create Account') }}
                </a>

                @if (session('status'))
                    <p role="status" class="ui-alert text-sm font-medium text-green-700">{{ session('status') }}</p>
                @endif

                <nav aria-label="{{ __('Account types') }}" class="flex flex-wrap gap-4 border-b border-gray-200 pb-3">
                    <a href="{{ route('admin.accounts.index', ['type' => 'student']) }}"
                       @if ($type === 'student') aria-current="page" @endif
                       class="text-sm text-indigo-600 underline">
                        {{ __('Students') }} ({{ $studentCount }})
                    </a>
                    <a href="{{ route('admin.accounts.index', ['type' => 'faculty']) }}"
                       @if ($type === 'faculty') aria-current="page" @endif
                       class="text-sm text-indigo-600 underline">
                        {{ __('Faculty') }} ({{ $facultyCount }})
                    </a>
                </nav>

                @if ($type === 'student')
                    <nav aria-label="{{ __('Filter by course') }}" class="flex flex-wrap gap-2">
                        <a href="{{ route('admin.accounts.index', array_filter(['type' => 'student', 'q' => $search], fn ($value) => $value !== null)) }}"
                           @if ($course === null) aria-current="true" @endif
                           class="button {{ $course === null ? 'button-primary' : 'button-secondary' }}">
                            {{ __('All courses') }} ({{ $allStudentCount }})
                        </a>
                        @foreach (config('student_options.courses') as $option)
                            <a href="{{ route('admin.accounts.index', array_filter([
                                'type' => 'student',
                                'course' => $course === $option ? null : $option,
                                'q' => $search,
                            ], fn ($value) => $value !== null)) }}"
                               @if ($course === $option) aria-current="true" @endif
                               class="button {{ $course === $option ? 'button-primary' : 'button-secondary' }}">
                                {{ $option }} ({{ $courseCounts[$option] ?? 0 }})
                            </a>
                        @endforeach
                    </nav>

                    @if ($course !== null)
                        <nav aria-label="{{ __('Filter by section') }}" class="flex flex-wrap gap-2">
                            <a href="{{ route('admin.accounts.index', array_filter(['type' => 'student', 'course' => $course, 'q' => $search], fn ($value) => $value !== null)) }}"
                               @if ($section === null) aria-current="true" @endif
                               class="button {{ $section === null ? 'button-primary' : 'button-secondary' }}">
                                {{ __('All sections') }} ({{ array_sum($sectionCounts) }})
                            </a>
                            @foreach (config('student_options.sections') as $option)
                                <a href="{{ route('admin.accounts.index', array_filter(['type' => 'student', 'course' => $course, 'section' => $option, 'q' => $search], fn ($value) => $value !== null)) }}"
                                   @if ($section === $option) aria-current="true" @endif
                                   class="button {{ $section === $option ? 'button-primary' : 'button-secondary' }}">
                                    {{ $option }} ({{ $sectionCounts[$option] ?? 0 }})
                                </a>
                            @endforeach
                        </nav>
                    @endif

                    <form method="GET" action="{{ route('admin.accounts.index') }}" class="flex flex-wrap items-end gap-4">
                        <input type="hidden" name="type" value="student">
                        @if ($course !== null)
                            <input type="hidden" name="course" value="{{ $course }}">
                        @endif
                        @if ($section !== null)
                            <input type="hidden" name="section" value="{{ $section }}">
                        @endif
                        <div>
                            <x-input-label for="q" :value="__('Search name or email')" />
                            <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" maxlength="100" :value="$search ?? ''" />
                        </div>
                        <x-primary-button>{{ __('Apply') }}</x-primary-button>
                        <a href="{{ route('admin.accounts.index', ['type' => 'student']) }}" class="text-sm text-indigo-600 underline">{{ __('Clear') }}</a>
                    </form>
                @else
                    <form method="GET" action="{{ route('admin.accounts.index') }}" class="flex flex-wrap items-end gap-4">
                        <input type="hidden" name="type" value="faculty">
                        <div>
                            <x-input-label for="q" :value="__('Search name or email')" />
                            <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" maxlength="100" :value="$search ?? ''" />
                        </div>
                        <x-primary-button>{{ __('Apply') }}</x-primary-button>
                        <a href="{{ route('admin.accounts.index', ['type' => 'faculty']) }}" class="text-sm text-indigo-600 underline">{{ __('Clear') }}</a>
                    </form>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ $type === 'student' ? __('Student accounts') : __('Faculty accounts') }}</caption>
                        <thead>
                            <tr class="border-b border-gray-200">
                                @if ($type === 'student')
                                    <th scope="col" class="px-4 py-2 whitespace-nowrap">{{ __('Name') }}</th>
                                    <th scope="col" class="px-4 py-2 whitespace-nowrap">{{ __('Email') }}</th>
                                    <th scope="col" class="px-4 py-2 whitespace-nowrap">{{ __('Student Number') }}</th>
                                    <th scope="col" class="px-4 py-2 whitespace-nowrap">{{ __('Course') }}</th>
                                    <th scope="col" class="px-2 py-2 whitespace-nowrap">{{ __('Year') }}</th>
                                    <th scope="col" class="px-2 py-2 whitespace-nowrap">{{ __('Section') }}</th>
                                    <th scope="col" class="px-2 py-2 whitespace-nowrap">{{ __('Created') }}</th>
                                    <th scope="col" class="px-2 py-2 whitespace-nowrap">{{ __('Actions') }}</th>
                                @else
                                    <th scope="col" class="px-4 py-2">{{ __('Name') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('Email') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('Department') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('Created') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('Actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($accounts as $account)
                                <tr class="border-b border-gray-100">
                                    <td class="px-4 py-2">{{ $account->name }}</td>
                                    <td class="px-4 py-2 {{ $type === 'student' ? 'break-words' : '' }}">{{ $account->email }}</td>
                                    @if ($type === 'student')
                                        <td class="px-4 py-2">{{ $account->studentProfile?->student_number ?? '—' }}</td>
                                        <td class="px-4 py-2">{{ $account->studentProfile?->course ?? '—' }}</td>
                                        <td class="px-4 py-2">{{ config('student_options.year_levels')[$account->studentProfile?->year_level] ?? '—' }}</td>
                                        <td class="px-4 py-2">{{ $account->studentProfile?->section ?? '—' }}</td>
                                    @else
                                        <td class="px-4 py-2">{{ $account->facultyProfile?->department ?? '—' }}</td>
                                    @endif
                                    <td class="px-4 py-2">{{ $account->created_at?->format('M j, Y') }}</td>
                                    <td class="px-4 py-2"><a href="{{ route('admin.accounts.edit', $account) }}" class="text-indigo-600 underline hover:text-indigo-800">{{ __('Edit') }}</a></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $type === 'student' ? 8 : 5 }}" class="px-4 py-2 text-gray-600">
                                        {{ $type === 'student' ? __('No Student accounts yet.') : __('No Faculty accounts yet.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $accounts->links() }}
            </section>
        </div>
    </div>
</x-app-layout>
