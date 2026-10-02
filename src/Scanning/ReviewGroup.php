<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

/**
 * One suggested group of duplicates, ready for a list row.
 *
 * A group is a suggestion, never a verdict: the same record can legitimately
 * appear in several groups because several rules can match it, which is why the
 * rule that produced the group travels with it.
 */
final class ReviewGroup
{
    /**
     * @param  list<ReviewMember>  $members  the preview page of members, not necessarily all of them
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $ruleLabel,
        public readonly string $digest,
        public readonly int $memberCount,
        public readonly array $members = [],
    ) {}

    /**
     * Whether the group still has two records a reviewer could merge.
     */
    public function isReviewable(): bool
    {
        $usable = 0;

        foreach ($this->members as $member) {
            if (! $member->missing && ! $member->retired) {
                $usable++;
            }
        }

        return $usable >= 2;
    }

    public function hasStaleMember(): bool
    {
        foreach ($this->members as $member) {
            if ($member->isStale()) {
                return true;
            }
        }

        return false;
    }

    public function showsAllMembers(): bool
    {
        return count($this->members) >= $this->memberCount;
    }

    public function hiddenMemberCount(): int
    {
        return max(0, $this->memberCount - count($this->members));
    }
}
