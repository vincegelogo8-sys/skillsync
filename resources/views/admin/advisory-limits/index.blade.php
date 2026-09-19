<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ __('Advisory Limits') }}</h1></x-slot>
    <x-slot name="description">{{ __('Review active advisory loads and manage faculty capacity.') }}</x-slot>
    <div class="py-12"><section class="mx-auto max-w-7xl bg-white p-6 shadow sm:rounded-lg">
        <p class="text-sm text-gray-600">{{ __('Manage faculty capacity. Advisory load counts active assignments; limits do not affect recommendation scores.') }}</p>
        <div class="mt-5 divide-y divide-gray-200">
            @forelse ($faculties as $faculty)
                @php($capacity = $capacities[$faculty->id])
                <article class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div><h3 class="font-medium text-gray-900 break-words">{{ $faculty->user->name }}</h3><p class="mt-1 text-sm text-gray-600">{{ $faculty->department }}</p><p class="mt-2 text-sm">{{ __('Advisory Load') }}: {{ $capacity['load'] }} / {{ $capacity['limit'] }} · <x-status-badge :status="$capacity['status']">{{ $capacity['status'] }}</x-status-badge></p></div>
                    <a href="{{ route('admin.advisory-limits.edit', $faculty) }}" class=" button button-secondary">{{ __('Edit limit') }}<span class="sr-only"> {{ $faculty->user->name }}</span></a>
                </article>
            @empty
                <x-empty-state title="{{ __('No faculty profiles available.') }}" />
            @endforelse
        </div>
        <div class="mt-4">{{ $faculties->links() }}</div>
    </section></div>
</x-app-layout>
