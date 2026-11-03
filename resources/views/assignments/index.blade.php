<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ $role === 'faculty' ? __('Assigned Students') : __('Adviser Assignments') }}</h1></x-slot>

    <div class="py-12"><section class="mx-auto max-w-7xl bg-white p-6 shadow sm:rounded-lg">
        @if (session('status') === 'adviser-assigned')<p role="status" class="ui-alert mt-4 text-sm text-green-700">{{ __('Request accepted.') }}</p>@endif
        <div class="mt-5 divide-y divide-gray-200">
            @forelse ($assignments as $assignment)
                <article class="py-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <h3 class="font-semibold text-gray-900 break-words">{{ $assignment->researchProposal->title }}</h3>
                        <x-status-badge :status="$assignment->status" />
                    </div>
                    <p class="mt-2 text-sm text-gray-700">{{ __('Adviser') }}: {{ $assignment->facultyProfile->user->name }}</p>
                    @if ($role !== 'student')<p class="mt-2 text-sm text-gray-700">{{ __('Student') }}: {{ $assignment->researchProposal->studentProfile->user->name }} · {{ $assignment->researchProposal->studentProfile->student_number }}</p>@endif
                    <p class="mt-2 text-sm text-gray-700">{{ __('Approved by') }}: {{ $assignment->approver?->name ?? __('Former account') }}</p>
                    <p class="mt-2 text-xs text-gray-600">{{ __('Assigned') }} {{ $assignment->assigned_at->format('Y-m-d H:i T') }}</p>
                    @if ($role !== 'faculty')<a href="{{ route($role.'.proposals.show', $assignment->researchProposal) }}" class="mt-3 inline-block text-sm text-indigo-600 underline">{{ __('View proposal') }}</a>@endif
                </article>
            @empty
                <x-empty-state title="{{ __('No adviser assignments.') }}" />
            @endforelse
        </div>
        <div class="mt-5">{{ $assignments->links() }}</div>
    </section></div>
</x-app-layout>
