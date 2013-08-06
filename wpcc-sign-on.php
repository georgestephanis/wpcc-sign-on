<?php
/**
 * Plugin Name: WPCC Sign On
 * Plugin URI: http://wordpress.org/plugins/wpcc-sign-on/
 * Description: A single-sign-on via WordPress.com for your WordPress.org site!
 * Author: George Stephanis
 * Version: 1.0
 * Author URI: http://stephanis.info/
 */

class WPCC_Sign_On {
	static $instance = null;

	var $request_token_url, // Fixed URL.
		$authenticate_url,  // Fixed URL.
		$user_data_url,     // Fixed URL.
		$new_app_url_base,  // Fixed URL.
		$options,           // Options Array.
		$client_id,         // Option.
		$client_secret,     // Option.
		$new_user_override, // Option.
		$match_by_email,    // Option.
		$redirect_url,
		$secret,
		$user_data;

	function __construct() {
		if ( self::$instance ) {
			return self::$instance;
		}

		self::$instance = $this;

		$this->request_token_url = 'https://public-api.wordpress.com/oauth2/token';
		$this->authenticate_url  = 'https://public-api.wordpress.com/oauth2/authenticate';
		$this->user_data_url     = 'https://public-api.wordpress.com/rest/v1/me/';
		$this->new_app_url_base  = 'https://developer.wordpress.com/apps/new/';
		$this->options           = $this->fetch_options();
		$this->client_id         = $this->options['client_id'];
		$this->client_secret     = $this->options['client_secret'];
		$this->new_user_override = $this->options['new_user_override'];
		$this->match_by_email    = $this->options['match_by_email'];
		$this->redirect_url      = wp_login_url();

		add_action( 'admin_init', array( $this, 'admin_init' ) );

		if ( empty( $this->client_id ) ) {
			return;
		}

		add_action( 'login_init',            array( $this, 'login_init' )            );
		add_action( 'login_enqueue_scripts', array( $this, 'login_enqueue_scripts' ) );
		add_action( 'login_form',            array( $this, 'login_form' )            );
	}

	function fetch_options() {
		return wp_parse_args( get_option( 'wpcc_options' ), $this->default_options() );
	}

	function default_options() {
		return array(
			'client_id'         => get_option( 'wpcc_sign_on_client_id' ),
			'client_secret'     => get_option( 'wpcc_sign_on_client_secret' ),
			'new_user_override' => get_option( 'wpcc_new_user_override' ),
			'match_by_email'    => null,
		);
	}

	function admin_init() {
		// Create the section
		add_settings_section(
			'wpcc',
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( 'http://developer.wordpress.com/docs/wpcc/' ), esc_html__( 'WordPress.com Connect', 'wpcc-sign-on' ) ),
			array( $this, 'wpcc_settings_section' ),
			'general'
		);

		add_settings_field(
			'wpcc_sign_on_client_id',
			sprintf( '<label for="wpcc_sign_on_client_id">%1$s</label>', __( 'WPCC Client ID', 'wpcc-sign-on' ) ),
			array( $this, 'wpcc_sign_on_client_id_cb' ),
			'general',
			'wpcc'
		);
		add_settings_field(
			'wpcc_sign_on_client_secret',
			sprintf( '<label for="wpcc_sign_on_client_secret">%1$s</label>', __( 'WPCC Client Secret', 'wpcc-sign-on' ) ),
			array( $this, 'wpcc_sign_on_client_secret_cb' ),
			'general',
			'wpcc'
		);
		add_settings_field(
			'wpcc_new_user_override',
			sprintf( '<label for="wpcc_new_user_override">%1$s</label>', __( 'New User Registration', 'wpcc-sign-on' ) ),
			array( $this, 'wpcc_new_user_override_cb' ),
			'general',
			'wpcc'
		);
		add_settings_field(
			'wpcc_match_by_email',
			sprintf( '<label for="wpcc_match_by_email">%1$s</label>', __( 'Match By Email', 'wpcc-sign-on' ) ),
			array( $this, 'wpcc_match_by_email_cb' ),
			'general',
			'wpcc'
		);

		register_setting( 'general', 'wpcc_options', array( $this, 'sanitize_options' ) );

		if ( true || ! empty( $this->client_id ) ) {
			add_action( 'show_user_profile', array( $this, 'edit_profile_fields' ) ); // For their own profile
			add_action( 'edit_user_profile', array( $this, 'edit_profile_fields' ) ); // For folks editing others profiles
		}
	}

	function sanitize_options( $options ) {
		if ( ! empty( $options['client_id'] ) ) {
			$options['client_id'] = intval( $options['client_id'] );
		}

		if ( ! empty( $options['client_secret'] ) ) {
			$options['client_secret'] = sanitize_text_field( $options['client_secret'] );
		}

		if ( isset( $options['new_user_override'] ) ) {
			$options['new_user_override'] = intval( $options['new_user_override'] );
		}

		if ( isset( $options['match_by_email'] ) ) {
			$options['match_by_email'] = intval( $options['match_by_email'] );
		}

		return $options;
	}

	function wpcc_settings_section() {
		?>

		<p id="wpcc-sign-on-section"><?php _e( 'Sign-on with your WordPress.com account!', 'wpcc-sign-on' ); ?></p>

		<?php
	}

	function wpcc_sign_on_client_id_cb() {
		echo '<input class="regular-text code" autocomplete="off" type="text" id="wpcc_sign_on_client_id" name="wpcc_options[client_id]" value="' . esc_attr( $this->client_id ) . '" />';
	}

	function wpcc_sign_on_client_secret_cb() {
		echo '<input class="regular-text code" autocomplete="off" type="text" id="wpcc_sign_on_client_secret" name="wpcc_options[client_secret]" value="' . esc_attr( $this->client_secret ) . '" />';
		if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
			printf( '<h2 style="display:inline; margin-left:1em;"><a href="%1$s">%2$s</a></h2>', esc_url( $this->get_new_app_url() ), __( 'Get client keys &rarr;', 'wpcc-sign-on' ) );
		}
	}

	function wpcc_new_user_override_cb() {
		echo '<input type="checkbox" id="wpcc_new_user_override" name="wpcc_options[new_user_override]" value="1" ' . checked( 1, $this->new_user_override, false ) . '  />';
		printf( ' <em>%1$s</em>', __( 'If you do not ordinarily <a href="#users_can_register">let users register</a> above, this will override that and let them register through <abbr title="WordPress.com Connect">WPCC</abbr>.', 'wpcc-sign-on' ) );
	}

	function wpcc_match_by_email_cb() {
		echo '<input type="checkbox" id="wpcc_match_by_email" name="wpcc_options[match_by_email]" value="1" ' . checked( 1, $this->match_by_email, false ) . '  />';
		printf( ' <em>%1$s</em>', __( 'Should the user be permitted to log on if their local account email address is a match to their verified WordPress.com account email address?', 'wpcc-sign-on' ) );
	}

	function edit_profile_fields( $user ) {
		?>

		<h3><?php _e( 'WordPress.com Connect', 'wpcc-sign-on' ); ?></h3>

		<?php if ( $user_data = get_user_meta( $user->ID, 'wpcom_user_data', true ) ) : /* If the user is currently connected... */ ?>

			<pre>
				<?php var_dump( $user_data ); ?>
			</pre>

		<?php elseif ( get_current_user_id() == $user->ID ) : ?>

			<p><?php _e( 'This is your profile, and you can connect it if you want to!', 'wpcc-sign-on' ); ?></p>

		<?php else : ?>

			<p><?php _e( 'This profile is not linked to a WordPress.com Profile.', 'wpcc-sign-on' ); ?></p>

		<?php endif;
	}

	function login_init() {
		// Set the wpcc_state
		$this->wpcc_state = md5( mt_rand() );
		if ( isset( $_COOKIE['wpcc_state'] ) ) {
			$this->wpcc_state = $_COOKIE['wpcc_state'];
		} else {
			setcookie( 'wpcc_state', $this->wpcc_state );
		}

		// If they just got forwarded back ...
		if ( isset( $_GET['code'] ) ) {
			if ( empty( $_GET['state'] ) ) {
				wp_die( __( 'Warning! State variable missing after authentication.', 'wpcc-sign-on' ) );
			}

			if ( $_GET['state'] != $this->wpcc_state ) {
				wp_die( __( 'Warning! State mismatch. Authentication attempt may have been compromised.', 'wpcc-sign-on' ) );
			}

			$args = array(
				'client_id'     => $this->client_id,
				'redirect_uri'  => $this->redirect_url,
				'client_secret' => $this->client_secret,
				'code'          => sanitize_text_field( $_GET['code'] ), // The code from the previous request
				'grant_type'    => 'authorization_code',
			);

			$response = wp_remote_post( $this->request_token_url, array( 'body' => $args ) );

			if ( is_wp_error( $response ) ) {
				wp_die( __( 'Warning! Could not confirm request token url!', 'wpcc-sign-on' ) );
			}

			$this->secret = json_decode( wp_remote_retrieve_body( $response ) );

			$args = array(
				'headers' => array(
					'Authorization' => sprintf( 'Bearer %s', $this->secret->access_token ),
				),
			);

			$response = wp_remote_get( $this->user_data_url, $args );

			if ( is_wp_error( $response ) ) {
				wp_die( __( 'Warning! Could not fetch user data!', 'wpcc-sign-on' ) );
			}

			$this->user_data = json_decode( wp_remote_retrieve_body( $response ) );

			$this->auth_user( $this->user_data );
		}
	}

	function login_enqueue_scripts() {
		wp_enqueue_style( 'wpcc-sign-on', plugins_url( 'wpcc-sign-on.css', __FILE__ ), 0, filemtime( dirname( __FILE__ ) . '/wpcc-sign-on.css' ) );
		wp_enqueue_script( 'wpcc-sign-on', plugins_url( 'wpcc-sign-on.js', __FILE__ ), array( 'jquery' ), filemtime( dirname( __FILE__ ) . '/wpcc-sign-on.js'  ) );
	}

	function login_form() {
		if( ! did_action( 'login_init' ) )
			return;

		echo $this->button();
	}

	function button() {
		$args = array(
			'response_type' => 'code',
			'client_id'     => $this->client_id,
			'state'         => $this->wpcc_state,
			'redirect_uri'  => $this->redirect_url,
		);

		$url = add_query_arg( $args, $this->authenticate_url );

		return sprintf( '<a id="wpcc-sign-on" href="%1$s"><img src="//s0.wp.com/i/wpcc-button.png" width="231" /></a>', esc_url( $url ) );
	}

	function auth_user( $user_data ) {

		if ( ! $user_data->verified ) {
			return false;
		}

		$user = $this->get_user_by_wpcom_id( $user_data->ID );

		// If we don't have one by wpcom_user_id, try by the email?
		if ( empty( $user ) && $this->match_by_email ) {
			$user = get_user_by( 'email', $user_data->email );
			if ( $user ) {
				update_user_meta( $user->ID, 'wpcom_user_id', $user_data->ID );
			}
		}

		// If we've still got nothing, create the user.
		if ( empty( $user ) && ( get_option( 'users_can_register' ) || $this->new_user_override ) ) {
			$username = $user_data->username;

			if ( username_exists( $username ) ) {
				$username .= '_' . $user_data->ID;
			}

			if ( username_exists( $username ) )
				$username .= '_' . mt_rand();

			$password = wp_generate_password( 12, true );
			$user_id  = wp_create_user( $username, $password, $user_data->email );
			$user     = get_userdata( $user_id );

			$user->display_name = $user_data->display_name;
			wp_update_user( $user );

			update_user_meta( $user->ID, 'wpcom_user_id', $user_data->ID );
		}

		if ( $user ) {
			// Cache the user's details, so we can present it back to them on their user screen.
			update_user_meta( $user->ID, 'wpcom_user_data', $user_data );
			wp_set_auth_cookie( $user->ID );

			$redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : site_url();
			wp_safe_redirect( apply_filters( 'wpcc_sign_on_redirect', $redirect_to ) );
			exit;
		}

		add_action( 'login_message', array( $this, 'cant_find_user' ) );
	}

	function get_user_by_wpcom_id( $wpcom_user_id ) {
		$user_query = new WP_User_Query( array(
			'meta_key'   => 'wpcom_user_id',
			'meta_value' => intval( $wpcom_user_id ),
		) );

		$users = $user_query->get_results();

		return ( is_array( $users ) && ! empty( $users ) ) ? array_shift( $users ) : $users;
	}

	function cant_find_user( $message ) {
		$err_format = __( 'We couldn\'t find an account with the email <strong><code>%1$s</code></strong> to log you in with.  If you already have an account on <strong>%2$s</strong>, please make sure that <strong><code>%1$s</code></strong> is configured as the email address.', 'wpcc-sign-on' );
		$err = sprintf( $err_format, $this->user_data->email, get_bloginfo( 'name' ) );
		$message .= sprintf( '<p class="message" id="login_error">%s</p>', $err );
		return $message;
	}

	function get_new_app_url() {
		$args = array(
			'title'        => urlencode( get_bloginfo( 'name' ) ),
			'description'  => urlencode( get_bloginfo( 'description' ) ),
			'url'          => urlencode( site_url() ),
			'redirect_uri' => urlencode( $this->redirect_url ),
		);
		return add_query_arg( $args, $this->new_app_url_base );
	}
}

new WPCC_Sign_On;
