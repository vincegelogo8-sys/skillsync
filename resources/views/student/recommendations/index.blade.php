<x-app-layout>
    <x-slot name="header"><h1 class="font-semibold text-xl text-gray-800">{{ __('Adviser Recommendations') }}</h1></x-slot>

    <div class="py-12"><div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <a href="{{ route('student.proposals.show', $proposal) }}" class=" button button-secondary">{{ __('Back to proposal') }}</a>
            <h3 class="mt-4 text-xl font-semibold text-gray-900 break-words">{{ $proposal->title }}</h3>
            <p class="mt-3 text-sm text-gray-600">{{ __('Recommendations include advisers whose saved faculty skills match categories identified in your proposal.') }}</p>
            @if (session('status') === 'recommendations-generated')<p role="status" class="ui-alert mt-4 text-sm font-medium text-green-700">{{ __('Recommendations generated successfully.') }}</p>@endif
            @if (session('status') === 'recommendations-empty')<p role="status" class="ui-alert mt-4 text-sm text-gray-700">{{ __('No advisers with relevant faculty skills were found for this proposal. You may review the extracted skills or consult the authorized academic personnel.') }}</p>@endif
            <div role="alert"><x-input-error :messages="$errors->get('recommendations')" class="mt-4" /></div>
            <div role="alert"><x-input-error :messages="$errors->get('analysis')" class="mt-4" /></div>
            <div role="alert"><x-input-error :messages="$errors->get('adviser_request')" class="mt-4" /></div>
            <a href="{{ route('student.requests.index') }}" class="mt-4 inline-block text-sm text-indigo-600 underline">{{ __('View My Adviser Requests') }}</a>
            @if (! $proposal->analysis?->hasCurrentAnalysis())
                <p class="mt-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('Analyze your proposal before generating recommendations.') }}</p>
            @else
                <form method="POST" action="{{ route('student.recommendations.generate', $proposal) }}" class="mt-5">
                    @csrf
                    <x-primary-button>{{ $recommendations->total() ? __('Refresh Recommendations') : __('Generate Recommendations') }}</x-primary-button>
                </form>
                <p class="mt-3 text-sm text-gray-600">{{ __('Refresh recommendations after faculty skills change.') }}</p>
            @endif
        </section>

        @forelse ($recommendations as $recommendation)
            @include('student.recommendations.card', ['recommendation' => $recommendation])
        @empty
            <section class="bg-white p-6 shadow sm:rounded-lg">
                @if ($proposal->analysis?->hasCurrentAnalysis())
                    <p class="text-sm text-gray-700">
                        {{ $hasRelevantFaculty
                            ? __('Generate recommendations to see faculty whose skills match your proposal.')
                            : __('No advisers with relevant faculty skills were found for this proposal. You may review the extracted skills or consult the authorized academic personnel.') }}
                    </p>
                @else
                    <p class="text-sm text-gray-700">{{ __('Complete proposal analysis before recommendations can be generated.') }}</p>
                @endif
            </section>
        @endforelse
        <div>{{ $recommendations->links() }}</div>
    </div></div>
</x-app-layout>
