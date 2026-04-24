<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpp_sticky_limit');
delete_option('wpp_do_activation_redirect');