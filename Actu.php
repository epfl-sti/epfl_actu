<?php

/**
 * "Actu" custom post type and taxonomy.
 *
 * For each entry in actus.epfl.ch that the WordPress administrators
 * are interested in, there is a local copy as a post inside the
 * WordPress database. This allows e.g. putting actus news into the
 * newsletter or using the full-text search on them.
 */

namespace EPFL\Actu;

use WP_Query;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/i18n.php");

/**
 * Object model for Actu streams
 *
 * One stream corresponds to one so-called "term" in the
 * 'epfl-actu-channel' WordPress taxonomy. Each stream has an API URL
 * from which news are continuously fetched.
 */
class ActuStream
{
    static function get_taxonomy_slug ()
    {
        return 'epfl-actu-channel';
    }

    function __construct($term_or_term_id, $url = null)
    {
        if (is_object($term_or_term_id)) {
            $this->ID = $term_or_term_id->term_id;
        } else {
            $this->ID = $term_or_term_id;
        }
        if ($url !== null) {
            $this->url = $url;
        }
    }

    const CHANNEL_API_URL_SLUG = "epfl_actu_channel_api_url";

    function get_url ()
    {
        if (! $this->url) {
            $this->url = get_term_meta( $this->ID, self::CHANNEL_API_URL_SLUG, true );
        }
        return $this->url;
    }

    function set_url ($url)
    {
        $this->url = $url;
        delete_term_meta($this->ID, self::CHANNEL_API_URL_SLUG);
        add_term_meta($this->ID, self::CHANNEL_API_URL_SLUG, $url);
    }

    function as_category ()
    {
        return $this->ID;
    }

    function sync ()
    {
        require_once (dirname(__FILE__) . "/ActuAPI.php");
        $client = new ActuAPIClient($this);
        foreach ($client->fetch() as $APIelement) {
            $actuItem = Actu::get_or_create($APIelement["news_id"], $APIelement["translation_id"]);
            $actuItem->update($APIelement);
            $actuItem->add_found_in_stream($this);
        }
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
    static function get ($post_id)
    {
        if (get_post_type($post_id) !== Actu::get_post_type()) {
            return null;
        }
        $theclass = get_called_class();
        return new $theclass($post_id);
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
            $this->ID, ActuStream::get_taxonomy_slug(),
            array('fields' => 'ids'));
        if (! in_array($stream_object->ID, $terms)) {
            wp_set_post_terms($this->ID, array($stream_object->ID),
                              ActuStream::get_taxonomy_slug(),
                              true);  // Append
        }
    }

    const THUMBNAIL_META = "epfl_actu_external_thumbnail";

    /**
     * Update this news post with $details, overwriting most of the
     * mutable state of it.
     *
     * Only taxonomy terms (managed by @link add_found_in_stream) are
     * left unchanged.
     */
    function update ($details)
    {
        $meta = array();
        foreach (["news_id", "translation_id", "news_thumbnail_absolute_url",
                  "video"]
                 as $keep_this_as_meta)
        {
            if ($details[$keep_this_as_meta]) {
                $meta[$keep_this_as_meta] = $details[$keep_this_as_meta];
            }
        }

        $matched = array();
        if (preg_match('#youtube.com/embed/([A-Za-z0-9]+)#', $details["video"],
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
            $meta[self::THUMBNAIL_META] = $meta["news_thumbnail_absolute_url"];
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
    }

    function get_external_thumbnail_url ()
    {
        return get_post_meta($this->ID, self::THUMBNAIL_META, true);
    }
}

/**
 * Configuration UI and WP callbacks for the actu stream taxonomy
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuStreamConfig
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
                // TODO: capabilities here.
                'rewrite'           => array( 'slug' => $taxonomy_slug ),
            ));
        add_action("${taxonomy_slug}_add_form_fields", array(get_called_class(), "create_channel_widget"));
        add_action( "${taxonomy_slug}_edit_form_fields", array(get_called_class(), "update_channel_widget"), 10, 2);
        add_action( "created_${taxonomy_slug}", array(get_called_class(), 'edited_channel'), 10, 2 );
        add_action( "edited_${taxonomy_slug}", array(get_called_class(), 'edited_channel'), 10, 2 );
    }

    static function create_channel_widget ($taxonomy)
    {
        self::render_channel_widget(array("placeholder" => "http://example.com/"));
    }

    static function update_channel_widget ($term, $taxonomy)
    {
        self::render_channel_widget(array("value" => (new ActuStream($term))->get_url()));
    }

    const CHANNEL_WIDGET_URL_SLUG = 'epfl_actu_channel_url';

    static function render_channel_widget ($input_attributes)
    {
      ?><div class="form-field term-group">
        <label for="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>"><?php echo ___('Actu Channel API URL'); ?></label>
        <input id="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>" name="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>" <?php
           foreach ($input_attributes as $k => $v) {
               echo "$k=" . htmlspecialchars($v);
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
class ActuConfig
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        $main_plugin_file = dirname(__FILE__) . "/EPFL-actu.php";
        register_activation_hook($main_plugin_file, array(get_called_class(), "register_caps"));
        register_deactivation_hook($main_plugin_file, array(get_called_class(), "deregister_caps"));

        add_action( sprintf('manage_%s_posts_columns', Actu::get_post_type()) , array(get_called_class(), "alter_columns"));
        add_action( sprintf('manage_%s_posts_custom_column', Actu::get_post_type()),
                    array(get_called_class(), "render_thumbnail_column"), 10, 2);
        add_filter("post_thumbnail_html",
                   array(get_called_class(), "filter_post_thumbnail_html"), 10, 5);
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
                'rewrite'            => array( 'slug' => Actu::get_post_type() ),
                  // ad hoc access control, see (de|)register_caps() below:
                'capability_type'    => array('epfl_actu', 'epfl_actus'),
                'has_archive'        => true,
                'hierarchical'       => false,
                'taxonomies'         => array(ActuStream::get_taxonomy_slug(), 'category'),
                'menu_position'      => null,
                'menu_icon'          => 'dashicons-megaphone',
                'supports'           => array( 'title' )
            ));
    }

    const ROLES_THAT_MAY_VIEW_ACTUS = array('administrator', 'editor', 'author', 'contributor');
    const CAPS_FOR_VIEWERS = array(
        'edit_epfl_actus',
        'read_epfl_actu',
        'delete_epfl_actu'
    );
    const ALL_ROLES = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
    const ALL_CAPS = array(
        'edit_epfl_actu', 
        'read_epfl_actu',
        'delete_epfl_actu', 
        'edit_others_epfl_actus', 
        'publish_epfl_actus',       
        'read_private_epfl_actus', 
        'edit_epfl_actus'
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
//            foreach (self::ALL_CAPS as $cap) {
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
        if ($column === 'thumbnail') {
            echo get_the_post_thumbnail($post_id);
        }
    }

    /**
     * Arrange for get_the_post_thumbnail() to return the external thumbnail for Actus.
     *
     * This is set as a filter for WordPress' @link post_thumbnail_html hook. Note that
     * it is impossible to hijack the return value of @link get_the_post_thumbnail_url
     * in this way (but you can always call the @link get_external_thumbnail_url instance
     * method on an Actu object).
     */
    static function filter_post_thumbnail_html ($orig_html, $post_id, $unused_thumbnail_id,
                                                $unused_size, $attr)
    {
        $post = Actu::get($post_id);
        if (! $post) return $orig_html;

        error_log("filter_post_thumbnail_html for post ID $post_id");  // XXX

        $html = sprintf("<img src=\"%s\"", $post->get_external_thumbnail_url());
        if ($attr) {
            foreach ( $attr as $name => $value ) {
                $html .= " $name=" . '"' . $value . '"';
            }
        }
        $html .= " />";
        return $html;
    }
}


ActuStreamConfig::hook();
ActuConfig::hook();
