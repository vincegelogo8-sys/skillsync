
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">
            {{ __('Adviser Requests') }}
        </h1>
    </x-slot>

    <div class="py-12">
        <section class="mx-auto max-w-7xl bg-white p-6 shadow sm:rounded-lg">

            <p class="text-sm text-gray-600">
                {{ __('Pending requests do not reserve an adviser.') }}
            </p>

            @if (session('status') === 'request-submitted')
                <p role="status" class="ui-alert mt-4 text-sm text-green-700">
                    {{ __('Adviser request sent.') }}
                </p>
            @endif

            @if (session('status') === 'request-cancelled')
                <p role="status" class="ui-alert mt-4 text-sm text-green-700">
                    {{ __('Adviser request cancelled.') }}
                </p>
            @endif

            @if (session('status') === 'request-deleted')
                <p role="status" class="ui-alert mt-4 text-sm text-green-700">
                    {{ __('Cancelled adviser request removed from your list. Its history has been retained.') }}
                </p>
            @endif

            @if (session('status') === 'request-declined')
                <p role="status" class="ui-alert mt-4 text-sm text-green-700">
                    {{ __('Request declined.') }}
                </p>
            @endif

            <div role="alert">
                <x-input-error :messages="$errors->get('adviser_request')" class="mt-4" />
            </div>

            <div role="alert">
                <x-input-error :messages="$errors->get('assignment')" class="mt-4" />
            </div>

            @if ($role === 'faculty')
                <p class="mt-3 text-sm text-gray-600">
                    {{ __('Accepting assigns you as adviser and closes other pending requests for this proposal.') }}
                </p>
            @endif

            <div class="mt-5 divide-y divide-gray-200">
                @forelse ($requests as $adviserRequest)
                    <article class="py-5">

                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <h3 class="font-semibold text-gray-900 break-words">
                                {{ $adviserRequest->researchProposal->title }}
                            </h3>

                            <x-status-badge :status="$adviserRequest->status" />
                        </div>

                        <p class="mt-2 text-sm text-gray-700">
                            {{ __('Faculty') }}:
                            {{ $adviserRequest->facultyProfile->user->name }}

                            @if ($adviserRequest->facultyProfile->user->isDeactivated())
                                <x-status-badge status="inactive">
                                    {{ __('Inactive') }}
                                </x-status-badge>
                            @endif
                        </p>

                        @if ($role === 'student' && $adviserRequest->status === 'pending' && $adviserRequest->facultyProfile->user->isDeactivated())
                            <p class="mt-1 text-sm text-gray-600">
                                {{ __('This adviser\'s account is inactive. Cancel this request to choose another adviser.') }}
                            </p>
                        @endif

                        @if ($role !== 'student')
                            <p class="mt-2 text-sm text-gray-700">
                                {{ __('Student') }}:
                                {{ $adviserRequest->studentProfile->user->name }}

                                @if ($adviserRequest->studentProfile->user->isDeactivated())
                                    <x-status-badge status="inactive">
                                        {{ __('Inactive') }}
                                    </x-status-badge>
                                @endif

                                · {{ $adviserRequest->studentProfile->student_number }}
                            </p>
                        @endif

                        @php($acceptedBy = $adviserRequest->researchProposal->assignment)

                        @if ($role !== 'student' && $adviserRequest->status === 'cancelled' && $acceptedBy && $acceptedBy->status === 'active' && $acceptedBy->faculty_profile_id !== $adviserRequest->faculty_profile_id)
                            <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                {{ __('This request was closed because the student has already been accepted by :name.', ['name' => $acceptedBy->facultyProfile->user->name]) }}
                            </p>
                        @endif

                        <p class="mt-2 text-xs text-gray-600">
                            {{ __('Requested') }}
                            {{ $adviserRequest->requested_at->format('Y-m-d H:i T') }}

                            @if ($adviserRequest->responded_at)
                                · {{ __('Updated') }}
                                {{ $adviserRequest->responded_at->format('Y-m-d H:i T') }}
                            @endif
                        </p>

                        <div class="mt-4 flex flex-wrap items-center gap-4">

                            {{-- Faculty: View Proposal --}}
                            @if ($role === 'faculty')
                                <a href="{{ route('adviser-requests.proposal', $adviserRequest) }}"
                                   class="button button-secondary inline-flex items-center">
                                    {{ __('View Proposal') }}
                                </a>
                            @endif

                            {{-- Student: View Recommendations only for Pending or Declined --}}
                            @if (
                                $role === 'student' &&
                                in_array($adviserRequest->status, ['pending', 'declined'], true)
                            )
                                <a href="{{ route('student.recommendations.index', $adviserRequest->researchProposal) }}"
                                   class="text-sm text-indigo-600 underline">
                                    {{ __('View recommendations') }}
                                </a>
                            @endif

                            {{-- Admin: View Proposal --}}
                            @if ($role === 'admin')
                                <a href="{{ route('admin.proposals.show', $adviserRequest->researchProposal) }}"
                                   class="text-sm text-indigo-600 underline">
                                    {{ __('View proposal') }}
                                </a>
                            @endif

                            {{-- Student: Delete Cancelled Request --}}
                            @if ($role === 'student' && $adviserRequest->status === 'cancelled')
                                <form method="POST"
                                      action="{{ route('student.requests.destroy', $adviserRequest) }}"
                                      data-confirm="Remove this cancelled adviser request from your list? Its history will be retained."
                                      data-confirm-title="Remove request?"
                                      data-confirm-button="Yes, remove"
                                      data-confirm-tone="danger">

                                    @csrf
                                    @method('DELETE')

                                    <x-danger-button>
                                        {{ __('Delete Request') }}
                                    </x-danger-button>
                                </form>
                            @endif

                            {{-- Pending Request Actions --}}
                            @if ($adviserRequest->status === 'pending' && $role !== 'admin')

                                @if ($role === 'faculty')
                                    <form method="POST"
                                          action="{{ route('faculty.requests.approve', $adviserRequest) }}"
                                          data-confirm="Accept this request and become the assigned adviser?"
                                          data-confirm-title="Accept request?"
                                          data-confirm-button="Yes, accept"
                                          data-confirm-tone="primary">

                                        @csrf

                                        <x-primary-button class="button-success">
                                            {{ __('Accept Request') }}
                                        </x-primary-button>
                                    </form>
                                @endif

                                <form method="POST"
                                      action="{{ route($role.'.requests.'.($role === 'student' ? 'cancel' : 'decline'), $adviserRequest) }}"
                                      data-confirm="Close this adviser request?"
                                      data-confirm-title="Close request?"
                                      data-confirm-button="Yes, close request"
                                      data-confirm-tone="danger">

                                    @csrf
                                    @method('PATCH')

                                    <x-danger-button>
                                        {{ $role === 'student' ? __('Cancel Request') : __('Decline Request') }}
                                    </x-danger-button>
                                </form>

                            @endif

                        </div>
                    </article>

                @empty
                    <x-empty-state title="{{ __('No adviser requests.') }}" />

                    @if ($role === 'student')
                        <a href="{{ route('student.proposals.index') }}"
                           class="text-sm text-indigo-600 underline">
                            {{ __('View proposals') }}
                        </a>
                    @endif
                @endforelse
            </div>

            <div class="mt-5">
                {{ $requests->links() }}
            </div>

        </section>
    </div>
</x-app-layout>
