<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent;

use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\AppDefinition;

final class App
{
    private ?string $resourceUri = null;

    private mixed $source = null;

    private string $sourceType = 'html';

    private string $visibility = 'model';

    /** @var array<string,list<string>> */
    private array $csp = ['connectDomains' => [], 'resourceDomains' => [], 'frameDomains' => [], 'baseUriDomains' => []];

    /** @var array<string,\stdClass> */
    private array $permissions = [];

    /** @var array<string,mixed> */
    private array $meta = [];

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function resource(string $uri): self
    {
        if (! str_starts_with($uri, 'ui://')) {
            throw new \InvalidArgumentException('MCP Apps require a ui:// resource URI.');
        }
        $this->resourceUri = $uri;

        return $this;
    }

    public function html(string $html): self
    {
        $this->source = $html;
        $this->sourceType = 'html';

        return $this;
    }

    public function file(string $path): self
    {
        $this->source = $path;
        $this->sourceType = 'file';

        return $this;
    }

    /** @param array<string,mixed> $data */
    public function view(string $view, array $data = []): self
    {
        $this->source = [$view, $data];
        $this->sourceType = 'view';

        return $this;
    }

    public function visibility(string $value): self
    {
        if (! in_array($value, ['model', 'app'], true)) {
            throw new \InvalidArgumentException('App visibility must be model or app.');
        }
        $this->visibility = $value;

        return $this;
    }

    /** @param array<string,list<string>> $value */
    public function csp(array $value): self
    {
        $directives = array_fill_keys(array_keys($this->csp), []);
        foreach ($value as $directive => $domains) {
            if (! array_key_exists($directive, $directives) || ! is_array($domains) || ! array_is_list($domains)) {
                throw new \InvalidArgumentException("Unsupported or invalid MCP App CSP directive [{$directive}].");
            }
            foreach ($domains as $domain) {
                $probe = is_string($domain) ? str_replace('://*.', '://wildcard.', $domain) : '';
                $parts = filter_var($probe, FILTER_VALIDATE_URL) !== false ? parse_url($probe) : false;
                if (! is_string($domain) || ! is_array($parts)
                    || ! in_array($parts['scheme'] ?? null, ['http', 'https', 'ws', 'wss'], true)
                    || ! is_string($parts['host'] ?? null)
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                    || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
                    throw new \InvalidArgumentException("MCP App CSP directive [{$directive}] contains an invalid origin.");
                }
            }
            $directives[$directive] = array_values(array_unique($domains));
        }
        $this->csp = $directives;

        return $this;
    }

    public function permissions(string ...$value): self
    {
        $mapping = ['camera' => 'camera', 'microphone' => 'microphone', 'geolocation' => 'geolocation', 'clipboard-write' => 'clipboardWrite'];
        $permissions = [];
        foreach ($value as $permission) {
            if (! isset($mapping[$permission])) {
                throw new \InvalidArgumentException("Invalid MCP App permission [{$permission}].");
            }
            $permissions[$mapping[$permission]] = new \stdClass;
        }
        $this->permissions = $permissions;

        return $this;
    }

    public function downloadFile(bool $enabled = true): self
    {
        if ($enabled && ! (bool) config('mcp-pack.apps.experimental_download_file', false)) {
            throw new \LogicException('ui/download-file is experimental and disabled. Enable mcp-pack.apps.experimental_download_file explicitly.');
        }
        if ($enabled) {
            $this->meta['ui']['downloadFile'] = new \stdClass;
        } else {
            unset($this->meta['ui']['downloadFile']);
        }

        return $this;
    }

    /** @param array<string,mixed> $value */
    public function meta(array $value): self
    {
        $this->meta = array_replace_recursive($this->meta, $value);

        return $this;
    }

    public function compile(): AppDefinition
    {
        if ($this->resourceUri === null || $this->source === null) {
            throw new \LogicException("App [{$this->name}] requires both resource() and html(), file() or view().");
        }

        return new AppDefinition($this->name, $this->resourceUri, $this->source, $this->sourceType, $this->visibility, $this->csp, $this->permissions, $this->meta);
    }
}
