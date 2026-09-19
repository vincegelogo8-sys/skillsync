@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white'])

@php
    $alignmentClasses = $align === 'left' ? 'start-0' : 'end-0';
    $widthClass = $width === '48' ? 'w-48' : $width;
@endphp

<details data-dropdown {{ $attributes->class(['relative']) }}>
    <summary class="cursor-pointer rounded-md px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500">
        {{ $trigger }}
    </summary>
    <div class="absolute z-50 mt-2 rounded-md shadow-lg {{ $widthClass }} {{ $alignmentClasses }}">
        <div class="rounded-md ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</details>
