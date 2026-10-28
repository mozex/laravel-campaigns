@use(Mozex\Campaigns\Support\PageLocale)
<!DOCTYPE html>
<html lang="{{ PageLocale::htmlLang(app()->getLocale()) }}" dir="{{ PageLocale::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        {{ __('campaigns::mail.confirm.heading') }}
    </title>
</head>
<body style="margin: 0; padding: 0; background: #f4f4f5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 560px; background: #ffffff; border: 1px solid #e4e4e7; border-radius: 12px;">
                    <tr>
                        <td style="padding: 32px; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.6; color: #18181b;">
                            <p style="margin: 0 0 24px; font-size: 14px; font-weight: bold; color: #71717a;">
                                {{ $app }}
                            </p>
                            <h1 style="margin: 0 0 16px; font-size: 22px; line-height: 1.3;">
                                {{ __('campaigns::mail.confirm.heading') }}
                            </h1>
                            <p style="margin: 0 0 24px;">
                                {{ __('campaigns::mail.confirm.body', ['list' => $listName, 'app' => $app]) }}
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-radius: 8px; background: #18181b;">
                                        <a href="{{ $url }}" style="display: inline-block; padding: 12px 20px; font-weight: bold; color: #ffffff; text-decoration: none;">
                                            {{ __('campaigns::mail.confirm.button') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 24px 0 8px; font-size: 14px; color: #52525b;">
                                {{ __('campaigns::mail.confirm.link') }}
                            </p>
                            <p style="margin: 0 0 24px; font-size: 14px; word-break: break-all;">
                                <a href="{{ $url }}" style="color: #18181b;">{{ $url }}</a>
                            </p>
                            <p style="margin: 0; font-size: 14px; color: #52525b;">
                                {{ __('campaigns::mail.confirm.ignore') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
