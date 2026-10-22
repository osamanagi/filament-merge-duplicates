# ADR 0010 — Stable retirement domains and legacy conversion

Status: accepted for implementation, 22 October 2026. Corrects the original
implementation to match ADR 0003's permanent-identity requirement.

## Problem

The original code used the matching HMAC for `retirement_domain`. Changing the
matching secret, its version, or the fallback application key therefore hid
existing terminal mappings. A restored source could be reused. Rebuilding scan
generations did not repair the ledger.

## Decision

Version 2 retirement domains are SHA-256 of the length-delimited tuple
`('retirement-domain:v2', connection, model alias, ownership domain)`. Record ID
type and record ID remain separate columns in the existing unique constraint.
These inputs are stable identifiers, not matched field values or tenant values;
they need collision-resistant encoding, not a rotatable secret. Definition and
panel IDs remain excluded so independent definitions share terminal retirement.

Matching keys, fingerprints, and scope hashes keep their versioned HMACs.
Rotation still invalidates matching-derived generations, dismissals and previews.

An additive migration introduces an indexed `retirement_domain_version` column.
Existing rows start at version 1. Registered definitions and the original matching
configuration reconstruct each old domain. Conversion changes only the digest
and its version, in one data transaction. Source/survivor IDs, operation IDs,
timestamps, scope references, and encrypted audit bytes remain untouched. New
entries use version 2.

Before changing anything, the conversion validates that every legacy domain is
recognized. Unknown domains or uniqueness conflicts stop the migration without
partial conversion. The host must drain old workers and writers first. MySQL can
retain the added column after a failed conversion because its DDL is not
transactional; version-1 rows then make retirement-dependent operations fail
closed. Rerunning the migration resumes safely once configuration is corrected.
PostgreSQL also retains its transactional migration guarantees.

Downgrading to the HMAC implementation is unsafe once permanent mappings exist.
The migration's `down()` refuses a nonempty ledger, rather than silently making
sources reusable. Empty fresh installations can still roll back and reinstall.

After rotation, old scope hashes cannot be reproduced using the new matching
configuration. A shared ledger-scope guard allows history/replay only when the
scope's definition, connection and model agree, the stable retirement domain
agrees, and authenticated audit metadata identifies the same definition,
connection and tenant. Operation abilities and replay actor/choice checks remain
mandatory. Missing metadata is refused. Historical ciphertext is not rewritten;
application-key rotation still requires Laravel's previous encryption keys.

## Limits

Changing connection names, model aliases or ownership domains still needs an
explicit identity migration. This change does not infer renamed identifiers,
recover lost secrets, or merge conflicting ledger rows. Deployments that already
rotated keys before converting legacy rows need their original key settings and
definitions restored for conversion; mixed historical key configurations require
explicit data recovery rather than guessing.

See [the upgrade procedure](../retirement-identity-upgrade.md).
