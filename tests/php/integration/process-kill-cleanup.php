<?php

global $wpdb;
$wpdb->delete($wpdb->prefix . 'sfs_sync_outbox', ['locale' => 'process-kill-test'], ['%s']);
