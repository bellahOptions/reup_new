@extends('admin.layouts.app')

@section('title', 'Email templates')
@section('page-title', 'Email templates')
@section('page-description', 'The content lifecycle automations send.')

@section('page-actions')
    @if(auth()->user()->hasPermission('manage_lifecycle'))
        <a href="{{ route('admin.lifecycle.templates.create') }}" class="btn btn-primary btn-sm">
            <x-icon name="plus" class="h-4 w-4" />
            New template
        </a>
    @endif
@endsection

@section('content')
<div class="space-y-6">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Templates</h2>
            <p class="card-description">
                Body markup is sanitised when you save it, so what you preview is what the customer receives.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Template</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th class="text-right">Versions</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($templates as $template)
                        <tr>
                            <td>
                                <a href="{{ route('admin.lifecycle.templates.edit', $template) }}" class="font-medium link">
                                    {{ $template->name }}
                                </a>
                                <p class="text-xs text-muted-foreground">{{ $template->key }}</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ Str::limit($template->subject, 70) }}</p>
                            </td>
                            <td>
                                <span class="badge {{ $template->isMarketing() ? 'badge-warning' : 'badge-neutral' }}">
                                    {{ ucfirst($template->category) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge {{ $template->status === 'active' ? 'badge-success' : ($template->status === 'archived' ? 'badge-neutral' : 'badge-warning') }}">
                                    {{ ucfirst($template->status) }}
                                </span>
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($template->versions_count) }}</td>
                            <td class="text-right">
                                <a href="{{ route('admin.lifecycle.templates.edit', $template) }}"
                                   class="btn btn-ghost btn-sm">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted-foreground">
                                No templates yet.
                                @if(auth()->user()->hasPermission('manage_lifecycle'))
                                    <a href="{{ route('admin.lifecycle.templates.create') }}" class="link">Create the first one.</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
