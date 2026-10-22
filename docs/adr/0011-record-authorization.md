# ADR 0011 — Authorize actual merge records

Status: accepted — 23 October 2026.

## Context

An operation grant cannot express mixed permissions within one tenant. Eloquent
saves do not invoke model policies automatically. A visible source might be
undeletable, or one child might not be assignable to the selected survivor.
The original contract accepted no models and therefore could not enforce this.

## Decision

Add `RecordMergeAuthorizer` extending the operation contract, with parent/child
view, directional pair, and exact child transfer checks. Detection-only hosts
can retain `MergeAuthorizer`; merging fails closed without the extension.
Provide `GateMergeAuthorizer` using Laravel's Gate with an explicit actor and
host operation mappings. Require conventional view/update/delete policies plus
an explicit child transfer ability; allow an optional pair ability.

Share orchestration in `MergeAuthorization`. Verify preview permissions and
repeat checks on locked execution rows before writes. Check each actual transfer
and the parents again before retirement. Denials roll back the whole merge.
Read-only idempotent replay requires current parent visibility/view permission,
without requiring mutation rights on an already retired source.

The fixed ability-map implementation defaults to denying records; a named
`allowAllRecords` opt-in supports deliberately trusted actors and fixtures.
Do not infer mutation authority from scope visibility or an operation grant.

## Consequences

Existing merge integrations must implement the new contract or configure the
Gate adapter. No database migration is needed. Reads remain bounded by the
existing child cap; execution locks children in primary-key order. Model saves
retain casts and observers. Application policies and complete relation inventories
remain host responsibilities. This is a focused correctness change ahead of
scan housekeeping and the eventual larger refactor.

See [integration and upgrade examples](../record-authorization.md).
