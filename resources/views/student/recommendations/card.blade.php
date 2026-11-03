@php
    $capacity = $capacities[$recommendation->faculty_profile_id];
@endphp
<article class="recommendation-card bg-white p-4 shadow sm:rounded-lg sm:p-8" aria-labelledby="faculty-{{ $recommendation->id }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h3 id="faculty-{{ $recommendation->id }}" class="mt-1 text-xl font-semibold text-gray-900 break-words">{{ $recommendation->facultyProfile->user->name }}</h3>
            <p class="mt-1 text-sm text-gray-600 break-words">{{ $recommendation->facultyProfile->department }}</p>
        </div>
    </div>
    <div class="mt-4">
        <h4 class="text-sm font-semibold text-gray-800">{{ __('Matching Faculty Skills') }}</h4>
        <ul class="mt-2 space-y-3">
            @foreach ($recommendation->details['matching_expertise'] ?? [] as $match)
                <li>
                    <span class="concept-chip">{{ $match['area'] }}</span>
                    <span class="ml-1 text-xs font-medium text-gray-600">
                        {{ $match['match_type'] === 'direct' ? __('Direct match') : __('Related match') }}
                    </span>
                    <p class="mt-1 text-sm text-gray-600">{{ $match['explanation'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
    <div class="recommendation-availability mt-5 flex flex-wrap items-center gap-3 text-sm">
        <p class="font-medium text-gray-800">{{ __('Students Handled') }}: {{ $capacity['load'] }}</p>
        <x-status-badge :status="$capacity['status']">{{ $capacity['status'] }}</x-status-badge>
    </div>
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
    <p class="mt-4 text-xs text-gray-500">{{ __('Generated') }} {{ $recommendation->updated_at->format('Y-m-d H:i T') }}</p>
</article>
