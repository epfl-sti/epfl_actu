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

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/base-classes.inc");

require_once(dirname(__FILE__) . "/inc/i18n.inc");

/**
 * Object model for Actu streams
 *
 * One stream corresponds to one so-called "term" in the
 * 'epfl-actu-channel' WordPress taxonomy. Each stream has an API URL
 * from which news are continuously fetched.
 */
class ActuStream extends \EPFL\WS\Base\APIChannelTaxonomy
{
    static function get_taxonomy_slug ()
    {
        return 'epfl-actu-channel';
    }

    static function get_term_meta_slug ()
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
 * 'epfl-actu').
 */
class Actu extends \EPFL\WS\Base\APIChannelPost
{
    static function get_post_type ()
    {
        return 'epfl-actu';
    }

    static function get_api_id_key ()
    {
        return "news_id";
    }

    static function get_image_url_key ()
    {
        return "news_thumbnail_absolute_url";
    }

    /**
     * Update this news post with $details, overwriting most of the
     * mutable state of it.
     *
     * Only taxonomy terms (categories, as well as @link
     * APIChannelTaxonomy#set_ownership) are left unchanged.
     */
    protected function _update_post_meta ($api_result)
    {
        parent::_update_post_meta($api_result);
        $this->_post_meta = $meta = array();
        foreach (["video", "news_has_video",
                  "visual_and_thumbnail_description"]
                 as $keep_this_as_meta)
        {
            if ($api_result[$keep_this_as_meta]) {
                $meta[$keep_this_as_meta] = $api_result[$keep_this_as_meta];
            }
        }

        $youtube_id = $this->_extract_youtube_id($api_result);
        if ($youtube_id) {
            $meta["youtube_id"] = $youtube_id;
        }

        // Support for WP Subtitle plugin
        if (class_exists("WPSubtitle")) {
            $subtitle = $this->extract_subtitle($api_result);
            if ($subtitle && $subtitle !== $api_result["title"]) {
                // Like private function get_post_meta_key() in subtitle.php
                $subtitle_meta_key = apply_filters( 'wps_subtitle_key', 'wps_subtitle', $this->ID);
                $meta[$subtitle_meta_key] = $subtitle;
            }
        }
    }

    protected function _get_auto_categories($api_result) {
        $categories = array();
        $actu_cat = ActuCategory::get_by_actu_id(
            $api_result["news_category_id"],
            function ($terms) use ($api_result) {
                // Perhaps the returned categories are translations of each other?
                $filtered_terms = array();
                if (function_exists("pll_get_term")) {  // Polylang
                    foreach ($terms as $term) {
                        if (pll_get_term($term->term_id, $api_result["language"]) === $term->term_id) {
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
            array_push($categories, $actu_cat->ID());
        }
        return $categories;
    }

    function get_youtube_id ()
    {
        return $this->_get_post_meta()["youtube_id"];
    }

    protected function _update_image_meta ($api_result)
    {
        $youtube_id = $this->_extract_youtube_id($api_result);
        if ($youtube_id) {
            // The "right" thumbnail for a YouTube video is the one
            // YouTube serves - See also
            // https://stackoverflow.com/a/2068371/435004
            return sprintf(
                "https://img.youtube.com/vi/%s/default.jpg",
                $youtube_id);
        } else {
            return parent::_update_image_meta($api_result);
        }
    }

    private function _extract_youtube_id ($api_result)
    {
        $matched = array();
        if (preg_match('#youtube.com/embed/([^/?]+)#', $api_result["video"],
                       $matched)) {
            return $matched[1];
        }
    }

    protected function _get_excerpt ($api_result)
    {
        return $api_result["subtitle"];
    }
    protected function _get_content ($api_result)
    {
        return $api_result["text"];
    }

    /**
     * Extract a subtitle from the API's excerpt.
     *
     * The field named "subtitle" is generally unfit for use as a
     * subtitle in the WordPress sense — It is more like post_excerpt.
     * However, some subtitles on actu.epfl.ch do start with a short
     * sentence followed with a <br />. If that is the case, return it.
     */
    private function extract_subtitle ($api_result) {
        $matched = array();
        $max_subtitle_length = 80;
        if (preg_match("/^(.{1,$max_subtitle_length})<br/", $api_result["subtitle"], $matched)) {
            return trim($matched[1]);
        } elseif (preg_match("/^<p>(.{1,$max_subtitle_length})<\/p>/", $api_result["subtitle"], $matched)) {
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
class ActuStreamController extends \EPFL\WS\Base\APIChannelTaxonomyController
{
    static function get_taxonomy_class () {
        return ActuStream::class;
    }

    static function get_human_labels ()
    {
        return array(
            // These are for regster_taxonomy
            'name'              => __x( 'News Channels', 'taxonomy general name'),
            'singular_name'     => __x( 'News Channel', 'taxonomy singular name'),
            'search_items'      => ___( 'Search News Channels'),
            'all_items'         => ___( 'All News Channels'),
            'edit_item'         => ___( 'Edit News Channel'),
            'update_item'       => ___( 'Update News Channel'),
            'add_new_item'      => ___( 'Add News Channel'),
            'new_item_name'     => ___( 'New Channel Name'),
            'menu_name'         => ___( 'News Channels'),

            // These are internal to APIChannelTaxonomyController
            'url_legend'        => ___('Actu Channel API URL'),
            'url_legend_long'   => ___("Source URL of the JSON data. Use <a href=\"https://wiki.epfl.ch/api-rest-actu-memento/actu\" target=\"_blank\">actu-doc</a> for details.")
        );
    }

    static function get_placeholder_api_url ()
    {
        return "https://actu.epfl.ch/api/jahia/channels/sti/news/en/?format=json";
    }
}

/**
 * WP configuration and callbacks for the EPFL Actu post type
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuController extends \EPFL\WS\Base\APIChannelPostController
{
    static function get_taxonomy_class () {
        return ActuStream::class;
    }

    static function get_wpadmin_icon () {
        return 'dashicons-megaphone';
    }

    static function get_human_labels ()
    {
        return array(
            // For register_post_type:
            'name'               => __x( 'EPFL News', 'post type general name' ),
            'singular_name'      => __x( 'EPFL News', 'post type singular name' ),
            'menu_name'          => __x( 'EPFL News', 'admin menu' ),
            'name_admin_bar'     => __x( 'EPFL News', 'add new on admin bar' ),
            'view_item'          => ___( 'View EPFL News Item' ),
            'all_items'          => ___( 'All EPFL News for this site' ),
            'search_items'       => ___( 'Search News' ),
            'not_found'          => ___( 'No news found.' ),
            'not_found_in_trash' => ___( 'No news found in Trash.' ),

            // Others:
            'description'        => ___( 'EPFL News from news.epfl.ch' )
        );
    }

    /**
     * Overloaded to show an actual embedded video in the admin area, yow!
     */
    static function render_thumbnail_column ($actu, $img)
    {
        if ($actu->get_youtube_id()) {
            echo '<object style="width:160px;height:89px;float: none; clear: both; margin: 2px auto;" data="https://www.youtube.com/embed/'.$actu->get_youtube_id().'"></object>';
            printf("<p><a href=\"https://youtu.be/%s\">YouTube link</a></p>", $actu->get_youtube_id());
        } else {
            parent::render_thumbnail_column ($actu, $img);
        }
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

    static function get_actu_category_id ($tag_id)
    {
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
