<?php
/*
 * Plugin Name: EPFL Personal Pages (shortcode)
 * Description: Manage personal pages for EPFL staff
 * Version:     0.1
 * Author:      STI Web Task Force
 * Author URI:  mailto:stiitweb@groupes.epfl.ch
 */
namespace EPFL\Persons;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/ldap.inc");

function ___($text)
{
    return __($text, "epfl-person");
}

function __x($text, $context)
{
    return _x($text, $context, "epfl-person");
}

function ends_with($haystack, $needle)
{
    $length = strlen($needle);

    return $length === 0 ||
    (substr($haystack, -$length) === $needle);
}

/**
 * True iff we are on the "/post-new.php" page.
 */
function is_form_new ()
{
    return ends_with(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH),
                     "/post-new.php");
}

class SCIPERException extends \Exception { }

class PersonNotFoundException extends SCIPERException
{
    function as_text ()
    {
        return sprintf(
            ___('Person with SCIPER %d not found'),
            $this->message);

    }
}
class PersonAlreadyExistsException extends SCIPERException
{
    function as_text ()
    {
        return sprintf(
            ___('A person with SCIPER %d already exists'),
            $this->message);
    }
}

class DuplicatePersonException  extends SCIPERException
{
    function as_text ()
    {
        return sprintf(
            ___('Multiple persons found with SCIPER %d'),
            $this->message);
    }
}

class Person
{
    const SLUG = "epfl-person";

    static function get_post_type ()
    {
        return self::SLUG;
    }

    /**
     * Private constructor — Call @link get_or_create instead
     */
    private function __construct ($id)
    {
        $this->ID = $id;
    }

    public static function get ($post_or_post_id)
    {
        if (is_object($post_or_post_id)) {
            if ($post_or_post_id->post_type !== Person::get_post_type()) return;
            $post_id = $post_or_post_id->ID;
        } else {
            $post_id = $post_or_post_id;
            if (get_post_type($post_id) !== Person::get_post_type()) return;
        }
        $theclass = get_called_class();
        $that = new $theclass($post_id);
        if (is_object($post_or_post_id)) {
            $that->_wp_post = $post_or_post_id;
        }
        return $that;
    }

    function wp_post ()
    {
        if (! $this->_wp_post) {
            $this->_wp_post = get_post($this->ID);
        }
        return $this->_wp_post;
    }

    public function set_sciper($sciper)
    {
        $search_query = new \WP_Query(array(
            'post_type' => Person::get_post_type(),
            'meta_query' => array(array(
                'key'     => 'sciper',
                'value'   => $sciper,
                'compare' => '='
            ))));
        $results = $search_query->get_posts();
        if (sizeof($results) > 1) {
            throw new DuplicatePersonException($sciper);
        } elseif (sizeof($results) == 1) {
            if ($results[0]->ID === $this->ID) {
                return $this;  // Nothing to do; still chainable
            } else {
                throw new PersonAlreadyExistsException($sciper);
            }
        } else {
            $update = array(
                'ID'         => $this->ID,
                'post_name'  => $sciper,
                'meta_input' => array(
                    'sciper' => $sciper
                )
            );
            $title = $this->get_title();
            if (! $title ||
                // Ackpttht
                in_array($title, ["Auto Draft", "Brouillon auto"])) {
                $update['post_title'] = "[SCIPER $sciper]";
            }
            wp_update_post($update);
            return $this;  // Chainable
        }
    }

    public function update ()
    {
        $this->update_from_ldap();
        return $this;  // Chainable
    }

    public function get_title ()
    {
        return $this->wp_post()->post_title;
    }

    public function get_sciper ()
    {
        return get_post_meta($this->ID, 'sciper', true);  // Cached by WP
    }

    private function update_from_ldap ()
    {
        $entries = LDAPClient::query_by_sciper($this->get_sciper());
        if (! $entries) {
            throw new PersonNotFoundException(
                sprintf(___('Person with SCIPER %d not found'),
                        $this->get_sciper()));
        }

        $greeting = $entries[0]['personaltitle'][0];
        $job      = $entries[0]['title'][0];
        $name     = $entries[0]['cn'][0];
        
        $update = array(
            'ID'         => $this->ID,
            'post_title' => "$greeting $name",
            'meta_input' => array(
                'greeting'  => $greeting,
                'job_title' => $job
            )
        );
        wp_update_post($update);
    }
}

class PersonConfig
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        /* Customize the edit form */
        add_action('edit_form_after_title',
                   array(get_called_class(), 'meta_boxes_above_editor'));
        add_action(sprintf('save_post_%s', Person::get_post_type()),
                   array(get_called_class(), 'save_meta_boxes'), 10, 3);
        add_action("admin_notices",
                   array(get_called_class(), 'maybe_show_admin_error'));

        /* Make permalinks work - See doc for flush_rewrite_rules() */
        register_deactivation_hook(__FILE__, 'flush_rewrite_rules' );
        register_activation_hook(__FILE__, function () {
            PersonConfig::register_post_type();
            flush_rewrite_rules();
        });
    }

    /**
     * Make it so that people pages exist.
     *
     * Under WordPress, almost everything publishable is a post. register_post_type() is
     * invoked to create a particular flavor of posts that describe people.
     */
    static function register_post_type ()
    {
        register_post_type(
            Person::get_post_type(),
            array(
                'labels'             => array(
                    'name'               => __x( 'People', 'post type general name' ),
                    'singular_name'      => __x( 'Person', 'post type singular name' ),
                    'menu_name'          => __x( 'EPFL People', 'admin menu' ),
                    'name_admin_bar'     => __x( 'Person', 'add new on admin bar' ),
                    'add_new'            => __x( 'Add New', 'add new person' ),
                    'add_new_item'       => ___( 'Add New Person' ),
                    'new_item'           => ___( 'New Person' ),
                    'edit_item'          => ___( 'Edit Person' ),
                    'view_item'          => ___( 'View Person' ),
                    'all_items'          => ___( 'All People' ),
                    'search_items'       => ___( 'Search People' ),
                    'not_found'          => ___( 'No persons found.' ),
                    'not_found_in_trash' => ___( 'No persons found in Trash.' )
                ),
                'description'        => ___( 'Noteworthy people' ),
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'query_var'          => true,
                'rewrite'            => array( 'slug' => Person::get_post_type() ),
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'menu_position'      => 26,
                'menu_icon'          => 'dashicons-welcome-learn-more',  // Mortar hat
                'supports'           => array( 'editor', 'thumbnail' ),
                'register_meta_box_cb' => array(get_called_class(), 'add_meta_boxes')
            ));
    }

    /**
     * Add custom fields and behavior to the new person / edit person form
     * using "meta boxes".
     *
     * Called as the 'register_meta_box_cb' at @link register_post_type time.
     *
     * @see https://code.tutsplus.com/tutorials/how-to-create-custom-wordpress-writemeta-boxes--wp-20336
     */
    static function add_meta_boxes ()
    {
        if (is_form_new()) {
            self::add_meta_box('find_by_sciper', ___('Find person'));
        } else {
            self::add_meta_box('show_person_details', ___('Person details'));
        }
    }

    static function render_meta_box_find_by_sciper ($unused_post)
    {
        ?><input type="text" id="sciper" name="sciper" placeholder="<?php echo ___("SCIPER"); ?>"><?php
    }

    static function save_meta_box_find_by_sciper ($post_id, $post, $is_update)
    {
        try {
            Person::get($post_id)->set_sciper(intval($_REQUEST['sciper']))->update();
        } catch (LDAPException $e) {
            // Not fatal, we'll try again later
            error_log(sprintf("LDAPException: %s", $e->getMessage()));
            self::admin_error($post_id, $e->getMessage());
        } catch (\Exception $e) {
            // Fatal - Undo save
            wp_delete_post($post_id, true);
            $message = method_exists($e, "as_text") ? $e->as_text() : $e->getMessage();
            error_log(sprintf("%s: %s", get_class($e), $message));
            wp_die($message);
        }
    }

    static function render_meta_box_show_person_details ($post)
    {
        $sciper = get_post_meta($post->ID, 'sciper', true);
        ?><h1><?php echo $post->post_title; ?></h1><?php
    }

    static function save_meta_box_show_person_details ($post_id, $post, $is_update)
    {
        // Strictly speaking, this meta box has no state to change (for now).
        // Still, this sort of makes sense to fetch data from LDAP again here.
        Person::get($post_id)->update();
    }


    /**
     * Arrange for a nonfatal error to be shown in a so-called "admin notice."
     */
    static private function admin_error ($post_id, $text)
    {
        // Use "Saving It in a Transient" technique from
        // https://www.sitepoint.com/displaying-errors-from-the-save_post-hook-in-wordpress/
        
        set_transient(
            self::get_error_transient_key($post_id),
            $text,
            45);  // Seconds before it self-destructs
    }

    static function maybe_show_admin_error ()
    {
        global $post_id;
        $key = self::get_error_transient_key ($post_id);
        if ($error = get_transient($key)) {
            delete_transient($key);
            ?>
    <div class="notice notice-error is-dismissible">
        <p><?php echo $error; ?></p>
    </div><?php
        }
    }

    static private function get_error_transient_key ($post_id)
    {
        return sprintf("%s-save_post_errors_%d_%d",
                       Person::SLUG, $post_id, get_current_user_id());
    }

    /**
     * Simpler version of the WordPress add_meta_box function
     *
     * @param $slug Unique name for this meta box. The render function
     * is the method called "render_meta_box_$slug", and the save function
     * is the method called "save_meta_box_$slug" (for the latter see
     * @link save_meta_boxes)
     *
     * @param $title The human-readable title for the meta box
     *
     * @param $position The position to render the meta box at;
     *        defaults to "above-editor" (see @link meta_boxes_above_editor).
     *        Can be set to any legal value for the $priority argument
     *        to the WordPress add_meta_box function, in particular "default"
     *        to render the meta box after the editor.
     */
    static function add_meta_box ($slug, $title, $position = null)
    {
        if (! $position) $position = 'above-editor';
        $klass = get_called_class();
        $meta_box_name = self::get_meta_box_name($slug);
        add_meta_box($meta_box_name, $title,
                     function () use ($meta_box_name, $klass, $slug) {
                         wp_nonce_field($meta_box_name, $meta_box_name);
                         global $post;
                         call_user_func(
                             array($klass, "render_meta_box_$slug"),
                             $post);
                     },
                     null, $position);
    }

    private static function get_meta_box_name ($slug)
    {
        return sprintf("%s-nonce-meta_box_%s", Person::get_post_type(), $slug);
    }

    /**
     * Call the save_meta_box_$slug for any and all meta box that is
     * posting information.
     *
     * Any and all nonces present in $_REQUEST, for which a corresponding
     * class method exists, are checked; then the class method is called.
     */
    static function save_meta_boxes ($post_id, $post, $is_update)
    {
        // Bail if we're doing an auto save
        if (defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE) return;

        foreach ($_REQUEST as $k => $v) {
            $matched = array();
            if (preg_match(sprintf('/%s-nonce-meta_box_([a-zA-Z0-9_]+)$/',
                                   Person::get_post_type()),
                           $k, $matched)) {
                $save_method_name = "save_meta_box_" . $matched[1];
                if (method_exists(get_called_class(), $save_method_name)) {
                    if (! wp_verify_nonce($v, $k)) {
                        wp_die(___("Nonce check failed"));
                    } elseif (! current_user_can('edit_post')) {
                        wp_die(___("Permission denied: edit person"));
                    } elseif (self::$saved_meta_boxes[$k]) {
                        // Break out of silly recursion: we call
                        // writer functions such as wp_insert_post()
                        // and wp_update_post(), which call us back
                        return;
                    } else {
                        self::$saved_meta_boxes[$k] = true;
                        call_user_func(
                            array(get_called_class(), $save_method_name),
                            $post_id, $post, $is_update);
                    }
                }
            }
        }  // End foreach
    }
    static private $saved_meta_boxes = array();

    /**
     * Render all meta boxes configured to show up above the editor.
     */
    static function meta_boxes_above_editor ($post)
    {
        if ($post->post_type !== Person::get_post_type()) return;
        do_meta_boxes(get_current_screen(), 'above-editor', $post);
    }
}

PersonConfig::hook();
