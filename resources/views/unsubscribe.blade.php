@extends('campaigns::layout')

@section('title', __("campaigns::pages.{$state}.title"))

@section('content')
    <h1>
        {{ __("campaigns::pages.{$state}.heading", ['app' => $app]) }}
    </h1>

    @if ($email !== null)
        <p class="address">
            {{ $email }}
        </p>
    @endif

    <p>
        {{ __("campaigns::pages.{$state}.body", ['app' => $app]) }}
    </p>

    @if ($state === 'subscribed')
        <form method="POST" action="{{ route('campaigns.unsubscribe.submit', ['token' => $token], false) }}">
            <button type="submit">
                {{ __('campaigns::pages.subscribed.button') }}
            </button>
        </form>
    @elseif ($state === 'unsubscribed' && $canResubscribe)
        <p>
            {{ __('campaigns::pages.unsubscribed.again') }}
        </p>
        <form method="POST" action="{{ route('campaigns.resubscribe', ['token' => $token], false) }}">
            <button type="submit">
                {{ __('campaigns::pages.unsubscribed.button') }}
            </button>
        </form>
    @endif

    @if ($isTest)
        <p class="note">
            {{ __('campaigns::pages.test') }}
        </p>
    @endif
@endsection
