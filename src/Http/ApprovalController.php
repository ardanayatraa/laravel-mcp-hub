<?php

namespace McpHub\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use McpHub\Laravel\Commands\CommandException;
use McpHub\Laravel\Commands\Commands;
use McpHub\Laravel\Contracts\ActorResolver;
use Throwable;

final class ApprovalController
{
    public function show(Request $request, string $proposal, Commands $commands, ActorResolver $resolver)
    {
        try {
            $user = Auth::guard(config('mcp-hub.web.guard'))->user();
            abort_unless($user, 403);
            $preview = $commands->preview($resolver->forUser($user), $proposal);
            return response()->view('mcp-hub::approval', $preview, 200, $this->headers());
        } catch (CommandException $error) {
            return $this->error($error->status());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function confirm(Request $request, string $proposal, Commands $commands, ActorResolver $resolver)
    {
        try {
            $user = Auth::guard(config('mcp-hub.web.guard'))->user();
            abort_unless($user, 403);
            abort_if(array_diff(array_keys($request->all()), ['decision', '_token']), 422);
            $receipt = $commands->confirm($resolver->forUser($user), $proposal, (string) $request->input('decision'));
            if (! $request->expectsJson()) {
                return redirect()->route('mcp-hub.approval.show', ['proposal' => $proposal])->withHeaders($this->headers());
            }
            return response()->json(['success' => true, 'data' => $receipt], 200, $this->headers());
        } catch (CommandException $error) {
            return $this->error($error->status());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    private function error(int $status)
    {
        return response()->json(['success' => false, 'error' => [
            'code' => 'COMMAND_REJECTED', 'message' => 'Proposal could not be completed.',
        ]], $status, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"];
    }
}
