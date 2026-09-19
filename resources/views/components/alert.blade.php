@props(['type' => 'info'])
@php($tone = in_array($type, ['success', 'error', 'warning'], true) ? $type : 'info')
<div {{ $attributes->class(['ui-alert', 'alert-'.$tone])->merge(['role' => $tone === 'error' ? 'alert' : 'status']) }}>{{ $slot }}</div>
