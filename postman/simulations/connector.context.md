# Connector transport simulation

2026-10-28: Postman CLI 1.80.0 served the local Hub contract on 18130.

Run the command in README.md from the repository root and set
MCP_HUB_SIM_URL=http://127.0.0.1:18130/hub/mcp before composer test.
The full standalone suite passed: 18 tests and 60 assertions.

The Hub member received the PHP transport's real POST /mcp, validated the dummy
bearer credential, origin and JSON-RPC metadata, and returned a matching response ID.
The only modeled operation is tools/list; unmodeled paths return 501.

The 0.2 compatibility implementation was also run against this same simulation
on Laravel 5.8.38 / PHP 7.4.33 and Laravel 12.70.2 / PHP 8.3.16. Both completed
19 tests and 71 assertions without skips. The simulation logged a real POST
/mcp returning 200 from each runtime.

This proves the PHP client against this local stand-in. It does not prove a live
Hub, a provider completion, or production configuration. Other matrix rows run
the same source authorization and Guzzle transport regression checks in CI.
