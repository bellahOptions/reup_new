@extends('errors.layout')

@section('title', 'Access denied')

@section('content')
    <x-error-state
        code="403"
        title="Access denied"
        message="You do not have permission to view this page. If you believe this is a mistake, contact support and we will take a look."
        icon="lock-closed"
    />
@endsection
