<x-app-layout>
    <x-slot name="header"><h1 class="font-semibold text-xl text-gray-800">{{ $question->exists ? __('Edit Assessment Question') : __('Add Assessment Question') }}</h1></x-slot>
    <x-slot name="description">{{ __('Write a clear question, provide answer choices, and select the correct answer.') }}</x-slot>
    <div class="py-12"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <form method="POST" action="{{ $question->exists ? route('admin.assessment-questions.update', $question) : route('admin.assessment-questions.store') }}" class="max-w-2xl space-y-6">
                @csrf
                @if ($question->exists) @method('PATCH') @endif
                @foreach (['question' => 'Question', 'option_a' => 'Option A', 'option_b' => 'Option B', 'option_c' => 'Option C', 'option_d' => 'Option D'] as $field => $label)
                    @php $value = old($field, $question->{$field}); @endphp
                    <div>
                        <x-input-label :for="$field" :value="__($label)" />
                        <textarea id="{{ $field }}" name="{{ $field }}" rows="{{ $field === 'question' ? 4 : 2 }}" required maxlength="{{ $field === 'question' ? 5000 : 500 }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" aria-describedby="{{ $field }}_error">{{ is_string($value) ? $value : '' }}</textarea>
                        <x-input-error :id="$field.'_error'" :messages="$errors->get($field)" class="mt-2" />
                    </div>
                @endforeach
                <div>
                    <x-input-label for="correct_answer" :value="__('Correct Answer (Admin only)')" />
                    <select id="correct_answer" name="correct_answer" required class="mt-1 block w-full rounded-md border-gray-300" aria-describedby="correct_answer_error">
                        <option value="">{{ __('Select the correct option') }}</option>
                        @foreach (\App\Models\SkillsAssessmentQuestion::OPTIONS as $option)
                            <option value="{{ $option }}" @selected(old('correct_answer', $question->correct_answer) === $option)>{{ strtoupper($option) }}</option>
                        @endforeach
                    </select>
                    <x-input-error id="correct_answer_error" :messages="$errors->get('correct_answer')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="category" :value="__('Category')" />
                    <select id="category" name="category" required class="mt-1 block w-full rounded-md border-gray-300" aria-describedby="category_error">
                        <option value="">{{ __('Select a category') }}</option>
                        @foreach (config('expertise.areas') as $category)
                            <option value="{{ $category }}" @selected(old('category', $question->category) === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                    <x-input-error id="category_error" :messages="$errors->get('category')" class="mt-2" />
                </div>
                <div class="flex items-center gap-4">
                    <x-primary-button>{{ __('Save Question') }}</x-primary-button>
                    <a href="{{ route('admin.assessment-questions.index') }}" class=" button button-secondary">{{ __('Cancel') }}</a>
                </div>
            </form>
        </section>
    </div></div>
</x-app-layout>
