<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Skills Assessment') }} #{{ $attempt->id }}</h2></x-slot>
    <div class="py-12"><div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        @if ($attempt->completed_at)
            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Assessment completed') }}</h3>
                <p role="status" class="mt-4 text-2xl font-semibold text-gray-900">{{ $attempt->score }}/10 — {{ $attempt->percentage }}%</p>
                <p class="mt-2 text-sm text-gray-600">{{ __('Completed') }}: {{ $attempt->completed_at->format('Y-m-d H:i T') }}</p>
                <a href="{{ route('faculty.assessment.index') }}" class="mt-4 inline-block text-indigo-600 underline">{{ __('Back to assessment history') }}</a>
            </section>
        @else
            <form method="POST" action="{{ route('faculty.assessment.submit', $attempt) }}" class="space-y-6">
                @csrf
                <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <p class="text-sm text-gray-600">{{ __('Select one answer for every question, then submit. Your answers become final when submitted.') }}</p>
                    <p class="mt-2 text-sm text-gray-600">{{ __('Answers are not saved until submission. Keep this page open while answering.') }}</p>
                    @if ($errors->any())<div role="alert" class="mt-4 text-sm text-red-700"><p>{{ __('Please answer all 10 questions using the available options.') }}</p></div>@endif
                </section>
                @foreach ($items as $item)
                    <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <fieldset>
                            <legend class="font-medium text-gray-900 whitespace-pre-wrap break-words">{{ $item->position }}. {{ $item->question }}</legend>
                            <div class="mt-4 space-y-3">
                                @foreach (['a', 'b', 'c', 'd'] as $option)
                                    <label class="flex items-start gap-3 rounded-md border border-gray-200 p-3 cursor-pointer">
                                        <input type="radio" name="answers[{{ $item->id }}]" value="{{ $option }}" required
                                            @checked(old('answers.'.$item->id) === $option) class="mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-sm text-gray-800 whitespace-pre-wrap break-words">{{ strtoupper($option) }}. {{ $item->{'option_'.$option} }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    </section>
                @endforeach
                <div class="px-4 sm:px-0"><x-primary-button>{{ __('Submit Assessment') }}</x-primary-button></div>
            </form>
        @endif
    </div></div>
</x-app-layout>
