<section class="mt-8 border-t border-gray-200 pt-6" aria-labelledby="analysis-heading">
    <h4 id="analysis-heading" class="text-lg font-medium text-gray-900">{{ __('Proposal Analysis') }}</h4>
    @if ($analysis->analyzed_at)
        <p class="mt-2 text-sm text-gray-600">{{ __('Analysis complete. Results use terms found in your title and document. Review them against the extracted text.') }}</p>
        <dl class="mt-4 space-y-4 text-sm">
            <div><dt class="font-medium text-gray-800">{{ __('Abstract / Summary') }}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-gray-600">{{ $analysis->abstract ?? __('No labeled abstract or summary section was found.') }}</dd></div>
            <div><dt class="font-medium text-gray-800">{{ __('Project Type') }}</dt><dd class="mt-1 text-gray-600">{{ $analysis->project_type ?? __('Not identified from the available text.') }}</dd></div>
            @foreach (['keywords' => 'Keywords', 'technologies' => 'Technologies Mentioned', 'identified_expertise_areas' => 'Identified Expertise Areas'] as $field => $label)
                <div><dt class="font-medium text-gray-800">{{ __($label) }}</dt><dd class="mt-1 text-gray-600">
                    @forelse ($analysis->{$field} ?? [] as $item)
                        <span class="mb-1 mr-1 inline-block rounded bg-indigo-50 px-2 py-1 text-indigo-800">{{ $item }}</span>
                    @empty
                        {{ __('No matching terms found.') }}
                    @endforelse
                </dd></div>
            @endforeach
            <div><dt class="font-medium text-gray-800">{{ __('Analyzed') }}</dt><dd class="mt-1 text-gray-600">{{ $analysis->analyzed_at->format('Y-m-d H:i T') }}</dd></div>
        </dl>
    @else
        <p class="mt-2 text-sm text-gray-600">{{ __('Analyze the saved text to identify keywords, project type, technologies, and up to three expertise areas.') }}</p>
        <form method="POST" action="{{ route(($admin ? 'admin' : 'student').'.proposals.analyze', $proposal) }}" class="mt-4">
            @csrf
            <x-primary-button>{{ __('Analyze Proposal') }}</x-primary-button>
        </form>
    @endif
</section>
