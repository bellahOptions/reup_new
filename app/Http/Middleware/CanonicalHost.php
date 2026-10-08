<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CanonicalHost
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->environment('production')) {
            return $next($request);
        }

        $targetHost = $this->targetHost();
        $currentHost = strtolower($request->getHost());

        if (! $targetHost || $currentHost === $targetHost) {
            return $next($request);
        }

        if (! $this->isOwnedHostPair($currentHost, $targetHost)) {
            return $next($request);
        }

        $url = 'https://' . $targetHost . $request->getRequestUri();
        $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

        return redirect()->away($url, $status);
    }

    private function targetHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return 'reup.com.ng';
        }

        $host = strtolower($host);

        return $this->isPublicHostname($host) ? $host : 'reup.com.ng';
    }

    private function isOwnedHostPair(string $currentHost, string $targetHost): bool
    {
        $allowed = [
            'reup.com.ng',
            'www.reup.com.ng',
            $targetHost,
            str_starts_with($targetHost, 'www.') ? substr($targetHost, 4) : 'www.' . $targetHost,
        ];

        return in_array($currentHost, array_unique($allowed), true);
    }

    private function isPublicHostname(string $host): bool
    {
        return $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! str_ends_with($host, '.local')
            && ! str_ends_with($host, '.test');
    }
}
