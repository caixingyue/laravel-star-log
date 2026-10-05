<?php

namespace Caixingyue\LaravelStarLog\Http\Middleware;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Support\HttpContentType;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Caixingyue\LaravelStarLog\Support\UserAgentDetector;
use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Record an incoming HTTP request.
 */
class RouteLog
{
    /**
     * The request attribute used to retain route log state until the final response is available.
     */
    public const STATE_ATTRIBUTE = 'starlog.route_log';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->shouldLog($request)) {
            return $next($request);
        }

        $request->attributes->set(self::STATE_ATTRIBUTE, ['started_at' => hrtime(true)]);

        $this->recordRequest($request);

        return $next($request);
    }

    /**
     * Determine whether the request should be logged.
     */
    private function shouldLog(Request $request): bool
    {
        $ignore = StarLog::getConfig('route.ignore', []);

        if (! is_array($ignore)) {
            return true;
        }

        return ! $this->matchesPath($request, $ignore['paths'] ?? [])
            && ! $this->matchesMethod($request, $ignore['methods'] ?? [])
            && ! $this->matchesRouteName($request, $ignore['route_names'] ?? []);
    }

    /**
     * Record the request without allowing logging failures to affect the request.
     */
    private function recordRequest(Request $request): void
    {
        try {
            $normalizer = HttpLogDataNormalizer::fromConfig(StarLog::getConfig('route', []));

            $context = array_filter([
                'route_name' => $this->resolveRouteName($request),
                'headers' => $normalizer->prepareHeaders($request->headers->all(), StarLog::getConfig('route.request.headers', [])),
                'query' => $this->buildQueryContext($request, $normalizer),
                'body' => $this->describeRequestBody($request, $normalizer),
            ], static fn (mixed $value): bool => $value !== null);

            Log::info($this->formatRequestMessage($request), $context);
        } catch (Throwable) {
            // Route logging must never prevent an application response.
        }
    }

    /**
     * Determine whether a request path matches an ignored path pattern.
     */
    private function matchesPath(Request $request, mixed $paths): bool
    {
        if (! is_array($paths)) {
            return false;
        }

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && ($request->is($path) || $request->fullUrlIs($path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a request method appears in the ignored method list.
     */
    private function matchesMethod(Request $request, mixed $methods): bool
    {
        if (! is_array($methods)) {
            return false;
        }

        return in_array($request->method(), array_filter($methods, 'is_string'), true);
    }

    /**
     * Determine whether a named route appears in the ignored route name list.
     */
    private function matchesRouteName(Request $request, mixed $routeNames): bool
    {
        if (! is_array($routeNames)) {
            return false;
        }

        $routeNames = array_filter($routeNames, 'is_string');

        return $routeNames !== []
            && ($routeName = $this->resolveRouteName($request)) !== null
            && in_array($routeName, $routeNames, true);
    }

    /**
     * Build the request query context when enabled.
     */
    private function buildQueryContext(Request $request, HttpLogDataNormalizer $normalizer): ?array
    {
        if (StarLog::getConfig('route.request.query', false) !== true) {
            return null;
        }

        $query = $normalizer->prepare($request->query());

        return $query === [] ? null : $query;
    }

    /**
     * Build the request body context, including file metadata in its original field position.
     */
    private function describeRequestBody(Request $request, HttpLogDataNormalizer $normalizer): ?array
    {
        if (StarLog::getConfig('route.request.body', false) !== true) {
            return null;
        }

        $contentType = HttpContentType::normalizeContentType($request->header('Content-Type', ''));

        if (HttpContentType::isBinaryContentType($contentType)) {
            return $normalizer->describeBody('binary', $contentType);
        }

        $stream = Utils::streamFor($request->getContent(true));

        try {
            $content = $normalizer->readBody($stream, $contentType);
        } finally {
            // The resource may belong to the request; the wrapper must not close it.
            $stream->detach();
        }

        if ($content === null) {
            return $normalizer->describeBody('unknown', $contentType);
        }

        if ($content === '' && $request->request->all() === [] && $request->allFiles() === []) {
            return $normalizer->describeBody('empty');
        }

        if (str_contains($contentType ?? '', 'json')) {
            return $normalizer->describeJsonBody($content, $contentType);
        }

        $files = $request->allFiles();

        if ($files !== [] || str_contains($contentType ?? '', 'multipart/')) {
            return $normalizer->describeDataBody(
                'multipart',
                array_replace_recursive($request->request->all(), $files),
                $contentType,
            );
        }

        $input = $request->request->all();

        if (in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            return $normalizer->describeHtmlBody($content, $contentType);
        }

        return $input !== []
            ? $normalizer->describeDataBody('form', $input, $contentType)
            : $normalizer->describeTextBody($content, $contentType);
    }

    /**
     * Get the request route name when a route has been resolved.
     */
    private function resolveRouteName(Request $request): ?string
    {
        $route = $request->route();

        if (! is_object($route)) {
            try {
                $route = app(Router::class)->getRoutes()->match($request);
            } catch (Throwable) {
                return null;
            }
        }

        return is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
    }

    /**
     * Format the request summary in the original route log style.
     */
    private function formatRequestMessage(Request $request): string
    {
        return StarLog::translate('route.request', [
            'terminal' => $this->describeClientDevice($request),
            'ip' => $request->ip() ?? 'unknown',
            'method' => $request->method(),
            'path' => $request->path(),
        ]);
    }

    /**
     * Describe the request terminal without storing the complete user agent.
     */
    private function describeClientDevice(Request $request): string
    {
        try {
            $agent = new UserAgentDetector;
            $agent->setUserAgent($request->userAgent() ?? '');
            $details = array_filter([$agent->device(), $agent->platform()]);
            $details[] = $agent->isDesktop() ? 'desktop' : ($agent->isMobile() ? 'mobile' : 'unknown');

            return implode('|', $details);
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
