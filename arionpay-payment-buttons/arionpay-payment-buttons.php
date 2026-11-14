<?php
/**
 * Plugin Name:       ArionPay Payment Buttons
 * Plugin URI:        https://arionpay.com
 * Description:       "Pay with crypto" buttons for any WordPress site: fixed prices, "enter your amount" and donations. Payments go straight to your own wallet.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ArionPay
 * License:           MIT
 * Text Domain:       arionpay-payment-buttons
 *
 * Shortcode: [arionpay_button amount="25" currency="USD" label="Pay with crypto" description="Consulting hour"]
 *            [arionpay_button custom_amount="yes" min="5" max="1000" currency="EUR" label="Donate"]
 */

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_BUTTONS_VERSION', '1.0.0' );

if ( ! class_exists( '\ArionPay\Client', true ) ) {
	require_once __DIR__ . '/lib/arionpay-php/autoload.php';
}

final class ArionPay_Buttons {

	const OPTION     = 'arionpay_buttons';
	const POST_TYPE  = 'arionpay_payment';
	const CURRENCIES = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR' );
	const MAX_BODY   = 262144;

	public static function init() {
		add_shortcode( 'arionpay_button', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_arionpay_pay', array( __CLASS__, 'pay' ) );
		add_action( 'admin_post_nopriv_arionpay_pay', array( __CLASS__, 'pay' ) );
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	// ------------------------------------------------------------------ settings

	public static function option( $name ) {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) && isset( $o[ $name ] ) ? trim( (string) $o[ $name ] ) : '';
	}

	public static function admin_menu() {
		add_options_page( 'ArionPay', 'ArionPay', 'manage_options', 'arionpay-buttons', array( __CLASS__, 'settings_page' ) );
	}

	public static function register_settings() {
		register_setting(
			'arionpay_buttons',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/** Secrets posted empty keep the saved value: the settings page never prints them. */
	public static function sanitize( $input ) {
		$old = get_option( self::OPTION, array() );
		$old = is_array( $old ) ? $old : array();
		$out = array(
			'api_key'  => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'test'     => ! empty( $input['test'] ) ? '1' : '',
			'base_url' => isset( $input['base_url'] ) ? esc_url_raw( trim( $input['base_url'] ) ) : '',
			'success'  => isset( $input['success'] ) ? esc_url_raw( trim( $input['success'] ) ) : '',
		);
		foreach ( array( 'api_secret', 'webhook_secret' ) as $k ) {
			$v         = isset( $input[ $k ] ) ? trim( (string) $input[ $k ] ) : '';
			$out[ $k ] = '' !== $v ? $v : ( isset( $old[ $k ] ) ? (string) $old[ $k ] : '' );
		}
		if ( '' !== $out['api_key'] && ! preg_match( '/^pk_[A-Za-z0-9_-]{8,64}$/', $out['api_key'] ) ) {
			add_settings_error( self::OPTION, 'api_key', __( 'The API key starts with pk_.', 'arionpay-payment-buttons' ) );
		}
		return $out;
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$saved  = __( 'Saved. Leave empty to keep it.', 'arionpay-payment-buttons' );
		$hook   = rest_url( 'arionpay/v1/webhook' );
		$check  = '';
		if ( isset( $_GET['arionpay-check'] ) && check_admin_referer( 'arionpay-check' ) ) {
			$check = self::check_connection();
		}
		?>
		<div class="wrap">
			<h1>ArionPay</h1>
			<p><?php esc_html_e( 'Your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet: ArionPay never holds it.', 'arionpay-payment-buttons' ); ?></p>
			<?php if ( $check ) : ?>
				<div class="notice <?php echo 0 === strpos( $check, 'OK' ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $check ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'arionpay_buttons' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="ap-key"><?php esc_html_e( 'API key', 'arionpay-payment-buttons' ); ?></label></th>
						<td><input id="ap-key" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="<?php echo esc_attr( self::option( 'api_key' ) ); ?>" placeholder="pk_..." autocomplete="off"/>
						<p class="description"><?php esc_html_e( 'ArionPay dashboard > your store > API key.', 'arionpay-payment-buttons' ); ?></p></td></tr>
					<tr><th><label for="ap-secret"><?php esc_html_e( 'API secret', 'arionpay-payment-buttons' ); ?></label></th>
						<td><input id="ap-secret" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[api_secret]" value="" placeholder="<?php echo esc_attr( self::option( 'api_secret' ) ? $saved : 'sk_...' ); ?>" autocomplete="new-password"/></td></tr>
					<tr><th><label for="ap-wh"><?php esc_html_e( 'Webhook signing secret', 'arionpay-payment-buttons' ); ?></label></th>
						<td><input id="ap-wh" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[webhook_secret]" value="" placeholder="<?php echo esc_attr( self::option( 'webhook_secret' ) ? $saved : 'whsec_...' ); ?>" autocomplete="new-password"/>
						<p class="description"><?php esc_html_e( 'ArionPay dashboard > Webhooks > signing secret. With it, payments are listed under ArionPay payments in this admin area.', 'arionpay-payment-buttons' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Test mode', 'arionpay-payment-buttons' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[test]" value="1" <?php checked( '1', self::option( 'test' ) ); ?>/> <?php esc_html_e( 'Test invoices: no real money moves. Turn it off before you take real payments.', 'arionpay-payment-buttons' ); ?></label></td></tr>
					<tr><th><label for="ap-success"><?php esc_html_e( 'Thank-you page', 'arionpay-payment-buttons' ); ?></label></th>
						<td><input id="ap-success" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[success]" value="<?php echo esc_attr( self::option( 'success' ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"/>
						<p class="description"><?php esc_html_e( 'Where customers go after paying (a button can set its own with success="...").', 'arionpay-payment-buttons' ); ?></p></td></tr>
					<tr><th><label for="ap-base"><?php esc_html_e( 'ArionPay address', 'arionpay-payment-buttons' ); ?></label></th>
						<td><input id="ap-base" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[base_url]" value="<?php echo esc_attr( self::option( 'base_url' ) ); ?>" placeholder="https://merchant.arionpay.com"/>
						<p class="description"><?php esc_html_e( 'Leave empty unless ArionPay tells you otherwise.', 'arionpay-payment-buttons' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<p><a class="button" id="arionpay-check" href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=arionpay-buttons&arionpay-check=1' ), 'arionpay-check' ) ); ?>"><?php esc_html_e( 'Check connection', 'arionpay-payment-buttons' ); ?></a></p>
			<h2><?php esc_html_e( 'Add a button', 'arionpay-payment-buttons' ); ?></h2>
			<p><?php esc_html_e( 'Put a shortcode in any page or post (in the block editor: a Shortcode block):', 'arionpay-payment-buttons' ); ?></p>
			<p><code>[arionpay_button amount="25" currency="USD" label="Pay with crypto" description="Consulting hour"]</code></p>
			<p><code>[arionpay_button custom_amount="yes" min="5" max="1000" currency="EUR" label="Donate with crypto" description="Donation"]</code></p>
			<p><?php esc_html_e( 'Payment updates arrive at:', 'arionpay-payment-buttons' ); ?> <code><?php echo esc_html( $hook ); ?></code> — <?php esc_html_e( 'nothing to paste: every invoice tells ArionPay this address.', 'arionpay-payment-buttons' ); ?></p>
		</div>
		<?php
	}

	private static function check_connection() {
		try {
			self::client()->getInvoice( '000000000000000000000000' );
			return 'OK: ' . __( 'ArionPay accepted these keys.', 'arionpay-payment-buttons' );
		} catch ( \ArionPay\ApiException $e ) {
			if ( 404 === $e->getStatus() ) {
				return 'OK: ' . __( 'ArionPay accepted these keys.', 'arionpay-payment-buttons' );
			}
			return __( 'Not connected:', 'arionpay-payment-buttons' ) . ' ' . $e->getMessage();
		} catch ( \InvalidArgumentException $e ) {
			return __( 'Not connected:', 'arionpay-payment-buttons' ) . ' ' . $e->getMessage();
		}
	}

	// ------------------------------------------------------------------ the button

	/**
	 * The button form. A fixed price is signed with this site's secret salt, so a visitor cannot change it; an
	 * "enter your amount" button is limited by its own min and max (also signed).
	 */
	public static function shortcode( $atts ) {
		$a = shortcode_atts(
			array(
				'amount'        => '',
				'currency'      => 'USD',
				'label'         => __( 'Pay with crypto', 'arionpay-payment-buttons' ),
				'description'   => get_bloginfo( 'name' ),
				'custom_amount' => 'no',
				'min'           => '1',
				'max'           => '10000',
				'success'       => '',
			),
			$atts,
			'arionpay_button'
		);
		$currency = strtoupper( sanitize_text_field( $a['currency'] ) );
		$custom   = 'yes' === strtolower( $a['custom_amount'] );
		if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
			return current_user_can( 'edit_posts' ) ? '<p><em>' . esc_html( 'ArionPay button: unsupported currency ' . $currency ) . '</em></p>' : '';
		}
		if ( ! $custom && ( ! is_numeric( $a['amount'] ) || (float) $a['amount'] <= 0 ) ) {
			return current_user_can( 'edit_posts' ) ? '<p><em>ArionPay button: set amount="..." or custom_amount="yes".</em></p>' : '';
		}
		$spec = array(
			'amount'      => $custom ? '' : number_format( (float) $a['amount'], 2, '.', '' ),
			'currency'    => $currency,
			'description' => mb_substr( sanitize_text_field( $a['description'] ), 0, 200 ),
			'min'         => $custom ? number_format( max( 0.01, (float) $a['min'] ), 2, '.', '' ) : '',
			'max'         => $custom ? number_format( max( (float) $a['min'], (float) $a['max'] ), 2, '.', '' ) : '',
			'success'     => esc_url_raw( $a['success'] ),
		);
		$packed = base64_encode( wp_json_encode( $spec ) );
		$sig    = hash_hmac( 'sha256', $packed, wp_salt( 'auth' ) . 'arionpay-button' );
		ob_start();
		?>
		<form class="arionpay-button" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="arionpay_pay"/>
			<input type="hidden" name="spec" value="<?php echo esc_attr( $packed ); ?>"/>
			<input type="hidden" name="sig" value="<?php echo esc_attr( $sig ); ?>"/>
			<?php if ( $custom ) : ?>
				<label><?php echo esc_html( $currency ); ?> <input type="number" name="amount" min="<?php echo esc_attr( $spec['min'] ); ?>" max="<?php echo esc_attr( $spec['max'] ); ?>" step="0.01" required/></label>
			<?php endif; ?>
			<button type="submit" class="wp-element-button"><?php echo esc_html( $a['label'] ); ?><?php echo $custom ? '' : ' (' . esc_html( $spec['amount'] . ' ' . $currency ) . ')'; ?></button>
			<?php if ( '1' === self::option( 'test' ) ) : ?>
				<small><?php esc_html_e( 'Test mode: no real money is taken.', 'arionpay-payment-buttons' ); ?></small>
			<?php endif; ?>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** Button pressed: make the ArionPay invoice and go to its payment page. */
	public static function pay() {
		$packed = isset( $_POST['spec'] ) ? (string) wp_unslash( $_POST['spec'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- signed below.
		$sig    = isset( $_POST['sig'] ) ? (string) wp_unslash( $_POST['sig'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		$spec   = json_decode( (string) base64_decode( $packed, true ), true );
		if ( ! is_array( $spec ) || ! hash_equals( hash_hmac( 'sha256', $packed, wp_salt( 'auth' ) . 'arionpay-button' ), $sig ) ) {
			wp_die( esc_html__( 'This payment button is not valid. Please reload the page.', 'arionpay-payment-buttons' ), '', array( 'response' => 400 ) );
		}
		if ( '' === $spec['amount'] ) {
			$amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			if ( $amount < (float) $spec['min'] || $amount > (float) $spec['max'] ) {
				wp_die( esc_html( sprintf( __( 'Please enter an amount between %1$s and %2$s.', 'arionpay-payment-buttons' ), $spec['min'], $spec['max'] ) ), '', array( 'response' => 400, 'back_link' => true ) );
			}
			$amount = number_format( $amount, 2, '.', '' );
		} else {
			$amount = $spec['amount'];
		}
		$test    = '1' === self::option( 'test' );
		$success = $spec['success'] ? $spec['success'] : ( self::option( 'success' ) ? self::option( 'success' ) : home_url( '/' ) );
		$back    = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$ref     = 'WP-' . strtoupper( wp_generate_password( 8, false ) );
		try {
			$invoice = self::client()->createInvoice(
				array(
					'amount'      => $amount,
					'currency'    => $spec['currency'],
					'orderId'     => $ref,
					'description' => $spec['description'],
					'successUrl'  => $success,
					'cancelUrl'   => $back,
					'callbackUrl' => self::option( 'webhook_secret' ) ? rest_url( 'arionpay/v1/webhook' ) : null,
					'metadata'    => array( 'platform' => 'wordpress-buttons', 'reference' => $ref ),
					'test'        => $test,
				)
			);
		} catch ( \Throwable $e ) {
			wp_die( esc_html__( 'The crypto payment could not be started right now. Please try again in a minute.', 'arionpay-payment-buttons' ), '', array( 'response' => 502, 'back_link' => true ) );
		}
		wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s · %s %s · %s', $ref, $amount, $spec['currency'], $spec['description'] ),
				'meta_input'  => array(
					'_arionpay_invoice_id' => $invoice['id'],
					'_arionpay_status'     => 'new',
					'_arionpay_amount'     => $amount,
					'_arionpay_currency'   => $spec['currency'],
					'_arionpay_test'       => $test ? '1' : '',
				),
			)
		);
		wp_redirect( $invoice['invoiceUrl'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the ArionPay payment page.
		exit;
	}

	// ------------------------------------------------------------------ payments list and webhook

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'ArionPay payments', 'arionpay-payment-buttons' ),
					'singular_name' => __( 'ArionPay payment', 'arionpay-payment-buttons' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-money-alt',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', function ( $cols ) {
			return array_merge( $cols, array( 'arionpay_status' => __( 'Status', 'arionpay-payment-buttons' ) ) );
		} );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', function ( $col, $id ) {
			if ( 'arionpay_status' === $col ) {
				echo esc_html( get_post_meta( $id, '_arionpay_status', true ) . ( get_post_meta( $id, '_arionpay_test', true ) ? ' (test)' : '' ) );
			}
		}, 10, 2 );
	}

	public static function register_webhook() {
		register_rest_route(
			'arionpay/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // signed by ArionPay, checked below
			)
		);
	}

	public static function webhook( \WP_REST_Request $request ) {
		$secret = self::option( 'webhook_secret' );
		$raw    = (string) $request->get_body();
		if ( '' === $secret ) {
			return new \WP_REST_Response( array( 'error' => 'not_configured' ), 503 );
		}
		if ( strlen( $raw ) > self::MAX_BODY ) {
			return new \WP_REST_Response( array( 'error' => 'payload_too_large' ), 413 );
		}
		try {
			$event = \ArionPay\Webhook::verify( $raw, (string) $request->get_header( 'arionpay_signature' ), $secret );
		} catch ( \ArionPay\SignatureException $e ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}
		if ( \ArionPay\Webhook::isTestEvent( $event ) ) {
			return new \WP_REST_Response( array( 'received' => true, 'test_event' => true ), 200 );
		}
		$invoice = $event['data']['invoice'];
		$posts   = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'meta_key'       => '_arionpay_invoice_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => isset( $invoice['id'] ) ? (string) $invoice['id'] : '-', // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'post_status'    => 'any',
			)
		);
		if ( ! $posts ) {
			return new \WP_REST_Response( array( 'received' => true, 'ignored' => 'not_a_button_payment' ), 200 );
		}
		$id      = $posts[0]->ID;
		$current = get_post_meta( $id, '_arionpay_status', true );
		if ( 'paid' !== $current ) { // a paid payment stays paid; later events are only kept in the log
			update_post_meta( $id, '_arionpay_status', sanitize_key( isset( $invoice['status'] ) ? $invoice['status'] : '' ) );
		}
		$log   = (array) get_post_meta( $id, '_arionpay_events', true );
		$log[] = gmdate( 'Y-m-d H:i' ) . ' UTC ' . sanitize_text_field( $event['type'] ) . ( ! empty( $invoice['paidCrypto'] ) ? ' ' . sanitize_text_field( $invoice['paidCrypto'] . ' ' . ( isset( $invoice['asset'] ) ? $invoice['asset'] : '' ) ) : '' );
		update_post_meta( $id, '_arionpay_events', array_slice( $log, -30 ) );
		if ( 'invoice.paid' === $event['type'] && 'paid' !== $current ) {
			/**
			 * A button payment was confirmed by ArionPay.
			 *
			 * @param int   $post_id The ArionPay payment record.
			 * @param array $invoice The verified invoice.
			 */
			do_action( 'arionpay_button_paid', $id, $invoice );
		}
		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/** @return \ArionPay\Client */
	private static function client() {
		$base = self::option( 'base_url' );
		return new \ArionPay\Client(
			self::option( 'api_key' ),
			self::option( 'api_secret' ),
			array(
				'baseUrl' => '' !== $base ? $base : 'https://merchant.arionpay.com',
				'appInfo' => 'wordpress arionpay-buttons/' . ARIONPAY_BUTTONS_VERSION,
			)
		);
	}
}

ArionPay_Buttons::init();
