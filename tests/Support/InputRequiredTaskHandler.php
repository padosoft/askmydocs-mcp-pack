<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;
use Padosoft\AskMyDocsMcpPack\Protocol\RequestStateCipher;

final readonly class InputRequiredTaskHandler
{
    public function __construct(private RequestStateCipher $states) {}

    public function handle(McpRequest $request): McpResult
    {
        if ($request->inputResponses === []) {
            return McpResult::make()->inputRequired(
                [['id' => 'approval', 'type' => 'boolean', 'prompt' => 'Approve?']],
                $this->states->issue($request, $request->arguments),
            );
        }

        return McpResult::structured(['approved' => (bool) data_get($request->inputResponses, 'approval.value', $request->inputResponses['approval'] ?? false)]);
    }
}
