<?php
/**
 * Manage periodic tasks on behalf of epfl-ws
 */

namespace EPFL\WS;

use \WPPrometheusExporter;  // In a plug-in - No harm if it doesn't exist

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/i18n.inc");

function run_every ($slug, $seconds, $callable, $right_now_for_debug = false) {
    $slug = "epfl-ws-batch-" . sanitize_title($slug);
    if ($right_now_for_debug) {
        // Run it as soon as possible, but not sooner
        add_action("init", function () use ($callable) {
            call_user_func($callable);
        }, 1000);
        return;
    }

    $schedule_slug = sprintf("epfl-ws-every-%d-s", $seconds);
    add_filter(
        'cron_schedules',
        function ($schedules) use ($seconds, $schedule_slug) {
            $schedules[$schedule_slug] = array(
                'interval' => $seconds,
                'display'  => sprintf(
                    ___('Every %d seconds (for epfl-ws)'), $seconds)
            );
            return $schedules;
        });
    if (! wp_next_scheduled($slug)) {
        wp_schedule_event(time(), $schedule_slug, $slug);
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
        $this->started = microtime(true);
        try {
            call_user_func($callable);
            $this->failed = null;
        } catch (\Throwable $e) {
            $this->failed = $e;
        } catch (\Exception $e) {
            // Sigh. http://php.net/manual/en/language.errors.php7.php#119652
            $this->failed = $e;
        }
        $this->finished = microtime(true);
        $this->prometheus_export();
        error_log(sprintf(
            "%s, duration: %.03ds, status: %s",
            $this->banner,
            $this->finished - $this->started,
            ($this->failed                              ?
             sprintf("ERROR - %s at %s line %d\n%s",
                     $this->failed->getMessage(),
                     $this->failed->file,
                     $this->failed->line,
                     $this->failed->getTraceAsString()) :
             "OK")));
    }

    function prometheus_export ()
    {
        if (! ($this->prometheus_labels)) return;
        if (! class_exists("WPPrometheusExporter")) {
            // error_log("WARNING: no WPPrometheusExporter, cannot log");
            return;
        }
        (new WPPrometheusExporter('epfl_ws_batch_duration', $this->prometheus_labels))
            ->update($this->finished - $this->started);
        (new WPPrometheusExporter('epfl_ws_batch_success', $this->prometheus_labels))
            ->update($this->failed ? 0 : 1);
        if (! $this->failed) {
            (new WPPrometheusExporter('epfl_ws_batch_last_success',
                                      $this->prometheus_labels))
                ->update($this->finished);
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
