@extends('errors.layout')

@section('title', 'Page not found')

@section('content')
    <x-error-state
        code="404"
        title="Page not found"
        message="The page you are looking for has moved, been renamed, or never existed. Check the address or head back to the dashboard."
        icon="magnifying-glass"
    />
@endsection
