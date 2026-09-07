<?php

// Simulates an MCP stdio server that crashes mid-line: it emits the beginning
// of a JSON-RPC response without the trailing newline and exits.
fwrite(STDOUT, '{"jsonrpc":"2.0","id":');
exit(0);
