<?php

namespace McpHub\Laravel\Http;

use Illuminate\Http\Request;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Exports\ExportException;
use McpHub\Laravel\Exports\Exports;
use McpHub\Laravel\Security\Assertions;
use McpHub\Laravel\Security\SourceAccess;
use McpHub\Laravel\Security\WebAccess;
use McpHub\Laravel\Support\Json;
use Throwable;

final class ExportController
{
    public function prepare(Request $request, string $resource, Exports $exports, SourceAccess $access, Assertions $assertions)
    {
        if (! $access->allows($request)) {
            return $this->error(401);
        }
        try {
            $actor = $assertions->verify((string) $request->header('X-Hub-Assertion', ''));
            if (strtolower(explode(';', (string) $request->header('Content-Type'))[0]) !== 'application/json'
                || $request->query() !== [] || strlen($request->getContent()) > 4096
                || substr(ltrim($request->getContent()), 0, 1) !== '{') {
                return $this->error(422);
            }
            try {
                $input = Json::decode($request->getContent(), 8);
            } catch (Throwable $error) {
                return $this->error(422);
            }
            return $this->result($exports->prepare($actor, $resource, $input, (string) $request->header('Idempotency-Key', '')));
        } catch (ExportException $error) {
            return $this->error($error->status());
        } catch (\InvalidArgumentException $error) {
            return $this->error(422);
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function status(Request $request, string $resource, string $reference, Exports $exports, SourceAccess $access, Assertions $assertions)
    {
        if (! $access->allows($request)) {
            return $this->error(401);
        }
        try {
            if ($request->query() !== []) {
                return $this->error(422);
            }
            $actor = $assertions->verify((string) $request->header('X-Hub-Assertion', ''));
            return $this->result($exports->status($actor, $reference, $resource));
        } catch (ExportException $error) {
            return $this->error($error->status());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function download(Request $request, string $reference, Exports $exports, WebAccess $access, ActorResolver $resolver)
    {
        try {
            if ($request->query() !== []) {
                return $this->error(422);
            }
            $actor = $resolver->forUser($access->user('export_gate'));
            list($format, $bytes) = $exports->download($actor, $reference);
            $types = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pdf' => 'application/pdf'];
            return response($bytes, 200, ['Content-Type' => $types[$format],
                'Content-Disposition' => 'attachment; filename="report.'.$format.'"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer']);
        } catch (ExportException $error) {
            return $this->error($error->status());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    private function result(array $receipt)
    {
        return response()->json(['success' => true, 'data' => [$receipt], 'meta' => [
            'as_of' => now('UTC')->toAtomString(), 'timezone' => 'UTC', 'scope' => 'authorized_records',
            'coverage' => 'complete',
        ]], 200, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function error(int $status)
    {
        $codes = [401 => 'UNAUTHENTICATED', 403 => 'PERMISSION_DENIED', 404 => 'NOT_FOUND', 409 => 'CONFLICT',
            410 => 'EXPIRED', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED', 503 => 'UNAVAILABLE'];
        return response()->json(['success' => false, 'error' => ['code' => $codes[$status] ?? 'UNAVAILABLE',
            'message' => 'Export request could not be completed.']], $status,
            ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
