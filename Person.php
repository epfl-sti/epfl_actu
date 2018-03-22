<?php // -*- web-mode-code-indent-offset: 4; -*-

namespace EPFL\WS\Persons;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(__DIR__ . "/Lab.php");
use \EPFL\WS\Labs\Lab;

require_once(__DIR__ . "/inc/ldap.inc");
use \EPFL\WS\LDAPClient;

require_once(__DIR__ . "/inc/scrape.inc");
use function \EPFL\WS\scrape;

require_once(__DIR__ . "/inc/title.inc");
use \EPFL\WS\Persons\NoSuchTitleException;
use \EPFL\WS\Persons\Title;

require_once(__DIR__ . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

require_once(__DIR__ . "/inc/auto-fields.inc");
use \EPFL\WS\AutoFields;
use \EPFL\WS\AutoFieldsController;

require_once(dirname(__FILE__) . "/inc/batch.inc");
use function \EPFL\WS\run_every;
use \EPFL\WS\BatchTask;

function ends_with($haystack, $needle)
{
    $length = strlen($needle);

    return $length === 0 ||
    (substr($haystack, -$length) === $needle);
}

/**
 * True if we are on the "/post-new.php" page.
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

    // Auto fields
    const SCIPER_META             = 'sciper';
    const DN_META                 = 'dn';
    const GIVEN_NAME_META         = 'givenName';
    const SURNAME_META            = 'surname';
    const EMAIL_META              = 'mail';
    const PROFILE_URL_META        = 'profile';
    const POSTAL_ADDRESS_META     = 'postaladdress';
    const ROOM_META               = 'room';
    const PHONE_META              = 'phone';
    const OU_META                 = 'epfl_ou';
    const UNIT_QUAD_META          = 'unit_quad';
    const TITLE_CODE_META         = 'title_code';
    const LAB_UNIQUE_ID_META      = 'epfl_person_lab_id';
    const THUMBNAIL_META          = 'epfl_person_external_thumbnail';
    // User-editable field
    const PUBLICATION_LINK_META   = 'publication_link';

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
        return get_post_meta($this->ID, self::SCIPER_META, true);  // Cached by WP
    }

    public function get_full_name ()
    {
        return $this->wp_post()->post_title;
    }

    public function get_given_name ()
    {
        return get_post_meta($this->ID, self::GIVEN_NAME_META, true);
    }

    public function get_surname ()
    {
        return get_post_meta($this->ID, self::SURNAME_META, true);
    }

    public function get_publication_link ()
    {
        return get_post_meta($this->ID, self::PUBLICATION_LINK_META, true);
    }

    public function set_publication_link ($link) {
        $this->_update_meta(array(
            self::PUBLICATION_LINK_META => $link
        ));
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
                self::SCIPER_META => $sciper,
            )
        );
        AutoFields::of(get_called_class())->append(array(self::SCIPER_META));

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
                'key'     => self::SCIPER_META,
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

    public static function foreach ($callback)
    {
        $all = new \WP_Query(array(
            'post_type'      => Person::get_post_type(),
            'posts_per_page' => -1
        ));
        while ($all->have_posts()) {
            $all->next_post();
            $person = static::get($all->post);
            if ($person) {
                call_user_func($callback, $person);
            }
        }
    }

    function get_title ()
    {
        $title_code = get_post_meta($this->ID, "title_code", true);
        return $title_code ? new Title($title_code) : null;
    }

    public function get_dn ()
    {
        return get_post_meta($this->ID, self::DN_META, true);
    }

    public function get_mail ()
    {
        return get_post_meta($this->ID, self::EMAIL_META, true);
    }

    public function get_profile_url ()
    {
        return get_post_meta($this->ID, self::PROFILE_URL_META, true);
    }

    public function get_postaladdress ()
    {
        return get_post_meta($this->ID, self::POSTAL_ADDRESS_META, true);
    }

    public function get_room ()
    {
        return get_post_meta($this->ID, self::ROOM_META, true);
    }

    public function set_room ($room)
    {
        return update_post_meta($this->ID, self::ROOM_META, $room);
    }

    public function get_phone ()
    {
        return get_post_meta($this->ID, self::PHONE_META, true);
    }

    public function set_phone ($phone)
    {
        return update_post_meta($this->ID, self::PHONE_META, $phone);
    }

    public function get_unit ()
    {
        return get_post_meta($this->ID, self::OU_META, true);
    }

    public function get_unit_long ()
    {
        return get_post_meta($this->ID, self::UNIT_QUAD_META, true);
    }

    public function sync ()
    {
        if ($this->_synced_already) { return; }
        $this->update_from_ldap();
        $this->import_image_from_people();
        if (! $this->get_bio()) {
            $this->update_bio_from_people();
        }

        $this->_synced_already = true;
        $more_meta = apply_filters('epfl_person_additional_meta', array(), $this);
        if ($more_meta) {
            $this->_update_meta($more_meta);
        }

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
        $entry = $entries[0];

        $meta = array();

        if ($title = Title::from_ldap($entries)) {
            $meta[self::TITLE_CODE_META] = $title->code;
        }

        if ($given_name = $entry["givenname"][0]) {
            $meta[self::GIVEN_NAME_META] = $given_name;
        }

        if ($surname = $entry["sn"][0]) {
            $meta[self::SURNAME_META] = $surname;
        }

        if ($mail = $entry["mail"][0]) {
            $meta[self::EMAIL_META] = $mail;
        }

        if ($profile = $entry["labeleduri"][0]) {
            $meta[self::PROFILE_URL_META] = explode(" ", $profile)[0];
        }

        if ($postaladdress = $entry["postaladdress"][0]) {
            $meta[self::POSTAL_ADDRESS_META] = $postaladdress;
        }

        if ($roomnumber = $entry["roomnumber"][0]) {
          $meta[self::ROOM_META] = $roomnumber;
        }

        if ($telephonenumber = $entry["telephonenumber"][0]) {
            $meta[self::PHONE_META] = $telephonenumber;
        }

        if ($dn = $entry["dn"]) {
            $meta[self::DN_META] = $dn;
            $bricks = explode(',', $dn);
            // construct a unit string, e.g. EPFL / STI / STI-SG / STI-IT
            $meta[self::UNIT_QUAD_META] = strtoupper(explode('=', $bricks[4])[1]) . " / " . strtoupper(explode('=', $bricks[3])[1]) . " / " . strtoupper(explode('=', $bricks[2])[1]) . " / " . strtoupper(explode('=', $bricks[1])[1]);
        }

        $unit = $entry["ou"][0];
        $lab = $this->get_lab();
        if ($lab) {
            $lab->sync();
            if ($unit !== $lab->get_abbrev()) {
                $lab = null;  // And try again below
            }
        }
        if (! $lab) {
            $lab = Lab::get_or_create_by_name($unit);
            $meta[self::LAB_UNIQUE_ID_META] = $lab->get_unique_id();
            $lab->sync();
        }

        $update = array(
            'ID'         => $this->ID,
            // We want a language-neutral post_title so we can't
            // work in the greeting - Filters will be used for that instead.
            'post_title' => $entry['cn'][0],
        );
        wp_update_post($update);
        $this->_update_meta($meta);
    }

    public function get_lab ()
    {
        $unique_id = get_post_meta($this->ID, self::LAB_UNIQUE_ID_META, true);
        if (! $unique_id) { return; }
        return Lab::get_by_unique_id($unique_id);
    }

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
        $this->_update_meta(array(self::THUMBNAIL_META => $src));
        return $this;
    }

    public function get_bio()
    {
        return do_shortcode($this->wp_post()->post_content);
    }

    public function update_bio_from_people ()
    {
        $dom = $this->_get_bio_dom();
        $xpath = new \DOMXpath($dom);
        $bio_nodes = $xpath->query("//div[@id='content']/h3[text()='Biography']/following-sibling::node()");
        $biography = '';
        foreach($bio_nodes as $element){
            if (in_array($element->nodeName, array("h1", "h2", "h3"))) break;
            $newdoc = new \DOMDocument();
            $cloned = $element->cloneNode(TRUE);
            $newdoc->appendChild($newdoc->importNode($cloned,TRUE));
            $allowed_html = array(
                                      'a' => array(
                                          'href' => array(),
                                          'title' => array()
                                      ),
                                      'br' => array(),
                                      'em' => array(),
                                      'strong' => array(),
                                      'p' => array(),
                                      'b' => array(),
                                      'i' => array(),
                                      'code' => array(),
                                      'pre' => array(),
                                  );
            $biography .= wp_kses($newdoc->saveHTML(), $allowed_html);
        }

        $biography = apply_filters("epfl_person_bio", $biography, $this);

        $this->set_bio($biography);
    }

    function set_bio ($biography)
    {
        $update = array(
            'ID'           => $this->ID,
            'post_content' => $biography
        );
        wp_update_post($update);
    }

    public function get_lab_website_url ()
    {
        return $this->get_lab()->get_website_url();
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

    private function _get_bio_dom()
    {
        if (! $this->_bio_dom) {
            $bio_html = file_get_contents(sprintf(
                "https://people.epfl.ch/cgi-bin/people?id=%d&op=bio&lang=en&cvlang=%s",
                $this->get_sciper(),
                "en"  // TODO: Offer multilingual bios
            ));
            $dom = new \DOMDocument();
            // https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
            @$dom->loadHTML(mb_convert_encoding($bio_html, 'HTML-ENTITIES', 'UTF-8'));
            $this->_bio_dom = $dom;
        }
        return $this->_bio_dom;
    }

    private function _update_meta($meta_array)
    {
        $auto_fields = AutoFields::of(get_called_class());
        foreach ($meta_array as $k => $v) {
            update_post_meta($this->ID, $k, $v);
            $auto_fields->append(array($k));
        }
    }

    public function get_title_as_text ()
    {
        if ($this->get_title()) {
            return $this->get_title()->localize();
        }
    }

    public function get_title_and_full_name ()
    {
        if ($this->get_title()) {
            return $this->get_title()->as_greeting() . " " . $this->get_full_name();
        } else {
            return $this->get_full_name();
        }
    }

    public function get_short_title_and_full_name ()
    {
        if ($this->get_title()) {
            return $this->get_title()->as_short_greeting() . " " . $this->get_full_name();
        } else {
            return $this->get_full_name();
        }
    }

    public function as_thumbnail ()
    {
      $alt = $this->get_title_and_full_name();
      return get_the_post_thumbnail($this->wp_post(), 'post-thumbnail',
        array(
          'class' => 'card-img-top',
          'alt'   => $alt,
          'title' => $alt
        ));
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
        add_action( 'init', array(get_called_class(), 'register_post_type'));

        /* Behavior of Persons on the main site */
        add_filter( 'post_thumbnail_html',
                   array(get_called_class(), 'filter_post_thumbnail_html'), 10, 5);
        add_filter( 'single_template',
                   array(get_called_class(), 'maybe_use_default_template'), 99);

        /* Behavior of Persons in the admin aera */
        (new AutoFieldsController(Person::class))->hook();
        // Add you column name here to make it sortable
        add_filter( sprintf('manage_edit-%s_sortable_columns', Person::get_post_type()),
                   array(get_called_class(), 'make_people_columns_sortable'));

        /* Customize the edit form */
        add_action( 'edit_form_after_title',
                   array(get_called_class(), 'meta_boxes_above_editor'));
        add_action( 'edit_form_after_editor',
                   array(get_called_class(), 'meta_boxes_after_editor'));

        add_action( sprintf('save_post_%s', Person::get_post_type()),
                   array(get_called_class(), 'save_meta_boxes'), 10, 3);
        add_action( 'admin_notices',
                   array(get_called_class(), 'maybe_show_admin_error'));

        add_action( 'admin_enqueue_scripts', array(get_called_class(), 'init_styles'));
        add_action( sprintf('manage_%s_posts_columns', Person::get_post_type()) , array(get_called_class(), 'alter_columns'));
        add_action( sprintf('manage_%s_posts_custom_column', Person::get_post_type()),
                    array(get_called_class(), 'render_people_thumbnail_column'), 10, 2);
        add_action( sprintf('manage_%s_posts_custom_column', Person::get_post_type()),
                    array(get_called_class(), 'render_people_unit_column'), 10, 2);
        add_action( 'pre_get_posts', array(get_called_class(), 'sort_people_unit_column') );
        add_action( sprintf('manage_%s_posts_custom_column', Person::get_post_type()),
                    array(get_called_class(), 'render_people_publication_column'), 10, 2);
        add_action( 'pre_get_posts', array(get_called_class(), 'sort_people_publication_column') );

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
                basename(__DIR__) . '/languages');
        });

        run_every(600, array(get_called_class(), "sync_all"));
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
                'menu_position'      => 41,
                'query_var'          => true,
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'taxonomies'         => array('category', 'post_tag'),
                'menu_icon'          => 'dashicons-welcome-learn-more',  // Mortar hat
                'supports'           => array( 'editor', 'thumbnail' ),
                'register_meta_box_cb' => array(get_called_class(), 'add_meta_boxes')
            ));
    }

    /**
     * Style for admin page.
     */
    function init_styles () {
        if (is_admin()) {
            wp_register_style('person', plugins_url( 'css/person.css', __FILE__ ) );
            wp_enqueue_style('person');
        }
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
        if ($orig_html) {
            # Person already has a thumbnail; use it
            return $orig_html;
        }
        
        $person = Person::get($post_id);
        if (! $person) return '';
        $src = $person->get_image_url();
        if (! $src) return '';

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
     * Serve https://YOURWORDPRESS/epfl-person/123456 (where 123456 is a SCIPER)
     * out of a default template that can be overridden by the theme.
     *
     * This applies the "Add single-{post_type}-{slug}.php to Template
     * Hierarchy" recipe from the WordPress Codex.
     */
    static function maybe_use_default_template ($single_template) {
        $object = get_queried_object();
        if ($object->post_type != Person::get_post_type()) { return $single_template; }
        if (file_exists($single_template)) {
            // Some other filter, theme or plug-in already found the file; don't interfere.
            return $single_template;
        }
        return __DIR__ . "/example-templates/content-single-" . Person::get_post_type() . ".php";
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
        (new AutoFieldsController(Person::class))->add_meta_boxes();
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
                ->sync();
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
        Person::get($post_id)->sync();
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
            Person::get($post_id)->set_publication_link($_POST['publication_link']);
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
            array('publication' => __( 'Pub.' )),
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

    static function render_people_unit_column ($column, $post_id) {
        if ($column !== 'unit') return;
        $person = Person::get($post_id);
        if (! $person) return;

        $unit = $person->get_unit_long();
        if (! $unit) return;

        echo $unit;
    }

    static function sort_people_unit_column ($query) {
        if ( ! is_admin() ) return;

        $orderby = $query->get( 'orderby' );

        if ( 'unit' == $orderby ) {
            $query->set( 'meta_key', 'unit_quad' );
            $query->set( 'orderby', 'meta_value' );
        }

    }

    static function render_people_publication_column ($column, $post_id) {

        if ($column !== 'publication') return;
        $person = Person::get($post_id);
        if (! $person) return;

        $pl = $person->get_publication_link();
        if (! $pl) {
            echo '<input type="checkbox" id="publication_' . $post_id . '" disabled="true" />';
        } else {
            echo '<input type="checkbox" id="publication_' . $post_id . '" checked="checked" disabled="true" title="'. $pl .'"/>';
        }
    }

    // Help: https://www.ractoon.com/2016/11/wordpress-custom-sortable-admin-columns-for-custom-posts/
    // https://code.tutsplus.com/articles/quick-tip-make-your-custom-column-sortable--wp-25095
    static function sort_people_publication_column ($query) {
        if ( ! is_admin() ) return;

        $orderby = $query->get( 'orderby' );

        if ( 'publication' == $orderby ) {
            $query->set( 'meta_key', 'publication_link' );
            $query->set( 'orderby', 'meta_value' );
        }

    }

    // https://codex.wordpress.org/Plugin_API/Filter_Reference/manage_edit-post_type_columns
    static function make_people_columns_sortable  ($columns) {
        $columns['publication'] = 'publication';
        $columns['unit'] = 'unit';
        return $columns;
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

    /**
     * Periodically refresh all Persons (and indirectly, Labs)
     */
    static function sync_all ()
    {
        (new BatchTask())
            ->set_banner("Syncing all Persons and their Labs")
            ->set_prometheus_labels(array(
                'kind' => Person::get_post_type()
            ))
            ->run(function() {
                Person::foreach(function($person) {
                    $person->sync();
                });
            });
    }
}

PersonController::hook();
