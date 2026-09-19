<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Skills Assessment Questions') }}</h2></x-slot>
    <div class="py-12"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-gray-600">{{ $questions->total() }} {{ __('questions in the bank. At least 10 are required to start an assessment.') }}</p>
                <a href="{{ route('admin.assessment-questions.create') }}" class="text-indigo-600 underline">{{ __('Add Question') }}</a>
            </div>
            <p class="mt-3 text-sm text-gray-600">{{ __('Each new attempt selects 10 questions at random. Edits and removals affect future attempts only.') }}</p>
            @if (session('status'))<p role="status" class="mt-4 text-sm text-green-700">{{ session('status') }}</p>@endif
            <div class="mt-4 divide-y divide-gray-200">
                @forelse ($questions as $question)
                    <article class="py-5 space-y-3">
                        <p class="text-sm text-gray-600">{{ $question->category }}</p>
                        <h3 class="font-medium text-gray-900 whitespace-pre-wrap break-words">{{ $question->question }}</h3>
                        <p class="text-sm text-gray-600">{{ __('Correct answer') }}: {{ strtoupper($question->correct_answer) }}</p>
                        <div class="flex flex-wrap items-start gap-4">
                            <a href="{{ route('admin.assessment-questions.edit', $question) }}" class="text-sm text-indigo-600 underline">{{ __('Edit question') }} #{{ $question->id }}</a>
                            <details class="text-sm">
                                <summary class="cursor-pointer text-red-700 underline">{{ __('Remove question') }} #{{ $question->id }}</summary>
                                <form method="POST" action="{{ route('admin.assessment-questions.destroy', $question) }}" class="mt-3">
                                    @csrf @method('DELETE')
                                    <p class="mb-3 text-gray-600">{{ __('Remove this question from future assessments? Existing attempts remain available.') }}</p>
                                    <x-danger-button>{{ __('Confirm removal') }}</x-danger-button>
                                </form>
                            </details>
                        </div>
                    </article>
                @empty
                    <p class="py-4 text-gray-600">{{ __('No questions yet. Add questions and their correct answers to prepare the assessment.') }}</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $questions->links() }}</div>
        </section>
    </div></div>
</x-app-layout>
