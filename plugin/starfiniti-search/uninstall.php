<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/Infrastructure/WordPress/Security/Capabilities.php';
\Starfiniti\Search\Infrastructure\WordPress\Security\Capabilities::remove();

// Enterprise safety policy: indexes, outbox records, and configuration are retained by default.
// Destructive cleanup is a separate, explicit operation in the administration and WP-CLI layers.
