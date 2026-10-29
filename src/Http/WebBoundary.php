<?php

namespace McpHub\Laravel\Http;

use Closure;
use Illuminate\Http\Request;

/** Keep package mutations protected even if the host exempts broad CSRF paths. */
final class WebBoundary
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $origin = $request->header('Origin');
            if (is_string($origin) && rtrim($origin, '/') !== rtrim($request->getSchemeAndHttpHost(), '/')) {
                return $this->deny(403);
            }
            $expected = $request->session()->token();
            $supplied = $request->input('_token', $request->header('X-CSRF-TOKEN', ''));
            if (! is_string($expected) || $expected === '' || ! is_string($supplied)
                || ! hash_equals($expected, $supplied)) {
                return $this->deny(419);
            }
            if (strlen($request->getContent()) > 32768) {
                return $this->deny(413);
            }
        }
        return $next($request);
    }

    private function deny(int $status)
    {
        return response()->json(['success' => false, 'error' => [
            'code' => 'REQUEST_REJECTED', 'message' => 'Request could not be verified.',
        ]], $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
