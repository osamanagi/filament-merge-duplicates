<?php

// translations for Nagi/FilamentMergeDuplicates
return [

    /*
    |--------------------------------------------------------------------------
    | Duplicate banner
    |--------------------------------------------------------------------------
    |
    | Shown above a resource table. Counts are groups the acting user may see,
    | never a count of records or of unique people.
    |
    */

    'banner' => [
        'never_scanned_title' => 'Not scanned for duplicates yet',
        'never_scanned_description' => 'Run a scan to look for possible duplicate records in this resource.',

        'scanning_title' => 'Scanning for duplicates…',
        'scanning_description' => 'Results below are from the last completed scan and will be replaced when this scan finishes.',

        'failed_title' => 'The last duplicate scan failed',
        'failed_description' => 'Results from an earlier scan are still shown. Retry when you are ready.',

        'empty_title' => 'No possible duplicates found',
        'empty_description' => 'The last scan completed and found no group you can review.',

        'results_title' => ':count possible duplicate group|:count possible duplicate groups',
        'results_description' => 'Groups are suggestions, not verdicts: two records can appear in more than one group.',

        'counts_visible_only' => 'Only records you may view are counted.',
    ],

    'labels' => [
        'last_scan' => 'Last completed scan: :time',
        'never_completed' => 'No scan has completed for this scope',
        'reason_code' => 'Reason code: :code',
        'results_may_have_changed' => 'Results may have changed since this scan.',
    ],

    'actions' => [
        'review' => 'Review duplicates',
        'scan' => 'Scan for duplicates',
        'retry_scan' => 'Retry scan',
    ],

];
