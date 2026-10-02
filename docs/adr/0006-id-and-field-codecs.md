# ADR 0006 — Record ID and field value codecs

Status: accepted (M0)

## Context

Matching keys, previews and fingerprints must be deterministic, lossless and
collision resistant. The specification forbids delimiter concatenation, forbids
casting record IDs to integers, and requires that `0`, `'0'`, `false`, `null`
and `''` stay distinguishable unless a field-specific normalizer equates them.

## Decision

### Record IDs

1. Record IDs are handled as **typed strings** everywhere: `(string) $key`,
   never `(int) $key`. Precision is therefore preserved for bigint-as-string,
   UUID and ULID keys.
2. Every persisted ID carries a `record_id_type` in `int | string | uuid | ulid`,
   detected from the model's key type and cast, not from the value's shape.
3. Ordering is defined per type and used for deterministic lock ordering and for
   the "stable ID ordering" tiebreak when `created_at` is absent: numeric for
   `int`, bytewise for `string`, `uuid` and `ulid`.
4. Persisted ID columns are `VARCHAR(191)` with a type column alongside. Index
   size limits are respected by indexing digests where a long value would
   otherwise overflow.

### Field values for matching

5. Matching tuples are encoded as a **JSON array of tagged scalars** in a fixed
   field order, for example `[["str","a,b"],["str","c"]]`. Because the encoding
   is parsed rather than split, no delimiter collision is possible.
6. Every component carries an explicit type tag. A value that produces no key
   (null, whitespace-only, invalid, missing required component) removes the whole
   tuple rather than contributing an empty string.
7. The encoded tuple is versioned and hashed with HMAC-SHA256 keyed by the
   application secret, scoped by definition, rule, definition revision and
   normalizer version. Only the digest is persisted; digests are treated as
   sensitive derived data.

### Field values for merge and fingerprints

8. Supported scalar codecs are explicit: string, boolean, integer,
   decimal-as-string, backed enum and date/datetime. Floating point is never used
   for money or large integers.
9. `null` and configured blank-string treatment are the only "missing" states.
   Blank behaviour is field-specific. PHP `empty()` is never used.
10. Preview, input and relation fingerprints use the same lossless tagged
    encoding as matching, not `updated_at`, so a field changed without touching
    `updated_at` still invalidates the preview.
11. Date/datetime codecs preserve timezone meaning; enum codecs preserve the
    backed type; long text is preserved intact and truncated for display only.

## Consequences

- Unsupported casts (JSON, arrays, media, translations, generated columns) block
  configuration for merge rather than being coerced.
- Rotating `APP_KEY` changes digests, invalidating generations and dismissals.
  Rescan is required and documented.
