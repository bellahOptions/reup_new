@extends('errors.layout')

@section('title', 'Server error')

@section('content')
    <x-error-state
        code="500"
        title="Server error"
        message="Something broke on our side. The issue has been logged and we are looking into it. Your wallet balance is unaffected."
        icon="server-stack"
    />
@endsection
