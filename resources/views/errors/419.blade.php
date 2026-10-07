@extends('errors.layout')

@section('title', 'Session expired')

@section('content')
    <x-error-state
        code="419"
        title="Session expired"
        message="Your session timed out while the page was open. Refresh the page and try that action again."
        icon="clock"
    />
@endsection
