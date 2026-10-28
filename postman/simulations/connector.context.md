# Connector transport simulation

2026-10-28: Postman CLI 1.80.0 served the local Hub contract on 18130.

Run the command in README.md from the repository root and set
MCP_HUB_SIM_URL=http://127.0.0.1:18130/hub/mcp before composer test.
The full standalone suite passed: 18 tests and 60 assertions.

The Hub member received the PHP transport's real POST /mcp, validated the dummy
bearer credential, origin and JSON-RPC metadata, and returned a matching response ID.
The only modeled operation is tools/list; unmodeled paths return 501.

This proves the PHP client against this local stand-in. It does not prove a live
Hub, a provider completion, production configuration, or other Laravel majors.
