<?php
/**
 * Model and controller for fields that *may not* be edited by users.
 */
namespace EPFL\WS;

require_once(__DIR__ . "/activation.inc");
use function \EPFL\WS\plugin_deactivation_hook;

require_once(dirname(__DIR__) . "/Person.php");
use \EPFL\WS\Persons\Person;

/**
 * Auto fields of a model class.
 *
 * An instance represents the auto fields associated to one model
 * class. Whenever some code non-interactively updates attributes, it
 * ought to call `AutoFields::of($model_class)->append($fields)`.
 * The AutoFieldsController makes sure to make these fields un-editable.
 */

class AutoFields
{
    private function __construct ($for_class)
    {
        $this->model_class = $for_class;
    }

    /**
     * Named constructor idiom.
     */
    static function of ($for_class) {
        $thisclass = get_called_class();
        return new $thisclass($for_class);
    }

    function get ()
    {
        $auto_fields = get_option($this->_get_option_key());
        if (! $auto_fields) { $auto_fields = []; }
        return $auto_fields;
    }

    /**
     * Mark these fields as protected
     */
    function append ($fields)
    {
        $protected = $this->get();
        $changed = false;
        if (! is_array($fields)) {
            $fields = [$fields];
        }
        foreach ($fields as $field) {
            if (false === array_search($field, $protected)) {
                array_push($protected, $field);
                $changed = true;
            }
        }
        if ($changed) {
            update_option($this->_get_option_key(), $protected);
        }
    }

    function clear () {
        delete_option($this->_get_option_key());
    }

    const SLUG = "epfl_ws_protected_fields";

    private function _get_option_key () {
        return static::SLUG . "_" . sanitize_key($this->model_class);
    }
}

/**
 * Controller helper class for the admin area of classes that have AutoFields.
 *
 * An instance of this class deals with one specific model class, and
 * arrange for its auto-fields to be shown read-only in the wp-admin
 * edit pages for items of that model class. In order to achieve this,
 * controller code is supposed to call @link hook and @link
 * add_meta_boxes as indicated in their respective docstrings.
 */
class AutoFieldsController {
    /**
     * An instance of the class represents the auto-fields controller for
     * one @param $model_class. Since the instance is stateless, it is fine to
     * construct multiple, ephemeral instances at the different hook
     * points in the controller.
     *
     * The following assumptions are made on @param $model_class:
     *
     * - The ::get() static function takes a post ID and returns NULL if there
     *   is no instance of the class with that post ID (even if the post ID does
     *   exist, but for a post that does not belong to the class)
     */
    function __construct ($model_class) {
        $this->model_class = $model_class;
    }

    /**
     * Should be called during the controller class' own
     * initialization, i.e. its own "hook" method.
     */
    function hook ()
    {
        add_filter("is_protected_meta", array($this, "filter_is_protected_meta"), 10, 3);
        plugin_deactivation_hook(array($this, "clear_auto_fields"));
    }

    /**
     * Should be called at WordPress `add_meta_boxes` time, e.g. from the
     * model class' `register_meta_box_cb` parameter to @link register_post_type
     */
    function add_meta_boxes ()
    {
        add_meta_box("epfl_readonly_meta_" . $this->model_class,
                     ___("Automatic Custom Fields"),
                     array($this, "render_protected_meta_box"),
                     null, 'normal', 'high');
    }

    function filter_is_protected_meta ($is_protected, $meta_key, $unused_meta_type)
    {
        global $post;
        if ($this->model_class::get($post) &&
            in_array($meta_key, AutoFields::of($this->model_class)->get())) {
                return true;
            } else {
                return $is_protected;
            }
    }

    function render_protected_meta_box ()
    {
        global $post;
        if (! $this->model_class::get($post)) return;

        $meta = get_post_meta($post->ID);
        echo "<table>\n";
        foreach (AutoFields::of($this->model_class)->get() as $key) {
            // All meta keys are single-valued
            $value = $meta[$key][0];
            if (gettype($value) != "string") {
                $value_html = "<pre>" . gettype($value) . "</pre>";
            } elseif ($this->_is_sciper_type($key)) {
                $value_html = $this->sciper_link($value);
            } else {
                $max_length = 120;
                if (strlen($value) > $max_length) {
                    $value = substr($value, 0, $max_length) . "...";
                }
                $value_html = htmlspecialchars($value);
            }
            echo "<tr><td>$key</td><td>$value_html</td></tr>\n";
        }
        echo "</table>\n";
    }

    function _is_sciper_type ($field_name)
    {
        return (($field_name === "epfl_lab_manager") ||
                ($field_name === "epfl_work_by"));
    }

    function sciper_link ($sciper)
    {
        $person = Person::find_by_sciper($sciper);
        if ($person) {
            return sprintf(
                '<a href="/wp-admin/post.php?post=%d&action=edit">%s</a>',
                $person->ID, $sciper);
        } else {
            return sprintf(
                '%d <a href="/wp-admin/post-new.php?post_type=epfl-person&SCIPER=%d">(Create)</a>',
                $sciper, $sciper);
        }
    }

    function clear_auto_fields ()
    {
        AutoFields::of($this->model_class)->clear();
    }
}
