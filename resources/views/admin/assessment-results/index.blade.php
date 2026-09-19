<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Skills Assessment Results') }}</h2></x-slot>
    <div class="py-12"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <p class="text-sm text-gray-600">{{ __('Completed faculty assessments, newest first.') }}</p>
            <div class="mt-4 divide-y divide-gray-200">
                @forelse ($attempts as $attempt)
                    <article class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="min-w-0"><h3 class="font-medium text-gray-900 break-words">{{ $attempt->facultyProfile->user->name }}</h3>
                            <p class="text-sm text-gray-600 break-words">{{ $attempt->facultyProfile->user->email }}</p>
                            <p class="text-sm text-gray-600">{{ __('Attempt') }} #{{ $attempt->id }} · {{ $attempt->completed_at->format('Y-m-d H:i T') }}</p>
                        </div>
                        <p class="font-medium text-gray-900">{{ $attempt->score }}/10 — {{ $attempt->percentage }}%</p>
                    </article>
                @empty
                    <p class="py-4 text-gray-600">{{ __('No completed assessments yet.') }}</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $attempts->links() }}</div>
        </section>
    </div></div>
</x-app-layout>
