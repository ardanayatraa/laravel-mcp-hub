<?php

namespace McpHub\Laravel\Http;

use Illuminate\Http\Request;
use InvalidArgumentException;
use McpHub\Laravel\Commands\CommandException;
use McpHub\Laravel\Commands\Commands;
use McpHub\Laravel\Security\Assertions;
use McpHub\Laravel\Security\SourceAccess;
use McpHub\Laravel\Support\Json;
use Throwable;

final class CommandController
{
    public function prepare(Request $request, string $resource, string $operation, Commands $commands,
                            SourceAccess $access, Assertions $assertions)
    {
        if (! $access->allows($request)) {
            return $this->error(401);
        }
        try {
            $actor = $assertions->verify((string) $request->header('X-Hub-Assertion', ''));
            if (strtolower(explode(';', (string) $request->header('Content-Type'))[0]) !== 'application/json'
                || $request->query() !== [] || strlen($request->getContent()) > 32768
                || substr(ltrim($request->getContent()), 0, 1) !== '{') {
                return $this->error(422);
            }
            try {
                $input = Json::decode($request->getContent(), 8);
            } catch (Throwable $error) {
                return $this->error(422);
            }
            $receipt = $commands->prepare($actor, $resource, $operation, $input,
                (string) $request->header('Idempotency-Key', ''));
            return $this->result($receipt);
        } catch (CommandException $error) {
            return $this->error($error->status());
        } catch (InvalidArgumentException $error) {
            return $this->error(422);
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function status(Request $request, string $resource, string $proposal, Commands $commands,
                           SourceAccess $access, Assertions $assertions)
    {
        if (! $access->allows($request)) {
            return $this->error(401);
        }
        try {
            if ($request->query() !== []) {
                return $this->error(422);
            }
            $actor = $assertions->verify((string) $request->header('X-Hub-Assertion', ''));
            return $this->result($commands->status($actor, $proposal, $resource));
        } catch (CommandException $error) {
            return $this->error($error->status());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    private function result(array $receipt)
    {
        return response()->json(['success' => true, 'data' => [$receipt], 'meta' => [
            'requires_confirmation' => $receipt['requires_confirmation'],
            'as_of' => now('UTC')->toAtomString(), 'scope' => 'authorized_records',
            'coverage' => 'complete', 'timezone' => 'UTC',
        ]], 200, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function error(int $status)
    {
        $codes = [401 => 'UNAUTHENTICATED', 403 => 'PERMISSION_DENIED', 404 => 'NOT_FOUND',
            409 => 'CONFLICT', 410 => 'EXPIRED', 413 => 'PAYLOAD_TOO_LARGE', 422 => 'VALIDATION_FAILED'];
        return response()->json(['success' => false, 'error' => [
            'code' => $codes[$status] ?? 'UNAVAILABLE', 'message' => 'Command request could not be completed.',
        ]], $status, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
