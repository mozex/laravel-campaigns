<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center" style="padding: 24px 16px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.6; color: #71717a;">
            <p style="margin: 0 0 8px;">
                {{ __('campaigns::mail.footer.sent_to', ['app' => $app, 'email' => $email]) }}
            </p>
            <p style="margin: 0 0 8px;">
                <a href="{{ $unsubscribeUrl }}" style="color: #52525b; text-decoration: underline;">{{ __('campaigns::mail.footer.unsubscribe') }}</a>
                @if ($privacyUrl)
                    &nbsp;&middot;&nbsp;
                    <a href="{{ $privacyUrl }}" style="color: #52525b; text-decoration: underline;">{{ __('campaigns::mail.footer.privacy') }}</a>
                @endif
            </p>
            @if ($postalAddress)
                <p style="margin: 0;">
                    {{ $postalAddress }}
                </p>
            @endif
        </td>
    </tr>
</table>
