<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Faculty Preferences') }}</h1>
    </x-slot>
    <x-slot name="description">{{ __('Choose the project types and technologies you prefer to advise.') }}</x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('faculty.preferences.update') }}" class="space-y-6">
                @csrf
                @method('PATCH')

                <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Projects you prefer to advise') }}</h3>
                    <p class="mt-2 text-sm text-gray-600">{{ __('Choose the project types and technologies you prefer working with. You may select multiple options in either list.') }}</p>
                    <p class="mt-2 text-sm text-gray-600">{{ __('Uncheck an option and save to remove it. Saving with no selections clears your preferences.') }}</p>
                    @if (session('status') === 'faculty-preferences-updated')
                        <p role="status" class="ui-alert mt-4 text-sm font-medium text-green-700">{{ __('Faculty preferences saved successfully.') }}</p>
                    @endif
                    @if ($errors->any())
                        <div role="alert" class="mt-4 text-sm text-red-700">
                            <p class="font-medium">{{ __('Your preferences were not saved. Please review these errors:') }}</p>
                            <ul class="mt-2 list-disc pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>

                @foreach ([
                    ['field' => 'project_types', 'label' => 'Preferred Project Types', 'options' => $projectTypes, 'saved' => $selectedProjects],
                    ['field' => 'technologies', 'label' => 'Preferred Technologies', 'options' => $technologies, 'saved' => $selectedTechnologies],
                ] as $group)
                    @php
                        $selected = session()->hasOldInput() ? old($group['field'], []) : $group['saved'];
                        $selected = is_array($selected) ? $selected : [];
                    @endphp
                    <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <fieldset>
                            <legend class="text-lg font-medium text-gray-900">{{ __($group['label']) }}</legend>
                            <div class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('Saved preferences') }}">
                                @forelse ($group['saved'] as $savedPreference)
                                    <span class="status-badge badge-neutral">{{ $savedPreference }}</span>
                                @empty
                                    <p class="text-xs text-gray-500">{{ __('No saved selections in this category.') }}</p>
                                @endforelse
                            </div>
                            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($group['options'] as $option)
                                    <label for="{{ $group['field'] }}_{{ $loop->index }}" class="flex items-start gap-3 rounded-md border border-gray-200 p-3 text-sm text-gray-800 cursor-pointer hover:bg-gray-50">
                                        <input id="{{ $group['field'] }}_{{ $loop->index }}" type="checkbox" name="{{ $group['field'] }}[]"
                                            value="{{ $option }}" @checked(in_array($option, $selected, true))
                                            class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    </section>
                @endforeach

                <div class="flex flex-wrap items-center gap-4 px-4 sm:px-0">
                    <x-primary-button>{{ __('Save Preferences') }}</x-primary-button>
                    <a href="{{ route('faculty.preferences.index') }}" class="text-sm text-gray-600 underline">{{ __('Discard unsaved changes') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
