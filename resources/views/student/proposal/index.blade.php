<x-app-layout>

    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800">
            {{ __('Research Proposals') }}
        </h1>
    </x-slot>
    <x-slot name="description">{{ __('Manage research proposals and follow their progress toward adviser assignment.') }}</x-slot>

    <div class="py-12">

        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">

                {{-- HEADER --}}
                <div class="flex flex-wrap items-center justify-between gap-4">

                    <p class="text-sm text-gray-600">
                        {{ $admin
                            ? __('Review submitted student research proposals.')
                            : __('Your uploaded research proposals, newest first.') }}
                    </p>

                    @unless ($admin)
                        <a
                            href="{{ route('student.proposals.create') }}"
                            class=" button button-primary"
                        >
                            {{ __('Upload Proposal') }}
                        </a>
                    @endunless

                </div>

                {{-- DELETE SUCCESS --}}
                @if (session('status') === 'proposal-deleted')
                    <div
                        role="status"
                        class="ui-alert mt-6 rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-700"
                    >
                        {{ __('Research proposal deleted successfully.') }}
                    </div>
                @endif

                {{-- PROPOSALS --}}
                <div class="mt-4 divide-y divide-gray-200">

                    @forelse ($proposals as $proposal)

                        <article
                            class="flex flex-wrap items-start justify-between gap-4 py-5"
                        >

                            {{-- INFORMATION --}}
                            <div class="min-w-0 flex-1">

                                <h3 class="font-medium text-gray-900 break-words">
                                    {{ $proposal->title }}
                                </h3>

                                @if ($admin)
                                    <p class="mt-1 text-sm text-gray-600 break-words">
                                        {{ $proposal->studentProfile->user->name }}
                                        ·
                                        {{ $proposal->studentProfile->student_number }}
                                    </p>
                                @endif

                                <p class="mt-1 text-sm text-gray-600">
                                    {{ strtoupper($proposal->file_type) }}
                                    ·
                                    {{ __('Uploaded') }}
                                    {{ $proposal->created_at->format('Y-m-d H:i T') }}
                                </p>

                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('Status') }}:
                                    <x-status-badge :status="$proposal->status" />
                                </p>

                            </div>

                            {{-- ACTIONS --}}
                            <div class="flex flex-wrap items-center gap-3">

                                <a
                                    href="{{ route(($admin ? 'admin' : 'student').'.proposals.show', $proposal) }}"
                                    class=" button button-secondary"
                                >
                                    {{ __('View proposal') }}
                                    #{{ $proposal->id }}
                                </a>

                                {{-- QUICK DELETE ON LIST --}}
                                @unless ($admin)

                                    <form
                                        method="POST"
                                        action="{{ route('student.proposals.destroy', $proposal) }}"
                                        onsubmit="return confirm('Are you sure you want to permanently delete this proposal? The uploaded document and its related analysis and recommendation data will also be deleted.');"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="button button-danger"
                                        >
                                            {{ __('Delete') }}
                                        </button>
                                    </form>

                                @endunless

                            </div>

                        </article>

                    @empty

                        <x-empty-state title="{{ __('No research proposals uploaded yet.') }}" />

                    @endforelse

                </div>

                {{-- PAGINATION --}}
                <div class="mt-4">
                    {{ $proposals->links() }}
                </div>

            </section>

        </div>

    </div>

</x-app-layout>