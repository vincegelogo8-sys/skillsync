
<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Faculty Skills') }}
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                @if ($routePrefix === 'admin.expertise.')
                    <a href="{{ route('admin.expertise.faculty') }}"
                       class="button button-secondary">
                        {{ __('Back to Faculty Skills') }}
                    </a>
                @endif

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
                        </fieldset>
                        <x-input-error :messages="$errors->get('skills')" class="mt-2" />
                        <x-input-error :messages="$errors->get('skills.*')" class="mt-2" />
                        <x-primary-button>{{ __('Save Faculty Skills') }}</x-primary-button>
                    </form>
                </section>
            @endif

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $isFaculty ? __('My Skills') : __('Faculty Skills') }}
                </h3>
                @if ($canManage)
                    <div class="mt-4 divide-y divide-gray-200">
                    @forelse ($entries as $entry)
                        <article class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <h4 class="font-medium text-gray-900 break-words">
                                    {{ $entry->expertise_area }}
                                </h4>
                            </div>

                            @if ($canManage)
                                <div class="flex flex-wrap items-center gap-4">
                                    <a
                                        href="{{ route($routePrefix.'edit', $routeParameters + ['expertise' => $entry]) }}"
                                        class="text-sm text-indigo-600 underline"
                                        aria-label="{{ __('Edit :area', ['area' => $entry->expertise_area]) }}"
                                    >
                                        {{ __('Edit') }}
                                    </a>

                                    <details class="text-sm">
                                        <summary class="cursor-pointer text-red-700 underline">
                                            {{ __('Remove :area', ['area' => $entry->expertise_area]) }}
                                        </summary>

                                        <form
                                            method="POST"
                                            action="{{ route($routePrefix.'destroy', $routeParameters + ['expertise' => $entry]) }}"
                                            class="mt-3"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <p class="mb-2 text-gray-600">
                                                {{ __('Remove this faculty skill?') }}
                                            </p>

                                            <x-danger-button>
                                                {{ __('Confirm removal') }}
                                            </x-danger-button>
                                        </form>
                                    </details>
                                </div>
                            @endif
                        </article>
                    @empty
                        <x-empty-state :title="__('No faculty skills registered.')" />
                    @endforelse
                    </div>
                @else
                    <ul class="mt-4 flex flex-wrap gap-2">
                        @forelse ($entries as $entry)
                            <li class="concept-chip">{{ $entry->expertise_area }}</li>
                        @empty
                            <li class="text-sm text-gray-600">{{ __('No faculty skills selected yet.') }}</li>
                        @endforelse
                    </ul>
                @endif
            </section>

            @if ($canManage)
                <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <div class="max-w-xl">
                        <h3 class="text-lg font-medium text-gray-900">
                            {{ __('Add Faculty Skill') }}
                        </h3>

                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('Add each faculty skill category once.') }}
                        </p>

                        <form
                            method="POST"
                            action="{{ route($routePrefix.'store', $routeParameters) }}"
                            class="mt-6 space-y-6"
                        >
                            @csrf

                            @include('faculty.expertise.form', ['expertise' => null])

                            <x-primary-button>
                                {{ __('Add Faculty Skill') }}
                            </x-primary-button>
                        </form>
                    </div>
                </section>
            @endif

        </div>
    </div>
</x-app-layout>
