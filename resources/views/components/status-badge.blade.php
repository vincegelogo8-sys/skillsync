@props(['status'])
@php
    $tone = match (strtolower($status)) {
        'approved', 'accepted', 'active', 'available', 'analyzed', 'extracted', 'assigned', 'completed' => 'success',
        'pending', 'uploaded', 'in_progress', 'not analyzed' => 'warning',
        'declined', 'full', 'extraction_failed' => 'danger',
        default => 'neutral',
    };
@endphp
<span {{ $attributes->class(['status-badge', 'badge-'.$tone]) }}>{{ $slot->isEmpty() ? __(ucfirst(str_replace('_', ' ', $status))) : $slot }}</span>
