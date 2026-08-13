<?php

namespace Padosoft\AskMyDocsMcpPack\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_id
 * @property string $name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property array<string,mixed>|null $annotations
 * @property array<string,mixed>|null $metadata
 * @property CarbonImmutable|null $expires_at
 * @property Carbon|null $created_at
 */
final class McpArtifact extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'mcp_artifacts';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'size_bytes' => 'integer',
        'annotations' => 'array',
        'metadata' => 'encrypted:array',
        'expires_at' => 'immutable_datetime',
    ];
}
