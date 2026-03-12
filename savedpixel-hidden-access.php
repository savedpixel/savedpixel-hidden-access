<?php
/**
 * Plugin Name: SavedPixel Hidden Access
 * Plugin URI: https://github.com/savedpixel
 * Description: Hide the default WordPress login routes and replace them with a private login URL.
 * Version: 1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Byron Jacobs
 * Author URI: https://github.com/savedpixel
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: savedpixel-hidden-access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/savedpixel-admin-shared.php';

savedpixel_register_admin_preview_asset(
	plugin_dir_url( __FILE__ ) . 'assets/css/savedpixel-admin-preview.css',
	'1.0',
	array( 'savedpixel', 'savedpixel-hidden-access' )
);

final class SavedPixel_Hidden_Access {

	const OPTION        = 'savedpixel_hidden_access_settings';
	const LEGACY_OPTION = 'bsa_settings';
	const QUERY_VAR     = 'savedpixel_hidden_access';

	private static $instance = null;

	public static function bootstrap() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate() {
		self::ensure_settings();
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function ensure_settings() {
		$current  = get_option( self::OPTION, null );
		$settings = is_array( $current ) ? $current : array();
		$defaults = self::defaults();
		$changed  = ! is_array( $current );

		if ( empty( $settings ) ) {
			$legacy = get_option( self::LEGACY_OPTION, array() );
			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				$settings = $legacy;
				$changed  = true;
			}
		}

		if ( empty( $settings['login_slug'] ) ) {
			$settings['login_slug'] = $defaults['login_slug'];
			$changed                = true;
		}

		$sanitized_slug = self::sanitize_slug( $settings['login_slug'] );
		if ( $sanitized_slug !== $settings['login_slug'] ) {
			$settings['login_slug'] = $sanitized_slug;
			$changed                = true;
		}

		if ( $changed ) {
			update_option( self::OPTION, $settings );
		}
	}

	private static function defaults() {
		return array(
			'login_slug' => 'portal-' . bin2hex( random_bytes( 6 ) ),
		);
	}

	public static function settings() {
		$settings = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$settings['login_slug'] = self::sanitize_slug( $settings['login_slug'] );

		return $settings;
	}

	public static function login_slug() {
		$settings = self::settings();

		return $settings['login_slug'];
	}

	public static function login_url( $args = array() ) {
		$url = home_url( '/' . self::login_slug() . '/' );

		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}

	public static function add_rewrite_rules() {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '1' );
		add_rewrite_rule( '^' . preg_quote( self::login_slug(), '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	private static function sanitize_slug( $value ) {
		$slug     = sanitize_title( (string) $value );
		$reserved = array( 'wp-admin', 'wp-login', 'wp-login.php', 'wp-json' );

		if ( '' === $slug || in_array( $slug, $reserved, true ) ) {
			$slug = self::defaults()['login_slug'];
		}

		return $slug;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrites' ), 1 );
		add_action( 'init', array( $this, 'maybe_render_custom_login' ), 0 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'login_init', array( $this, 'maybe_block_default_login' ), 0 );
		add_action( 'init', array( $this, 'maybe_block_wp_admin_probe' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_blank_public_request' ), 0 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'logout_url', array( $this, 'filter_logout_url' ), 10, 2 );
		add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 10, 2 );
		add_filter( 'register_url', array( $this, 'filter_register_url' ) );
		add_filter( 'login_title', array( $this, 'filter_login_title' ), 10, 2 );
		add_filter( 'login_headerurl', array( $this, 'filter_login_header_url' ) );
		add_filter( 'login_headertext', array( $this, 'filter_login_header_text' ) );
		add_filter( 'login_display_language_dropdown', '__return_false' );
		add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 4 );
		add_filter( 'rest_authentication_errors', array( $this, 'maybe_hide_rest' ) );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_branding' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_rewrites() {
		self::ensure_settings();
		self::add_rewrite_rules();
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public function maybe_render_custom_login() {
		if ( ! $this->is_custom_login_path() ) {
			return;
		}

		global $pagenow;

		$pagenow                = 'wp-login.php';
		$_SERVER['PHP_SELF']    = '/wp-login.php';
		$_SERVER['SCRIPT_NAME'] = '/wp-login.php';

		require ABSPATH . 'wp-login.php';
		exit;
	}

	public function maybe_block_default_login() {
		if ( wp_doing_ajax() || defined( 'WP_CLI' ) ) {
			return;
		}

		if ( $this->is_custom_login_path() ) {
			return;
		}

		$this->render_blank( 404 );
	}

	public function maybe_block_wp_admin_probe() {
		if ( is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$path = $this->request_path();

		if ( $this->is_allowed_admin_asset( $path ) ) {
			return;
		}

		if ( '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' ) ) {
			$this->render_blank( 404 );
		}
	}

	public function maybe_blank_public_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( get_query_var( self::QUERY_VAR ) || $this->is_custom_login_path() ) {
			return;
		}

		$this->render_blank( 200 );
	}

	public function filter_login_url( $login_url, $redirect, $force_reauth ) {
		$args = array();

		if ( ! empty( $redirect ) ) {
			$args['redirect_to'] = $redirect;
		}

		if ( $force_reauth ) {
			$args['reauth'] = '1';
		}

		return self::login_url( $args );
	}

	public function filter_logout_url( $logout_url, $redirect ) {
		$args = array( 'action' => 'logout' );

		if ( ! empty( $redirect ) ) {
			$args['redirect_to'] = $redirect;
		}

		if ( false !== strpos( $logout_url, '_wpnonce=' ) ) {
			parse_str( (string) wp_parse_url( $logout_url, PHP_URL_QUERY ), $query_args );
			if ( ! empty( $query_args['_wpnonce'] ) ) {
				$args['_wpnonce'] = $query_args['_wpnonce'];
			}
		}

		return self::login_url( $args );
	}

	public function filter_lostpassword_url( $lostpassword_url, $redirect ) {
		$args = array( 'action' => 'lostpassword' );

		if ( ! empty( $redirect ) ) {
			$args['redirect_to'] = $redirect;
		}

		return self::login_url( $args );
	}

	public function filter_register_url() {
		return self::login_url( array( 'action' => 'register' ) );
	}

	public function filter_login_title( $login_title, $title ) {
		unset( $login_title, $title );

		return 'Secure Access Portal';
	}

	public function filter_login_header_url() {
		return home_url( '/' );
	}

	public function filter_login_header_text() {
		return 'Secure Access Portal';
	}

	public function filter_site_url( $url, $path, $scheme, $blog_id ) {
		unset( $scheme, $blog_id );

		if ( 0 !== strpos( (string) $path, 'wp-login.php' ) ) {
			return $url;
		}

		$query = wp_parse_url( $path, PHP_URL_QUERY );
		$args  = array();

		if ( ! empty( $query ) ) {
			parse_str( $query, $args );
		}

		return self::login_url( $args );
	}

	public function maybe_hide_rest( $result ) {
		if ( ! empty( $result ) || is_user_logged_in() ) {
			return $result;
		}

		return new WP_Error( 'savedpixel_hidden_access_rest_hidden', 'Not found.', array( 'status' => 404 ) );
	}

	public function enqueue_login_branding() {
		?>
		<style>
			body.login {
				background: linear-gradient(180deg, #f4f1e8 0%, #e6edf2 100%);
			}
			body.login #login {
				padding-top: 7vh;
			}
			body.login #login h1 a {
				background-image: none;
				height: auto;
				margin: 0;
				text-indent: 0;
				width: auto;
			}
			body.login #login h1 a::before {
				color: #0f172a;
				content: 'Secure Access Portal';
				display: block;
				font-size: 26px;
				font-weight: 700;
				letter-spacing: 0.08em;
				text-align: center;
				text-transform: uppercase;
			}
			body.login #login form {
				border-radius: 14px;
				border: 1px solid #d6dce5;
				box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
			}
			body.login .message,
			body.login .notice,
			body.login #login_error {
				border-radius: 10px;
			}
			body.login #nav,
			body.login #backtoblog {
				display: none;
			}
		</style>
		<?php
	}

	public function register_settings_page() {
		add_submenu_page(
			function_exists( 'savedpixel_admin_parent_slug' ) ? savedpixel_admin_parent_slug() : 'options-general.php',
			'SavedPixel Hidden Access',
			'Hidden Access',
			'manage_options',
			'savedpixel-hidden-access',
			array( $this, 'render_settings_page' ),
			10
		);
	}

	public function enqueue_admin_assets() {
		if ( 'savedpixel-hidden-access' !== savedpixel_current_admin_page() ) {
			return;
		}

		savedpixel_admin_enqueue_preview_style();
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'savedpixel-hidden-access' ) );
		}

		$settings = self::settings();
		$updated  = false;
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'post' === $request_method && isset( $_POST['spha_save_settings'] ) ) {
			check_admin_referer( 'spha_save_settings' );

			$new_slug = self::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['spha_login_slug'] ?? '' ) ) );
			$changed  = $new_slug !== $settings['login_slug'];

			$settings['login_slug'] = $new_slug;
			update_option( self::OPTION, $settings );

			if ( $changed ) {
				self::add_rewrite_rules();
				flush_rewrite_rules();
			}

			$updated = true;
		}

		$login_url = self::login_url();
		?>
		<?php savedpixel_admin_page_start( 'spha-page' ); ?>
				<header id="spha-header" class="sp-page-header">
					<div id="spha-header-main">
						<h1 id="spha-header-title" class="sp-page-title">SavedPixel Hidden Access</h1>
						<p id="spha-header-desc" class="sp-page-desc">Control the private login slug used to reach this site and reduce direct access to WordPress login endpoints.</p>
					</div>
					<div id="spha-header-actions" class="sp-header-actions">
						<a id="spha-back-link" class="button" href="<?php echo esc_url( savedpixel_admin_page_url( savedpixel_admin_parent_slug() ) ); ?>">Back to Overview</a>
					</div>
				</header>

				<?php if ( $updated ) : ?>
					<div id="spha-notice" class="sp-note">
						<p id="spha-notice-text">Settings saved.</p>
					</div>
				<?php endif; ?>

				<div id="spha-settings-card" class="sp-card sp-card--hidden-access">
					<div id="spha-settings-body" class="sp-card__body">
						<h2 id="spha-settings-title">Access Settings</h2>
						<form id="spha-settings-form" method="post">
							<?php wp_nonce_field( 'spha_save_settings' ); ?>
							<table id="spha-settings-table" class="form-table sp-form-table">
								<tr id="spha-row-slug">
									<th><label for="spha_login_slug">Custom login slug</label></th>
									<td id="spha-field-slug">
										<input name="spha_login_slug" id="spha_login_slug" type="text" class="regular-text" value="<?php echo esc_attr( $settings['login_slug'] ); ?>">
										<p class="description">Direct requests to <code>/wp-login.php</code> and <code>/wp-admin/</code> are blanked for unauthenticated visitors.</p>
									</td>
								</tr>
								<tr id="spha-row-url">
									<th>Current login URL</th>
									<td id="spha-field-url">
										<input type="text" id="spha-current-login-url" class="large-text code" readonly value="<?php echo esc_attr( $login_url ); ?>">
										<p class="sp-inline-actions">
											<button id="spha-copy-url-btn" type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('spha-current-login-url').value).then(function(){var s=document.getElementById('spha-copy-status');s.textContent='Copied!';setTimeout(function(){s.textContent=''},1500)})">Copy URL</button>
											<a id="spha-open-link-btn" href="<?php echo esc_url( $login_url ); ?>" target="_blank" class="button">Open Link</a>
											<span id="spha-copy-status" class="sp-status-text" aria-live="polite"></span>
										</p>
									</td>
								</tr>
							</table>
							<p id="spha-submit-row" class="submit"><button type="submit" name="spha_save_settings" id="spha_save_settings" class="button button-primary">Save Settings</button></p>
						</form>
					</div>
				</div>
		<?php
		savedpixel_admin_page_end();
	}

	private function request_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$uri         = wp_parse_url( $request_uri, PHP_URL_PATH );

		return untrailingslashit( (string) $uri );
	}

	private function is_custom_login_path() {
		$path = $this->request_path();

		return '/' . self::login_slug() === $path;
	}

	private function is_allowed_admin_asset( $path ) {
		if ( preg_match( '#^/wp-admin/(load-(styles|scripts)\.php|css/|images/|js/)#', $path ) ) {
			return true;
		}

		return in_array( $path, array( '/wp-admin/admin-ajax.php', '/wp-admin/async-upload.php' ), true );
	}

	private function render_blank( $status = 200 ) {
		status_header( $status );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		exit;
	}
}

if ( ! class_exists( 'Backup_Stealth_Access', false ) ) {
	class_alias( 'SavedPixel_Hidden_Access', 'Backup_Stealth_Access' );
}

register_activation_hook( __FILE__, array( 'SavedPixel_Hidden_Access', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SavedPixel_Hidden_Access', 'deactivate' ) );

SavedPixel_Hidden_Access::bootstrap();
