<?php
/**
 * Manage periodic tasks on behalf of epfl-ws
 */

namespace EPFL\WS;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/i18n.inc");

const DEBUG_CRON = false;

function run_every ($seconds, $callable) {
    if (DEBUG_CRON) {
        // Run it as soon as possible, but not sooner
        add_action("init", function () use ($callable) {
            call_user_func($callable);
        }, 1000);
        return;
    }

    $slug = sprintf("epfl-ws-every-%d-s", $seconds);
    add_filter(
        'cron_schedules',
        function ($schedules) use ($seconds, $slug) {
            $schedules[$slug] = array(
                'interval' => $seconds,
                'display'  => sprintf(
                    ___('Every %d seconds (for epfl-ws)'), $seconds)
            );
            return $schedules;
        });
    if (! wp_next_scheduled($slug)) {
        wp_schedule_event( time(), $slug, $slug);            
    }
    add_action($slug, $callable);
}
