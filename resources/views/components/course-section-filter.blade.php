@props([
    'route',
    'course' => null,
    'section' => null,
    'courseCounts' => [],
    'sectionCounts' => [],
    'allCourseCount' => 0,
    'queryParams' => [],
])
@php
    $base = collect($queryParams)->except(['course', 'section', 'page'])->all();
    $url = function (array $params) use ($route): string {
        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');
        $ordered = [];
        foreach (['type', 'course', 'section'] as $key) {
            if (array_key_exists($key, $params)) {
                $ordered[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        return route($route, [...$ordered, ...$params]);
    };
@endphp
<nav aria-label="{{ __('Filter by course') }}" class="flex flex-wrap gap-2">
    <a href="{{ $url($base) }}"
       @if ($course === null) aria-current="true" @endif
       class="button {{ $course === null ? 'button-primary' : 'button-secondary' }}">
        {{ __('All courses') }} ({{ $allCourseCount }})
    </a>
    @foreach (config('student_options.courses') as $option)
        <a href="{{ $url([...$base, 'course' => $course === $option ? null : $option]) }}"
           @if ($course === $option) aria-current="true" @endif
           class="button {{ $course === $option ? 'button-primary' : 'button-secondary' }}">
            {{ $option }} ({{ $courseCounts[$option] ?? 0 }})
        </a>
    @endforeach
</nav>
@if ($course !== null)
    <nav aria-label="{{ __('Filter by section') }}" class="flex flex-wrap gap-2">
        <a href="{{ $url([...$base, 'course' => $course]) }}"
           @if ($section === null) aria-current="true" @endif
           class="button {{ $section === null ? 'button-primary' : 'button-secondary' }}">
            {{ __('All sections') }} ({{ array_sum($sectionCounts) }})
        </a>
        @foreach (config('student_options.sections') as $option)
            <a href="{{ $url([...$base, 'course' => $course, 'section' => $section === $option ? null : $option]) }}"
               @if ($section === $option) aria-current="true" @endif
               class="button {{ $section === $option ? 'button-primary' : 'button-secondary' }}">
                {{ $option }} ({{ $sectionCounts[$option] ?? 0 }})
            </a>
        @endforeach
    </nav>
@endif
