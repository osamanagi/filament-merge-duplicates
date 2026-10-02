<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Data\RecordId;

/**
 * Recommends which record should survive.
 *
 * The older record wins when both records carry a comparable `created_at`,
 * because it is usually the one the business already references. Otherwise the
 * stable typed-ID ordering decides, so the recommendation is deterministic and
 * independent of retrieval order. The recommendation is never applied without
 * confirmation, and never used as an authorization.
 */
final class SurvivorRecommender
{
    public function recommend(Model $first, Model $second): SurvivorRecommendation
    {
        $firstId = RecordId::fromModel($first);
        $secondId = RecordId::fromModel($second);

        $firstCreated = $first->getAttribute('created_at');
        $secondCreated = $second->getAttribute('created_at');

        if (
            $firstCreated instanceof DateTimeInterface
            && $secondCreated instanceof DateTimeInterface
            && $firstCreated->getTimestamp() !== $secondCreated->getTimestamp()
        ) {
            $olderIsFirst = $firstCreated->getTimestamp() < $secondCreated->getTimestamp();

            return new SurvivorRecommendation(
                survivorId: $olderIsFirst ? $firstId : $secondId,
                sourceId: $olderIsFirst ? $secondId : $firstId,
                reason: 'The older record is recommended as the survivor.',
            );
        }

        $firstIsLower = $firstId->compareTo($secondId) <= 0;

        return new SurvivorRecommendation(
            survivorId: $firstIsLower ? $firstId : $secondId,
            sourceId: $firstIsLower ? $secondId : $firstId,
            reason: 'No comparable creation date, so the stable record ID order is used.',
        );
    }
}
