<section class="mt-8 border-t border-gray-200 pt-6" aria-labelledby="analysis-heading">
    <h2 id="analysis-heading" class="text-lg font-semibold text-gray-900">{{ __('Proposal Analysis') }}</h2>
    <div class="mt-3"><x-status-badge :status="$analysis->analysis_status" /></div>
    @if ($analysis->analysis_status === 'failed' && ! $errors->has('analysis'))
        <x-alert type="warning" class="mt-3">{{ __(\App\Services\LocalNlpService::UNAVAILABLE) }}</x-alert>
    @endif
    @if ($analysis->hasCurrentAnalysis())
        <dl class="analysis-grid mt-4 text-sm">
            <div class="analysis-block analysis-wide">
                <dt>{{ __('Directly identified skills') }}</dt>
                <dd class="concept-list mt-2">
                    @forelse ($analysis->keywords ?? [] as $concept)
                        <span class="concept-chip">{{ $concept }}</span>
                    @empty
                        <span class="text-gray-600">{{ __('None identified.') }}</span>
                    @endforelse
                </dd>
            </div>
            <div class="analysis-block analysis-wide">
                <dt>{{ __('Related skills supported by proposal requirements') }}</dt>
                <dd class="concept-list mt-2">
                    @forelse ($analysis->identified_expertise_areas ?? [] as $concept)
                        <span class="concept-chip">{{ $concept }}</span>
                    @empty
                        <span class="text-gray-600">{{ __('No related skills were supported by the proposal.') }}</span>
                    @endforelse
                </dd>
            </div>
            <div class="analysis-block analysis-wide"><dt>{{ __('Analyzed') }}</dt><dd class="mt-1 text-gray-600">{{ $analysis->analyzed_at->format('M j, Y, g:i a T') }}</dd></div>
        </dl>
    @elseif ($analysis->analysis_status !== 'failed')
        <p class="mt-3 text-sm text-gray-600">{{ $analysis->analyzed_at ? __('Reanalyze this proposal to update its research concepts and recommendations.') : __('Analyze your proposal to identify its research concepts.') }}</p>
    @endif
    <form method="POST" action="{{ route(($admin ? 'admin' : 'student').'.proposals.analyze', $proposal) }}" class="mt-4">
        @csrf
        <button type="submit" class="button button-secondary">{{ $analysis->analysis_status === 'failed' ? __('Reanalyze') : ($analysis->hasCurrentAnalysis() ? __('Reanalyze Proposal') : __('Analyze Proposal')) }}</button>
    </form>
</section>
