<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ $roleLabel }} {{ __('Dashboard') }}</h1></x-slot>

    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="dashboard-welcome rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-2xl font-semibold text-gray-900 break-words">{{ __('Welcome, :name', ['name' => Auth::user()->name]) }}</h3>
            @unless ($profileReady)
                <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p>{{ __('Complete your profile to get started.') }}</p>
                    <a href="{{ route($role.'.profile.edit') }}" class="mt-2 inline-block font-semibold underline">{{ __('Complete Profile') }}</a>
                </div>
            @endunless
        </section>
        <dl class="dashboard-metrics grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($metrics as $metric)
                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <dt class="text-sm text-gray-600"><a href="{{ route($metric['route'], $metric['params'] ?? []) }}" class="underline underline-offset-4">{{ __($metric['label']) }}</a></dt>
                    <dd class="mt-2 text-xl font-semibold text-gray-900 tabular-nums">
                        {{ $metric['value'] }}
                        @if (isset($metric['secondary']))
                            <span class="block text-xs font-normal text-gray-600">{{ $metric['secondary'] }}</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="rounded-lg bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-lg font-semibold text-gray-900">{{ __('Recent Requests') }}</h3><button type="button" class="cursor-pointer text-sm text-indigo-600 underline" data-open-modal="all-requests-modal" aria-haspopup="dialog">{{ __('View all requests') }}</button></div>
                <ul class="mt-4 divide-y divide-gray-100">
                    @forelse ($recentRequestGroups as $group)
                        <li class="py-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __($group['label']) }}</h4>
                            <ul class="divide-y divide-gray-100">
                                @foreach ($group['items'] as $item)
                                    @php($entry = $item['model'])
                                    <li class="py-3">
                                        <p class="font-medium text-gray-900 break-words">{{ $entry->researchProposal->title }}</p>
                                        <p class="mt-1 text-sm text-gray-600">
                                            {{ $role === 'student' ? $entry->facultyProfile->user->name : $entry->studentProfile->user->name }}
                                            · <x-status-badge :status="$entry->status" />
                                            · <time datetime="{{ $item['at']->toIso8601String() }}" title="{{ $item['at']->format('F j, Y g:i A T') }}">{{ $item['at']->format('g:i A') }}</time>
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @empty
                        <li class="py-3"><x-empty-state title="{{ __('No adviser requests in the last 7 days.') }}" /></li>
                    @endforelse
                </ul>
            </section>
            <section class="rounded-lg bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-900">{{ $role === 'faculty' ? __('Recent Assignments') : __('Recent Proposals') }}</h3>
                    @if ($role === 'faculty')
                        <button type="button" class="cursor-pointer text-sm text-indigo-600 underline" data-open-modal="all-assignments-modal" aria-haspopup="dialog">{{ __('View all assignments') }}</button>
                    @else
                        <button type="button" class="cursor-pointer text-sm text-indigo-600 underline" data-open-modal="all-proposals-modal" aria-haspopup="dialog">{{ __('View all proposals') }}</button>
                    @endif
                </div>
                <ul class="mt-4 divide-y divide-gray-100">
                    @if ($role === 'faculty')
                        @forelse ($recentAssignmentGroups as $group)
                            <li class="py-3">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __($group['label']) }}</h4>
                                <ul class="divide-y divide-gray-100">
                                    @foreach ($group['items'] as $item)
                                        @php($entry = $item['model'])
                                        <li class="py-3">
                                            <a href="{{ route('faculty.assignments.index') }}" class="font-medium text-indigo-600 underline break-words">{{ $entry->researchProposal->title }}</a>
                                            <p class="mt-1 text-sm text-gray-600">
                                                {{ $entry->researchProposal->studentProfile->user->name }} · {{ ucfirst($entry->status) }}
                                                · <time datetime="{{ $item['at']->toIso8601String() }}" title="{{ $item['at']->format('F j, Y g:i A T') }}">{{ $item['at']->format('g:i A') }}</time>
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @empty
                            <li class="py-3"><x-empty-state title="{{ __('No adviser assignments in the last 7 days.') }}" /></li>
                        @endforelse
                    @else
                        @forelse ($recentProposalGroups as $group)
                            <li class="py-3">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __($group['label']) }}</h4>
                                <ul class="divide-y divide-gray-100">
                                    @foreach ($group['items'] as $item)
                                        @php($entry = $item['model'])
                                        <li class="py-3">
                                            <a href="{{ route($role.'.proposals.show', $entry) }}" class="font-medium text-indigo-600 underline break-words">{{ $entry->title }}</a>
                                            <p class="mt-1 text-sm text-gray-600">
                                                @if ($role === 'admin')
                                                    {{ __('Uploaded by :name', ['name' => $entry->studentProfile->user->name]) }} ·
                                                @else
                                                    {{ __('Uploaded') }}
                                                @endif
                                                <time datetime="{{ $item['at']->toIso8601String() }}" title="{{ $item['at']->format('F j, Y g:i A T') }}">{{ $item['at']->format('g:i A') }}</time>
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @empty
                            <li class="py-3"><x-empty-state title="{{ __('No proposals in the last 7 days.') }}" /></li>
                        @endforelse
                    @endif
                </ul>
            </section>
        </div>
    </div></div>

    <x-list-modal id="all-requests-modal" :title="__('All Requests')" :total="$allRequestsTotal" :shown="$allRequests->count()" :full-url="route($role.'.requests.index')" :full-label="__('Open the full requests page')">
        <ul class="divide-y divide-gray-100">
            @forelse ($allRequests as $entry)
                @php($at = collect([$entry->requested_at, $entry->responded_at])->filter()->max() ?? $entry->updated_at)
                <li class="py-3">
                    <p class="font-medium text-gray-900 break-words">{{ $entry->researchProposal->title }}</p>
                    <p class="mt-1 text-sm text-gray-600">
                        @if ($role === 'admin')
                            {{ $entry->studentProfile->user->name }} → {{ $entry->facultyProfile->user->name }}
                        @else
                            {{ $role === 'student' ? $entry->facultyProfile->user->name : $entry->studentProfile->user->name }}
                        @endif
                        · <x-status-badge :status="$entry->status" />
                        · <time datetime="{{ $at->toIso8601String() }}">{{ $at->copy()->timezone(config('app.display_timezone', 'Asia/Manila'))->format('M j, Y g:i A') }}</time>
                    </p>
                </li>
            @empty
                <li class="py-3"><x-empty-state title="{{ __('No adviser requests yet.') }}" /></li>
            @endforelse
        </ul>
    </x-list-modal>

    @if ($role !== 'faculty')
        <x-list-modal id="all-proposals-modal" :title="__('All Proposals')" :total="$allProposalsTotal" :shown="$allProposals->count()" :full-url="route($role.'.proposals.index')" :full-label="__('Open the full proposals page')">
            <ul class="divide-y divide-gray-100">
                @forelse ($allProposals as $entry)
                    <li class="py-3">
                        <a href="{{ route($role.'.proposals.show', $entry) }}" class="font-medium text-indigo-600 underline break-words">{{ $entry->title }}</a>
                        <p class="mt-1 text-sm text-gray-600">
                            @if ($role === 'admin')
                                {{ __('Uploaded by :name', ['name' => $entry->studentProfile->user->name]) }} ·
                            @else
                                {{ __('Uploaded') }}
                            @endif
                            <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->copy()->timezone(config('app.display_timezone', 'Asia/Manila'))->format('M j, Y g:i A') }}</time>
                        </p>
                    </li>
                @empty
                    <li class="py-3"><x-empty-state title="{{ __('No proposals yet.') }}" /></li>
                @endforelse
            </ul>
        </x-list-modal>
    @endif

    @if ($role === 'faculty')
        <x-list-modal id="all-assignments-modal" :title="__('All Assignments')" :total="$allAssignmentsTotal" :shown="$allAssignments->count()" :full-url="route('faculty.assignments.index')" :full-label="__('Open the full assignments page')">
            <ul class="divide-y divide-gray-100">
                @forelse ($allAssignments as $entry)
                    <li class="py-3">
                        <p class="font-medium text-gray-900 break-words">{{ $entry->researchProposal->title }}</p>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ $entry->researchProposal->studentProfile->user->name }}
                            · <x-status-badge :status="$entry->status" />
                            @if ($entry->assigned_at)
                                · <time datetime="{{ $entry->assigned_at->toIso8601String() }}">{{ $entry->assigned_at->copy()->timezone(config('app.display_timezone', 'Asia/Manila'))->format('M j, Y g:i A') }}</time>
                            @endif
                        </p>
                    </li>
                @empty
                    <li class="py-3"><x-empty-state title="{{ __('No adviser assignments yet.') }}" /></li>
                @endforelse
            </ul>
        </x-list-modal>
    @endif
</x-app-layout>