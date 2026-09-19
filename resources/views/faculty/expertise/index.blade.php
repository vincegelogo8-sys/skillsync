<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Research Expertise') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                @if ($routePrefix === 'admin.expertise.')
                    <a href="{{ route('admin.expertise.faculty') }}" class="text-sm text-indigo-600 underline">{{ __('Back to Faculty Expertise') }}</a>
                @endif
                <h3 class="text-lg font-medium text-gray-900 mt-2">{{ $profile->user->name }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ $profile->department }}</p>
                <p class="mt-3 text-sm text-gray-600">{{ __('List research expertise and proficiency in each area. These scores will support research topic matching.') }}</p>
                @if (session('status'))
                    <p role="status" class="mt-4 text-sm font-medium text-green-700">{{ session('status') }}</p>
                @endif
            </section>

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Current expertise') }}</h3>
                <div class="mt-4 divide-y divide-gray-200">
                    @forelse ($entries as $entry)
                        <article class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div>
                                <h4 class="font-medium text-gray-900">{{ $entry->expertise_area }}</h4>
                                <p class="mt-1 text-sm text-gray-600">{{ __('Proficiency') }}: {{ $entry->proficiency_score }}/100</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route($routePrefix.'edit', $routeParameters + ['expertise' => $entry]) }}" class="text-sm text-indigo-600 underline"
                                    aria-label="{{ __('Edit :area', ['area' => $entry->expertise_area]) }}">{{ __('Edit') }}</a>
                                <details class="text-sm">
                                    <summary class="cursor-pointer text-red-700 underline">{{ __('Remove :area', ['area' => $entry->expertise_area]) }}</summary>
                                    <form method="POST" action="{{ route($routePrefix.'destroy', $routeParameters + ['expertise' => $entry]) }}" class="mt-3">
                                        @csrf
                                        @method('DELETE')
                                        <p class="mb-2 text-gray-600">{{ __('Remove this expertise entry?') }}</p>
                                        <x-danger-button>{{ __('Confirm removal') }}</x-danger-button>
                                    </form>
                                </details>
                            </div>
                        </article>
                    @empty
                        <p class="py-4 text-sm text-gray-600">{{ __('No research expertise added yet. Add your first area below.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <div class="max-w-xl">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Add expertise') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Add each area once. You may add multiple areas and edit their scores later.') }}</p>
                    <form method="POST" action="{{ route($routePrefix.'store', $routeParameters) }}" class="mt-6 space-y-6">
                        @csrf
                        @include('faculty.expertise.form', ['expertise' => null])
                        <x-primary-button>{{ __('Add Expertise') }}</x-primary-button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
