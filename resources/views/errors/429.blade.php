@extends('errors.layout')

@section('title', 'Too many requests')

@section('content')
    <x-error-state
        code="429"
        title="Too many requests"
        message="You have made a lot of requests in a short time. Wait a moment before trying again."
        icon="pause-circle"
    />
@endsection
