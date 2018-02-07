<?php
/**
 * Show a EPFL Map from plan.epfl.ch.
 */

namespace EPFL\WS\Widgets\Map;

if (! class_exists('WP_Widget')) {
  die( 'Access denied.' );
}

require_once(__DIR__ . "/../inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

class EPFLMap extends \WP_Widget
{
  public function __construct ()
  {
    parent::__construct(
      'EPFL_Widget_Map',   // unique id
      'EPFL Map',          // widget title
      array(
        'description' => ___( 'Show a EPFL Map from plan.epfl.ch' )
      )
    );
  }

  // The widget form in the wp_admin aera
  public function form ( $instance )
  {
    // Check values
    if ( $instance ) {
      $lookup = esc_attr($instance['lookup']);
    } else {
      $lookup = '';
    }

    print vsprintf("<p><label for=\"%s\">%s<input class=\"widefat\" id=\"%s\" name=\"%s\" type=\"text\" value=\"%s\" /></label><br><small>%s</small></p>",
                array(
                  $this->get_field_id('lookup'),
                  __x('Lookup:', 'epfl_sti'),
                  $this->get_field_id('lookup'),
                  $this->get_field_name('lookup'),
                  $lookup,
                  __x('e.g. office number ("MA A2 424") or a Sciper number ("133134").', 'epfl_sti'))
                );
  }

  // Update widget settings
  public function update ( $new_instance, $old_instance )
  {
    $instance = $old_instance;
    $instance['lookup'] = isset( $new_instance['lookup'] ) ? wp_strip_all_tags( $new_instance['lookup'] ) : '';
    return $instance;
  }

  public function widget ( $args, $instance )
  {
    extract( $args );
    $lookup = isset( $instance['lookup'] ) ? apply_filters( 'widget_text', $instance['lookup'] ) : '';

    echo $args['before_widget'];
    // TODO: add option to choose classes and 4by3 or 16by9
    print sprintf("<div class=\"embed-responsive embed-responsive-16by9\">
                      <iframe class=\"embed-responsive-item\" src=\"https://plan.epfl.ch/iframe/?map_zoom=12&q=%s\" ></iframe>
                    </div>",
                    $lookup);
    echo $args['after_widget'];
    echo "<!-- EPFL-WS MAP WIDGET: lookup=" . $lookup . " -->";
  }

}  // class EPFLMap

// Register the widget
add_action( 'widgets_init', function(){ register_widget(EPFLMap::class); } );
