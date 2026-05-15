<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/**
 * Thrown when the underlying transport (stdio process, HTTP gateway)
 * fails to deliver a JSON-RPC message — connection refused, broken
 * pipe, non-2xx HTTP response, timeout.
 */
class McpTransportException extends McpException {}
