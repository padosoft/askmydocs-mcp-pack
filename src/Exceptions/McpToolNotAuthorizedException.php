<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/**
 * Thrown when {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract}
 * denies a tool invocation. Surfaced to the model so it can recover
 * gracefully (apologise, try a different tool, hand off to a human).
 */
class McpToolNotAuthorizedException extends McpException {}
