<?php // -*- web-mode-code-indent-offset: 4; -*-

namespace EPFL\WS\Persons;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/ldap.inc");
use \EPFL\WS\LDAPClient;

require_once(dirname(__FILE__) . "/inc/scrape.inc");
use function \EPFL\WS\scrape;

require_once(dirname(__FILE__) . "/inc/title.inc");
use \EPFL\WS\Persons\NoSuchTitleException;
use \EPFL\WS\Persons\Title;

require_once(dirname(__FILE__) . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

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

    public function get_sciper ()
    {
        return get_post_meta($this->ID, 'sciper', true);  // Cached by WP
    }

    public function get_full_name ()
    {
        return $this->wp_post()->post_title;
    }

    public function get_publication_link ()
    {
        return get_post_meta($this->ID, 'publication_link', true);  // Cached by WP
    }

    public function set_sciper($sciper)
    {
        $metoo = get_called_class()::find_by_sciper($sciper);
        if ($metoo) {
            if ($metoo->wp_post()->ID === $this->ID) {
                return $this;  // Nothing to do; still chainable
            } else {
                throw new PersonAlreadyExistsException($sciper);
            }
        }

        // Person doesn't exist; create them
        $update = array(
            'ID'         => $this->ID,
            'post_name'  => $sciper,
            'meta_input' => array(
                'sciper' => $sciper,
                'publication_link' => ""
            )
        );
        $title = $this->wp_post()->post_title;
        if (! $title ||
            // Ackpttht
            in_array($title, ["Auto Draft", "Brouillon auto"])) {
            $update['post_title'] = "[SCIPER $sciper]";
        }
        wp_update_post($update);
        return $this;  // Chainable
    }

    public static function find_by_sciper ($sciper)
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
        }
        if (sizeof($results)) {
            return static::get($results[0]);
        } else {
            return null;
        }
    }

    function get_title ()
    {
        $title_code = get_post_meta($this->ID, "title_code", true);
        return $title_code ? new Title($title_code) : null;
    }

    public function get_dn ()
    {
        return get_post_meta($this->ID, 'dn', true);
    }

    public function get_postaladdress ()
    {
        return get_post_meta($this->ID, 'postaladdress', true);
    }

    public function get_unit ()
    {
        return get_post_meta($this->ID, 'ou', true);
    }

    public function get_unit_long ()
    {
        return get_post_meta($this->ID, 'unit_quad', true);
    }

    public function update ()
    {
        $this->update_from_ldap();
        return $this;  // Chainable
    }

    private function update_from_ldap ()
    {
        $entries = LDAPClient::query_by_sciper($this->get_sciper());
        if (! $entries) {
            throw new PersonNotFoundException(
                sprintf(___('Person with SCIPER %d not found'),
                        $this->get_sciper()));
        }

        $meta = array();
        $title = Title::from_ldap($entries);
        if ($title) {
            $meta["title_code"] = $title->code;
        }

        $postaladdress = $entries[0]["postaladdress"][0];
        if ($postaladdress) {
            $meta["postaladdress"] = $postaladdress;
        }

        $dn = $entries[0]["dn"];
        if ($dn) {
            $meta["dn"] = $dn;
            $bricks = explode(',', $dn);
            // construct a unit string, e.g. EPFL / STI / STI-SG / STI-IT
            $meta["unit_quad"] = strtoupper(explode('=', $bricks[4])[1]) . " / " . strtoupper(explode('=', $bricks[3])[1]) . " / " . strtoupper(explode('=', $bricks[2])[1]) . " / " . strtoupper(explode('=', $bricks[1])[1]);
        }

        $unit = $entries[0]["ou"][0];
        $meta["ou"] = $unit;

        $unit_entries = LDAPClient::query_by_unit_name($unit);
        if ($unit_entries && count($unit_entries)) {
            $labeleduri = $unit_entries[0]["labeleduri"][0];

            $meta[self::LAB_WEBSITE_URL_META] = explode(" ", $labeleduri)[0];
        }

        $update = array(
            'ID'         => $this->ID,
            // We want a language-neutral post_title so we can't
            // work in the greeting - Filters will be used for that instead.
            'post_title' => $entries[0]['cn'][0],
            'meta_input' => $meta
        );
        wp_update_post($update);
    }

    const THUMBNAIL_META  = "epfl_person_external_thumbnail";
    public function get_image_url ($size = null)
    {
        return get_post_meta($this->ID, self::THUMBNAIL_META, true);
    }

    public function import_image_from_people ()
    {
        $dom = $this->_get_people_dom();
        $xpath = new \DOMXpath($dom);
        $src_attr = $xpath->query("//div[@class=\"portrait\"]/img/@src")->item(0);
        if (! $src_attr) return null;
        $src = $src_attr->value;
        update_post_meta($this->ID, self::THUMBNAIL_META, $src);
        return $this;
    }

    const LAB_WEBSITE_URL_META = "epfl_person_lab_website_url";
    public function get_lab_website_url ()
    {
        return get_post_meta($this->ID, self::LAB_WEBSITE_URL_META, true);
    }

    private function _get_people_dom()
    {
        if (! $this->_people_dom) {
            $this->_people_dom = scrape(
                sprintf("https://people.epfl.ch/%d",
                        $this->get_sciper()));
        }
        return $this->_people_dom;
    }

}

/**
 * Instance-less class for the controller code (the C of MVC) that manages
 * persons, both on the main site and in wp-admin/
 */
class PersonController
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        /* Behavior of Persons on the main site */
        add_filter("post_thumbnail_html",
                   array(get_called_class(), "filter_post_thumbnail_html"), 10, 5);

        /* Customize the edit form */
        add_action('edit_form_after_title',
                   array(get_called_class(), 'meta_boxes_above_editor'));
        add_action( 'edit_form_after_editor',
                   array(get_called_class(), 'meta_boxes_after_editor'));

        add_action(sprintf('save_post_%s', Person::get_post_type()),
                   array(get_called_class(), 'save_meta_boxes'), 10, 3);
        add_action("admin_notices",
                   array(get_called_class(), 'maybe_show_admin_error'));


        /* Customize the list in the admin aera */
        add_action( sprintf('manage_%s_posts_columns', Person::get_post_type()) , array(get_called_class(), "alter_columns"));
        add_action( sprintf('manage_%s_posts_custom_column', Person::get_post_type()),
                    array(get_called_class(), "render_people_thumbnail_column"), 10, 2);
        add_action( sprintf('manage_%s_posts_custom_column', Person::get_post_type()),
                    array(get_called_class(), "render_people_unit_column"), 10, 2);

        /* Make permalinks work - See doc for flush_rewrite_rules() */
        register_deactivation_hook(__FILE__, 'flush_rewrite_rules' );
        register_activation_hook(__FILE__, function () {
            PersonController::register_post_type();
            flush_rewrite_rules();
        });

        /* i18n */
        add_action('plugins_loaded', function () {
            load_plugin_textdomain(
                'epfl-persons', false,
                basename(dirname(__FILE__)) . '/languages');
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
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'menu_position'      => 40,
                'menu_icon'          => 'dashicons-welcome-learn-more',  // Mortar hat
                'supports'           => array( 'editor', 'thumbnail' ),
                'register_meta_box_cb' => array(get_called_class(), 'add_meta_boxes')
            ));
    }

    /**
     * Arrange for get_the_post_thumbnail() to return the external thumbnail for Persons.
     *
     * This is set as a filter for WordPress' @link post_thumbnail_html hook. Note that
     * it isn't as easy to hijack the return value of @link get_the_post_thumbnail_url
     * in this way (but you can always call the @link get_image_url instance
     * method on a Person object).
     *
     * @return An <img /> tag with suitable attributes
     *
     * @param $orig_html The HTML that WordPress intended to return as
     *                   the picture (unused, as it will typically be
     *                   empty — Person objects lack attachments)
     *
     * @param $post_id   The post ID to compute the <img /> for
     *
     * @param $attr      Associative array of HTML attributes. If "class" is
     *                   not specified, the default "wp-post-image" is used
     *                   to match the WordPress behavior for local (attached)
     *                   images.
     */
    static function filter_post_thumbnail_html ($orig_html, $post_id, $unused_thumbnail_id,
                                                $unused_size, $attr)
    {
        $person = Person::get($post_id);
        if (! $person) return $orig_html;

        $src = $person->get_image_url();
        if (! $src) return $orig_html;

        if (! $attr) $attr = array();
        if (! $attr["class"]) {
            $attr["class"] = "wp-post-image";
        }
        $attrs = "";
        foreach ( $attr as $name => $value ) {
            $attrs .= sprintf(" %s=\"%s\"", $name, esc_attr($value));
        }
        return sprintf("<img src=\"%s\" %s/>", $src, $attrs);
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
            self::add_meta_box('show_publication_link', ___('Infoscience URL'), 'after-editor');
        } else {
            self::add_meta_box('show_person_details', ___('Person details'));
            self::add_meta_box('show_publication_link', ___('Infoscience URL'), 'after-editor');
        }
    }

    static function render_meta_box_find_by_sciper ($unused_post)
    {
        ?><input type="text" id="sciper" name="sciper" placeholder="<?php echo ___("SCIPER"); ?>"><?php
    }

    static function save_meta_box_find_by_sciper ($post_id, $post, $is_update)
    {
        try {
            Person::get($post_id)
                ->set_sciper(intval($_REQUEST['sciper']))
                ->update()
                ->import_image_from_people();
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

    static function render_meta_box_show_person_details ($the_post)
    {
        global $post; $post = $the_post; setup_postdata($post);
        $person = Person::get($post->ID);
        $sciper = $person->get_sciper();
        $title = $person->get_title();
        $greeting = $title ? sprintf("%s ", $title->as_greeting()) : "";
        $title_str = $title ? sprintf("%s, ", $title->localize()) : "";
        ?><h1><?php echo $greeting; the_title(); ?></h1>
          <h2><?php echo $title_str; ?><a href="https://people.epfl.ch/<?php echo $sciper; ?>">SCIPER <?php echo $sciper; ?></a></h2>
        <?php the_post_thumbnail(); ?>
       <?php
    }

    static function save_meta_box_show_person_details ($post_id, $post, $is_update)
    {
        // Strictly speaking, this meta box has no state to change (for now).
        // Still, it sort of makes sense that here be the place where
        // we sync data from LDAP again.
        Person::get($post_id)->update();
    }

    /**
     * Render publication_link meta boxes after the editor.
     */
    static function render_meta_box_show_publication_link ($post)
    {
        if ($post->post_type !== Person::get_post_type()) return;
        $person = Person::get($post->ID);
        $publication_link = $person->get_publication_link();
        echo '<div class="form-field">
                <h3>Publications</h3>
                <p class="label">
                    Go to infoscience, make a query (e.g. author:lastname), click "Integrate these publication into my website" and get the link (<a href="https://jahia-prod.epfl.ch/page-59729-en.html">help</a>). The link looks like <code>https://infoscience.epfl.ch/curator/export/12345/?ln=en</code>.
                </p>
                <input class="widefat" type="text" id="publication_link" name="publication_link" placeholder="'.  ___("INFOSCIENCE URL") .'" value="'.$publication_link.'" />
            </div>';
    }

    static function save_meta_box_show_publication_link ($post_id, $post, $is_update)
    {
        if (array_key_exists('publication_link', $_POST)) {
            update_post_meta(
                $post_id,
                'publication_link',
                $_POST['publication_link']
            );
        }
    }

    /**
     * Alter the columns shown in the Actu list admin page
     * (Add the thumbnail column between the checkbox and the title)
     */
    static function alter_columns ($columns)
    {
        // https://stackoverflow.com/a/3354804/435004
        return array_merge(
            array_slice($columns, 0, 1, true),
            array('thumbnail' => __( 'Thumbnail' )),
            array_slice($columns, 1, 1, true),
            array('unit' => __( 'Unit' )),
            array_slice($columns, 2, count($columns) - 1, true));
    }

    static function render_people_thumbnail_column ($column, $post_id)
    {
        if ($column !== 'thumbnail') return;
        $person = Person::get($post_id);
        if (! $person) return;

        $src = $person->get_image_url();
        if (! $src) return;

        $attrs = 'width="100px" ';
        echo sprintf("<img src=\"%s\" %s/>", $src, $attrs);
    }

    static function render_people_unit_column  ($column, $post_id) {
        if ($column !== 'unit') return;
        $person = Person::get($post_id);
        if (! $person) return;

        $unit = $person->get_unit_long();
        if (! $unit) return;

        echo $unit;
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
     * class method exists, are checked; then the class method is called,
     * unless already done in this request cycle.
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

    /**
     * Render all meta boxes configured to show up after the editor.
     */
    static function meta_boxes_after_editor ($post)
    {
        if ($post->post_type !== Person::get_post_type()) return;
        do_meta_boxes(get_current_screen(), 'after-editor', $post);
    }


}

PersonController::hook();
