@extends('campaigns::layout')

@section('title', __($confirmed ? 'campaigns::pages.confirmed.title' : 'campaigns::pages.confirm.title'))

@section('content')
    <h1>
        {{ __($confirmed ? 'campaigns::pages.confirmed.heading' : 'campaigns::pages.confirm.heading') }}
    </h1>

    <p>
        {{ __($confirmed ? 'campaigns::pages.confirmed.body' : 'campaigns::pages.confirm.body', ['list' => $list, 'app' => $app]) }}
    </p>

    @unless ($confirmed)
        <form method="POST" action="{{ route('campaigns.confirm.submit', ['token' => $token], false) }}">
            <button type="submit">
                {{ __('campaigns::pages.confirm.button') }}
            </button>
        </form>
    @endunless
@endsection
