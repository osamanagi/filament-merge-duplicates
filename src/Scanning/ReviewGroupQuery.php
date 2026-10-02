<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdCodec;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;

/**
 * The review list: groups for the acting user, with the members re-read live.
 *
 * Two properties matter here and are enforced rather than assumed.
 *
 * First, this is a service, not a page. Authorization, scope and visibility are
 * applied here so a UI cannot forget them, and so calling the same code from a
 * command cannot accidentally show more than the panel would.
 *
 * Second, the list never presents the scan as current truth. Members are
 * re-fetched through the definition's scope and visibility rules, and a member
 * that is gone, merged away or no longer matching the rule is flagged instead of
 * being printed as if the scan were still valid.
 */
final class ReviewGroupQuery
{
    public function __construct(
        private readonly SuggestionQuery $suggestions,
        private readonly KeyBuilder $keys,
        private readonly RetirementResolver $retirement,
    ) {}

    public function canReview(DuplicateDefinition $definition, DuplicateContext $context): bool
    {
        return $definition->authorizer()->allows($context, Ability::Review);
    }

    /**
     * One page of groups.
     *
     * @param  int  $membersPerGroup  the preview page of members inside each group
     * @return array{groups: list<ReviewGroup>, total: int, page: int, perPage: int, lastPage: int}
     *
     * @throws ForbiddenOperation when the acting user may not review these duplicates
     */
    public function page(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        int $page = 1,
        int $perPage = 10,
        int $membersPerGroup = 5,
    ): array {
        if (! $this->canReview($definition, $context)) {
            throw new ForbiddenOperation('The acting user may not review duplicates for this definition.');
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $membersPerGroup = max(1, $membersPerGroup);

        $total = $this->suggestions->count($definition, $context);
        $buckets = $this->suggestions->buckets(
            $definition,
            $context,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
        );

        $groups = [];

        foreach ($buckets as $bucket) {
            $groups[] = $this->group($definition, $context, $bucket, $membersPerGroup);
        }

        return [
            'groups' => $groups,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Re-reads the members of one group. Missing, retired and no-longer-matching
     * members are kept in the list, because a group that silently shrank would
     * look like a smaller group rather than a changed one.
     */
    private function group(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        CandidateBucket $bucket,
        int $membersPerGroup,
    ): ReviewGroup {
        $model = $definition->model();

        /** @var Model $instance */
        $instance = new $model;
        $idType = RecordIdCodec::detectType($instance);

        $memberIds = $this->suggestions->memberIds($definition, $context, $bucket, $membersPerGroup);
        $records = $this->records($definition, $context, $memberIds);

        $members = [];

        foreach ($memberIds as $memberId) {
            $recordId = RecordId::fromStored($idType, $memberId);
            $record = $records[$memberId] ?? null;

            if ($record === null) {
                $members[] = ReviewMember::missing($recordId);

                continue;
            }

            $retired = $this->retirement->isRetired(
                $definition->connection(),
                $model,
                $definition->ownershipDomain(),
                $recordId,
            );

            $members[] = new ReviewMember(
                recordId: $recordId,
                title: $this->titleOf($definition, $record),
                retired: $retired,
                changed: ! $this->stillMatches($definition, $record, $bucket),
            );
        }

        return new ReviewGroup(
            ruleId: $bucket->ruleId,
            ruleLabel: $bucket->ruleLabel,
            digest: $bucket->digest,
            memberCount: $bucket->visibleCount,
            members: $members,
        );
    }

    /**
     * Re-reads members through the definition's constraints and visibility, so a
     * record the actor may no longer see is reported as missing rather than shown.
     *
     * @param  list<string>  $memberIds
     * @return array<string, Model>
     */
    private function records(DuplicateDefinition $definition, DuplicateContext $context, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $model = $definition->model();

        /** @var Model $instance */
        $instance = new $model;

        $query = $instance->newQuery();
        $scoped = $definition->scopedRecordQuery();
        $query = $scoped->constrain($query, $context);
        $query = $scoped->visibleTo($query, $context);

        $found = [];

        foreach ($query->whereIn($instance->getKeyName(), $memberIds)->get() as $record) {
            $found[(string) $record->getKey()] = $record;
        }

        return $found;
    }

    /**
     * Whether the record still produces this group's rule key. A record whose
     * matching value changed, or became blank, no longer belongs to the group the
     * scan recorded it in.
     */
    private function stillMatches(DuplicateDefinition $definition, Model $record, CandidateBucket $bucket): bool
    {
        $keys = $this->keys->keysFor($definition, $record);
        $digest = $keys[$bucket->ruleId] ?? null;

        if (! is_string($digest)) {
            return false;
        }

        return hash_equals($bucket->digest, $digest);
    }

    /**
     * The record's configured title, falling back to its key. Values are returned
     * raw and escaped by whatever renders them; the service never renders.
     */
    private function titleOf(DuplicateDefinition $definition, Model $record): string
    {
        $attribute = $definition->recordTitleAttribute();

        if ($attribute !== null) {
            $title = $record->getAttribute($attribute);

            if (is_string($title) && $title !== '') {
                return $title;
            }

            if (is_numeric($title)) {
                return (string) $title;
            }
        }

        return '#' . (string) $record->getKey();
    }
}
