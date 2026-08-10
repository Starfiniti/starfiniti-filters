<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Security;

final class Capabilities
{
    public const VIEW_HEALTH = 'starfiniti_search_view_health';
    public const MANAGE_INDEX = 'starfiniti_search_manage_index';
    public const MANAGE_CONFIGURATION = 'starfiniti_search_manage_configuration';
    public const VIEW_ANALYTICS = 'starfiniti_search_view_analytics';
    public const PURGE_ANALYTICS = 'starfiniti_search_purge_analytics';
    public const RUN_MIGRATION = 'starfiniti_search_run_migration';
    public const VIEW_AUDIT = 'starfiniti_search_view_audit';
    public const VERSION = 2;

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW_HEALTH, self::MANAGE_INDEX, self::MANAGE_CONFIGURATION, self::VIEW_ANALYTICS, self::PURGE_ANALYTICS, self::RUN_MIGRATION, self::VIEW_AUDIT];
    }

    public static function install(): void
    {
        $role = get_role('administrator');
        if ($role !== null) {
            foreach (self::all() as $capability) {
                $role->add_cap($capability);
            }
        }
        update_option('starfiniti_search_capability_version', self::VERSION, false);
    }

    public static function remove(): void
    {
        foreach (wp_roles()->roles as $roleName => $_definition) {
            $role = get_role((string) $roleName);
            if ($role !== null) {
                foreach (self::all() as $capability) {
                    $role->remove_cap($capability);
                }
            }
        }
        delete_option('starfiniti_search_capability_version');
    }
}
