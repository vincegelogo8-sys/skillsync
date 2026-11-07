<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Faculty Skills') }}
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <h3 class="text-lg font-medium text-gray-900 mt-2">
                    {{ $profile->user->name }}
                </h3>

                <p class="mt-1 text-sm text-gray-600">
                    {{ $profile->department }}
                </p>

                @if (session('status'))
                    <p role="status"
                       class="ui-alert mt-4 text-sm font-medium text-green-700">
                        {{ session('status') === 'faculty-skills-updated' ? __('Faculty skills saved successfully.') : session('status') }}
                    </p>
                @endif
            </section>

            @if ($canUpdateSkills)
                @php
                    $selectedSkills = old('skills', $entries->pluck('expertise_area')->all());
                    $selectedSkills = is_array($selectedSkills) ? $selectedSkills : [];
                @endphp
                <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('My Skills') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Select the categories that reflect your faculty skills.') }}
                    </p>

                    <form method="POST" action="{{ route('faculty.expertise.skills.update') }}" class="mt-6 space-y-6">
                        @csrf
                        @method('PUT')

                        <fieldset class="space-y-3">
                            <legend class="sr-only">{{ __('Faculty Skills') }}</legend>
                            @foreach ($areas as $area)
                                <label for="skill-{{ $loop->index }}" class="flex items-center gap-3">
                                    <input
                                        id="skill-{{ $loop->index }}"
                                        name="skills[]"
                                        type="checkbox"
                                        value="{{ $area }}"
                                        @checked(in_array($area, $selectedSkills, true))
                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    >
                                    <span class="text-sm text-gray-800">{{ $area }}</span>
                                </label>
                            @endforeach

                            @php
                                $othersChecked = in_array($otherArea, $selectedSkills, true);
                                $otherText = old('other_specify', $otherSpecify);
                            @endphp
                            <label for="skill-others" class="flex items-center gap-3">
                                <input
                                    id="skill-others"
                                    name="skills[]"
                                    type="checkbox"
                                    value="{{ $otherArea }}"
                                    @checked($othersChecked)
                                    aria-controls="other-specify-wrap"
                                    onchange="document.getElementById('other-specify-wrap').hidden = !this.checked; if (this.checked) document.getElementById('other_specify').focus();"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                >
                                <span class="text-sm text-gray-800">{{ __('Others') }}</span>
                            </label>
                            <div id="other-specify-wrap" class="ml-8" @if (! $othersChecked) hidden @endif>
                                <x-input-label for="other_specify" :value="__('Please specify')" />
                                <x-text-input
                                    id="other_specify"
                                    name="other_specify"
                                    type="text"
                                    class="mt-1 block w-full max-w-xl"
                                    maxlength="255"
                                    :value="$otherText"
                                    placeholder="{{ __('e.g. Quantum Computing, Bioinformatics') }}"
                                />
                                <p class="mt-1 text-xs text-gray-600">{{ __('Separate several skills with commas. Proposals that mention them will be matched to you.') }}</p>
                                <x-input-error :messages="$errors->get('other_specify')" class="mt-2" />
                            </div>
                        </fieldset>
                        <x-input-error :messages="$errors->get('skills')" class="mt-2" />
                        <x-input-error :messages="$errors->get('skills.*')" class="mt-2" />
                        <x-primary-button>{{ __('Save Faculty Skills') }}</x-primary-button>
                    </form>
                </section>
            @endif

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ __('My Skills') }}
                </h3>
                <ul class="mt-4 flex flex-wrap gap-2">
                    @forelse ($entries as $entry)
                        <li class="concept-chip">{{ $entry->expertise_area === $otherArea && filled($entry->technical_skills) ? $entry->expertise_area.': '.$entry->technical_skills : $entry->expertise_area }}</li>
                    @empty
                        <li class="text-sm text-gray-600">{{ __('No faculty skills selected yet.') }}</li>
                    @endforelse
                </ul>
            </section>

        </div>
    </div>
</x-app-layout>