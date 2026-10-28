{{-- Plain text, so nothing is HTML-escaped: an escaped apostrophe would show as &#039;. --}}
{!! __('campaigns::mail.confirm.heading') !!}

{!! __('campaigns::mail.confirm.body', ['list' => $listName, 'app' => $app]) !!}

{!! __('campaigns::mail.confirm.button') !!}: {!! $url !!}

{!! __('campaigns::mail.confirm.ignore') !!}

{!! $app !!}
