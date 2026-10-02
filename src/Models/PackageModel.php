<?php

namespace Nagi\FilamentMergeDuplicates\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for the package tables.
 *
 * The package tables must live on the same connection as the target model, so
 * the connection is resolved from configuration at query time rather than being
 * baked into a model constant. Cross-connection merges are unsupported and are
 * rejected during configuration validation.
 */
abstract class PackageModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('merge-duplicates.connection') ?: parent::getConnectionName();
    }
}
