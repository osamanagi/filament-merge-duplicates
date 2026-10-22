<?php

namespace Nagi\FilamentMergeDuplicates\Retirement;

use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * Converts the original HMAC domains without changing source/survivor mappings,
 * operation IDs, audit ciphertext, or any other ledger metadata.
 *
 * Deployment must drain old writers before this migration. Original definitions
 * and matching key settings are required; unknown domains are never guessed.
 */
final class RetirementLedgerMigrator
{
    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly RetirementResolver $retirement,
    ) {}

    public function migrate(string $connection, ?KeyHasher $legacyHasher = null): int
    {
        return DB::connection($connection)->transaction(function () use ($connection, $legacyHasher): int {
            $legacy = DB::connection($connection)->table('filament_merge_duplicates_merges')
                ->where('retirement_domain_version', 1);
            $domains = (clone $legacy)->distinct()->pluck('retirement_domain')->all();

            if ($domains === []) {
                return 0;
            }

            // A fresh installation has no old digests to reconstruct and must
            // not require an application/matching key just to run migrations.
            $legacyHasher ??= app(KeyHasher::class);

            $replacements = [];

            foreach ($this->definitions->all() as $definition) {
                if ($definition->connection() !== $connection) {
                    continue;
                }

                $parts = [$connection, $definition->model(), $definition->ownershipDomain()];
                $old = $legacyHasher->hash(['retirement-domain'], TupleEncoder::encodeStrings($parts));
                $replacements[$old] = $this->retirement->domainDigest(...$parts);
            }

            foreach ($domains as $domain) {
                if (! isset($replacements[$domain])) {
                    throw new InvalidConfiguration(
                        'A legacy retirement domain could not be resolved. Register every original model/ownership-domain definition on this connection and restore the original matching secret and key_version, then rerun the migration. No ledger rows were converted.',
                    );
                }
            }

            $converted = 0;

            foreach ($domains as $domain) {
                $converted += (clone $legacy)->where('retirement_domain', $domain)->update([
                    'retirement_domain' => $replacements[$domain],
                    'retirement_domain_version' => RetirementResolver::DOMAIN_VERSION,
                ]);
            }

            return $converted;
        });
    }
}
