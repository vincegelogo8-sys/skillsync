<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ $roleLabel }} {{ __('Dashboard') }}</h1></x-slot>
    <x-slot name="description">{{ __('An overview of your research activity and the next steps in your workspace.') }}</x-slot>
    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="dashboard-welcome rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-2xl font-semibold text-gray-900 break-words">{{ __('Welcome, :name', ['name' => Auth::user()->name]) }}</h3>
            <p class="mt-2 text-gray-600">{{ match ($role) { 'student' => __('Follow your proposal from upload to final adviser assignment.'), 'faculty' => __('Keep your expertise current and review your advising activity.'), default => __('Review research activity and manage faculty and student workflows.') } }}</p>
            @unless ($profileReady)
                <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p>{{ __('Complete your profile to get started. Your account is ready, but your profile details are still needed.') }}</p>
                    <a href="{{ route($role.'.profile.edit') }}" class="mt-2 inline-block font-semibold underline">{{ __('Complete Profile') }}</a>
                </div>
            @endunless
        </section>
        <dl class="dashboard-metrics grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($metrics as $metric)
                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <dt class="text-sm text-gray-600"><a href="{{ route($metric['route']) }}" class="underline underline-offset-4">{{ __($metric['label']) }}</a></dt>
                    <dd class="mt-2 text-xl font-semibold text-gray-900 tabular-nums">{{ $metric['value'] }}</dd>
                </div>
            @endforeach
        </dl>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="rounded-lg bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-lg font-semibold text-gray-900">{{ __('Recent Requests') }}</h3><a href="{{ route($role.'.requests.index') }}" class="text-sm text-indigo-600 underline">{{ __('View all requests') }}</a></div>
                <ul class="mt-4 divide-y divide-gray-100">
                    @forelse ($recentRequests as $entry)
                        <li class="py-3"><p class="font-medium text-gray-900 break-words">{{ $entry->researchProposal->title }}</p><p class="mt-1 text-sm text-gray-600">{{ $role === 'student' ? $entry->facultyProfile->user->name : $entry->studentProfile->user->name }} · <x-status-badge :status="$entry->status" /></p></li>
                    @empty
                        <li class="py-3"><x-empty-state title="{{ __('No adviser requests yet.') }}" /></li>
                    @endforelse
                </ul>
            </section>
            <section class="rounded-lg bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-gray-900">{{ $role === 'faculty' ? __('Recent Assignments') : __('Recent Proposals') }}</h3>
                <ul class="mt-4 divide-y divide-gray-100">
                    @if ($role === 'faculty')
                        @forelse ($recentAssignments as $entry)
                            <li class="py-3"><a href="{{ route('faculty.assignments.index') }}" class="font-medium text-indigo-600 underline break-words">{{ $entry->researchProposal->title }}</a><p class="mt-1 text-sm text-gray-600">{{ $entry->researchProposal->studentProfile->user->name }} · {{ ucfirst($entry->status) }}</p></li>
                        @empty
                            <li class="py-3"><x-empty-state title="{{ __('No adviser assignments yet.') }}" /></li>
                        @endforelse
                    @else
                        @forelse ($recentProposals as $entry)
                            <li class="py-3"><a href="{{ route($role.'.proposals.show', $entry) }}" class="font-medium text-indigo-600 underline break-words">{{ $entry->title }}</a><p class="mt-1 text-sm text-gray-600">{{ __('Uploaded') }} {{ $entry->created_at->format('Y-m-d') }}</p></li>
                        @empty
                            <li class="py-3"><x-empty-state title="{{ __('No research proposals uploaded yet.') }}" /></li>
                        @endforelse
                    @endif
                </ul>
            </section>
        </div>
        @foreach (['primary' => 'Your Workspace', 'secondary' => config('workspace_navigation.'.$role.'.secondary_label')] as $group => $heading)
            <section>
                <h3 class="mb-3 text-lg font-semibold text-gray-900">{{ __($heading) }}</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (config('workspace_navigation.'.$role.'.'.$group) as $link)
                        <a href="{{ route($link['route']) }}" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-indigo-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                            <h4 class="font-semibold text-indigo-700">{{ __($link['label']) }}</h4><p class="mt-2 text-sm text-gray-600">{{ __($link['description']) }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div></div>
</x-app-layout>