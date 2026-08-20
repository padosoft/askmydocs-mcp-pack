<?php

namespace Padosoft\AskMyDocsMcpPack\Http\V2;

use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Artifacts\ArtifactDownloadScope;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ArtifactDownloadController
{
    public function __construct(private ArtifactManagerContract $artifacts, private ArtifactDownloadScope $scope) {}

    public function __invoke(Request $request, string $artifact): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        // The signed capability carries the tenant/actor pair it was minted for;
        // resolve through the scoped read path rather than by UUID alone.
        $scope = $this->scope->decode($request->query('scope'));
        abort_if($scope === null, 403);
        $model = $this->artifacts->read($artifact, $scope[0], $scope[1]);
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
