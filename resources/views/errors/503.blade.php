@extends('errors.layout')

@section('title', 'Down for maintenance')

@section('content')
    <x-error-state
        code="503"
        title="Down for maintenance"
        message="ReUp is briefly offline while we deploy an update. Please check back shortly — no transactions are affected."
        icon="wrench-screwdriver"
    />
@endsection
