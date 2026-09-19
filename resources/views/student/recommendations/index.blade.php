<x-app-layout>
    <x-slot name="header"><h1 class="font-semibold text-xl text-gray-800">{{ __('Adviser Recommendations') }}</h1></x-slot>
    <x-slot name="description">{{ __('Compare research suitability and current availability before requesting an adviser.') }}</x-slot>
    <div class="py-12"><div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <a href="{{ route(($admin ? 'admin' : 'student').'.proposals.show', $proposal) }}" class=" button button-secondary">{{ __('Back to proposal') }}</a>
            <h3 class="mt-4 text-xl font-semibold text-gray-900 break-words">{{ $proposal->title }}</h3>
            @if ($admin)<p class="mt-2 text-sm text-gray-600">{{ __('Student') }}: {{ $proposal->studentProfile->user->name }} · {{ $proposal->studentProfile->student_number }}</p>@endif
            <p class="mt-3 text-sm text-gray-600">{{ __('Compare faculty using research topic alignment, advising competency, preferences, and skills assessment. Recommendations do not assign an adviser.') }}</p>
            @if (session('status') === 'recommendations-generated')<p role="status" class="ui-alert mt-4 text-sm font-medium text-green-700">{{ __('Recommendations generated successfully.') }}</p>@endif
            @if (session('status') === 'recommendations-empty')<p role="status" class="ui-alert mt-4 text-sm text-gray-700">{{ __('No faculty profiles are available to rank.') }}</p>@endif
            <div role="alert"><x-input-error :messages="$errors->get('recommendations')" class="mt-4" /></div>
            <div role="alert"><x-input-error :messages="$errors->get('analysis')" class="mt-4" /></div>
            <div role="alert"><x-input-error :messages="$errors->get('adviser_request')" class="mt-4" /></div>
            @unless ($admin)<a href="{{ route('student.requests.index') }}" class="mt-4 inline-block text-sm text-indigo-600 underline">{{ __('View My Adviser Requests') }}</a>@endunless
            @if (! $proposal->analysis?->analyzed_at)
                <p class="mt-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('Complete text extraction and proposal analysis before generating recommendations. Open the proposal to continue.') }}</p>
            @else
                <form method="POST" action="{{ route(($admin ? 'admin' : 'student').'.recommendations.generate', $proposal) }}" class="mt-5">
                    @csrf
                    <x-primary-button>{{ $recommendations->total() ? __('Refresh Recommendations') : __('Generate Recommendations') }}</x-primary-button>
                </form>
                <p class="mt-3 text-sm text-gray-600">{{ __('Results are saved. Refresh recommendations to include changes to faculty profiles, preferences, or evaluations.') }}</p>
            @endif
        </section>

        @forelse ($recommendations as $recommendation)
            @include('student.recommendations.card', ['recommendation' => $recommendation])
        @empty
            <section class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="font-medium text-gray-900">{{ __('No saved recommendations') }}</h3>
                <p class="mt-2 text-sm text-gray-600">{{ $hasFaculty ? __('Generate recommendations after completing proposal analysis to see the ranked faculty here.') : __('Faculty profiles must be available before recommendations can be generated. Contact Admin for assistance.') }}</p>
            </section>
        @endforelse
        <div>{{ $recommendations->links() }}</div>
    </div></div>
</x-app-layout>
