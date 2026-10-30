<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

wp_clear_scheduled_hook('wtp_cleanup');