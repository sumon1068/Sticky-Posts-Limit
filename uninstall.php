<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wppspl_sticky_limit');
delete_option('wppspl_do_activation_redirect');
