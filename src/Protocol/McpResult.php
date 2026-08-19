<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

use Padosoft\AskMyDocsMcpPack\Models\McpArtifact;
use Padosoft\AskMyDocsMcpPack\Models\McpTask;

final class McpResult
{
    /** @var list<array<string,mixed>> */
    private array $content = [];

    private mixed $structuredContent = null;

    private ResultType $resultType = ResultType::Complete;

    private bool $isError = false;

    /** @var array<string,mixed> */
    private array $extra = [];

    public static function make(): self
    {
        return new self;
    }

    public static function text(string $text): self
    {
        return (new self)->addText($text);
    }

    /** @param array<string,mixed>|list<mixed> $value */
    public static function structured(array $value): self
    {
        return (new self)->withStructuredContent($value);
    }

    public static function recoverableError(string $message, mixed $details = null): self
    {
        $result = (new self)->addText($message);
        $result->isError = true;
        if ($details !== null) {
            $result->extra['errorDetails'] = $details;
        }

        return $result;
    }

    public function addText(string $text): self
    {
        $this->content[] = ['type' => 'text', 'text' => $text];

        return $this;
    }

    public function addImage(string $data, string $mimeType): self
    {
        $this->content[] = ['type' => 'image', 'data' => $data, 'mimeType' => $mimeType];

        return $this;
    }

    public function addAudio(string $data, string $mimeType): self
    {
        $this->content[] = ['type' => 'audio', 'data' => $data, 'mimeType' => $mimeType];

        return $this;
    }

    /** @param array<string,mixed> $resource */
    public function embeddedResource(array $resource): self
    {
        $this->content[] = ['type' => 'resource', 'resource' => $resource];

        return $this;
    }

    public function resourceLink(string $uri, string $name, ?string $mimeType = null, ?int $size = null): self
    {
        $link = ['type' => 'resource_link', 'uri' => $uri, 'name' => $name];
        if ($mimeType !== null) {
            $link['mimeType'] = $mimeType;
        }
        if ($size !== null) {
            $link['size'] = $size;
        }
        $this->content[] = $link;

        return $this;
    }

    public function artifact(McpArtifact $artifact, ?string $contents = null, ?int $embedBelowBytes = null): self
    {
        $embedBelowBytes ??= (int) config('mcp-pack.artifacts.embed_below_bytes', 65_536);
        $uri = 'artifact://'.$artifact->getKey();
        if ($contents !== null && strlen($contents) < $embedBelowBytes) {
            $resource = ['uri' => $uri, 'mimeType' => $artifact->mime_type, 'name' => $artifact->name];
            if (str_starts_with((string) $artifact->mime_type, 'text/') || $artifact->mime_type === 'application/json') {
                $resource['text'] = $contents;
            } else {
                $resource['blob'] = base64_encode($contents);
            }
            $this->embeddedResource($resource);
        } else {
            $this->resourceLink($uri, (string) $artifact->name, $artifact->mime_type, (int) $artifact->size_bytes);
        }
        $this->extra['artifactIds'][] = (string) $artifact->getKey();

        return $this;
    }

    /** @param array<string,mixed> $value */
    public function withStructuredContent(array $value): self
    {
        $this->structuredContent = $value;
        if ($this->content === [] && (bool) config('mcp-pack.v2.structured_text_fallback', true)) {
            $this->addText(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return $this;
    }

    /** @param list<array<string,mixed>> $requests */
    public function inputRequired(array $requests, string $requestState): self
    {
        $this->resultType = ResultType::InputRequired;
        $this->extra['requests'] = $requests;
        $this->extra['requestState'] = $requestState;

        return $this;
    }

    public function task(McpTask $task): self
    {
        $this->resultType = ResultType::Task;
        $this->extra = array_merge($this->extra, $task->toProtocolArray());

        return $this;
    }

    /** @param array<string,mixed> $serverInfo */
    public function toArray(array $serverInfo = []): array
    {
        $result = array_merge($this->extra, [
            'resultType' => $this->resultType->value,
            'serverInfo' => $serverInfo,
        ]);
        if ($this->content !== []) {
            $result['content'] = $this->content;
        }
        if ($this->structuredContent !== null) {
            $result['structuredContent'] = $this->structuredContent;
        }
        if ($this->isError) {
            $result['isError'] = true;
        }

        return $result;
    }

    /** @param array<string,mixed> $serverInfo */
    public static function normalise(mixed $value, array $serverInfo = []): array
    {
        if ($value instanceof self) {
            return $value->toArray($serverInfo);
        }
        if (is_string($value)) {
            return self::text($value)->toArray($serverInfo);
        }
        if (is_array($value)) {
            if (isset($value['resultType'])) {
                $value['serverInfo'] ??= $serverInfo;

                return $value;
            }

            return self::structured($value)->toArray($serverInfo);
        }

        return self::text((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->toArray($serverInfo);
    }
}
