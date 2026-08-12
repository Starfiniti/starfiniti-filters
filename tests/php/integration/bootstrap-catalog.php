<?php

if (!get_option('starfiniti_search_initial_seed_complete')) {
    do_action('starfiniti_search_seed_catalog', 0);
}
