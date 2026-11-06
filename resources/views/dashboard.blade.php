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
                <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-lg font-semibold text-gray-900">{{ __('Recent Requests') }}</h3><a href="{{ route($role.'.requests.index') }}" class="text-sm text-indigo-600 underline">{{ __('View all requests') }}</a></div>
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
                    <a href="{{ route($role.'.'.($role === 'faculty' ? 'assignments' : 'proposals').'.index') }}" class="text-sm text-indigo-600 underline">{{ $role === 'faculty' ? __('View all assignments') : __('View all proposals') }}</a>
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
</x-app-layout>