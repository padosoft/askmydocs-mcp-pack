<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * Convenience builders for the message array shape consumed by
 * {@see HostChatTurn::$messages}.
 *
 * The orchestrator stays array-native (so hosts that already track
 * messages as plain arrays do not pay a translation tax), but these
 * builders keep call-sites readable when the orchestrator constructs
 * tool-call follow-ups internally.
 */
final class HostMessage
{
    /** @return array{role:string,content:string} */
    public static function system(string $content): array
    {
        return ['role' => 'system', 'content' => $content];
    }

    /** @return array{role:string,content:string} */
    public static function user(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    /**
     * @param  array<int,array{id:string,type:string,function:array{name:string,arguments:string}}>  $toolCalls
     * @return array{role:string,content:string,tool_calls:array<int,mixed>}
     */
    public static function assistantWithToolCalls(string $content, array $toolCalls): array
    {
        return [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];
    }

    /** @return array{role:string,tool_call_id:string,name:string,content:string} */
    public static function tool(string $toolCallId, string $toolName, string $content): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'name' => $toolName,
            'content' => $content,
        ];
    }
}
