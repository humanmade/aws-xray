<?php

namespace HM\Platform\XRay\Query_Monitor;

use QM_Collector;
use QM_Output_Html;

class Output_Flamegraph_Html extends QM_Output_Html {

	public function __construct( QM_Collector $collector ) {
		parent::__construct( $collector );
		add_filter( 'qm/output/panel_menus', [ $this, 'panel_menu' ], 40 );
	}

	public function output() {
		$xhprof = null;
		foreach ( $this->collector->traces as $trace ) {
			if ( $trace['name'] === 'xhprof' ) {
				$xhprof = $trace;
				break;
			}
		}

		if ( ! $xhprof ) {
			return;
		}

		?>
		<?php $this->before_non_tabular_output( 'qm-aws-xray-flamegraph' ); ?>
		<caption>
			<?php /* translators: Number of miliseconds/ */ ?>
			<h2><?php printf( esc_html__( 'Sampled Profile (%dms intervals)', 'aws-xray' ), 5 ) ?></h2>
		</caption>
		<div class="aws-xray-flamegraph"><?php echo wp_json_encode( $xhprof ) ?></div>
		<?php

		$this->after_non_tabular_output();
	}

	/**
	 * Add the Flamegraph child menu item to the Query Monitor panel menu.
	 *
	 * @param array $menu
	 * @return array
	 */
	public function panel_menu( array $menu ) : array {
		$menu['aws-xray']['children'][] = [
			'title' => __( 'Flamegraph', 'aws-xray' ),
			'id'    => 'query-monitor-aws-xray-flamegraph',
			'href'  => '#qm-aws-xray-flamegraph',
		];
		return $menu;
	}
}
