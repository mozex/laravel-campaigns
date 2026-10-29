{{-- Plain text, so nothing is HTML-escaped: an escaped apostrophe would show as &#039;. --}}
{!! $heading !!}
@foreach ($lines as $line)

{!! $line !!}
@endforeach
