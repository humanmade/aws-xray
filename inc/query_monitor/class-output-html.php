<?php

namespace HM\Platform\XRay\Query_Monitor;

use function HM\Platform\XRay\get_root_trace_id;
use QM_Collector;
use QM_Output_Html;

class Output_Html extends QM_Output_Html {

	public function __construct( QM_Collector $collector ) {
		parent::__construct( $collector );
		add_filter( 'qm/output/panel_menus', [ $this, 'panel_menu' ], 40 );
	}

	public function name() {
		return __( 'AWS X-Ray', 'aws-xray' );
	}

	public function output() {
		?>
		<?php $this->before_tabular_output(); ?>
		<caption>
			<?php /* translators: Trace ID */ ?>
			<h2><?php printf( esc_html__( 'Trace ID: %s', 'aws-xray' ), get_root_trace_id() ); ?></h2>
		</caption>
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Segment Name', 'aws-xray' ) ?></th>
				<th><?php echo esc_html__( 'In Progress', 'aws-xray' ) ?></th>
				<th><?php echo esc_html__( 'Time', 'aws-xray' ) ?></th>
				<th><?php echo esc_html__( 'Segment', 'aws-xray' ) ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $this->collector->traces as $trace ) : ?>
				<tr>
					<td><?php echo esc_html( $trace['name'] ) ?></td>
					<td><?php echo ! empty( $trace['in_progress'] ) ? '&#x2714;' : '' ?>
					<td>
						<?php if ( empty( $trace['in_progress'] ) ) : ?>
							<?php echo esc_html( round( ( $trace['end_time'] - $trace['start_time'] ) * 1000 ), 1 ) ?>ms</td>
						<?php endif ?>
					</td>
					<td class="qm-has-toggle">
						<ol class="qm-toggler">
							<?php echo $this->build_toggler() ?>
							<div class="qm-toggled">
								<pre><?php echo esc_html( print_r( $trace, true ) ) ?></pre>
							</div>
						</ol>
					</td>
				</tr>
			<?php endforeach ?>
		</tbody>
		<?php
		$this->after_tabular_output();
	}

	public function panel_menu( array $menu ) : array {
		$menu['aws-xray']['title'] = __( 'AWS X-Ray', 'aws-xray' );
		$menu['aws-xray']['href'] = '#qm-aws-xray';
		return $menu;
	}
}
