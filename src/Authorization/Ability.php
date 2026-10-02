<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

/**
 * The operations that are authorized separately.
 *
 * A suggestion never authorizes a merge: reviewing and dismissing a pair is a
 * different ability from merging it.
 */
enum Ability: string
{
    case Review = 'review';
    case Dismiss = 'dismiss';
    case Scan = 'scan';
    case Merge = 'merge';
    case ViewAudit = 'view-audit';
}
