<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Scheduling;

final class ActionSchedulerQueue
{
    public const GROUP = 'starfiniti-search';

    /** @param list<mixed> $args */
    public static function scheduleExactContinuation(string $hook, array $args, int $delaySeconds = 1): bool
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_single_action')) {
            return false;
        }
        if (as_has_scheduled_action($hook, $args, self::GROUP)) {
            return false;
        }

        // Action Scheduler's unique flag is hook/group-wide in the DB store. Exact
        // argument lookup plus a non-unique insert prevents another cursor or
        // generation from starving this continuation.
        return as_schedule_single_action(
            time() + max(1, $delaySeconds),
            $hook,
            $args,
            self::GROUP,
            false
        ) > 0;
    }

    public static function scheduleTokenizedSuccessor(string $hook, int $delaySeconds = 1): bool
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('wp_generate_uuid4')) {
            return false;
        }

        // One successor per running worker keeps a queue self-draining. The token
        // makes the successor distinct from the current action; database leases
        // make concurrent or delayed extra workers harmless.
        return as_schedule_single_action(
            time() + max(1, $delaySeconds),
            $hook,
            [wp_generate_uuid4()],
            self::GROUP,
            false
        ) > 0;
    }
}
