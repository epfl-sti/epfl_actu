<?php // -*- web-mode-code-indent-offset: 4; -*-

namespace EPFL\WS\Persons;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

use \Exception;

require_once(__DIR__ . "/Lab.php");
use \EPFL\WS\Labs\Lab;

require_once(__DIR__ . "/inc/base-classes.inc");
use \EPFL\WS\Base\UniqueKeyTypedPost;
use \EPFL\WS\Base\CustomPostTypeController;

require_once(__DIR__ . "/inc/ldap.inc");
use \EPFL\WS\LDAPClient;
use \EPFL\WS\LDAPException;

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

require_once(dirname(__FILE__) . "/inc/related.inc");
use \EPFL\WS\Related\Related;
use \EPFL\WS\Related\RelatedController;

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

class SCIPERException extends Exception {
    function __construct ($sciper)
    {
        $this->sciper = $sciper;
    }
}

class PersonNotFoundException extends SCIPERException
{
    function as_text ()
    {
        return sprintf(
            ___('Person with SCIPER %d not found'),
            $this->sciper);

    }
}
class PersonAlreadyExistsException extends SCIPERException
{
    function as_text ()
    {
        return sprintf(
            ___('A person with SCIPER %d already exists'),
            $this->sciper);
    }
}

class DuplicatePersonException extends SCIPERException
{
    function as_text ()
    {
        return sprintf(
            ___('Multiple persons found with SCIPER %d'),
            $this->sciper);
    }
}

class Person extends UniqueKeyTypedPost
{
    const SLUG = "epfl-person";

    // Auto fields
    const SCIPER_META                = 'sciper';
    const DN_META                    = 'dn';
    const GIVEN_NAME_META            = 'givenName';
    const SURNAME_META               = 'surname';
    const DISPLAY_NAME_META          = 'displayName';
    const EMAIL_META                 = 'mail';
    const PROFILE_URL_META           = 'profile';
    const POSTAL_ADDRESS_META        = 'postaladdress';
    const ROOM_META                  = 'room';
    const PHONE_META                 = 'phone';
    const OU_META                    = 'epfl_ou';
    const UNIT_QUAD_META             = 'unit_quad';
    const TITLE_CODE_META            = 'title_code';
    const OVERRIDDEN_TITLE_CODE_META = 'override_title_code';
    const LAB_UNIQUE_ID_META         = 'epfl_person_lab_id';
    const THUMBNAIL_META             = 'epfl_person_external_thumbnail';

    // User-editable fields
    const PUBLICATION_LINK_META      = 'publication_link';
    const KEYWORDS_META              = 'research_keywords';
    const RESEARCH_INTERESTS_META    = 'research_interests_html';

    static function _is_user_editable_field ($field)
    {
        return (false !== array_search($field, array(
            self::PUBLICATION_LINK_META,
            self::KEYWORDS_META,
            self::RESEARCH_INTERESTS_META)));
    }

    static function get_post_type ()
    {
        return self::SLUG;
    }

    public function get_sciper ()
    {
        return 0 + get_post_meta($this->ID, self::SCIPER_META, true);  // Cached by WP
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

    public function set_publication_link ($link)
    {
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
        return static::_get_by_unique_key(array(self::SCIPER_META => $sciper));
    }

    function get_title ()
    {
        if ($title_code = $this->get_overridden_title()) return $title_code;

        $title_code = get_post_meta($this->ID, self::TITLE_CODE_META, true);
        return $title_code ? new Title($title_code) : null;
    }

    function get_overridden_title ()
    {
        $title_code = get_post_meta($this->ID, self::OVERRIDDEN_TITLE_CODE_META, true);
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

    public function get_research_keywords ()
    {
        return get_post_meta($this->ID, self::KEYWORDS_META, true);
    }

    public function get_research_interests ()
    {
        return get_post_meta($this->ID, self::RESEARCH_INTERESTS_META, true);
    }

    public function set_research_interests ($newval)
    {
        update_post_meta($this->ID, self::RESEARCH_INTERESTS_META, $newval);
    }

    public function unset_research_interests ()
    {
        delete_post_meta($this->ID, self::RESEARCH_INTERESTS_META);
    }

    public function get_attribution_tag ()
    {
        return sprintf("ATTRIBUTION=SCIPER:%d", $this->get_sciper());
    }

    public function attributions ()
    {
        if (! $this->_attributions) {
            $this->_attributions = new Related($this->get_attribution_tag());
        }
        return $this->_attributions;
    }

    public function get_mentioned_tag ()
    {
        return sprintf("MENTIONED=SCIPER:%d", $this->get_sciper());
    }

    public function mentioned ()
    {
        if (! $this->_mentioned) {
            $this->_mentioned = new Related($this->get_mentioned_tag());
        }
        return $this->_mentioned;
    }

    public function sync ()
    {
        if ($this->_synced_already) { return; }
        $this->_synced_already = true;

        $this->update_from_ldap();
        $this->import_image_from_people();
        // See INC0264101
        // Just ignore the person's bio for now, until we get a cleaner way
        // to fetch it.
        /*
        if (! $this->get_bio()) {
            $this->update_bio_from_people();
        }
        */

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
            throw new PersonNotFoundException($this->get_sciper());
        }

        $best_entry = $entries[0];

        $meta = array();

        if ($title = Title::from_ldap($entries)) {
            $meta[self::TITLE_CODE_META] = $title->code;
        }

        if ($display_name = $best_entry["displayname"][0]) {
            $meta[self::DISPLAY_NAME_META] = $display_name;
        }

        if ($given_name = $best_entry["givenname"][0]) {
            $meta[self::GIVEN_NAME_META] = $given_name;
        }

        if ($surname = $best_entry["sn"][0]) {
            $meta[self::SURNAME_META] = $surname;
        }

        if ($mail = $best_entry["mail"][0]) {
            $meta[self::EMAIL_META] = $mail;
        }

        if ($profile = $best_entry["labeleduri"][0]) {
            $meta[self::PROFILE_URL_META] = explode(" ", $profile)[0];
        }

        if ($postaladdress = $best_entry["postaladdress"][0]) {
            $meta[self::POSTAL_ADDRESS_META] = $postaladdress;
        }

        if ($roomnumber = $best_entry["roomnumber"][0]) {
          $meta[self::ROOM_META] = $roomnumber;
        }

        if ($telephonenumber = $best_entry["telephonenumber"][0]) {
            $meta[self::PHONE_META] = $telephonenumber;
        }

        if ($dn = $best_entry["dn"]) {
            $meta[self::DN_META] = $dn;
            $bricks = explode(',', $dn);
            // construct a unit string, e.g. EPFL / STI / STI-SG / STI-IT
            $meta[self::UNIT_QUAD_META] = strtoupper(explode('=', $bricks[4])[1]) . " / " . strtoupper(explode('=', $bricks[3])[1]) . " / " . strtoupper(explode('=', $bricks[2])[1]) . " / " . strtoupper(explode('=', $bricks[1])[1]);
        }

        $unit = $best_entry["ou"][0];
        $lab = $this->get_lab();
        if ($lab) {
            $lab->sync();
            if ($unit !== $lab->get_abbrev()) {
                $lab = null;  // And try again below
            }
        }
        if (! $lab) {
            $lab = Lab::get_or_create_by_abbrev($unit);
            $meta[self::LAB_UNIQUE_ID_META] = $lab->get_unique_id();
            $lab->sync();
        }

        $update = array(
            'ID'         => $this->ID,
            // We want a language-neutral post_title so we can't
            // work in the greeting - Filters will be used for that instead.
            // We were using the cn as post_title, but some person get some
            // unofficial middlename. Hopefully the LDAP provide a displayname:
            'post_title' => $best_entry['displayname'][0],
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

    /**
     * @return a Lab object if this person is the head of their lab,
     * false if not, and null if the data is incomplete.
     */
    public function is_head_of_unit ()
    {
        $lab = $this->get_lab();
        if (! $lab) { return null; }
        $mgr = $lab->get_lab_manager();
        if (! $mgr) { return null; }
        if ($mgr->ID + 0 === $this->ID + 0) {
            return $lab;
        } else {
            return false;
        }
    }

    public function get_image_url ($size = null)
    {
        return get_post_meta($this->ID, self::THUMBNAIL_META, true);
    }

    public function import_image_from_people ()
    {
        $image_url = "https://people.epfl.ch/private/common/photos/links/" . $this->get_sciper() . '.jpg';
        if (@getimagesize($image_url)) {
            $this->_update_meta(array(self::THUMBNAIL_META => $image_url));
        } else {
            return null;
        }
        
        return $this;
    }

    public function get_bio()
    {
        return do_shortcode($this->wp_post()->post_content);
    }

    public function update_bio_from_people ()
    {
        // See INC0264101
        // Just ignore the person's bio for now, until we get a cleaner way
        // to fetch it.
        return true;
        
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
        $more_auto_fields = array();
        foreach ($meta_array as $k => $v) {
            if (! is_array($v)) {
                $v = [$v];
            }
            delete_post_meta($this->ID, $k);
            foreach ($v as $vitem) {
                add_post_meta($this->ID, $k, $vitem);
            }
            if (! $this->_is_user_editable_field($k)) {
                array_push($more_auto_fields, $k);
            }
        }
        $auto_fields->append($more_auto_fields);
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

    public function set_inactive ()
    {
        if (get_post_status_object('archive')) {
	    wp_update_post(array(
                'ID' => $this->wp_post()->ID,
                'post_status' => 'archive'));
        }
    }

    public function sync_or_inactivate ()
    {
        try {
            $this->sync();
        } catch (PersonNotFoundException $e) {
            $this->set_inactive();
        }
    }
}

/**
 * Instance-less class for the controller code (the C of MVC) that manages
 * persons, both on the main site and in wp-admin/
 */
class PersonController extends CustomPostTypeController
{
    static function get_model_class ()
    {
        return Person::class;
    }

    static function hook ()
    {
        parent::hook();

        $thisclass = get_called_class();
        add_action( 'init', array($thisclass, 'register_post_type'));

        /* Behavior of Persons on the main site */
        add_filter('post_thumbnail_html',
                   array($thisclass, 'filter_post_thumbnail_html'), 10, 5);
        add_filter('single_template',
                   array($thisclass, 'maybe_use_default_template'), 99);
        /* Behavior of Persons in the admin aera */
        (new AutoFieldsController(Person::class))->hook();

        /* Customize the edit form */
        static::hook_meta_boxes();
        add_action('admin_head',
                   array($thisclass, 'render_css_for_meta_boxes'));

        add_filter("is_protected_meta",
                   array($thisclass, 'additional_protected_metas'), 10, 3);

        /* Customize the list view */
        $post_type = static::get_post_type();
        add_filter(
            sprintf('manage_%s_posts_columns', $post_type),
            array($thisclass, '_filter_title_column_name'));

        static::add_thumbnail_column();
        static::column('rank')
              ->set_title(__('Title'))
              ->hook_after('title');

        static::column('unit')
              ->set_title(__('Unit'))
              ->make_sortable(array('meta_key' => 'unit_quad'))
              ->hook_after('rank');

        static::column('publication')
              ->set_title(__('Pub.'))
              ->make_sortable(array('meta_key' => 'publication_link'))
              ->hook_after('unit');

        static::add_editor_css("
#after-editor-sortables {
  padding-top: 1em;
}
");

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

        run_every("PersonController::sync_all", 3600,
                  array($thisclass, "sync_all"));
    }

    /**
     * Rewrite "Title" to "Name", as "Title" is used for academic rank.
     */
    function _filter_title_column_name ($columns)
    {
        $columns['title'] = ___('Name');
        return $columns;
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
                'supports'           => array('editor', 'thumbnail', 'custom-fields'),
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
     * Register the Person meta boxes using the superclass' @link add_meta_box,
     * which in turn calls back our render_meta_box_foo and save_meta_box_foo
     * methods.
     *
     * @see https://code.tutsplus.com/tutorials/how-to-create-custom-wordpress-writemeta-boxes--wp-20336
     */
    static function add_meta_boxes ()
    {
        if (is_form_new()) {
            self::add_meta_box('find_by_sciper', ___('Find person'));
        } else {
            self::add_meta_box('person_details', ___('Person details'));
            self::add_meta_box('person_related', ___('Related Content'));
            self::add_meta_box('research_interests', ___('Research interests'), 'after-editor');
        }
        self::add_meta_box('publication_link', ___('Infoscience URL'), 'after-editor');
        (new AutoFieldsController(Person::class))->add_meta_boxes();

        // https://wordpress.stackexchange.com/a/90745/132235
        remove_meta_box('slugdiv', Person::get_post_type(), 'normal');
    }

    static function render_meta_box_find_by_sciper ($unused_post)
    {
        echo sprintf(
            '<input type="text" id="sciper" name="sciper" placeholder="%s"%s>',
            ___("SCIPER"),
            $_GET["SCIPER"] ? sprintf('value="%d"', $_GET["SCIPER"]) : '');
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
            static::admin_error($post_id, $e->getMessage());
        } catch (Exception $e) {
            // Fatal - Undo save
            wp_delete_post($post_id, true);
            $message = method_exists($e, "as_text") ? $e->as_text() : $e->getMessage();
            error_log(sprintf("%s: %s", get_class($e), $message));
            wp_die($message);
        }
    }

    static function render_meta_box_person_related ($the_post)
    {
        $person = Person::get($the_post);
        print sprintf(___('<h3>Tagged with <code>%s</code></h3>'), $person->get_attribution_tag());
        RelatedController::render_for_meta_box($person->attributions());
        print sprintf(___('<h3>Tagged with <code>%s</code></h3>'), $person->get_mentioned_tag());
        RelatedController::render_for_meta_box($person->mentioned());
    }

    static function render_meta_box_person_details ($the_post)
    {
        global $post; $post = $the_post; setup_postdata($post);
        $person = Person::get($post->ID);
        $sciper = $person->get_sciper();
        $title = $person->get_title();
        $localized_title = ($title) ? $title->localize() : "";
        $greeting = $title ? sprintf("%s ", $title->as_greeting()) : "";
        ?><h1><?php echo $greeting; the_title(); ?></h1><?php

        if ($lab = $person->is_head_of_unit()) {
          printf(
              '<h2>%s, <a href="/wp-admin/post.php?post=%d&action=edit">%s</a></h2>',
              $localized_title,
              $lab->ID,
              $lab->get_abbrev()
          );
        } else {
          printf('<h2>%s</h2>', $title_str);
        }
        printf(
            '<h2><a href="/epfl-person/%d">SCIPER %d</a> (<a href="https://people.epfl.ch/%d">school directory</a>)</h2>' .
            "\n" .
            '<div class="people-picture">%s</div>' .
            "\n",
            $sciper, $sciper, $sciper,
            get_the_post_thumbnail());
    }

    static function save_meta_box_person_details ($post_id, $post, $is_update)
    {
        // Strictly speaking, this meta box has no state to change (for now).
        // However, it makes sense to schedule a sync from here, so that
        // it only happens on existing Person records (with a SCIPER set)
        static::call_sync_on_save();
    }

    const RESEARCH_INTERESTS_METABOX_FIELD = 'epfl_ws_research_interests';

    /**
     * Display the "research_interests_html" custom field in an HTML editor.
     */
    static function render_meta_box_research_interests ($post)
    {
        if (! ($person = Person::get($post))) return;

        $interests = $person->get_research_interests();

        // https://wordpress.stackexchange.com/a/117253/132235
        wp_editor($interests, static::RESEARCH_INTERESTS_METABOX_FIELD, array(
            'wpautop'       => true,
            'media_buttons' => false,
            'teeny'         => true
        ));
    }

    static function save_meta_box_research_interests ($post_id, $post, $is_update)
    {
        if (! ($person = Person::get($post))) {
            error_log("Hmm, saving meta box for nonexisting Person? ($post_id)");
            return;
        }
        // https://wordpress.stackexchange.com/a/117253/132235 ditto
        if ($newval = $_POST[static::RESEARCH_INTERESTS_METABOX_FIELD]) {
            $person->set_research_interests($newval);
        } else {
            $person->unset_research_interests();
        }
    }

    /**
     * Render publication_link meta boxes after the editor.
     */
    static function render_meta_box_publication_link ($post)
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

    static function save_meta_box_publication_link ($post_id, $post, $is_update)
    {
        if (! ($infoscience_link = $_POST['publication_link'])) { return; }
        Person::get($post_id)->set_publication_link($infoscience_link);
    }

    static function render_rank_column ($person)
    {
        echo $person->get_title_as_text();
        if ($person->get_overridden_title()) {
            echo "<br/><i>";
            echo ___("Explicitly set");
            echo "</i>";
        }
    }

    /**
     * Called automatically by the ::columns() mechanism to render
     * the unit column.
     *
     * Based on @link Person::get_unit_long
     */
    static function render_unit_column ($person) {
        $unit = $person->get_unit_long();
        if (! $unit) return;

        echo $unit;
    }

    /**
     * Called automatically by the ::columns() mechanism to render
     * the "Pub." column.
     *
     * Render an empty or checked checkbox, depending on whether this
     * Person has an infoscience link set up.
     */
    static function render_publication_column ($person) {
        $pl = $person->get_publication_link();
        if (! $pl) {
            echo '<input type="checkbox" id="publication_' . $post_id . '" disabled="true" />';
        } else {
            echo '<input type="checkbox" id="publication_' . $post_id . '" checked="checked" disabled="true" title="'. $pl .'"/>';
        }
    }


    static function render_css_for_meta_boxes ()
    {
        ?>
<style>
#epfl-person-nonce-meta_box_show_person_details img {
        max-width: 40%;
        height: auto;
}
</style>
        <?php
    }

    /**
     * Make it so "research_interests_html" and "publication_link"
     * don't appear as mutable custom fields.
     *
     * These fields can be edited out of their own meta boxes instead
     * (see @link render_meta_box_research_interests and @link
     * render_meta_box_publication_link).
     *
     * This static method is installed as a filter on @link
     * is_protected_meta at @link hook time.
     */
    static function additional_protected_metas ($is_protected,
                                                $meta_key, $unused_meta_type)
    {
        global $post;
        if (! Person::get($post)) return $is_protected;
        if ($meta_key === Person::KEYWORDS_META) return false;
        if (Person::_is_user_editable_field($meta_key)) return true;
        return $is_protected;
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
                    $person->sync_or_inactivate();
                });
            });
    }
}

PersonController::hook();
