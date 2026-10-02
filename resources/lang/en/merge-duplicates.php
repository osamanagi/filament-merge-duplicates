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
        'audit_reference' => 'Audit reference',
        'view_audit' => 'View audit entry',
        'back_to_review' => 'Back to review',
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate review page
    |--------------------------------------------------------------------------
    |
    | The page header reuses the banner wording for its states, so only the list
    | itself and its staleness labels live here.
    |
    */

    'review' => [
        'title' => 'Duplicate review',
        'heading' => ':label duplicates',
        'groups_total' => ':count possible group|:count possible groups',
        'groups_heading' => 'Suggested groups',
        'records' => ':count record|:count records',
        'stale_badge' => 'Changed since scan',
        'not_reviewable_badge' => 'Cannot be merged now',
        'member_missing' => 'Missing',
        'member_retired' => 'Merged away',
        'member_changed' => 'No longer matches',
        'showing_of' => 'Showing :shown of :total records',
        'pagination' => 'Duplicate group pages',
        'previous' => 'Previous',
        'next' => 'Next',
        'page_of' => 'Page :page of :last',
        'empty_body' => 'The last scan completed and found no group you can review.',
        'never_scanned_body' => 'This resource has not been scanned yet. Run a scan to look for possible duplicates.',
        'scan_already_running' => 'A scan is already running for this scope.',
        'compare' => 'Review two records',
    ],

    /*
    |--------------------------------------------------------------------------
    | Merge preview
    |--------------------------------------------------------------------------
    |
    | The comparison and confirmation surface. Values shown in the grid come
    | from the records and are escaped by the view.
    |
    */

    'merge' => [
        'title' => 'Compare and merge',
        'heading' => 'Merge :label records',
        'survivor_reason' => 'Recommended: :reason',
        'survivor_heading' => 'Record to keep',
        'survivor_hint' => 'The other record will be retired and its children moved to the record you keep.',
        'keep' => 'Keep :title',
        'survivor_value' => 'Current value on :title',
        'source_value' => 'Value on :title',
        'fields_heading' => 'Field comparison',
        'choice_required' => 'Choose a value',
        'proposed_from_source' => 'Will take the other record\'s value',
        'choose_for' => 'Choose a value for :label',
        'keep_survivor_value' => 'Keep the kept record\'s value',
        'take_source_value' => 'Take the other record\'s value',
        'relations_heading' => 'Relationship impact',
        'blockers_heading' => 'This pair cannot be merged',
        'retirement_warning' => ':title will be soft-deleted and marked merged. Restoring it later is not an unmerge.',
        'confirm' => 'Confirm merge',
        'not_duplicates' => 'Not duplicates',
        'choices_required' => 'Choose a value for every differing field before confirming.',
        'stale' => 'These records changed after the preview. Review the updated proposal before confirming.',
        'failed' => 'The merge was not completed (reason code: :code). Nothing was changed.',
        'succeeded' => 'Merged into :title.',
        'configuration_title' => 'Merging is not enabled for this definition',
        'configuration_description' => 'This definition is configured for detection only. No records can be merged.',
        'succeeded_title' => 'Merge completed',
        'succeeded_body' => 'The records were merged into :title.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Merge audit
    |--------------------------------------------------------------------------
    */

    'audit' => [
        'title' => 'Merge history',
        'heading' => 'Merge history entry',
        'operation_label' => 'Operation',
        'unreadable_title' => 'This entry cannot be read',
        'unreadable_description' => 'The application key that can read this history has changed. The merge itself is unaffected.',
        'empty' => 'This entry holds no readable values.',
    ],

];
