<?php

namespace HM\Platform\XRay\Query_Monitor;

use QM_Collector;
use QM_Output_Html;

class Output_Flamegraph_Html extends QM_Output_Html {

	public function __construct( QM_Collector $collector ) {
		parent::__construct( $collector );
		add_filter( 'qm/output/panel_menus', [ $this, 'panel_menu' ], 40 );
	}

	public function name() {
		return __( 'Flamegraph', 'aws-xray' );
	}

	public function output() {
		$xhprof = null;
		$end_trace = null;
		foreach ( $this->collector->traces as $trace ) {
			if ( $trace['name'] === 'xhprof' ) {
				$xhprof = $trace;
			} elseif ( empty( $trace['parent_id'] ) && empty( $trace['in_progress'] ) ) {
				$end_trace = $trace;
			}
		}

		if ( ! $xhprof ) {
			return;
		}
		?>
		<?php $this->before_non_tabular_output( 'qm-aws-xray-flamegraph' ); ?>
		<caption>
			<h2>
				<?php /* translators: %d = Number of milliseconds */ ?>
				<?php printf( esc_html__( 'Sampled Profile (%dms intervals)', 'aws-xray' ), 5 ) ?>
				<?php if ( isset( $end_trace['metadata']['stats']['object_cache']['time'] ) ) : ?>
					<?php esc_html_e( 'Object Cache Time', 'aws-xray' ); ?>: <?php echo esc_html( round( $end_trace['metadata']['stats']['object_cache']['time'] * 1000 ) ) ?>ms
				<?php endif ?>

				<?php if ( isset( $end_trace['metadata']['stats']['db']['time'] ) ) : ?>
					<?php esc_html_e( 'Database Time', 'aws-xray' ); ?>: <?php echo esc_html( round( $end_trace['metadata']['stats']['db']['time'] * 1000 ) ) ?>ms
				<?php endif ?>

				<?php if ( isset( $end_trace['metadata']['stats']['remote']['time'] ) ) : ?>
					<?php esc_html_e( 'Remote Requests Time', 'aws-xray' ); ?>: <?php echo esc_html( round( $end_trace['metadata']['stats']['remote']['time'] * 1000 ) ) ?>ms
				<?php endif ?>
			</h2>
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
