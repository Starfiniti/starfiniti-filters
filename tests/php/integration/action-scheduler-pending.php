<?php

$group = getenv('STARFINITI_TEST_ACTION_GROUP');
$group = is_string($group) && preg_match('/^[a-z0-9-]{1,64}$/', $group) ? $group : 'starfiniti-search';

$actions = ActionScheduler::store()->query_actions(
    [
        'group'    => $group,
        'status'   => ActionScheduler_Store::STATUS_PENDING,
        'per_page' => 1,
    ]
);

echo (string) count($actions);
