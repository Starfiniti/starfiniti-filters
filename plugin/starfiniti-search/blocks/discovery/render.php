<?php
defined('ABSPATH') || exit;
echo (new \Starfiniti\Search\Infrastructure\WordPress\Storefront\SearchShortcode())->renderDiscovery(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
