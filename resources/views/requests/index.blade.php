<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ __('Adviser Requests') }}</h1></x-slot>
    <x-slot name="description">{{ __('Follow adviser requests, faculty decisions, and their current status.') }}</x-slot>
    <div class="py-12"><section class="mx-auto max-w-7xl bg-white p-6 shadow sm:rounded-lg">
        <p class="text-sm text-gray-600">{{ __('Track adviser requests and their status. A pending request does not reserve capacity or assign an adviser.') }}</p>
        @if (session('status') === 'request-submitted')<p role="status" class="ui-alert mt-4 text-sm text-green-700">{{ __('Adviser request submitted successfully.') }}</p>@endif
        @if (session('status') === 'request-cancelled')<p role="status" class="ui-alert mt-4 text-sm text-green-700">{{ __('Adviser request cancelled.') }}</p>@endif
        @if (session('status') === 'request-declined')<p role="status" class="ui-alert mt-4 text-sm text-green-700">{{ __('Adviser request declined.') }}</p>@endif
        <div role="alert"><x-input-error :messages="$errors->get('adviser_request')" class="mt-4" /></div>
        <div role="alert"><x-input-error :messages="$errors->get('assignment')" class="mt-4" /></div>
        @if ($role === 'faculty')<p class="mt-3 text-sm text-gray-600">{{ __('Accept Request creates the final active assignment and cancels other pending requests for that proposal. Capacity is checked again when you accept.') }}</p>@endif
        <div class="mt-5 divide-y divide-gray-200">
            @forelse ($requests as $adviserRequest)
                <article class="py-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <h3 class="font-semibold text-gray-900 break-words">{{ $adviserRequest->researchProposal->title }}</h3>
                        <x-status-badge :status="$adviserRequest->status" />
                    </div>
                    <p class="mt-2 text-sm text-gray-700">{{ __('Faculty') }}: {{ $adviserRequest->facultyProfile->user->name }}</p>
                    @if ($role !== 'student')<p class="mt-2 text-sm text-gray-700">{{ __('Student') }}: {{ $adviserRequest->studentProfile->user->name }} · {{ $adviserRequest->studentProfile->student_number }}</p>@endif
                    <p class="mt-2 text-xs text-gray-600">{{ __('Requested') }} {{ $adviserRequest->requested_at->format('Y-m-d H:i T') }} @if ($adviserRequest->responded_at) · {{ __('Updated') }} {{ $adviserRequest->responded_at->format('Y-m-d H:i T') }} @endif</p>
                    <div class="mt-4 flex flex-wrap items-center gap-4">
                        @if ($role !== 'faculty')<a href="{{ route($role.'.recommendations.index', $adviserRequest->researchProposal) }}" class="text-sm text-indigo-600 underline">{{ __('View recommendations') }}</a>@endif
                        @if ($adviserRequest->status === 'pending' && $role !== 'admin')
                            @if ($role === 'faculty')
                                <form method="POST" action="{{ route('faculty.requests.approve', $adviserRequest) }}">
                                    @csrf
                                    <x-primary-button class="button-success">{{ __('Accept Request') }}</x-primary-button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route($role.'.requests.'.($role === 'student' ? 'cancel' : 'decline'), $adviserRequest) }}">
                                @csrf @method('PATCH')
                                <x-danger-button>{{ $role === 'student' ? __('Cancel Request') : __('Decline Request') }}</x-danger-button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <x-empty-state title="{{ __('No adviser requests yet.') }}" />
                @if ($role === 'student')<a href="{{ route('student.proposals.index') }}" class="text-sm text-indigo-600 underline">{{ __('Open your proposals to view recommendations and request an adviser.') }}</a>@endif
            @endforelse
        </div>
        <div class="mt-5">{{ $requests->links() }}</div>
    </section></div>
</x-app-layout>
