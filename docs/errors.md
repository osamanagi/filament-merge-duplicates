# Errors and failure codes

Every failure leaves one sanitized code behind. Raw SQL, bindings, matched values,
record contents and secrets are never persisted to a scan row, shown in a page or
put in a log line. The code is the only thing that travels.

Package exceptions extend `Nagi\FilamentMergeDuplicates\Exceptions\MergeDuplicatesException`
and expose `errorCode()`.

## Codes

| Code | Exception | Typically means | What to do |
| --- | --- | --- | --- |
| `invalid_configuration` | `InvalidConfiguration` | A definition cannot be used as configured: a missing model, an unregistered ID, a forbidden field, a rule or field that names a column the model does not have, an unmergeable model. | Read the `ConfigurationReport` from `DefinitionValidator::validate()`; the report names the offending field. Merging stays disabled until every blocker is gone. Detection keeps working. |
| `forbidden_operation` | `ForbiddenOperation` | The acting user may not do this: review, dismiss, scan, merge or read the audit trail. | Fix the definition's `authorizer()`, or the actor's policies. The UI answers 403. |
| `missing_context` | `MissingContext` | No trusted actor, panel or tenant could be resolved, so nothing ran. | The banner treats it as "no banner" rather than an error, so a guest never breaks a panel. Anywhere else it fails closed: give the surface a resolver that can answer, or authenticate the user. The UI answers 403. |
| `stale_preview` | `StalePreview` | The preview expired (`preview.ttl_minutes`), or a record changed after the preview was built, or the plan no longer matches the pair. | Review again: the merge page rebuilds the plan automatically. Nothing was written. |
| `record_unavailable` | `RecordUnavailable` | A record is gone, soft-deleted, retired by an earlier merge, invisible to the acting user, or is being merged into itself. | Re-run the scan; the retired and invisible records are excluded from then on. The UI answers 404. |
| `domain_conflict` | `DomainConflict` | The operation conflicts with current state: a scan is already queued or running for the scope, a model event cancelled a write, the encrypted audit payload cannot be read, or the operation was already committed with different choices. | Wait for the running scan, or fix the event listener that cancelled the write. The review page reports "a scan is already running for this scope" instead of a permission error, because the refusal comes from the data scope, not from the actor. |
| `merge_too_large` | `MergeTooLarge` | A declared relation holds more children than `relations.max_children_per_merge`. | Raise the cap deliberately, or split the work. The merge is blocked rather than truncated, so children are never silently left behind. |
| `unsupported_relation` | `UnsupportedRelation` | A declared relation type cannot be transferred in v1 (anything other than an ordinary `HasMany`), or a relation does not cover the whole foreign key. | Replace it with a supported declaration, or handle the reference outside the package. Blocked instead of guessed. |
| `retry_exhausted` | `RetryExhausted` | A write was retried past the policy because of sustained contention. | Retry the merge. Nothing was committed. |
| `scan_failed` | — | Fallback for a scan failure that was not a package exception. | Inspect the application log: the row stores only this code, by design. |

## Where a code shows up

| Surface | Behaviour |
| --- | --- |
| Review page | A failed scan keeps the previously published results on screen and shows the code as the reason, with a retry action. An empty completed scan is a different state from "never scanned". |
| Review banner | Carries the same failure code, so a host can surface it above a table without a second query. |
| Merge page | A `StalePreview` rebuilds the plan in place. Any other package exception becomes a danger notification carrying the code; an invalid configuration is shown on the page and disables confirmation. |
| Audit page | An unknown operation, a record the actor may not see, or an entry that vanished answers 404; a denied ability answers 403. |
| Scan row | `failure_code` holds the code; `counters` hold what was processed before it failed. |
| Events | `ScanFailed` and `MergeFailed` carry the code to listeners. |
| CLI | Prints the exception message and returns a non-zero exit code. |

The `code` is also passed to the translated notification strings, so a host can
override the copy for its own locale without changing the code.

## Known debt

- `domain_conflict` covers four different situations (active scan, cancelled
  write, unreadable audit payload, already-committed-with-different-choices).
  Separate codes would let a host react differently; recorded as debt in
  [docs/m5-handover.md](docs/m5-handover.md) rather than changed after the merge.
- A scan row keeps only the code for an unexpected failure. The exception itself
  is logged by the worker, so an operator needs log access to diagnose
  `scan_failed`.
