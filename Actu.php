<?php

/**
 * "Actu" custom post type and taxonomy.
 *
 * For each entry in actus.epfl.ch that the WordPress administrators
 * are interested in, there is a local copy as a post inside the
 * WordPress database. This allows e.g. putting actus news into the
 * newsletter or using the full-text search on them.
 *
 * The "Actu" custom post type integrates with WP Subtitles, if
 * installed (https://wordpress.org/plugins/wp-subtitle/). Note that
 * only Actu items fetched *after* WP Subtitles is installed, can get
 * a subtitle.
 */

namespace EPFL\WS\Actu;

use WP_Query;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/base-classes.php");

require_once(dirname(__FILE__) . "/inc/i18n.php");
require_once(dirname(__FILE__) . "/inc/image-size.php");
use function EPFL\WS\get_image_size;

/**
 * Object model for Actu streams
 *
 * One stream corresponds to one so-called "term" in the
 * 'epfl-actu-channel' WordPress taxonomy. Each stream has an API URL
 * from which news are continuously fetched.
 */
class ActuStream extends \EPFL\WS\Base\StreamedTaxonomy
{
    static function get_taxonomy_slug ()
    {
        return 'epfl-actu-channel';
    }

    static function get_channel_api_url_slug ()
    {
        return "epfl_actu_channel_api_url";
    }

    static function get_post_class ()
    {
        return Actu::class;
    }
}

/**
 * Object model for Actu posts
 *
 * There is one instance of the Actu class for every unique piece of
 * news (identified by the "news_id" and "translation_id" API fields,
 * and materialized as a WordPress "post" object of post_type ==
 * 'epfl-accred').
 */
class Actu
{
    var $ID;
    var $news_ID;
    var $translation_ID;

    static function get_post_type ()
    {
        return 'epfl-actu';
    }

    /**
     * Private constructor — Call @link get_or_create instead
     */
    function __construct ($id)
    {
        $this->ID = $id;
    }

    /**
     * Retrieve one Actu item per its primary key components.
     *
     * If the corresponding post does not exist in-database, it will
     * be created with no contents besides the `meta_input` made up
     * of $news_id and $translation_id (but see @link update).
     */
    static function get_or_create ($news_id, $translation_id)
    {
        $search_query = new WP_Query(array(
           'post_type'  => Actu::get_post_type(),
           'meta_query' => array(
               'relation' => 'AND',
               array(
                   'key'     => 'news_id',
                   'value'   => $news_id,
                   'compare' => '='
               ),
                array(
                   'key'     => 'translation_id',
                   'value'   => $translation_id,
                   'compare' => '='
               )
           )
        ));
        $results = $search_query->get_posts();
        if (0 === sizeof($results)) {
            $id = wp_insert_post(array(
                "post_type" => Actu::get_post_type(),
                "post_status" => "publish",
                "meta_input" => array(
                    "news_id" => $news_id,
                    "translation_id" => $translation_id
                )), true);
            $self = new Actu($id);
        } else {
            $self = new Actu($results[0]->ID);
        }
        $self->news_id = $news_id;
        $self->translation_id = $translation_id;
        return $self;
    }

    /**
     * Retrieve one Actu item, only if it does exist.
     *
     * @return an instance of Actu or null.
     */
    static function get ($post_or_post_id)
    {
        if (is_object($post_or_post_id)) {
            if ($post_or_post_id->post_type !== Actu::get_post_type()) return;
            $post_id = $post_or_post_id->ID;
        } else {
            $post_id = $post_or_post_id;
            if (get_post_type($post_id) !== Actu::get_post_type()) return;
        }
        $theclass = get_called_class();
        $that = new $theclass($post_id);
        if (is_object($post_or_post_id)) {
            $that->_wp_post = $post_or_post_id;
        }
        return $that;
    }

    /**
     * Mark in the database that this piece of news was found by
     * fetching from $stream_object.
     *
     * This is materialized by a relationship in the
     * wp_term_relationships SQL table, using the @link
     * wp_set_post_terms API.
     */
    function add_found_in_stream($stream_object)
    {
        $terms = wp_get_post_terms(
            $this->ID, $stream_object->get_taxonomy_slug(),
            array('fields' => 'ids'));
        if (! in_array($stream_object->ID, $terms)) {
            wp_set_post_terms($this->ID, array($stream_object->ID),
                              $stream_object->get_taxonomy_slug(),
                              true);  // Append
        }
    }

    const THUMBNAIL_META  = "epfl_actu_external_thumbnail";
    const MAX_HEIGHT_META = "epfl_actu_external_img_max_height";
    const MAX_WIDTH_META  = "epfl_actu_external_img_max_width";

    /**
     * Update this news post with $details, overwriting most of the
     * mutable state of it.
     *
     * Only taxonomy terms (managed by @link add_found_in_stream) are
     * left unchanged.
     */
    function update ($details)
    {
        $this->_post_meta = $meta = array();
        foreach (["news_id", "translation_id", "news_thumbnail_absolute_url",
                  "absolute_slug", "video", "news_has_video", "visual_and_thumbnail_description"]
                 as $keep_this_as_meta)
        {
            if ($details[$keep_this_as_meta]) {
                $meta[$keep_this_as_meta] = $details[$keep_this_as_meta];
            }
        }

        $matched = array();
        if (preg_match('#youtube.com/embed/([^/?]+)#', $details["video"],
                       $matched)) {
            $meta["youtube_id"] = $matched[1];
        }

        if ($meta["youtube_id"]) {
            // The "right" thumbnail for a YouTube video is the one
            // YouTube serves - See also
            // https://stackoverflow.com/a/2068371/435004
            $meta[self::THUMBNAIL_META] = sprintf(
                "https://img.youtube.com/vi/%s/default.jpg",
                $meta["youtube_id"]);
        } elseif ($meta["news_thumbnail_absolute_url"]) {
            $news_thumbnail_url = $meta["news_thumbnail_absolute_url"];
            $meta[self::THUMBNAIL_META]  = $news_thumbnail_url;
            $max_size = get_image_size($this->get_image_url("8000x6000"));
            $meta[self::MAX_HEIGHT_META] = $max_size["height"];
            $meta[self::MAX_WIDTH_META]  = $max_size["width"];
        }

        // Support for WP Subtitle plugin
        if (class_exists("WPSubtitle")) {
            $subtitle = $this->extract_subtitle($details);
            if ($subtitle && $subtitle !== $details["title"]) {
                // Like private function get_post_meta_key() in subtitle.php
                $subtitle_meta_key = apply_filters( 'wps_subtitle_key', 'wps_subtitle', $this->ID);
                $meta[$subtitle_meta_key] = $subtitle;
            }
        }

        // Polylang
        if (function_exists("pll_set_post_language")) {
            pll_set_post_language($this->ID, $details["language"]);
        }

        wp_update_post(
            array(
                "ID"            => $this->ID,
                "post_type"     => Actu::get_post_type(),
                "post_title"    => $details["title"],
                "post_excerpt"  => $details["subtitle"],
                "post_content"  => $details["text"],
                "meta_input"    => $meta
            )
        );

        $add_in_categories = array();
        $actu_cat = ActuCategory::get_by_actu_id(
            $details["news_category_id"],
            function ($terms) use ($details) {
                // Perhaps the returned categories are translations of each other?
                $filtered_terms = array();
                if (function_exists("pll_get_term")) {  // Polylang
                    foreach ($terms as $term) {
                        if (pll_get_term($term->term_id, $details["language"]) === $term->term_id) {
                            array_push($filtered_terms, $term);
                        }
                    }
                    return $filtered_terms;
                } else {
                    // Ah well, just go with the first one
                    return $terms;
                }
            }
        );
        if ($actu_cat) {
            array_push($add_in_categories, $actu_cat->ID());
        }

        if (count($add_in_categories)) {
            wp_set_post_categories($this->ID, $add_in_categories,
                                   /* $append = */ true);
        }
    }

    function _get_post_meta ()
    {
        if (! $this->_post_meta) {
            $this->_post_meta = array();
            foreach (get_post_meta($this->ID) as $key => $array) {
                // All meta keys are single-valued
                $this->_post_meta[$key] = $array[0];
            }
        }
        return $this->_post_meta;
    }

    function get_permalink ()
    {
        $meta = $this->_get_post_meta();
        return $meta["absolute_slug"];
    }

    function get_max_size ()
    {
        $meta = $this->_get_post_meta();
        if ($meta[self::MAX_HEIGHT_META] &&
            $meta[self::MAX_WIDTH_META]) {
            return array("height" => $meta[self::MAX_HEIGHT_META],
                         "width"  => $meta[self::MAX_WIDTH_META]);
        } else {
            return null;
        }
    }

    function get_youtube_id ()
    {
        return $this->_get_post_meta()["youtube_id"];
    }

    function wp_post ()
    {
        if (! $this->_wp_post) {
            $this->_wp_post = get_post($this->ID);
        }
        return $this->_wp_post;
    }

    /**
     * @return the URL for a server-side resized image of $size
     *
     * @param $size e.g. "1024x768". If omitted, utilize the thumbnail
     *        size as returned by the API.
     */
    function get_image_url ($size = null)
    {
        $meta = $this->_get_post_meta();
        $url = $meta[self::THUMBNAIL_META];
        if (! $url) return null;
        if (! $size) return $url;
        $matched = array();
        if (preg_match("/^(.*)\/(\d+x\d+)\.([a-zA-z]{1,6})$/", $url, $matched)) {
            return sprintf("%s/%s.%s", $matched[1], $size, $matched[3]);
        } else {
            return $url;
        }
    }

    /**
     * Extract a subtitle from the API's excerpt.
     *
     * The field named "subtitle" is generally unfit for use as a
     * subtitle in the WordPress sense — It is more like post_excerpt.
     * However, some subtitles on actu.epfl.ch do start with a short
     * sentence followed with a <br />. If that is the case, return it.
     */
    private function extract_subtitle ($details) {
        $matched = array();
        $max_subtitle_length = 80;
        if (preg_match("/^(.{1,$max_subtitle_length})<br/", $details["subtitle"], $matched)) {
            return trim($matched[1]);
        } elseif (preg_match("/^<p>(.{1,$max_subtitle_length})<\/p>/", $details["subtitle"], $matched)) {
            return trim($matched[1]);
        } else {
            return null;
        }
    }
}

/**
 * Configuration UI and WP callbacks for the actu stream taxonomy
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuStreamController
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_taxonomy'));
    }

    /**
     * Create Actu channels as a taxonomy.
     *
     * A "taxonomy" is a complicated word for a category of Actu
     * entries. Actu entries are grouped by "channels", i.e. the feed
     * they come from. Channels have names and host suitable metadata,
     * i.e. an API URL to fetch from.
     */
    static function register_taxonomy ()
    {
        $taxonomy_slug = ActuStream::get_taxonomy_slug();
        register_taxonomy(
            $taxonomy_slug,
            array( Actu::get_post_type() ),
            array(
                'hierarchical'      => false,
                'labels'            => array(
                'name'              => __x( 'News Channels', 'taxonomy general name'),
                'singular_name'     => __x( 'News Channel', 'taxonomy singular name'),
                'search_items'      => ___( 'Search News Channels'),
                'all_items'         => ___( 'All News Channels'),
                'edit_item'         => ___( 'Edit News Channel'),
                'update_item'       => ___( 'Update News Channel'),
                'add_new_item'      => ___( 'Add News Channel'),
                'new_item_name'     => ___( 'New Channel Name'),
                'menu_name'         => ___( 'News Channels'),
                ),
                'show_ui'           => true,
                'show_admin_column' => true,
                'query_var'         => true,
                'capabilities'      => array(
                    // Cannot change terms from Actu edit screen:
                    'assign_terms' => '__NEVER_PERMITTED__',
                    // Default permissions apply for the other operations
                ),
                'rewrite'           => array( 'slug' => $taxonomy_slug ),
            ));
        add_action("${taxonomy_slug}_add_form_fields", array(get_called_class(), "create_channel_widget"));
        add_action( "${taxonomy_slug}_edit_form_fields", array(get_called_class(), "update_channel_widget"), 10, 2);
        add_action( "created_${taxonomy_slug}", array(get_called_class(), 'edited_channel'), 10, 2 );
        add_action( "edited_${taxonomy_slug}", array(get_called_class(), 'edited_channel'), 10, 2 );
    }

    static function create_channel_widget ($taxonomy)
    {
        self::render_channel_widget(array("placeholder" => "https://actu.epfl.ch/api/jahia/channels/sti/news/en/?format=json", "size" => 40, "type" => "text"));
    }

    static function update_channel_widget ($term, $taxonomy)
    {
        ?><tr class="form-field actu-channel-url-wrap">
            <th scope="row">
                <label for="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>">
                    <?php echo ___('Actu Channel API URL'); ?>
                </label>
            </th>
            <td>
                <input id="<?php echo self::CHANNEL_WIDGET_URL_SLUG; ?>" name="<?php echo self::CHANNEL_WIDGET_URL_SLUG; ?>" type="text" size="40" value="<?php echo (new ActuStream($term))->get_url(); ?>" />
                <p class="description"><?php echo ___("Source URL of the JSON data. Use <a href=\"https://wiki.epfl.ch/api-rest-actu-memento/actu\" target=\"_blank\">actu-doc</a> for details."); ?></p>
            </td>
        </tr><?php
    }

    const CHANNEL_WIDGET_URL_SLUG = 'epfl_actu_channel_url';

    static function render_channel_widget ($input_attributes)
    {
      ?><div class="form-field term-wrap">
        <label for="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>"><?php echo ___('Actu Channel API URL'); ?></label>
        <input id="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>" name="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>" <?php
           foreach ($input_attributes as $k => $v) {
               echo "$k=" . htmlspecialchars($v) . " ";
           }?> />
       </div><?php
    }

    static function edited_channel ($term_id, $tt_id)
    {
        $stream = new ActuStream($term_id);
        $stream->set_url($_POST[self::CHANNEL_WIDGET_URL_SLUG]);
        $stream->sync();
    }
}

/**
 * WP configuration and callbacks for the EPFL Actu post type
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuController
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        $main_plugin_file = dirname(__FILE__) . "/EPFL-ws.php";
        register_activation_hook($main_plugin_file, array(get_called_class(), "register_caps"));
        register_deactivation_hook($main_plugin_file, array(get_called_class(), "deregister_caps"));

        // Behavior of Actu posts on the main site
        add_filter("post_thumbnail_html",
                   array(get_called_class(), "filter_post_thumbnail_html"), 10, 5);
        add_filter("post_type_link",
                   array(get_called_class(), "filter_post_link"), 10, 4);

        // Behavior of Actu posts in the wp-admin area
        add_action('admin_init', array(get_called_class(), 'make_subtitles_readonly_in_admin'), 0);
        add_action( sprintf('manage_%s_posts_columns', Actu::get_post_type()) , array(get_called_class(), "alter_columns"));
        add_action( sprintf('manage_%s_posts_custom_column', Actu::get_post_type()),
                    array(get_called_class(), "render_thumbnail_column"), 10, 2);
        add_action("edit_form_after_title", array(get_called_class(), "render_in_edit_form"));
        add_action("admin_enqueue_scripts", array(get_called_class(), "editor_css"));

        // Behavior of Actu posts in search results
        add_filter('pre_get_posts', array(get_called_class(), "pre_get_posts"));
    }

    /**
     * Make it so that actus pages exist.
     *
     * Under WordPress, almost everything publishable is a post.
     * register_post_type() is invoked to create a particular flavor
     * of posts that describe news.
     */
    static function register_post_type ()
    {
        register_post_type(
            Actu::get_post_type(),
            array(
                'labels'             => array(
                    'name'               => __x( 'EPFL News', 'post type general name' ),
                    'singular_name'      => __x( 'EPFL News', 'post type singular name' ),
                    'menu_name'          => __x( 'EPFL News', 'admin menu' ),
                    'name_admin_bar'     => __x( 'EPFL News', 'add new on admin bar' ),
                    'view_item'          => ___( 'View EPFL News Item' ),
                    'all_items'          => ___( 'All EPFL News for this site' ),
                    'search_items'       => ___( 'Search News' ),
                    'not_found'          => ___( 'No news found.' ),
                    'not_found_in_trash' => ___( 'No news found in Trash.' )
                ),
                'description'        => ___( 'EPFL News from news.epfl.ch' ),
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'query_var'          => true,
                // ad hoc access control, see (de|)register_caps() below:
                'capabilities'       => array(
                    'read'                 => 'read_epfl_actus',
                    // Name notwithstanding, this is actually the
                    // permission to see the list of actus.
                    'edit_posts'           => 'read_epfl_actus',
                    'create_posts'         => '__NEVER_PERMITTED__',

                    'edit_post'            => 'edit_epfl_actus',
                    'edit_private_posts'   => 'edit_epfl_actus',
                    'edit_published_posts' => 'edit_epfl_actus',
                    'assign_categories'    => 'edit_epfl_actus',
                    'assign_post_tags'     => 'edit_epfl_actus',
                    // One is not actually supposed to delete posts manually —
                    // This is just an escape hatch in case an ActuStream was
                    // deleted and referential integrity was breached.
                    'delete_posts'         => 'edit_epfl_actus',
                    'delete_private_posts' => 'edit_epfl_actus',
                    'delete_others_posts'  => 'edit_epfl_actus',
                ),
                'has_archive'        => true,
                'hierarchical'       => false,
                'taxonomies'         => array(ActuStream::get_taxonomy_slug(), 'category'),
                'menu_position'      => 24,
                'menu_icon'          => 'dashicons-megaphone',
                'supports'           => array('wps_subtitle')
            ));
    }

    const ROLES_THAT_MAY_VIEW_ACTUS = array('administrator', 'editor', 'author', 'contributor');
    const ROLES_THAT_MAY_MANAGE_ACTUS = array('administrator', 'editor');
    const ALL_ROLES = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
    const CAPS_FOR_VIEWERS = array(
        'read_epfl_actus',
    );
    const ALL_CAPS = array(
        'read_epfl_actus',
        'edit_epfl_actus',
        // Obsolete caps, that still need to be remmoved upon plugin deactivation:
        'read_epfl_actu',
        'delete_epfl_actu'
    );

    /**
     * Register permissions ("capabilities") on Actu posts.
     *
     * Called at plugin activation time.
     *
     * The permission map is made so that administrators and editors can view and
     * delete Actus, but not edit them.
     */
    static function register_caps ()
    {
        foreach (self::ROLES_THAT_MAY_VIEW_ACTUS as $role_name) {
            $role = get_role($role_name);
            foreach (self::CAPS_FOR_VIEWERS as $cap) {
                $role->add_cap($cap);
            }
        }
        foreach (self::ROLES_THAT_MAY_MANAGE_ACTUS as $role_name) {
            $role = get_role($role_name);
            foreach (self::ALL_CAPS as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    /**
     * De-register permissions ("capabilities") on Actu posts.
     *
     * Called at plugin deactivation time.
     */
    static function deregister_caps ()
    {
        foreach (self::ALL_ROLES as $role_name) {
            $role = get_role($role_name);
            foreach (self::ALL_CAPS as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    /**
     * Alter the columns shown in the Actu list admin page
     */
    static function alter_columns ($columns)
    {
        // https://stackoverflow.com/a/3354804/435004
        return array_merge(
            array_slice($columns, 0, 1, true),
            array('thumbnail' => __( 'Thumbnail' )),
            array_slice($columns, 1, count($columns) - 1, true));
    }

    static function render_thumbnail_column ($column, $post_id)
    {
        if ($column !== 'thumbnail') return;
        $thumbnail = get_the_post_thumbnail($post_id);
        if (! $thumbnail) return;

        $actu = Actu::get($post_id);
        if (! $actu) return;

        if ($actu->get_youtube_id()) {
            echo '<object style="width:160px;height:89px;float: none; clear: both; margin: 2px auto;" data="https://www.youtube.com/embed/'.$actu->get_youtube_id().'"></object>';
            printf("<p><a href=\"https://youtu.be/%s\">YouTube link</a></p>", $actu->get_youtube_id());
        } elseif ($orig_size = $actu->get_max_size()) {
            echo $thumbnail;
            printf("<p>Size: %dx%d</p>", $orig_size["width"], $orig_size["height"]);
        } else {
            echo $thumbnail;
        }

    }

    /**
     * Make Actu subtitles read-only by preventing WP Subtitles from
     * initializing in the case of epfl-actu posts.
     */
    static function make_subtitles_readonly_in_admin ()
    {
		$post_type = '';

		if ( isset( $_REQUEST['post_type'] ) ) {
			$post_type = sanitize_text_field( $_REQUEST['post_type'] );
		} elseif ( isset( $_GET['post'] ) ) {
			$post_type = get_post_type( absint( $_GET['post'] ) );
        }
        if ($post_type !== Actu::get_post_type()) return;

        remove_action('admin_init', array( 'WPSubtitle_Admin', '_admin_init' ) );
        // Add back the subtitle column:
        add_filter( 'manage_edit-' . $post_type . '_columns', array( 'WPSubtitle_Admin', 'manage_subtitle_columns' ) );
        add_action( 'manage_' . $post_type . '_posts_custom_column', array( 'WPSubtitle_Admin', 'manage_subtitle_columns_content' ), 10, 2 );
    }

    /**
     * Arrange for get_the_post_thumbnail() to return the external thumbnail for Actus.
     *
     * This is set as a filter for WordPress' @link post_thumbnail_html hook. Note that
     * it isn't as easy to hijack the return value of @link get_the_post_thumbnail_url
     * in this way (but you can always call the @link get_image_url instance
     * method on an Actu object).
     *
     * @return An <img /> tag with suitable attributes
     *
     * @param $orig_html The HTML that WordPress intended to return as
     *                   the picture (unused, as it will typically be
     *                   empty — Actu objects lack attachments)
     *
     * @param $post_id   The post ID to compute the <img /> for
     *
     * @param $size      The requested size, in WordPress notation (either the
     *                   name of a well-known or declared size, or a [$height,
     *                   $width] array)
     *
     * @param $attr      Associative array of HTML attributes. If "class" is
     *                   not specified, the default "wp-post-image" is used
     *                   to match the WordPress behavior for local (attached)
     *                   images.
     */
    static function filter_post_thumbnail_html ($orig_html, $post_id, $unused_thumbnail_id,
                                                $size, $attr)
    {
        $actu = Actu::get($post_id);
        if (! $actu) return $orig_html;

        // Actu images are resizable server-side
        // TODO: we could actually interpret $size in a much finer way
        if (($size === "full" || $size === "large")) {
            $src = $actu->get_image_url("2048x1152");
        } else {
            $src = $actu->get_image_url();
        }
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
     * Serve the permalink from actu.epfl.ch instead of our own.
     *
     * Mostly, we keep the full text of the article in-database for the search engine.
     */
    static function filter_post_link ($orig_link, $post, $unused_leavename, $unused_is_sample)
    {
        $actu = Actu::get($post);
        if (! $actu) return $orig_link;
        $true_permalink = $actu->get_permalink();
        return $true_permalink ? $true_permalink : $orig_link;
    }

    function render_in_edit_form ($wp_post)
    {
        $actu = Actu::get($wp_post);
        if (! $actu) return;

        $permalink = get_permalink($wp_post);
        global $post;
        $subtitle = function_exists("get_the_subtitle") ? get_the_subtitle($post, "", "", false) : null;
        ?>
    <h1><?php echo $wp_post->post_title; ?></h1>
    <?php if ($subtitle) : ?><h2><?php echo $subtitle; ?></h2><?php endif; ?>
	<div id="edit-slug-box" class="hide-if-no-js">
    <img class="actu-thumbnail" src="<?php echo $actu->get_image_url() ?>"/>
    <p><b>Permalink:</b> <a href="<?php echo $permalink; ?>"><?php echo $permalink; ?></a></p>
    <?php echo $wp_post->post_content; ?>
	</div>
        <?php
    }

    static function editor_css ($hook)
    {
        if (! ('post.php' === $hook &&
               Actu::get($_GET["post"])) ) return;
        wp_register_style(
            'ws-editor',
            plugins_url( 'ws-editor.css', __FILE__ ) );
        wp_enqueue_style('ws-editor');
    }

    function pre_get_posts ($query) {
		$qv = &$query->query_vars;

        if (! is_admin() && $query->is_main_query()) {
            // Loosely based on https://wordpress.stackexchange.com/q/181803/132235
            $post_types = $query->get('post_type');
            if ($post_types === 'post') {
                $post_types = ['post'];
            }
            if (is_array($post_types) &&
                (false === array_search(Actu::get_post_type(), $post_types))) {
                array_push($post_types, Actu::get_post_type());
                $query->set('post_type', $post_types);
            }
        }
        return $query;
    }
}

/**
 * A standard WordPress category that is auto-assigned to Actu elements.
 */
class ActuCategory
{
    const ID_META = "epfl_actu_category_id";

    function __construct ($tag_id)
    {
        $this->tag_id = $tag_id;
    }

    function ID ()
    {
        return $this->tag_id;
    }

    function get_actu_id ()
    {
        return get_term_meta($this->tag_id, self::ID_META, true);
    }

    static function get_by_actu_id ($actu_id, $discrim_func = null)
    {
        $klass = get_called_class();
        $terms = get_terms(array(
            'taxonomy'   => 'category',
            'meta_key'   => self::ID_META,
            'meta_value' => $actu_id,
            'hide_empty' => false
        ));
        if (! count($terms)) return;
        if (count($terms) > 1) {
            $terms = call_user_func($discrim_func, $terms);
        }
        return new $klass($terms[0]->term_id);
    }
}

/**
 * WP configuration and callbacks for categories of Actus
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuCategoryController
{
    static function get_model_class ()
    {
        return Actu::class;
    }

    static function hook ()
    {
        add_action ( 'category_add_form_fields', array(get_called_class(), 'render_actu_category_id'));
        add_action ( 'category_edit_form_fields', array(get_called_class(), 'render_actu_category_id'));
        add_action ( 'created_category', array(get_called_class(), 'save_actu_category_id'), 10, 2);
        add_action ( 'edited_category', array(get_called_class(), 'save_actu_category_id'), 10, 2);

        add_filter ( "manage_edit-category_columns", array(get_called_class(), 'add_column_category_id'));
        add_filter ( "manage_category_custom_column", array(get_called_class(), 'render_custom_column_category_id'), 10, 3);
    }

    // https://actus.epfl.ch/api/v1/categories/
    const ACTU_CATEGORY_LIST = array(
        "1" => "EPFL",
        "2" => "Education",
        "3" => "Research",
        "4" => "Innovation",
        "5" => "Campus Life",
        );

    static function get_actu_category_id ($tag_id) {
        return self::ACTU_CATEGORY_LIST[get_term_meta($tag_id, ActuCategory::ID_META, true)];
    }

    static function render_actu_category_id ()
    {
        $actu_category_id = (new ActuCategory($_REQUEST['tag_ID']))->get_actu_id();
        ?>
        <tr class="form-field actu-description-wrap">
            <th scope="row">
                <label for="actu_category_id">
                    <?php echo ___("Actu's category ID"); ?>
                </label>
            </th>
            <td>
                <select name="actu_category_id" id="actu_category_id" class="postform">
                    <option value="-1">None</option>
                <?php foreach (self::ACTU_CATEGORY_LIST as $catid => $cattitle) { ?>
                    <option class="level-0" value="<?php echo $catid; ?>"<?php echo ($actu_category_id==$catid) ? 'selected="true"':'';  ?>><?php echo ___($cattitle); ?></option>
                <?php } ?>
                </select>
                <p><?php echo ___("This allows to link any news.epfl.ch's category with this plugins categories."); ?></p>
            </td>
        </tr>
        <?php
    }

    static function save_actu_category_id ($term_id, $unused_taxonomy) {
        if ( isset( $_REQUEST['actu_category_id'] ) ) {
            add_term_meta($term_id, ActuCategory::ID_META, $_REQUEST['actu_category_id']);
        }
    }

    static function add_column_category_id ($columns)
    {
        $columns[ActuCategory::ID_META] = ___("Actu category");
        return $columns;
    }

    static function render_custom_column_category_id ($content, $column_name, $term_id)
    {
        if ($column_name === ActuCategory::ID_META) {
            return self::get_actu_category_id($term_id);
        }
    }
}

ActuStreamController::hook();
ActuController::hook();
ActuCategoryController::hook();
