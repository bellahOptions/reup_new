{{-- Token-based pagination.
     Laravel's stock Tailwind view hardcodes grey/blue utilities, which the
     design system forbids. This override keeps the same DOM contract
     (`aria-label`, `rel="prev"/"next"`, `aria-current="page"`) so
     Bootstrap-agnostic tests and assistive tech keep working, but renders with
     `btn`/`btn-outline`/`text-muted-foreground` instead.
     Used automatically by `$paginator->links()` and by the admin console, which
     publishes `pagination::tailwind` through this same path. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="flex flex-wrap items-center justify-center gap-2">
        @if ($paginator->onFirstPage())
            <span class="btn btn-outline btn-sm pointer-events-none opacity-50" aria-disabled="true" aria-label="Previous page">
                <x-icon name="chevron-left" class="h-4 w-4" />
                Previous
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
               class="btn btn-outline btn-sm" aria-label="Previous page">
                <x-icon name="chevron-left" class="h-4 w-4" />
                Previous
            </a>
        @endif

        <ul class="flex items-center gap-1">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li>
                        <span class="px-2 text-sm text-muted-foreground" aria-hidden="true">{{ $element }}</span>
                    </li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span class="btn btn-primary btn-sm min-w-9 tabular-nums" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="btn btn-outline btn-sm min-w-9 tabular-nums"
                                   aria-label="Go to page {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach
        </ul>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next"
               class="btn btn-outline btn-sm" aria-label="Next page">
                Next
                <x-icon name="chevron-right" class="h-4 w-4" />
            </a>
        @else
            <span class="btn btn-outline btn-sm pointer-events-none opacity-50" aria-disabled="true" aria-label="Next page">
                Next
                <x-icon name="chevron-right" class="h-4 w-4" />
            </span>
        @endif
    </nav>
@endif
