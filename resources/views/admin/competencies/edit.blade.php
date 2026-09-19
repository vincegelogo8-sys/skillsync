<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Research Advising Competency Evaluation') }}</h2>
    </x-slot>
    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <a href="{{ route('admin.competencies.index') }}" class="text-sm text-indigo-600 underline">{{ __('Back to faculty evaluations') }}</a>
                <h3 class="mt-4 text-lg font-medium text-gray-900">{{ $profile->user->name }}</h3>
                <p class="text-sm text-gray-600">{{ $profile->department }}</p>
                @if (session('status') === 'competency-saved')
                    <p role="status" class="mt-4 text-sm font-medium text-green-700">{{ __('Competency evaluation saved successfully.') }}</p>
                @endif
                @if ($competency)
                    <p class="mt-4 font-medium text-gray-900">{{ __('Saved competency score') }}: {{ number_format($score, 2) }}%</p>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Total ratings') }}: {{ (int) ($score / 4) }}/25. {{ __('Score = total ratings ÷ 25 × 100.') }}</p>
                    <p class="mt-2 text-sm text-gray-600">{{ __('Last evaluated by') }}: {{ $competency->evaluator?->name ?? __('Deleted account') }}</p>
                    <p class="text-sm text-gray-600">{{ __('Last saved') }}: {{ $competency->updated_at->format('Y-m-d H:i T') }}</p>
                @else
                    <p class="mt-4 font-medium text-gray-900">{{ __('Not evaluated') }}</p>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Select all five ratings to create an evaluation.') }}</p>
                @endif
            </section>

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <div class="max-w-2xl">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Evaluation rubric') }}</h3>
                    <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-700">
                        @foreach ($rubric as $rating => $label)
                            <li>{{ $rating }} = {{ __($label) }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-sm text-gray-600">{{ __('Rate each dimension based on your evaluation and record supporting evidence in the remarks.') }}</p>
                    <form method="POST" action="{{ route('admin.competencies.update', $profile) }}" class="mt-6 space-y-6">
                        @csrf
                        @method('PATCH')
                        @foreach ($dimensions as $field => $label)
                            @php $selected = old($field, $competency?->{$field}); @endphp
                            <div>
                                <x-input-label :for="$field" :value="__($label)" />
                                <select id="{{ $field }}" name="{{ $field }}" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    aria-describedby="{{ $field }}_error" aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}">
                                    <option value="">{{ __('Select a rating') }}</option>
                                    @foreach ($rubric as $rating => $description)
                                        <option value="{{ $rating }}" @selected(is_scalar($selected) && (string) $selected === (string) $rating)>{{ $rating }} — {{ __($description) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :id="$field.'_error'" :messages="$errors->get($field)" class="mt-2" />
                            </div>
                        @endforeach
                        @php $remarks = old('remarks', $competency?->remarks); @endphp
                        <div>
                            <x-input-label for="remarks" :value="__('Supporting remarks / evidence (optional)')" />
                            <textarea id="remarks" name="remarks" rows="5" maxlength="5000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                aria-describedby="remarks_error" aria-invalid="{{ $errors->has('remarks') ? 'true' : 'false' }}">{{ is_string($remarks) ? $remarks : '' }}</textarea>
                            <x-input-error id="remarks_error" :messages="$errors->get('remarks')" class="mt-2" />
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <x-primary-button>{{ __('Save Evaluation') }}</x-primary-button>
                            <a href="{{ route('admin.competencies.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
