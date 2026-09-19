@php
    $details = $recommendation->details;
    $capacity = $capacities[$recommendation->faculty_profile_id];
    $expertise = $details['topic_alignment']['expertise_breakdown'] ?? [];
    $preferences = $details['preferences'] ?? [];
    $criteria = [
        'topic_alignment_score' => 'Research Topic Alignment',
        'advising_competency_score' => 'Research Advising Competency',
        'preference_compatibility_score' => 'Preference Compatibility',
        'skills_assessment_score' => 'Skills Assessment',
    ];
@endphp
<article class="recommendation-card bg-white p-4 shadow sm:rounded-lg sm:p-8" aria-labelledby="faculty-{{ $recommendation->id }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-indigo-700">{{ __('Rank #:rank', ['rank' => $recommendation->rank]) }}</p>
            <h3 id="faculty-{{ $recommendation->id }}" class="mt-1 text-xl font-semibold text-gray-900 break-words">{{ $recommendation->facultyProfile->user->name }}</h3>
            <p class="mt-1 text-sm text-gray-600 break-words">{{ $recommendation->facultyProfile->department }}</p>
        </div>
        <div class="recommendation-score rounded-lg bg-indigo-50 px-5 py-3">
            <p class="text-sm font-medium text-indigo-900">{{ __('Final Recommendation Score') }}</p>
            <p class="mt-1 text-3xl font-bold text-indigo-800 tabular-nums">{{ number_format((float) $recommendation->final_score, 2) }}%</p>
        </div>
    </div>
    <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($criteria as $field => $label)
            <div class="rounded-md border border-gray-200 p-3">
                <dt class="text-sm text-gray-600">{{ __($label) }}</dt>
                <dd class="mt-1 text-lg font-semibold text-gray-900 tabular-nums">{{ number_format((float) $recommendation->{$field}, 2) }}%</dd>
                <dd><progress class="score-meter" value="{{ $recommendation->{$field} }}" max="100" aria-label="{{ __($label) }}"></progress></dd>
            </div>
        @endforeach
    </dl>
    <div class="recommendation-availability mt-5 flex flex-wrap items-center gap-3 text-sm">
        <p class="font-medium text-gray-800">{{ __('Advisory Load') }}: {{ $capacity['load'] }} / {{ $capacity['limit'] }}</p>
        <x-status-badge :status="$capacity['status']">{{ $capacity['status'] }}</x-status-badge>
        @if ($admin)<a href="{{ route('admin.advisory-limits.edit', $recommendation->faculty_profile_id) }}" class="text-indigo-600 underline">{{ __('Edit advisory limit') }}</a>@endif
    </div>
    <p class="mt-2 text-xs text-gray-600">{{ __('Availability reflects current active assignments and does not change the recommendation score.') }}</p>
    @unless ($admin)
        @if (in_array($recommendation->faculty_profile_id, $activeRequests, true))
            <button type="button" disabled class="button mt-3">{{ __('Already Requested') }}</button>
        @elseif ($hasAssignment)
            <p class="mt-3 text-sm text-gray-600">{{ __('This proposal already has an active adviser assignment.') }}</p>
        @elseif ($capacity['is_full'])
            <button type="button" disabled class="button mt-3">{{ __('Request Adviser') }}</button>
            <p class="mt-2 text-sm text-gray-600">{{ __('This adviser has reached their advisory limit.') }}</p>
        @else
            <form method="POST" action="{{ route('student.requests.store', [$proposal, $recommendation->faculty_profile_id]) }}" class="mt-3">
                @csrf
                <x-primary-button>{{ __('Request Adviser') }}</x-primary-button>
            </form>
        @endif
    @endunless
    @if ($details['missing_competency'] ?? false)<p class="mt-3 text-sm text-amber-800">{{ __('No competency evaluation was recorded when these results were generated; its contribution is zero.') }}</p>@endif
    @if ($details['missing_assessment'] ?? false)<p class="mt-2 text-sm text-amber-800">{{ __('No completed skills assessment was recorded when these results were generated; its contribution is zero.') }}</p>@endif
    <h4 class="mt-5 text-sm font-semibold text-gray-900">{{ __('Matched Expertise') }}</h4>
    <div class="mt-2 text-sm text-gray-700">
        @forelse (collect($expertise)->where('missing', false) as $area)
            <span class="mb-1 mr-1 inline-block rounded bg-indigo-50 px-2 py-1 text-indigo-800">{{ $area['expertise_area'] }} · {{ number_format($area['proficiency_score'], 2) }}%</span>
        @empty
            <p>{{ __('No matching expertise was recorded for the identified requirements.') }}</p>
        @endforelse
    </div>
    <details class="match-details mt-5 border-t border-gray-200 pt-4">
        <summary class="cursor-pointer text-sm font-medium text-indigo-700">{{ __('View score breakdown') }}</summary>
        <div class="match-breakdown">
            <div class="match-breakdown-header">
                <h4 class="font-semibold">{{ __('Why This Match?') }}</h4>
                <button type="button" class="button button-secondary" data-close-breakdown>{{ __('Back to Results') }}</button>
            </div>
            <p class="font-semibold text-gray-900 break-words">{{ $recommendation->facultyProfile->user->name }}</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="mb-3 text-left font-medium text-gray-900">{{ __('Weighted contributions to the final score') }}</caption>
                <thead><tr class="border-b border-gray-200 text-gray-600"><th scope="col" class="py-2 pr-4">{{ __('Criterion') }}</th><th scope="col" class="p-2">{{ __('Weight') }}</th><th scope="col" class="p-2">{{ __('Contribution') }}</th></tr></thead>
                <tbody>@foreach ($criteria as $field => $label)
                    <tr class="border-b border-gray-100"><th scope="row" class="py-2 pr-4 font-normal">{{ __($label) }}</th><td class="p-2 tabular-nums">{{ number_format(($details['weights'][$field] ?? 0) * 100, 0) }}%</td><td class="p-2 tabular-nums">{{ number_format($details['contributions'][$field] ?? 0, 2) }} {{ __('points') }}</td></tr>
                @endforeach</tbody>
            </table>
        </div>
        <p class="mt-4 text-sm text-gray-700">{{ __('Topic alignment combines 70% cosine similarity and 30% multi-expertise strength.') }}</p>
        <p class="mt-2 text-sm text-gray-700">{{ __('Cosine Similarity') }}: {{ number_format((float) $recommendation->cosine_similarity_score, 2) }}% · {{ __('Multi-Expertise Strength') }}: {{ number_format((float) $recommendation->multi_expertise_score, 2) }}%</p>
        <ul class="mt-3 space-y-1 text-sm text-gray-700">
            @forelse ($expertise as $area)
                <li>{{ $area['expertise_area'] }}: {{ number_format($area['proficiency_score'], 2) }}% @if ($area['missing'])— {{ __('Missing expertise') }}@endif</li>
            @empty
                <li>{{ __('No expertise requirements were identified; multi-expertise contributes zero.') }}</li>
            @endforelse
        </ul>
        <p class="mt-4 text-sm text-gray-700">{{ __('Preference compatibility combines 50% project-type match and 50% technology match.') }}</p>
        <p class="mt-2 text-sm text-gray-700">{{ __('Project-type match') }}: {{ ($preferences['project_type_match'] ?? false) ? __('Yes') : __('No') }} · {{ __('Technology match') }}: {{ count($preferences['matched_technologies'] ?? []) }} / {{ $preferences['technology_count'] ?? 0 }}</p>
        <p class="mt-2 text-sm text-gray-700">{{ __('Matched technologies') }}: {{ implode(', ', $preferences['matched_technologies'] ?? []) ?: __('None') }}</p>
        <p class="mt-2 text-sm text-gray-700">{{ __('Unmatched technologies') }}: {{ implode(', ', $preferences['unmatched_technologies'] ?? []) ?: __('None') }}</p>
        <p class="mt-3 text-xs text-gray-500">{{ __('Scores and contributions are rounded to two decimal places for display. Ranking uses the saved precision; exact ties follow faculty ID order.') }}</p>
        </div>
    </details>
    <p class="mt-4 text-xs text-gray-500">{{ __('Generated') }} {{ $recommendation->updated_at->format('Y-m-d H:i T') }}</p>
</article>
