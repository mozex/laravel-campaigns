@extends('campaigns::layout')

@section('title', __('campaigns::pages.invalid.title'))

@section('content')
    <h1>
        {{ $heading ?? __('campaigns::pages.invalid.heading') }}
    </h1>

    <p>
        {{ $body ?? __('campaigns::pages.invalid.body') }}
    </p>
@endsection
