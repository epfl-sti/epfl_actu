<?php

/**
 * Model and controller for an EPFL institute, section, DLL, platform etc.
 *
 * Unlike Persons and Labs, posts for these entities are *never*
 * auto-created. Instead, an operator needs to create them manually as
 * posts (or pages) with a custom field to mark them as such.
 *
 * The custom fields (a.k.a. "post meta") recognized by this class are
 * as follows:
 *
 * - epfl_dn
 *
 *   The DN of this organizational unit. This field existing, is a
 *   required condition for the code of this class to be used at all.
 *   Additionally, all Lab instances that have a `->get_dn()` that
 *   ends with this `epfl_dn`, are considered to belong in this
 *   OrganizationalUnit.
 */

namespace EPFL\WS\OrganizationalUnits;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
require_once(ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php');

require_once(__DIR__ . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

require_once(__DIR__ . "/inc/base-classes.inc");
use \EPFL\WS\Base\Post;

require_once(__DIR__ . "/Lab.php");
use \EPFL\WS\Labs\Lab;

require_once(__DIR__ . "/inc/ldap.inc");
use \EPFL\WS\LDAPClient;

require_once(dirname(__FILE__) . "/inc/batch.inc");
use function \EPFL\WS\run_every;
use \EPFL\WS\BatchTask;

use \WP_Screen;

class OrganizationalUnit extends Post
{
    /**
     * Overridden to keep only the posts (or pages) that have
     * an "epfl_dn" meta
     */
    function _belongs ()
    {
        return $this->get_dn() !== null;
    }

    const DN_META = "epfl_dn";
    function get_dn ()
    {
        return get_post_meta($this->ID, self::DN_META, true);
    }

    function find_by_dn ($dn)
    {
        $query = new \WP_Query(array(
            'post_type' => 'any',
            'meta_query' => array(array(
                'key'     => self::DN_META,
                'value'   => $dn,
                'compare' => '='
            ))));
        $results = $query->get_posts();
        if (sizeof($results) > 1 &&
            function_exists("pll_get_post_language") &&
            function_exists("pll_get_current_language")) {
            $results = array_values(array_filter(
                $results,
                function($result)  {
                    return (pll_current_language() ===
                            pll_get_post_language($result->ID));
                }));
        }
        if (! sizeof($results)) { return; }
        return static::get($results[0]);
    }

    function find_by_abbrev ($abbrev)
    {
        $entries = LDAPClient::query_by_unit_abbrev($abbrev);
        if (count($entries) === 1) {
            return static::find_by_dn($entries[0]["dn"]);
        } else {
            return null;
        }
    }

    function get_all_labs ()
    {
        return array_filter(
            Lab::find_all_by_dn_suffix($this->get_dn()),
            function ($lab) { return $lab->is_active(); });
    }

    static function all ()
    {
        $query = new \WP_Query(array(
            'post_type' => ['post', 'page'],
            'meta_query' => array(array(
                'key'     => self::DN_META,
                'compare' => 'EXISTS'
            ))));
        return array_map(function($result) {
            return static::get($result);
        }, $query->get_posts());
    }

    static function of_lab ($lab)
    {
        $parent_dn = preg_replace("@^.*?,@", "", $lab->get_dn());
        return static::find_by_dn($parent_dn);
    }

    /**
     * Auto-create and/or `->sync()` all Labs under this OrganizationalUnit.
     */
    function sync ()
    {
        foreach (LDAPClient::query_units_by_parent_dn($this->get_dn())
                 as $entry) {
            Lab::get_or_create_by_ldap_entry($entry)->sync();
        }
    }
}

class OrganizationalUnitController
{
    static function hook ()
    {
        add_action("admin_menu", array(get_called_class(), "setup_admin_menu"));
        run_every(600, array(get_called_class(), "sync_all"));
    }

    static function setup_admin_menu ()
    {
        $menu_slug = 'epfl-organizational-units-menu';
        add_menu_page(
            ___('EPFL Institutes and Sections'),
            ___('EPFL Insts &amp; Sections'),
            'edit_posts',
            $menu_slug,
            array(get_called_class(), 'render_list'),
            'dashicons-networking',
            43  /* Menu position */
        );
    }

    static function render_list ()
    {
        echo ___('
        <p>💡 To create a new institute or section:
         <ol>
          <li>First create a page or post,</li>
          <li>then add an <code>epfl_dn</code> custom field to it.</li>
         </ol>
        </p>
        ');
        $table = new OrganizationalUnitTable();
        $pagenum = $table->get_pagenum();
        if ($table->current_action()) {
            static::do_action($table->current_action());
        }
        $table->prepare_items();
        wp_enqueue_script('heartbeat');

        $table->views();
        ?>

<form id="posts-filter" method="get">

<?php $table->search_box( $post_type_object->labels->search_items, 'post' ); ?>

<input type="hidden" name="post_status" class="post_status_page" value="<?php echo !empty($_REQUEST['post_status']) ? esc_attr($_REQUEST['post_status']) : 'all'; ?>" />
<input type="hidden" name="post_type" class="post_type_page" value="<?php echo $post_type; ?>" />

<?php if ( ! empty( $_REQUEST['author'] ) ) { ?>
<input type="hidden" name="author" value="<?php echo esc_attr( $_REQUEST['author'] ); ?>" />
<?php } ?>

<?php if ( ! empty( $_REQUEST['show_sticky'] ) ) { ?>
<input type="hidden" name="show_sticky" value="1" />
<?php } ?>

<?php $table->display(); ?>

</form>
        <?php
        if ( $table->has_items() )
	    $table->inline_edit();
        ?>

<div id="ajax-response"></div>
<br class="clear" />
</div>

        <?php
    }

    static function sync_all ()
    {
        $thisclass = get_called_class();
        (new BatchTask())
            ->set_banner("Syncing all OrganizationalUnits and their Labs")
            ->set_prometheus_labels(array(
                'kind' => 'OrganizationalUnit'
            ))
            ->run(function() {
                $seen_dn = array();
                foreach (OrganizationalUnit::all() as $ou) {
                    $dn = $ou->get_dn();
                    if (! $seen_dn[$dn]) {
                        $ou->sync();
                    } else {
                        $seen_dn[$dn] = 1;
                    }
                }
            });
    }
}

/**
 * A subclass of WP_List_Table to show the list of organizational units
 */
class OrganizationalUnitTable extends \WP_List_Table
{
    const SLUG_PLURAL = 'epfl-ws-organizational-units';

    static function hook ()
    {
        add_filter('pll_get_post_types', function($post_types) {
            $post_types[self::SLUG_PLURAL] = self::SLUG_PLURAL;
            return $post_types;
        });
    }

    function __construct ()
    {
        parent::__construct( array(
            'plural' => self::SLUG_PLURAL,
            'ajax'   => false,
            'screen' => $this->get_screen()
        ));
    }

    public function get_screen ()
    {
        return WP_Screen::get("edit- " . self::SLUG_PLURAL);
    }

    public function get_columns ()
    {
        $columns = array();
        $columns['cb']         = '<input type="checkbox" />';
        $columns['title']      = __x('Title', 'OrganizationalUnitTable');
        $columns['labs']       = __x('Labs', 'OrganizationalUnitTable');
        $columns['categories'] = __x('Categories', 'OrganizationalUnitTable');
        $columns['tags']       = __x('Tags', 'OrganizationalUnitTable');
        return $columns;
    }

    protected function column_default ($item, $column_name)
    {
        return $item->$column_name;
    }

    protected function column_title ($item)
    {
        $ptable = new \WP_Posts_List_Table;
        global $post;
        $post = $item;
        try {
            $title_column_html = $ptable->column_title($item);
            return $title_column_html;
        } finally {
            $post = null;
        }
    }

    protected function column_labs ($item)
    {
        $html = "";
        $ou = OrganizationalUnit::get($item);
        foreach ($ou->get_all_labs() as $lab) {
            $html .= sprintf('<a href="%s">%s</a><br/>' . "\n",
                             get_edit_post_link($lab->wp_post()->ID),
                             $lab->get_name());
        }
        return $html;
    }

    protected function column_categories ($item)
    {
        $ptable = new \WP_Posts_List_Table;
        return $ptable->column_default($item, 'categories');
    }

   protected function column_tags ($item)
    {
        $ptable = new \WP_Posts_List_Table;
        return $ptable->column_default($item, 'tags');
    }

    /**
     * Prepare the list of items for displaying
     *
     * @since 1.8
     *
     * @param array $items
     */
    public function prepare_items( $items = array() )
    {
        $orgs = OrganizationalUnit::all();
        $this->items = array_map(function($ou) {
            return $ou->wp_post();
        }, $orgs);
    }

	protected function get_primary_column_name() {
		return 'title';
	}
}

OrganizationalUnitController::hook();
OrganizationalUnitTable::hook();
