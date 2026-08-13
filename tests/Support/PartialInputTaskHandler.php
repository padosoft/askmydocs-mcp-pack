<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;
use Padosoft\AskMyDocsMcpPack\Protocol\RequestStateCipher;

final readonly class PartialInputTaskHandler
{
    public function __construct(private RequestStateCipher $states) {}

    public function handle(McpRequest $request): McpResult
    {
        if (count($request->inputResponses) < 2) {
            return McpResult::make()->inputRequired([
                ['id' => 'approval', 'type' => 'boolean', 'prompt' => 'Approve?'],
                ['id' => 'reason', 'type' => 'string', 'prompt' => 'Why?'],
            ], $this->states->issue($request, $request->arguments));
        }

        return McpResult::structured([
            'approved' => (bool) data_get($request->inputResponses, 'approval.value'),
            'reason' => (string) data_get($request->inputResponses, 'reason.value'),
        ]);
    }
}
