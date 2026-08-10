<?php

defined('ABSPATH') || exit;

echo (new \Starfiniti\Search\Infrastructure\WordPress\Storefront\SearchShortcode())->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all dynamic values.
