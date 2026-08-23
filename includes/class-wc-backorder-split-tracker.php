<?php
/**
 * WPHEKA Plugin Deactivation Tracker
 *
 * @class       WC_Backorder_Split_Tracker
 * @version     2.1.0
 * @category    Class
 * @author      WPHEKA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Backorder_Split_Tracker {

	/**
	 * URL to the API endpoint
	 *
	 * @var string
	 */
	private static $api_url = 'https://wpheka.com/wp-json/wpheka/v1/plugins/feedback';

	/**
	 * Tracker ID
	 *
	 * @var string
	 */
	private static $tracker_id = '9065d9bace92cdd4738b57ad39065d9bace92cdd4738b57ad34622dfda69ba51';

	/**
	 * Deactivation Modal
	 *
	 * @var string
	 */
	private static $deactivation_modal = 'wc-backorder-split-deactivation-modal';

	/**
	 * Hook into cron event.
	 */
	public static function init() {

		// plugin deactivate actions.
		add_action( 'plugin_action_links_' . plugin_basename( WCBS_PLUGIN_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		add_action( 'admin_footer', array( __CLASS__, 'deactivate_scripts' ) );
		add_action( 'wp_ajax_wc_backorder_split_submit_deactivation', array( __CLASS__, 'send_tracking_deactivation' ) );
	}

	/**
	 * send tracking deactivation data.
	 *
	 * @param boolean $override
	 */
	public static function send_tracking_deactivation() {

		// Verify nonce for security
		if ( ! check_ajax_referer( 'wc_backorder_split_deactivation_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'error' => __( 'Security check failed. Please try again.', 'wc-backorder-split' ) ) );
			wp_die();
		}

		// Check user capabilities
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'error' => __( 'Insufficient permissions.', 'wc-backorder-split' ) ) );
			wp_die();
		}

		if ( empty( $_POST['deactivation_domain'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Something went wrong. Please try again later.', 'wc-backorder-split' ) ) );
			wp_die( -1 );
		}

		$feedback_url = self::$api_url;

		$deactivation_domain = isset( $_POST['deactivation_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['deactivation_domain'] ) ) : '';
		$deactivation_license_key = isset( $_POST['deactivation_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['deactivation_license_key'] ) ) : '';

		$email = isset( $_POST['deactivation_email'] ) ? sanitize_email( wp_unslash( $_POST['deactivation_email'] ) ) : '';

		$reason_id = isset( $_POST['reason_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reason_id'] ) ) : '';
		$reason_info = isset( $_POST['reason_info'] ) ? sanitize_text_field( wp_unslash( $_POST['reason_info'] ) ) : '';

		if ( empty( $reason_info ) ) {
			$deactivation_reason  = empty( $reason_id ) ? '' : $reason_id;
		} else {
			$deactivation_reason  = $reason_info;
		}

		wp_remote_post(
			$feedback_url,
			array(
				'timeout' => 30,
				'body' => array(
					'plugin' => 'WC Backorder Split',
					'deactivation_reason' => $deactivation_reason,
					'deactivation_domain' => $deactivation_domain,
					'deactivation_license_key' => $deactivation_license_key,
					'email' => $email,
				),
			)
		);

		wp_send_json_success();

		wp_die();
	}

	/**
	 * Hook into action links and modify the deactivate link
	 *
	 * @param  array $links
	 *
	 * @return array
	 */
	public static function plugin_action_links( $links ) {

		if ( array_key_exists( 'deactivate', $links ) ) {
			$links['deactivate'] = str_replace( '<a', '<a class="' . self::$tracker_id . '-deactivate-link"', $links['deactivate'] );
		}

		return $links;
	}

	/**
	 * Handle the plugin deactivation feedback
	 *
	 * @return void
	 */
	public static function deactivate_scripts() {
		global $pagenow;

		if ( 'plugins.php' != $pagenow ) {
			return;
		}

		$license_key = 'Free';
		$license_domain = get_site_url();
		$license_email = get_option( 'admin_email' );

		$deactivation_modal_id = self::$tracker_id . '-' . self::$deactivation_modal;

		$reasons = array(
			array(
				'id'          => 'could-not-understand',
				'text'        => 'I couldn\'t understand how to make it work',
				'type'        => 'textarea',
				'placeholder' => __( 'Would you like us to assist you?', 'wc-backorder-split' ),
			),
			array(
				'id'          => 'found-better-plugin',
				'text'        => 'I found a better plugin',
				'type'        => 'text',
				'placeholder' => __( 'Which plugin?', 'wc-backorder-split' ),
			),
			array(
				'id'          => 'not-have-that-feature',
				'text'        => 'The plugin is great, but I need specific feature that you don\'t support',
				'type'        => 'textarea',
				'placeholder' => __( 'Could you tell us more about that feature?', 'wc-backorder-split' ),
			),
			array(
				'id'          => 'is-not-working',
				'text'        => 'The plugin is not working',
				'type'        => 'textarea',
				'placeholder' => __( 'Could you tell us a bit more whats not working?', 'wc-backorder-split' ),
			),
			array(
				'id'          => 'looking-for-other',
				'text'        => 'It\'s not what I was looking for',
				'type'        => '',
				'placeholder' => '',
			),
			array(
				'id'          => 'did-not-work-as-expected',
				'text'        => 'The plugin didn\'t work as expected',
				'type'        => 'textarea',
				'placeholder' => __( 'What did you expect?', 'wc-backorder-split' ),
			),
			array(
				'id'          => 'other',
				'text'        => 'Other',
				'type'        => 'textarea',
				'placeholder' => __( 'Could you tell us a bit more?', 'wc-backorder-split' ),
			),
		);

		?>

		<div class="<?php echo esc_attr( self::$deactivation_modal ); ?>" id="<?php echo esc_attr( $deactivation_modal_id ); ?>">
			<div class="<?php echo esc_attr( self::$deactivation_modal ); ?>-wrap">
				<div class="<?php echo esc_attr( self::$deactivation_modal ); ?>-header">
					<h3><?php echo esc_html__( 'If you have a moment, please let us know why you are deactivating:', 'wc-backorder-split' ); ?></h3>
				</div>

				<div class="<?php echo esc_attr( self::$deactivation_modal ); ?>-body">
					<ul class="reasons">
						<?php foreach ( $reasons as $reason ) { ?>
							<li data-type="<?php echo esc_attr( $reason['type'] ); ?>" data-placeholder="<?php echo esc_attr( $reason['placeholder'] ); ?>">
								<label><input type="radio" name="deactivation_reason" value="<?php echo esc_attr( $reason['text'] ); ?>"> <?php echo esc_html( $reason['text'] ); ?></label>
							</li>
						<?php } ?>
					</ul>
					<input type="hidden" name="deactivation_domain" value="<?php echo esc_attr( $license_domain ); ?>">

					<input type="hidden" name="deactivation_license_key" value="<?php echo esc_attr( $license_key ); ?>">

					<input type="hidden" name="email" value="<?php echo esc_attr( $license_email ); ?>">
					
					<?php wp_nonce_field( 'wc_backorder_split_deactivation_nonce', 'nonce' ); ?>
				</div>

				<div class="<?php echo esc_attr( self::$deactivation_modal ); ?>-footer">
					<a href="#" class="dont-bother-me"><?php echo esc_html__( 'I rather wouldn\'t say', 'wc-backorder-split' ); ?></a>
					<button class="button-secondary"><?php echo esc_html__( 'Submit & Deactivate', 'wc-backorder-split' ); ?></button>
					<button class="button-primary"><?php echo esc_html__( 'Cancel', 'wc-backorder-split' ); ?></button>
				</div>
			</div>
		</div>

		<?php // Selectors below are literal and must track self::$deactivation_modal; WordPress has no CSS escaper. ?>
		<style type="text/css">
			.wc-backorder-split-deactivation-modal {
				position: fixed;
				z-index: 99999;
				top: 0;
				right: 0;
				bottom: 0;
				left: 0;
				background: rgba(0,0,0,0.5);
				display: none;
			}

			.wc-backorder-split-deactivation-modal.modal-active {
				display: block;
			}

			.wc-backorder-split-deactivation-modal-wrap {
				width: 475px;
				position: relative;
				margin: 10% auto;
				background: #fff;
			}

			.wc-backorder-split-deactivation-modal-header {
				border-bottom: 1px solid #eee;
				padding: 8px 20px;
			}

			.wc-backorder-split-deactivation-modal-header h3 {
				line-height: 150%;
				margin: 0;
			}

			.wc-backorder-split-deactivation-modal-body {
				padding: 5px 20px 20px 20px;
			}

			.wc-backorder-split-deactivation-modal-body .reason-input {
				margin-top: 5px;
				margin-left: 20px;
			}

			.wc-backorder-split-deactivation-modal-body textarea, .wc-backorder-split-deactivation-modal-body input[type="text"]{
				width: 100%;
			}

			.wc-backorder-split-deactivation-modal-footer {
				border-top: 1px solid #eee;
				padding: 12px 20px;
				text-align: right;
			}
		</style>

		<script type="text/javascript">
			(function($) {
				$(function() {
					var modal = $( '#<?php echo esc_js( $deactivation_modal_id ); ?>' );
					var deactivateLink = '';

					$( '#the-list' ).on('click', 'a.<?php echo esc_js( self::$tracker_id ); ?>-deactivate-link', function(e) {
						e.preventDefault();

						modal.addClass('modal-active');
						deactivateLink = $(this).attr('href');
						modal.find('a.dont-bother-me').attr('href', deactivateLink).css('float', 'left');
					});

					modal.on('click', 'button.button-primary', function(e) {
						e.preventDefault();

						modal.removeClass('modal-active');
					});

					modal.on('click', 'input[type="radio"]', function () {
						var parent = $(this).parents('li:first');

						modal.find('.reason-input').remove();

						var inputType = parent.data('type'),
							inputPlaceholder = parent.data('placeholder'),
							reasonInputHtml = '<div class="reason-input">' + ( ( 'text' === inputType ) ? '<input type="text" size="40" />' : '<textarea rows="5" cols="45"></textarea>' ) + '</div>';

						if ( inputType !== '' ) {
							parent.append( $(reasonInputHtml) );
							parent.find('input, textarea').attr('placeholder', inputPlaceholder).focus();
						}
					});

					modal.on('click', 'button.button-secondary', function(e) {
						e.preventDefault();

						var button = $(this);

						if ( button.hasClass('disabled') ) {
							return;
						}

						var $radio = $( 'input[type="radio"]:checked', modal );

						var $deactivation_domain = $( 'input[name="deactivation_domain"]', modal );
						var $deactivation_license_key = $( 'input[name="deactivation_license_key"]', modal );
						var $deactivation_email = $( 'input[name="email"]', modal );
						var $nonce = $( 'input[name="nonce"]', modal );

						var $selected_reason = $radio.parents('li:first'),
							$input = $selected_reason.find('textarea, input[type="text"]');

						$.ajax({
							url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
							type: 'POST',
							data: {
								action: 'wc_backorder_split_submit_deactivation',
								reason_id: ( 0 === $radio.length ) ? 'none' : $radio.val(),
								reason_info: ( 0 !== $input.length ) ? $input.val().trim() : '',
								deactivation_domain: ( 0 !== $deactivation_domain.length ) ? $deactivation_domain.val().trim() : '',
								deactivation_license_key: ( 0 !== $deactivation_license_key.length ) ? $deactivation_license_key.val().trim() : '',
								deactivation_email: ( 0 !== $deactivation_email.length ) ? $deactivation_email.val().trim() : '',
								nonce: ( 0 !== $nonce.length ) ? $nonce.val() : '',
							},
							beforeSend: function() {
								button.addClass('disabled');
								button.text('<?php echo esc_js( __( 'Processing…', 'wc-backorder-split' ) ); ?>');
							},
							error: function() {
								// A stale nonce answers 403 and no success
								// branch runs, so without this the button stayed
								// disabled on "Processing" and the plugin could
								// never be deactivated. Feedback is optional;
								// deactivating is not. Matches the sibling in
								// wc-search-orders-by-product.
								window.location.href = deactivateLink;
							},
							success: function( response ) {
								if ( response.success ) {
									window.location.href = deactivateLink;
								} else {
									window.alert( response.data.error );
									window.location.href = deactivateLink;
								}
							}
						});
					});
				});
			}(jQuery));
		</script>

		<?php
	}
}
