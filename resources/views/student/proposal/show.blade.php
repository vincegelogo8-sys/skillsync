<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">
            {{ __('Research Proposal') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">

                <a
                    href="{{ route(($admin ? 'admin' : 'student').'.proposals.index') }}"
                    class="text-sm text-indigo-600 underline"
                >
                    {{ __('Back to proposals') }}
                </a>

                {{-- SUCCESS MESSAGES --}}
                @if (session('status') === 'proposal-uploaded')
                    <div
                        role="status"
                        class="mt-4 rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-700"
                    >
                        {{ __('Research proposal uploaded successfully.') }}
                    </div>
                @endif

                @if (session('status') === 'extraction-refreshed')
                    <div
                        role="status"
                        class="mt-4 rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-700"
                    >
                        {{ __('Document extraction and proposal analysis were refreshed successfully.') }}
                    </div>
                @endif

                @if (session('status') === 'extraction-refresh-failed')
                    <div
                        role="alert"
                        class="mt-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700"
                    >
                        {{ __('The document could not be re-extracted. The previous successful analysis was preserved.') }}
                    </div>
                @endif

                <x-input-error
                    :messages="$errors->get('delete')"
                    class="mt-4"
                />

                <x-input-error
                    :messages="$errors->get('extraction')"
                    class="mt-4"
                />

                <x-input-error
                    :messages="$errors->get('analysis')"
                    class="mt-4"
                />

                <h3 class="mt-6 text-xl font-medium text-gray-900 break-words">
                    {{ $proposal->title }}
                </h3>

                {{-- ASSIGNED ADVISER --}}
                @if ($proposal->assignment)
                    <div
                        class="mt-4 rounded border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900"
                    >
                        <p class="font-semibold">
                            {{ __('Assigned Adviser') }}:
                            {{ $proposal->assignment->facultyProfile->user->name }}
                        </p>

                        <p class="mt-1">
                            {{ __('Status') }}:
                            {{ ucfirst($proposal->assignment->status) }}
                            ·
                            {{ __('Assigned') }}
                            {{ $proposal->assignment->assigned_at->format('Y-m-d H:i T') }}
                        </p>
                    </div>
                @endif

                {{-- DOCUMENT INFORMATION --}}
                <dl class="mt-6 space-y-4 text-sm">

                    @if ($admin)
                        <div>
                            <dt class="font-medium text-gray-800">
                                {{ __('Student') }}
                            </dt>

                            <dd class="mt-1 text-gray-600 break-words">
                                {{ $proposal->studentProfile->user->name }}
                                ·
                                {{ $proposal->studentProfile->student_number }}
                            </dd>
                        </div>
                    @endif

                    <div>
                        <dt class="font-medium text-gray-800">
                            {{ __('Document') }}
                        </dt>

                        <dd class="mt-1 text-gray-600 break-words">
                            {{ $proposal->original_filename }}
                        </dd>
                    </div>

                    <div>
                        <dt class="font-medium text-gray-800">
                            {{ __('File Type') }}
                        </dt>

                        <dd class="mt-1 text-gray-600">
                            {{ strtoupper($proposal->file_type) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="font-medium text-gray-800">
                            {{ __('Status') }}
                        </dt>

                        <dd class="mt-1 text-gray-600">
                            {{ ucfirst(str_replace('_', ' ', $proposal->status)) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="font-medium text-gray-800">
                            {{ __('Uploaded') }}
                        </dt>

                        <dd class="mt-1 text-gray-600">
                            {{ $proposal->created_at->format('Y-m-d H:i T') }}
                        </dd>
                    </div>

                </dl>

                {{-- DOCUMENT ACTIONS --}}
                <div class="mt-6 flex flex-wrap items-center gap-3">

                    <a
                        href="{{ route(($admin ? 'admin' : 'student').'.proposals.download', $proposal) }}"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition hover:bg-gray-50"
                    >
                        {{ __('Download Document') }}
                    </a>

                    {{-- REFRESH EXTRACTION --}}
                    <form
                        method="POST"
                        action="{{ route(($admin ? 'admin' : 'student').'.proposals.refresh-extraction', $proposal) }}"
                        onsubmit="return confirm('Refresh the document extraction? The uploaded PDF/DOCX will be kept, but the extracted text, proposal analysis, and generated recommendation scores will be recalculated.');"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        >
                            {{ __('Refresh Extraction') }}
                        </button>
                    </form>

                    {{-- DELETE - STUDENT ONLY --}}
                    @unless ($admin)

                        @if (! $proposal->assignment)
                            <form
                                method="POST"
                                action="{{ route('student.proposals.destroy', $proposal) }}"
                                onsubmit="return confirm('Are you sure you want to permanently delete this proposal? The uploaded document, analysis, recommendations, and related adviser requests will also be deleted. This action cannot be undone.');"
                            >
                                @csrf
                                @method('DELETE')

                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                                >
                                    {{ __('Delete Proposal') }}
                                </button>
                            </form>
                        @else
                            <button
                                type="button"
                                disabled
                                title="A proposal with an adviser assignment cannot be deleted."
                                class="inline-flex cursor-not-allowed items-center rounded-md bg-gray-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-600 opacity-70"
                            >
                                {{ __('Delete Proposal') }}
                            </button>
                        @endif

                    @endunless

                </div>

                {{-- EXTRACTION ERROR --}}
                @if ($proposal->extraction_error)
                    <div
                        role="alert"
                        class="mt-6 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                    >
                        <p class="font-semibold">
                            {{ __('Extraction Error') }}
                        </p>

                        <p class="mt-1">
                            {{ $proposal->extraction_error }}
                        </p>
                    </div>
                @endif

                {{-- RECOMMENDATIONS --}}
                <a
                    href="{{ route(($admin ? 'admin' : 'student').'.recommendations.index', $proposal) }}"
                    class="mt-6 block font-medium text-indigo-600 underline"
                >
                    {{ __('View Adviser Recommendations') }}
                </a>

                {{-- ANALYSIS --}}
                @if ($proposal->analysis)

                    @include(
                        'student.proposal.analysis',
                        [
                            'analysis' => $proposal->analysis
                        ]
                    )

                    <div class="mt-8 border-t border-gray-200 pt-6">

                        <div class="flex flex-wrap items-center justify-between gap-3">

                            <div>
                                <h4 class="text-lg font-medium text-gray-900">
                                    {{ __('Extracted Document Text') }}
                                </h4>

                                <p class="mt-1 text-sm text-gray-600">
                                    {{ __('Text is saved from your document. Check that the content is readable.') }}
                                </p>
                            </div>

                            {{-- SECOND REFRESH BUTTON NEAR EXTRACTED TEXT --}}
                            <form
                                method="POST"
                                action="{{ route(($admin ? 'admin' : 'student').'.proposals.refresh-extraction', $proposal) }}"
                                onsubmit="return confirm('Re-extract this document and recalculate its analysis?');"
                            >
                                @csrf

                                <button
                                    type="submit"
                                    class="text-sm font-medium text-indigo-600 underline hover:text-indigo-800"
                                >
                                    {{ __('Refresh Extraction') }}
                                </button>
                            </form>

                        </div>

                        <div
                            class="mt-4 max-h-96 overflow-auto rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-800 whitespace-pre-wrap break-words"
                        >{{ $proposal->analysis->extracted_text }}</div>

                    </div>

                @else

                    {{-- FIRST EXTRACTION / RETRY --}}
                    <form
                        method="POST"
                        action="{{ route(($admin ? 'admin' : 'student').'.proposals.extract', $proposal) }}"
                        class="mt-6"
                    >
                        @csrf

                        <x-primary-button>
                            {{ $proposal->extraction_error
                                ? __('Retry Text Extraction')
                                : __('Extract Document Text') }}
                        </x-primary-button>
                    </form>

                    @unless ($admin)
                        @if ($proposal->extraction_error)
                            <a
                                href="{{ route('student.proposals.create') }}"
                                class="mt-3 inline-block text-sm text-indigo-600 underline"
                            >
                                {{ __('Upload a corrected document') }}
                            </a>
                        @endif
                    @endunless

                @endif

            </section>

        </div>
    </div>
</x-app-layout>