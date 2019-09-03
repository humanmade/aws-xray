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
			<h2>Sampled Profile (5ms intervals)</h2>
		</caption>
		<div class="aws-xray-flamegraph"><?php echo wp_json_encode( $xhprof ) ?></div>
		<?php

		$this->after_non_tabular_output();
	}

	public function panel_menu( array $menu ) : array {
		$menu['aws-xray']['children'][] = [
			'title' => '└ ' . 'Flamegraph',
			'id'    => 'query-monitor-aws-xray-flamegraph',
			'href'    => '#qm-aws-xray-flamegraph',

		];
		return $menu;
	}
}
