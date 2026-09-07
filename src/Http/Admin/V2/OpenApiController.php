<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\V2;

use Symfony\Component\HttpFoundation\Response;

final class OpenApiController
{
    public function __invoke(): Response
    {
        $path = __DIR__.'/../../../../resources/openapi/v2.json';
        $contents = is_readable($path) ? file_get_contents($path) : false;
        abort_if($contents === false, 500, 'MCP Pack v2 OpenAPI specification is unavailable.');

        return new Response($contents, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=3600']);
    }
}
