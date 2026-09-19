<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Skills Assessment') }}</h2></x-slot>
    <div class="py-12"><div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Assess your technical skills') }}</h3>
            <p class="mt-2 text-sm text-gray-600">{{ __('Answer 10 multiple-choice questions. Your score is the number of correct answers divided by 10, multiplied by 100.') }}</p>
            <p class="mt-2 text-sm text-gray-600">{{ __('You may take another assessment after completing an attempt. Completed scores are kept in your history.') }}</p>
            <x-input-error :messages="$errors->get('assessment')" class="mt-3" />
            @if ($active)
                <a href="{{ route('faculty.assessment.show', $active) }}" class="mt-4 inline-block text-indigo-600 underline">{{ __('Resume Assessment') }}</a>
            @elseif ($ready)
                <form method="POST" action="{{ route('faculty.assessment.start') }}" class="mt-4">@csrf <x-primary-button>{{ __('Start Assessment') }}</x-primary-button></form>
            @else
                <p class="mt-4 text-sm text-amber-800">{{ __('Assessment is not available yet. Admin needs to add at least 10 questions.') }}</p>
            @endif
        </section>
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Your assessment history') }}</h3>
            <div class="mt-4 divide-y divide-gray-200">
                @forelse ($attempts as $attempt)
                    <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div><p class="font-medium text-gray-900">{{ __('Attempt') }} #{{ $attempt->id }}</p>
                            <p class="text-sm text-gray-600">{{ $attempt->completed_at ? $attempt->score.'/10 — '.$attempt->percentage.'%' : __('In progress') }}</p>
                            @if ($attempt->completed_at)<p class="text-sm text-gray-600">{{ $attempt->completed_at->format('Y-m-d H:i T') }}</p>@endif
                        </div>
                        <a href="{{ route('faculty.assessment.show', $attempt) }}" class="text-sm text-indigo-600 underline">{{ $attempt->completed_at ? __('View result') : __('Resume') }}</a>
                    </div>
                @empty
                    <p class="py-4 text-sm text-gray-600">{{ __('No attempts yet.') }}</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $attempts->links() }}</div>
        </section>
    </div></div>
</x-app-layout>
