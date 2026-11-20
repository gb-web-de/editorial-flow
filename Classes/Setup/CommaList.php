<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

/**
 * Comma-separated lists, the way TYPO3 stores groupMods, subgroup,
 * db_mountpoints and workspace members - and the way people type them on a
 * command line.
 *
 * Merging is the operation that matters: setup only ever ADDS to a list an
 * integrator already maintains. Overwriting `groupMods` on an existing group
 * would silently take modules away from every editor in it.
 */
final class CommaList
{
    /**
     * @param string|list<string> $value one string, or a repeated option's values
     * @return list<string> trimmed, empty entries dropped, first occurrence kept
     */
    public static function parse(string|array $value): array
    {
        $parts = [];
        foreach ((array)$value as $chunk) {
            foreach (explode(',', (string)$chunk) as $part) {
                $part = trim($part);
                if ($part !== '' && !in_array($part, $parts, true)) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }

    /**
     * @param list<string> $additions
     */
    public static function merge(string $existing, array $additions): string
    {
        return implode(',', self::parse([$existing, ...$additions]));
    }

    /**
     * @param list<string> $entries
     */
    public static function containsAll(string $existing, array $entries): bool
    {
        return array_diff($entries, self::parse($existing)) === [];
    }
}
