@php
    $taglines = ['admin' => __('Manage the system and keep ISATU connected.'), 'faculty' => __('Teach. Guide. Inspire.'), 'student' => __('Dream. Learn. Achieve.')];
    $overviewTitles = ['admin' => __('System Overview'), 'faculty' => __('My Advisory'), 'student' => __('My Proposals')];
    $byLabel = collect($metrics)->keyBy('label');

    // Ring chart: only real numbers already shown on this page.
    if ($role === 'admin') {
        $total = (int) ($byLabel['Research Proposals']['value'] ?? 0);
        $without = (int) ($byLabel['Proposals without an adviser']['value'] ?? 0);
        $ringPercent = $total > 0 ? (int) round((($total - $without) / $total) * 100) : 0;
        $ringCenter = $ringPercent.'%';
        $ringCaption = __('of proposals have an adviser');
    } elseif ($role === 'faculty') {
        $limit = (int) ($capacity['limit'] ?? 0);
        $load = (int) ($capacity['load'] ?? 0);
        $ringPercent = $limit > 0 ? min(100, (int) round(($load / $limit) * 100)) : 0;
        $ringCenter = $capacity ? $load.'/'.$limit : '–';
        $ringCaption = __('advisory load');
    } else {
        $total = (int) ($byLabel['Research Proposals']['value'] ?? 0);
        $awaiting = (int) ($byLabel['Awaiting Analysis']['value'] ?? 0);
        $ringPercent = $total > 0 ? (int) round((($total - $awaiting) / $total) * 100) : 0;
        $ringCenter = $ringPercent.'%';
        $ringCaption = __('of proposals analyzed');
    }

    $quickActions = match ($role) {
        'admin' => [
            ['route' => 'admin.accounts.create', 'label' => __('Add Account'), 'icon' => 'users'],
            ['route' => 'admin.requests.index', 'label' => __('Adviser Requests'), 'icon' => 'inbox'],
            ['route' => 'admin.advisory-limits.index', 'label' => __('Advisory Limits'), 'icon' => 'sliders'],
        ],
        'faculty' => [
            ['route' => 'faculty.requests.index', 'label' => __('Adviser Requests'), 'icon' => 'inbox'],
            ['route' => 'faculty.assignments.index', 'label' => __('Assigned Students'), 'icon' => 'graduation-cap'],
            ['route' => 'faculty.expertise.index', 'label' => __('Faculty Skills'), 'icon' => 'sparkles'],
        ],
        default => [
            ['route' => 'student.proposals.create', 'label' => __('Upload Proposal'), 'icon' => 'document'],
            ['route' => 'student.requests.index', 'label' => __('Adviser Requests'), 'icon' => 'inbox'],
            ['route' => 'student.profile.edit', 'label' => __('Student Profile'), 'icon' => 'user'],
        ],
    };
    $circumference = 2 * M_PI * 52;
@endphp
<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ $roleLabel }} {{ __('Dashboard') }}</h1></x-slot>
    <x-slot name="hero">
        <div class="hero-welcome">
            <x-user-avatar class="hero-avatar" :badge="$role" />
            <div class="hero-text">
                <p class="hero-kicker">{{ __('Welcome back,') }}</p>
                <p class="hero-name">{{ Auth::user()->name }}!</p>
                <p class="hero-tagline">{{ $taglines[$role] ?? '' }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        @unless ($profileReady)
            <div class="dash-notice">
                <p>{{ __('Complete your profile to get started.') }}</p>
                <a href="{{ route($role.'.profile.edit') }}" class="button button-primary">{{ __('Complete Profile') }}</a>
            </div>
        @endunless

        <div class="dash-grid">
            <section class="dash-card dash-overview" aria-labelledby="overview-title">
                <div class="dash-card-head">
                    <h3 id="overview-title"><span class="dash-card-icon"><x-nav-icon name="dashboard" /></span>{{ $overviewTitles[$role] ?? __('Overview') }}</h3>
                </div>
                <div class="dash-overview-body">
                    <figure class="dash-ring" role="img" aria-label="{{ $ringCenter }} {{ $ringCaption }}">
                        <svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
                            <circle class="dash-ring-track" cx="60" cy="60" r="52" />
                            @if ($ringPercent > 0)
                            <circle class="dash-ring-value" cx="60" cy="60" r="52" stroke-dasharray="{{ round($circumference * $ringPercent / 100, 2) }} {{ round($circumference, 2) }}" transform="rotate(-90 60 60)" />
                            @endif
                        </svg>
                        <figcaption><strong>{{ $ringCenter }}</strong><span>{{ $ringCaption }}</span></figcaption>
                    </figure>
                    <dl class="dashboard-metrics dash-tiles">
                        @foreach ($metrics as $metric)
                            <div class="dash-tile">
                                <dt><a href="{{ route($metric['route'], $metric['params'] ?? []) }}">{{ __($metric['label']) }}</a></dt>
                                <dd class="{{ is_numeric($metric['value']) ? '' : 'is-text' }}">
                                    {{ $metric['value'] }}
                                    @if (isset($metric['secondary']))
                                        <span class="dash-tile-note">{{ $metric['secondary'] }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>

            <section class="dash-card dash-quick" aria-labelledby="quick-title">
                <h3 id="quick-title">{{ __('Quick Actions') }}</h3>
                <div class="dash-quick-grid">
                    @foreach ($quickActions as $action)
                        <a href="{{ route($action['route']) }}" class="dash-quick-tile">
                            <x-nav-icon :name="$action['icon']" />
                            <span>{{ $action['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="dash-card dash-activity" aria-labelledby="requests-title">
                <div class="dash-card-head">
                    <h3 id="requests-title"><span class="dash-card-icon"><x-nav-icon name="inbox" /></span>{{ __('Recent Requests') }}</h3>
                    <button type="button" class="dash-link" data-open-modal="all-requests-modal" aria-haspopup="dialog">{{ __('View all requests') }}</button>
                </div>
                <ul class="timeline">
                    @forelse ($recentRequestGroups as $group)
                        <li class="timeline-group">
                            <h4>{{ __($group['label']) }}</h4>
                            <ul>
                                @foreach ($group['items'] as $item)
                                    @php($entry = $item['model'])
                                    <li class="timeline-item">
                                        <p class="timeline-title">{{ $entry->researchProposal->title }}</p>
                                        <p class="timeline-meta">
                                            {{ $role === 'student' ? $entry->facultyProfile->user->name : $entry->studentProfile->user->name }}
                                            · <x-status-badge :status="$entry->status" />
                                            · <time datetime="{{ $item['at']->toIso8601String() }}" title="{{ $item['at']->format('F j, Y g:i A T') }}">{{ $item['at']->format('g:i A') }}</time>
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @empty
                        <li><x-empty-state title="{{ __('No adviser requests in the last 7 days.') }}" /></li>
                    @endforelse
                </ul>
            </section>

            <section class="dash-card dash-activity" aria-labelledby="second-title">
                <div class="dash-card-head">
                    <h3 id="second-title"><span class="dash-card-icon"><x-nav-icon :name="$role === 'faculty' ? 'clipboard-check' : 'document'" /></span>{{ $role === 'faculty' ? __('Recent Assignments') : __('Recent Proposals') }}</h3>
                    @if ($role === 'faculty')
                        <button type="button" class="dash-link" data-open-modal="all-assignments-modal" aria-haspopup="dialog">{{ __('View all assignments') }}</button>
                    @else
                        <button type="button" class="dash-link" data-open-modal="all-proposals-modal" aria-haspopup="dialog">{{ __('View all proposals') }}</button>
                    @endif
                </div>
                <ul class="timeline">
                    @if ($role === 'faculty')
                        @forelse ($recentAssignmentGroups as $group)
                            <li class="timeline-group">
                                <h4>{{ __($group['label']) }}</h4>
                                <ul>
                                    @foreach ($group['items'] as $item)
                                        @php($entry = $item['model'])
                                        <li class="timeline-item">
                                            <a href="{{ route('faculty.assignments.index') }}" class="timeline-title">{{ $entry->researchProposal->title }}</a>
                                            <p class="timeline-meta">
                                                {{ $entry->researchProposal->studentProfile->user->name }} · {{ ucfirst($entry->status) }}
                                                · <time datetime="{{ $item['at']->toIso8601String() }}" title="{{ $item['at']->format('F j, Y g:i A T') }}">{{ $item['at']->format('g:i A') }}</time>
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @empty
                            <li><x-empty-state title="{{ __('No adviser assignments in the last 7 days.') }}" /></li>
                        @endforelse
                    @else
                        @forelse ($recentProposalGroups as $group)
                            <li class="timeline-group">
                                <h4>{{ __($group['label']) }}</h4>
                                <ul>
                                    @foreach ($group['items'] as $item)
                                        @php($entry = $item['model'])
                                        <li class="timeline-item">
                                            <a href="{{ route($role.'.proposals.show', $entry) }}" class="timeline-title">{{ $entry->title }}</a>
                                            <p class="timeline-meta">
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
                            <li><x-empty-state title="{{ __('No proposals in the last 7 days.') }}" /></li>
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