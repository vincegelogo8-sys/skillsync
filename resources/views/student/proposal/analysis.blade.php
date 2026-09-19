<section class="mt-8 border-t border-gray-200 pt-6" aria-labelledby="analysis-heading">
    <h4 id="analysis-heading" class="text-lg font-medium text-gray-900">{{ __('Proposal Analysis') }}</h4>
    @php
        // Read-only presentation of the same sections already used by analysis.
        $sections = null;
        try {
            $sections = app(\App\Services\ProposalSectionExtractor::class)->extract($analysis->extracted_text, $proposal->title);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            // Legacy or incomplete documents still display their saved results.
        }
    @endphp
    @if ($sections)
        <dl class="analysis-grid mt-5 text-sm">
            <div class="analysis-block analysis-wide"><dt>{{ __('Research Title') }}</dt><dd class="font-semibold text-gray-900 whitespace-pre-wrap">{{ $sections['title'] }}</dd></div>
            <div class="analysis-block analysis-wide"><dt>{{ __('Objectives') }}</dt><dd class="text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $sections['objectives'] }}</dd></div>
        </dl>
    @else
        <x-alert type="warning" class="mt-4">{{ __('Title and objectives are not available for display. Review the document and refresh extraction to identify the sections.') }}</x-alert>
    @endif
    @if ($analysis->analyzed_at)
        <p class="mt-2 text-sm text-gray-600">{{ __('Analysis complete. Keywords and matching use only your proposal title and objectives. Review them against the extracted text.') }}</p>
        <dl class="analysis-grid mt-4 text-sm">
            <div class="analysis-block"><dt>{{ __('Project Type') }}</dt><dd class="mt-1 font-semibold text-gray-900">{{ $analysis->project_type ?? __('Not identified from the available text.') }}</dd></div>
            <div class="analysis-block analysis-wide"><dt>{{ __('Extracted Keywords') }}</dt><dd class="mt-1 text-gray-600">
                @forelse ($analysis->keywords ?? [] as $item)
                    <span class="mb-1 mr-1 inline-block rounded bg-indigo-50 px-2 py-1 text-indigo-800">{{ $item }}</span>
                @empty
                    {{ __('No keywords were identified from the title and objectives.') }}
                @endforelse
            </dd></div>
        </dl>
        <p class="mt-4 text-xs text-gray-500">{{ __('Analyzed') }} {{ $analysis->analyzed_at->format('Y-m-d H:i T') }}</p>
    @else
        <p class="mt-2 text-sm text-gray-600">{{ __('Analyze the proposal title and objectives to extract keywords.') }}</p>
        <form method="POST" action="{{ route(($admin ? 'admin' : 'student').'.proposals.analyze', $proposal) }}" class="mt-4">
            @csrf
            <x-primary-button>{{ __('Analyze Proposal') }}</x-primary-button>
        </form>
    @endif
</section>
