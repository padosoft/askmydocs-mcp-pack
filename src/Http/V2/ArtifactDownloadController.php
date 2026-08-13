<?php

namespace Padosoft\AskMyDocsMcpPack\Http\V2;

use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Models\McpArtifact;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ArtifactDownloadController
{
    public function __construct(private ArtifactManagerContract $artifacts) {}

    public function __invoke(Request $request, string $artifact): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $model = McpArtifact::query()->whereKey($artifact)->where('expires_at', '>', now())->firstOrFail();
        $bytes = $this->artifacts->contents($model);
        $name = str_replace(['"', "\r", "\n"], '', (string) $model->name);

        return new StreamedResponse(static fn () => print ($bytes), 200, [
            'Content-Type' => (string) $model->mime_type,
            'Content-Length' => (string) $model->size_bytes,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
