@use(Mozex\Campaigns\Content\Personalization)
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>
        {{ __('campaigns::pages.preview.title') }} | {{ $owner->name }} | {{ $app }}
    </title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; padding: 1rem; background: #f4f4f5; color: #18181b; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 1100px; margin: 0 auto; }
        section { margin: 0 0 1rem; padding: 1.25rem; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; }
        .app { margin: 0 0 .25rem; color: #71717a; font-size: .8125rem; font-weight: 600; }
        h1 { margin: 0 0 .25rem; font-size: 1.25rem; line-height: 1.3; overflow-wrap: anywhere; }
        h2 { margin: 0 0 .75rem; font-size: 1rem; }
        .meta { margin: 0; color: #52525b; font-size: .875rem; overflow-wrap: anywhere; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .375rem 1rem; margin: 0; }
        dt { color: #71717a; }
        dd { margin: 0; overflow-wrap: anywhere; }
        .tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0 0; padding: 0; list-style: none; }
        .tabs a { display: inline-block; padding: .25rem .75rem; border: 1px solid #d4d4d8; border-radius: 999px; color: #18181b; text-decoration: none; font-size: .875rem; }
        .tabs a[aria-current] { background: #18181b; border-color: #18181b; color: #fff; }
        .note { margin: 1rem 0 0; padding: .625rem .875rem; background: #fef9c3; border-radius: 8px; font-size: .875rem; }
        .frames { display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start; }
        figure { margin: 0; max-width: 100%; }
        figcaption { margin: 0 0 .5rem; color: #52525b; font-size: .875rem; font-weight: 600; }
        iframe { display: block; max-width: 100%; height: 80vh; min-height: 600px; border: 1px solid #d4d4d8; border-radius: 8px; background: #fff; }
        pre { margin: 0; padding: 1rem; max-height: 60vh; overflow: auto; background: #fafafa; border: 1px solid #e4e4e7; border-radius: 8px; white-space: pre-wrap; overflow-wrap: anywhere; font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        ul.checks { margin: .5rem 0 0; padding-inline-start: 1.25rem; }
        ul.checks li { margin: 0 0 .25rem; overflow-wrap: anywhere; }
        p { margin: 0; }
    </style>
</head>
<body>
    <main>
        <section>
            <p class="app">
                {{ $app }}
            </p>
            <h1>
                {{ $owner->name }}
            </h1>
            <p class="meta">
                {{ __('campaigns::pages.preview.'.$kind) }} {{ $owner->key }} |
                {{ __('campaigns::pages.preview.revision', ['number' => $revision->number]) }}@if ($revision->frozenFrom !== null), {{ __('campaigns::pages.preview.frozen', ['number' => $revision->frozenFrom->number]) }}@endif
            </p>

            <ul class="tabs" aria-label="{{ __('campaigns::pages.preview.languages') }}">
                @foreach ($tabs as $tab)
                    <li>
                        <a href="{{ $tab['url'] }}" @if ($tab['current']) aria-current="page" @endif>
                            {{ $tab['locale'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        <section>
            <dl>
                <dt>
                    {{ __('campaigns::pages.preview.from') }}
                </dt>
                <dd>
                    @if ($sender['email'] === null)
                        {{ __('campaigns::pages.preview.not_configured') }}
                    @else
                        {{ $sender['name'] === null ? $sender['email'] : $sender['name'].' <'.$sender['email'].'>' }}
                    @endif
                </dd>
                <dt>
                    {{ __('campaigns::pages.preview.reply_to') }}
                </dt>
                <dd>
                    {{ $sender['reply_to'] ?? __('campaigns::pages.preview.none') }}
                </dd>
                <dt>
                    {{ __('campaigns::pages.preview.subject') }}
                </dt>
                <dd>
                    {{ $email->subject }}
                </dd>
                <dt>
                    {{ __('campaigns::pages.preview.preheader') }}
                </dt>
                <dd>
                    {{ $email->preheader ?? __('campaigns::pages.preview.none') }}
                </dd>
            </dl>

            <p class="note">
                {{ __('campaigns::pages.preview.sample', ['name' => Personalization::SAMPLE_NAME, 'email' => Personalization::SAMPLE_EMAIL]) }}
            </p>
        </section>

        <section>
            <div class="frames">
                @foreach ([600 => 'desktop', 375 => 'phone'] as $width => $label)
                    <figure>
                        <figcaption>
                            {{ __('campaigns::pages.preview.'.$label) }}
                        </figcaption>
                        <iframe sandbox="{{ $sandbox }}" width="{{ $width }}" style="width: {{ $width }}px" title="{{ __('campaigns::pages.preview.frame', ['width' => $width]) }}" srcdoc="{{ $framed }}"></iframe>
                    </figure>
                @endforeach
            </div>
        </section>

        <section>
            <h2>
                {{ __('campaigns::pages.preview.text') }}
            </h2>
            <pre>{{ $email->text }}</pre>
        </section>

        <section>
            <h2>
                {{ __('campaigns::pages.preview.checks') }}
            </h2>
            <p>
                @switch ($checks['status'])
                    @case ('queued')
                        {{ __('campaigns::pages.preview.checks_queued') }}
                        @break
                    @case ('failed')
                        {{ __('campaigns::pages.preview.checks_failed', ['message' => $checks['message'] ?? '']) }}
                        @break
                    @case ('done')
                        {{ __('campaigns::pages.preview.checks_done', ['passed' => $checks['passed'] ?? 0, 'failed' => $checks['failed'] ?? 0]) }}
                        @break
                    @default
                        {{ __('campaigns::pages.preview.checks_none') }}
                @endswitch
            </p>
            @if ($failed !== [])
                <ul class="checks">
                    @foreach ($failed as $check)
                        <li>
                            <strong>{{ $check['check'] }}</strong>@if ($check['locale'] !== null) ({{ $check['locale'] }})@endif: {{ $check['message'] }}@if ($check['items'] > 0) ({{ __('campaigns::pages.preview.check_items', ['count' => $check['items']]) }})@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </main>
</body>
</html>
