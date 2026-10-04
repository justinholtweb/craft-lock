<?php

namespace justinholtweb\lock\helpers;

use Craft;
use craft\db\Table;
use ReflectionClass;

/**
 * Tables a custom-table rule may never point at.
 *
 * The custom-table collector can be configured to delete or rewrite rows. Pointed at `users` it is
 * a way to delete accounts outside the element layer; pointed at `elements_sites` it rewrites every
 * entry's content as a raw string; pointed at one of Lock's own tables it edits the ledger that is
 * supposed to be append-only. Every one of those is already searched properly by a dedicated
 * collector, so there is nothing to gain and a great deal to lose.
 *
 * The list is Craft's own table registry — every constant on `craft\db\Table` — plus `content`
 * (Craft 4's, still present on upgraded sites) and anything starting `lock_`.
 */
final class TableGuard
{
    /** @var string[]|null */
    private static ?array $core = null;

    public static function isProtected(string $table): bool
    {
        $name = strtolower(trim($table));
        $prefix = strtolower((string)Craft::$app->getDb()->tablePrefix);

        // Someone who typed the prefixed name means the same table.
        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }

        if (str_starts_with($name, 'lock_')) {
            return true;
        }

        return in_array($name, self::core(), true);
    }

    /** @return string[] */
    private static function core(): array
    {
        if (self::$core !== null) {
            return self::$core;
        }

        $tables = ['content', 'users', 'elements', 'elements_sites', 'sessions'];

        foreach ((new ReflectionClass(Table::class))->getConstants() as $value) {
            if (is_string($value) && preg_match('/^\{\{%(.+)\}\}$/', $value, $match) === 1) {
                $tables[] = strtolower($match[1]);
            }
        }

        return self::$core = array_values(array_unique($tables));
    }
}
