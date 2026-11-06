@props([
    'route',
    'options' => [],
    'sort',
    'dir',
    'queryParams' => [],
])
<form method="GET" action="{{ route($route) }}" class="flex flex-wrap items-end gap-3">
    @foreach (collect($queryParams)->except(['sort', 'dir', 'page']) as $key => $value)
        @if (is_scalar($value))
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach
    <div>
        <x-input-label for="sort" :value="__('Sort by')" />
        <select id="sort" name="sort" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            @foreach ($options as $value => $label)
                <option value="{{ $value }}" @selected($sort === $value)>{{ __($label) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="dir" :value="__('Direction')" />
        <select id="dir" name="dir" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            <option value="asc" @selected($dir === 'asc')>{{ __('Ascending') }}</option>
            <option value="desc" @selected($dir === 'desc')>{{ __('Descending') }}</option>
        </select>
    </div>
    <button type="submit" class="button button-secondary">{{ __('Sort') }}</button>
</form>
