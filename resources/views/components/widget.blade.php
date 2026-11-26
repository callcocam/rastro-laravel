@props(['endpoint' => null, 'storageKey' => null])
{{-- The Rastro chat, compiled with Vue inside. Once, before </body>. --}}
<script
    src="{{ \Callcocam\Rastro\Widget::scriptUrl() }}"
    data-endpoint="{{ $endpoint ?? \Callcocam\Rastro\Widget::endpoint() }}"
    @if ($storageKey) data-storage-key="{{ $storageKey }}" @endif
    data-navigate-once
    defer
></script>
