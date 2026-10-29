{{-- Plain text, so nothing is HTML-escaped: an escaped apostrophe would show as &#039;. --}}
{!! __('campaigns::mail.footer.sent_to', ['app' => $app, 'email' => $email]) !!}

{!! __('campaigns::mail.footer.unsubscribe') !!}: {!! $unsubscribeUrl !!}
@if ($privacyUrl)
{!! __('campaigns::mail.footer.privacy') !!}: {!! $privacyUrl !!}
@endif
@if ($postalAddress)

{!! $postalAddress !!}
@endif
