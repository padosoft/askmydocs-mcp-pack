<?php

// Minimal MCP stdio server: answers the first request line with an empty
// success result carrying the same id, then exits.
$line = fgets(STDIN);
$request = is_string($line) ? json_decode($line, true) : null;
fwrite(STDOUT, json_encode([
    'jsonrpc' => '2.0',
    'id' => is_array($request) ? ($request['id'] ?? null) : null,
    'result' => ['ok' => true],
])."\n");
