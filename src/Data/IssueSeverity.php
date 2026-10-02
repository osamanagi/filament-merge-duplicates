<?php

namespace Nagi\FilamentMergeDuplicates\Data;

/**
 * A blocker prevents an operation outright. A warning is reported to developers
 * but does not stop detection.
 */
enum IssueSeverity: string
{
    case Blocker = 'blocker';
    case Warning = 'warning';
}
