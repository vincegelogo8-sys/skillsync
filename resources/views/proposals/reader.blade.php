<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ __('Proposal Reader') }}</h1></x-slot>

    <div class="py-12">
        <section class="proposal-reader-print-content relative isolate mx-auto max-w-5xl overflow-hidden rounded-lg bg-white p-6 shadow sm:p-8">
            <div class="proposal-reader-watermark" aria-hidden="true">
                @for ($watermarkIndex = 0; $watermarkIndex < 18; $watermarkIndex++)
                    <span>{{ $watermarkName }} · {{ $watermarkTime }}</span>
                @endfor
            </div>

            <div class="relative z-10">
                <a href="{{ $backUrl }}" class="button button-secondary">{{ __('Back') }}</a>

                <h1 class="mt-6 text-2xl font-bold text-gray-900">{{ $proposal->title }}</h1>

                <dl class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-semibold text-gray-800">{{ __('Student') }}</dt>
                        <dd class="mt-1 text-gray-700">{{ $proposal->studentProfile?->user?->name ?? __('Former account') }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-800">{{ __('Student Number') }}</dt>
                        <dd class="mt-1 text-gray-700">{{ $proposal->studentProfile?->student_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-800">{{ __('Course') }}</dt>
                        <dd class="mt-1 text-gray-700">{{ $proposal->studentProfile?->course ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-800">{{ __('Year') }}</dt>
                        <dd class="mt-1 text-gray-700">{{ config('student_options.year_levels')[$proposal->studentProfile?->year_level] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-800">{{ __('Section') }}</dt>
                        <dd class="mt-1 text-gray-700">{{ $proposal->studentProfile?->section ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-800">{{ __('Uploaded') }}</dt>
                        <dd class="mt-1 text-gray-700">{{ $proposal->created_at?->format('Y-m-d H:i T') ?? '—' }}</dd>
                    </div>
                </dl>

                <section class="mt-8 border-t border-gray-200 pt-6">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Extracted Proposal Text') }}</h2>
                    @if (filled($proposal->analysis?->extracted_text))
                        <div class="proposal-reader-text mt-4 max-h-[70vh] overflow-auto rounded-md border border-gray-200 bg-gray-50 p-5 text-sm leading-7 text-gray-800" data-no-copy>{{ $proposal->analysis->extracted_text }}</div>
                    @else
                        <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('The text of this proposal is not available yet. The student can retry extraction.') }}</p>
                    @endif
                </section>
            </div>
        </section>
        <p class="proposal-print-notice" aria-hidden="true">{{ __('Printing is disabled for proposals.') }}</p>
    </div>
</x-app-layout>
