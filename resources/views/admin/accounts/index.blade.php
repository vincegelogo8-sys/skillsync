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
                    <a href="{{ route('admin.accounts.index', array_filter(['type' => 'student', 'status' => $status !== 'all' ? $status : null], fn ($value) => $value !== null)) }}"
                       @if ($type === 'student') aria-current="page" @endif class="text-sm text-indigo-600 underline">
                        {{ __('Students') }} ({{ $studentCount }})
                    </a>
                    <a href="{{ route('admin.accounts.index', array_filter(['type' => 'faculty', 'status' => $status !== 'all' ? $status : null], fn ($value) => $value !== null)) }}"
                       @if ($type === 'faculty') aria-current="page" @endif class="text-sm text-indigo-600 underline">
                        {{ __('Faculty') }} ({{ $facultyCount }})
                    </a>
                </nav>

                @if ($type === 'student')
                    <x-course-section-filter
                        route="admin.accounts.index"
                        :course="$course"
                        :section="$section"
                        :course-counts="$courseCounts"
                        :section-counts="$sectionCounts"
                        :all-course-count="$allStudentCount"
                        :query-params="array_filter(['type' => 'student', 'status' => $status !== 'all' ? $status : null, 'q' => $search], fn ($value) => $value !== null)"
                    />

                    <form method="GET" action="{{ route('admin.accounts.index') }}" class="flex flex-wrap items-end gap-4">
                        <input type="hidden" name="type" value="student">
                        @if ($course !== null)<input type="hidden" name="course" value="{{ $course }}">@endif
                        @if ($section !== null)<input type="hidden" name="section" value="{{ $section }}">@endif
                        @if ($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
                        <div>
                            <x-input-label for="q" :value="__('Search name or email')" />
                            <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" maxlength="100" :value="$search ?? ''" />
                        </div>
                        <x-primary-button>{{ __('Apply') }}</x-primary-button>
                        <a href="{{ route('admin.accounts.index', ['type' => 'student']) }}" class="text-sm text-indigo-600 underline">{{ __('Clear') }}</a>
                    </form>

                    <form method="GET" action="{{ route('admin.accounts.index') }}" class="flex flex-wrap items-end gap-4">
                        <input type="hidden" name="type" value="student">
                        @if ($course !== null)<input type="hidden" name="course" value="{{ $course }}">@endif
                        @if ($section !== null)<input type="hidden" name="section" value="{{ $section }}">@endif
                        @if ($search !== null)<input type="hidden" name="q" value="{{ $search }}">@endif
                        <div>
                            <x-input-label for="status" :value="__('Status')" />
                            <select id="status" name="status" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="all" @selected($status === 'all')>{{ __('All statuses') }}</option>
                                <option value="active" @selected($status === 'active')>{{ __('Active') }}</option>
                                <option value="deactivated" @selected($status === 'deactivated')>{{ __('Deactivated') }}</option>
                            </select>
                        </div>
                        <x-primary-button>{{ __('Apply status') }}</x-primary-button>
                    </form>

                    <div data-bulk-action-bar hidden class="space-y-3 rounded border border-gray-200 p-4">
                        <p data-selection-summary class="text-sm text-gray-700"></p>
                        <a href="#" data-select-all-matching hidden class="text-sm text-indigo-600 underline">
                            {{ __('Select all') }} {{ $accounts->total() }} {{ __('students matching this filter') }}
                        </a>
                        <button type="button" data-clear-matching hidden class="text-sm text-indigo-600 underline">{{ __('Clear selection') }}</button>
                        <div class="flex flex-wrap gap-3">
                            <button type="button" class="button button-danger" data-open-modal="bulk-status-confirm" data-bulk-status-action="deactivate">{{ __('Deactivate selected') }}</button>
                            <button type="button" class="button button-secondary" data-open-modal="bulk-status-confirm" data-bulk-status-action="activate">{{ __('Activate selected') }}</button>
                        </div>
                    </div>
                    <form id="bulk-status-form" method="POST" action="{{ route('admin.accounts.bulk-status') }}">
                        @csrf
                        <input type="hidden" name="action" value="" data-bulk-action-value>
                        <input type="hidden" name="select_all_matching" value="">
                        <input type="hidden" name="type" value="student">
                        @if ($course !== null)<input type="hidden" name="course" value="{{ $course }}">@endif
                        @if ($section !== null)<input type="hidden" name="section" value="{{ $section }}">@endif
                        @if ($search !== null)<input type="hidden" name="q" value="{{ $search }}">@endif
                        @if ($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@else<input type="hidden" name="status" value="all">@endif
                    </form>
                @else
                    <form method="GET" action="{{ route('admin.accounts.index') }}" class="flex flex-wrap items-end gap-4">
                        <input type="hidden" name="type" value="faculty">
                        @if ($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
                        <div>
                            <x-input-label for="q" :value="__('Search name or email')" />
                            <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" maxlength="100" :value="$search ?? ''" />
                        </div>
                        <x-primary-button>{{ __('Apply') }}</x-primary-button>
                        <a href="{{ route('admin.accounts.index', ['type' => 'faculty']) }}" class="text-sm text-indigo-600 underline">{{ __('Clear') }}</a>
                    </form>
                    <form method="GET" action="{{ route('admin.accounts.index') }}" class="flex flex-wrap items-end gap-4">
                        <input type="hidden" name="type" value="faculty">
                        @if ($search !== null)<input type="hidden" name="q" value="{{ $search }}">@endif
                        <div>
                            <x-input-label for="status" :value="__('Status')" />
                            <select id="status" name="status" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="all" @selected($status === 'all')>{{ __('All statuses') }}</option>
                                <option value="active" @selected($status === 'active')>{{ __('Active') }}</option>
                                <option value="deactivated" @selected($status === 'deactivated')>{{ __('Deactivated') }}</option>
                            </select>
                        </div>
                        <x-primary-button>{{ __('Apply status') }}</x-primary-button>
                    </form>
                @endif

                <div class="overflow-x-auto">
                    <table class="table-readable w-full text-left text-sm">
                        <caption class="sr-only">{{ $type === 'student' ? __('Student accounts') : __('Faculty accounts') }}</caption>
                        <thead>
                            <tr class="border-b border-gray-200">
                                @if ($type === 'student')
                                    <th scope="col" class="cell-nowrap px-2 py-2">
                                        <input type="checkbox" aria-label="{{ __('Select all students on this page') }}" data-select-page>
                                    </th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Name') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Email') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Student Number') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Course') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Year') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Section') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Status') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Created') }}</th>
                                    <th scope="col" class="cell-nowrap px-2 py-2">{{ __('Actions') }}</th>
                                @else
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Name') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Email') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Department') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Status') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Created') }}</th>
                                    <th scope="col" class="cell-nowrap px-4 py-2">{{ __('Actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($accounts as $account)
                                <tr class="border-b border-gray-100 {{ $account->isDeactivated() ? 'bg-gray-50 text-gray-500' : '' }}">
                                    @if ($type === 'student')
                                        <td class="px-2 py-2">
                                            <input type="checkbox" name="ids[]" value="{{ $account->id }}" form="bulk-status-form" data-account-checkbox>
                                        </td>
                                    @endif
                                    <td class="cell-name px-4 py-2">{{ $account->name }}</td>
                                    <td class="cell-email px-4 py-2">{{ $account->email }}</td>
                                    @if ($type === 'student')
                                        <td class="cell-nowrap px-4 py-2">{{ $account->studentProfile?->student_number ?? '—' }}</td>
                                        <td class="px-4 py-2">{{ $account->studentProfile?->course ?? '—' }}</td>
                                        <td class="cell-nowrap px-2 py-2">{{ config('student_options.year_levels')[$account->studentProfile?->year_level] ?? '—' }}</td>
                                        <td class="cell-nowrap px-2 py-2">{{ $account->studentProfile?->section ?? '—' }}</td>
                                    @else
                                        <td class="px-4 py-2">{{ $account->facultyProfile?->department ?? '—' }}</td>
                                    @endif
                                    <td class="cell-nowrap px-2 py-2"><x-status-badge :status="$account->isActive() ? 'active' : 'deactivated'" /></td>
                                    <td class="cell-nowrap px-2 py-2">{{ $account->created_at?->format('M j, Y') }}</td>
                                    <td class="px-2 py-2">
                                        <a href="{{ route('admin.accounts.edit', $account) }}" class="text-indigo-600 underline hover:text-indigo-800">{{ __('Edit') }}</a>
                                        <button type="button" class="button {{ $account->isActive() ? 'button-danger' : 'button-secondary' }} mt-2"
                                            data-open-modal="account-status-{{ $account->id }}">
                                            {{ $account->isActive() ? __('Deactivate') : __('Activate') }}
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $type === 'student' ? 10 : 6 }}" class="px-4 py-2 text-gray-600">{{ $type === 'student' ? __('No Student accounts yet.') : __('No Faculty accounts yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $accounts->links() }}

                @foreach ($accounts as $account)
                    @php($deactivate = $account->isActive())
                    <x-modal :name="'account-status-'.$account->id" aria-labelledby="account-status-title-{{ $account->id }}">
                        <form method="POST" action="{{ route('admin.accounts.'.($deactivate ? 'deactivate' : 'activate'), $account) }}" class="p-6">
                            @csrf
                            @method('PATCH')
                            <h2 id="account-status-title-{{ $account->id }}" class="text-lg font-medium text-gray-900">
                                {{ $deactivate ? __('Deactivate') : __('Activate') }} {{ $account->name }}?
                            </h2>
                            <p class="mt-2 text-sm text-gray-600">
                                @if ($deactivate)
                                    {{ __('This account will be logged out immediately. Its data will be kept and it can be reactivated later.') }}
                                @else
                                    {{ __('This account will be able to sign in again. Its existing data will be restored.') }}
                                @endif
                            </p>
                            @if ($deactivate && $account->role === \App\Models\User::ROLE_FACULTY)
                                <p class="mt-2 text-sm text-gray-600">
                                    {{ $account->facultyProfile?->active_assignments_count ?? 0 }} {{ __('active assigned students') }};
                                    {{ $account->facultyProfile?->pending_requests_count ?? 0 }} {{ __('pending requests will stay pending') }}.
                                </p>
                            @endif
                            <div class="mt-6 flex justify-end gap-3">
                                <x-secondary-button type="button" data-close-modal>{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button class="{{ $deactivate ? 'button-danger' : '' }}">{{ $deactivate ? __('Deactivate Account') : __('Activate Account') }}</x-primary-button>
                            </div>
                        </form>
                    </x-modal>
                @endforeach

                @if ($type === 'student')
                    <x-modal name="bulk-status-confirm" aria-labelledby="bulk-status-title">
                        <div class="p-6">
                            <h2 id="bulk-status-title" class="text-lg font-medium text-gray-900" data-bulk-confirm-title></h2>
                            <p class="mt-2 text-sm text-gray-600" data-bulk-confirm-message></p>
                            <div class="mt-6 flex justify-end gap-3">
                                <x-secondary-button type="button" data-close-modal>{{ __('Cancel') }}</x-secondary-button>
                                <button type="submit" form="bulk-status-form" class="button button-primary">{{ __('Confirm') }}</button>
                            </div>
                        </div>
                    </x-modal>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
