<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

/**
 * What the planner proposes for one field.
 *
 * Only ChoiceRequired blocks confirmation. TakeSource is a proposal that is
 * still shown to the operator, never applied silently.
 */
enum FieldResolution: string
{
    /** Equal, or the survivor has a value and the source does not. */
    case RetainSurvivor = 'retain_survivor';

    /** The survivor has no value and the source does; the source value is proposed. */
    case TakeSource = 'take_source';

    /** Neither record has a value; the survivor's representation is kept and required rules still apply. */
    case BothMissing = 'both_missing';

    /** Both have different values, so the operator must choose explicitly. */
    case ChoiceRequired = 'choice_required';

    public function requiresChoice(): bool
    {
        return $this === self::ChoiceRequired;
    }
}
