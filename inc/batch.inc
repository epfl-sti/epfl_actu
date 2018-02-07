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

/**
 * One elementary task, whose duration and success status will be recorded.
 */
class BatchTask
{
    function __construct ()
    {
        $this->banner = "Unnamed batch task";
    }

    function set_banner ($banner)
    {
        $this->banner = $banner;
        return $this;
    }

    function set_prometheus_labels ($labels) {
        $this->prometheus_labels = $labels;
        return $this;
    }

    /**
     * Call $callable and record the duration and status to Prometheus and to
     * the Apache error log.
     *
     * This always succeeds, even if $callable throws.
     */
    function run ($callable)
    {
        error_log($this->banner);
        $started = microtime(true);
        try {
            call_user_func($callable);
            $failed = null;
        } catch (Exception $e) {
            $failed = $e;
        }
        $duration = microtime(true) - $started;
        error_log(sprintf(
            "%s, duration: %.03ds, status: %s",
            $this->banner,
            $duration,
            ($failed ? $failed->getMessage() : "OK")));
        if (! ($this->prometheus_labels &&
               class_exists("WPPrometheusExporter"))) { return; }
        (new WPPrometheusExporter('epfl_ws_batch_duration', $this->prometheus_labels))
            ->update($duration);
        (new WPPrometheusExporter('epfl_ws_batch_success', $this->prometheus_labels))
            ->update($failed ? 0 : 1);
        if (! $failed) {
            (new WPPrometheusExporter('epfl_ws_batch_last_success',
                                      $this->prometheus_labels))
                ->update($started);
        }
    }
}

add_action('plugins_loaded', function () {
    if (! class_exists("WPPrometheusExporter")) return;
    WPPrometheusExporter::register_metric(
        'epfl_ws_batch_last_success',
        array(
            'help' => 'Timestamp of last successful batch task'
        ));
    WPPrometheusExporter::register_metric(
        'epfl_ws_batch_duration',
        array(
            'help' => 'Duration of last batch task (successful or not)',
            'has_timestamp' => true
        )
    );
    WPPrometheusExporter::register_metric(
        'epfl_ws_batch_success',
        array(
            'type' => 'gauge',
            'help' => '0 for error, 1 for success',
            'has_timestamp' => true
        )
    );
});

