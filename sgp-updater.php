<?php
/**
 * Plugin Name: SGP Updater
 * Plugin URI:  https://github.com/SGP-Design/sgp-updater
 * Description: Keeps the active SGP-built theme updated from its GitHub repository, using WordPress's own update flow.
 * Version:     1.0.0
 * Author:      Strategic Growth Partners
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package SGP_Updater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SGP_UPDATER_VERSION', '1.0.0' );

/**
 * Read the repository URL from the active theme's `GitHub Theme URI` header.
 *
 * Deliberately the same header Git Updater uses, so a site can be moved between
 * the two without editing the theme.
 *
 * @return string Repository URL, or '' if the theme doesn't declare one.
 */
function sgp_updater_theme_repo_url() {
	// WP_Theme::get() only recognises WordPress's own fixed set of headers and
	// silently ignores custom ones, so read style.css directly.
	$headers = get_file_data(
		get_template_directory() . '/style.css',
		array( 'repo' => 'GitHub Theme URI' )
	);

	$uri = isset( $headers['repo'] ) ? $headers['repo'] : '';

	if ( ! is_string( $uri ) || '' === trim( $uri ) ) {
		return '';
	}

	$uri = trim( $uri );

	// Accept a bare `owner/repo` as well as a full URL.
	if ( ! preg_match( '#^https?://#i', $uri ) ) {
		$uri = 'https://github.com/' . ltrim( $uri, '/' );
	}

	return esc_url_raw( untrailingslashit( $uri ) );
}

/**
 * The GitHub token, preferring a wp-config.php constant over the stored option.
 *
 * A constant keeps the token out of the database, so it isn't exposed by a
 * database export. The option exists for sites where wp-config.php can't be
 * edited.
 *
 * @return string
 */
function sgp_updater_github_token() {
	if ( defined( 'SGP_GITHUB_TOKEN' ) && is_string( SGP_GITHUB_TOKEN ) && '' !== SGP_GITHUB_TOKEN ) {
		return SGP_GITHUB_TOKEN;
	}

	$token = get_option( 'sgp_updater_github_token', '' );

	return is_string( $token ) ? $token : '';
}

/**
 * Whether the token is pinned in wp-config.php rather than stored in the database.
 *
 * @return bool
 */
function sgp_updater_token_is_constant() {
	return defined( 'SGP_GITHUB_TOKEN' ) && is_string( SGP_GITHUB_TOKEN ) && '' !== SGP_GITHUB_TOKEN;
}

/**
 * Wire the active theme up to its GitHub repository.
 */
function sgp_updater_init_theme_updater() {
	$repo = sgp_updater_theme_repo_url();

	if ( '' === $repo ) {
		return;
	}

	require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

	$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$repo,
		get_template_directory() . '/style.css',
		get_template()
	);

	// Track the branch and compare the `Version:` header in style.css, so a
	// version bump plus a push is all it takes to offer an update. No tags or
	// releases to remember.
	$checker->setBranch( 'main' );

	$token = sgp_updater_github_token();
	if ( '' !== $token ) {
		$checker->setAuthentication( $token );
	}

	$GLOBALS['sgp_updater_checker'] = $checker;
}
add_action( 'plugins_loaded', 'sgp_updater_init_theme_updater' );

/**
 * Keep this plugin updated from its own repository.
 *
 * Without this, updating SGP Updater itself would mean uploading a zip by hand —
 * exactly the chore the plugin exists to remove.
 */
function sgp_updater_init_self_updater() {
	require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

	$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/SGP-Design/sgp-updater',
		__FILE__,
		'sgp-updater'
	);

	$checker->setBranch( 'main' );

	$token = sgp_updater_github_token();
	if ( '' !== $token ) {
		$checker->setAuthentication( $token );
	}

	$GLOBALS['sgp_updater_self_checker'] = $checker;
}
add_action( 'plugins_loaded', 'sgp_updater_init_self_updater' );

/* -------------------------------------------------------------------------
 * Settings screen
 * ---------------------------------------------------------------------- */

/**
 * Register the settings page.
 */
function sgp_updater_admin_menu() {
	add_options_page(
		__( 'SGP Updates', 'sgp-updater' ),
		__( 'SGP Updates', 'sgp-updater' ),
		'manage_options',
		'sgp-updater',
		'sgp_updater_render_settings_page'
	);
}
add_action( 'admin_menu', 'sgp_updater_admin_menu' );

/**
 * Register the token setting.
 */
function sgp_updater_register_settings() {
	register_setting(
		'sgp_updater',
		'sgp_updater_github_token',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sgp_updater_sanitize_token',
			'default'           => '',
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'sgp_updater_register_settings' );

/**
 * Strip whitespace and control characters from a submitted token.
 *
 * @param mixed $value Raw submitted value.
 * @return string
 */
function sgp_updater_sanitize_token( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	return preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $value ) );
}

/**
 * Ask GitHub whether the repository is reachable with the current credentials.
 *
 * Used only by the settings screen, to separate a token problem from a
 * connectivity problem from a header problem.
 *
 * @return array{ok:bool,message:string}
 */
function sgp_updater_connection_status() {
	$repo = sgp_updater_theme_repo_url();

	if ( '' === $repo ) {
		return array(
			'ok'      => false,
			'message' => __( 'The active theme has no "GitHub Theme URI" header, so there is no repository to check.', 'sgp-updater' ),
		);
	}

	$path = wp_parse_url( $repo, PHP_URL_PATH );
	if ( ! is_string( $path ) || ! preg_match( '#^/([^/]+)/([^/]+)#', $path, $m ) ) {
		return array(
			'ok'      => false,
			'message' => __( 'The theme\'s "GitHub Theme URI" header is not a recognisable owner/repository address.', 'sgp-updater' ),
		);
	}

	$args = array(
		'timeout' => 15,
		'headers' => array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'SGP-Updater/' . SGP_UPDATER_VERSION,
		),
	);

	$token = sgp_updater_github_token();
	if ( '' !== $token ) {
		$args['headers']['Authorization'] = 'Bearer ' . $token;
	}

	$response = wp_remote_get( "https://api.github.com/repos/{$m[1]}/{$m[2]}", $args );

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'      => false,
			/* translators: %s: error message returned by WordPress. */
			'message' => sprintf( __( 'Could not reach github.com: %s. This usually means the host is blocking outbound connections.', 'sgp-updater' ), $response->get_error_message() ),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 === $code ) {
		return array(
			'ok'      => true,
			/* translators: %s: owner/repository. */
			'message' => sprintf( __( 'Connected to %s.', 'sgp-updater' ), "{$m[1]}/{$m[2]}" ),
		);
	}

	if ( 401 === $code ) {
		return array(
			'ok'      => false,
			'message' => __( 'GitHub rejected the token. Check that it is correct and has not expired.', 'sgp-updater' ),
		);
	}

	if ( 404 === $code ) {
		return array(
			'ok'      => false,
			'message' => __( 'Repository not found. For a private repository this also happens when the token has no access to it.', 'sgp-updater' ),
		);
	}

	return array(
		'ok'      => false,
		/* translators: %d: HTTP status code. */
		'message' => sprintf( __( 'GitHub returned an unexpected status (%d).', 'sgp-updater' ), $code ),
	);
}

/**
 * Render the settings screen.
 */
function sgp_updater_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$repo   = sgp_updater_theme_repo_url();
	$theme  = wp_get_theme( get_template() );
	$status = sgp_updater_connection_status();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SGP Updates', 'sgp-updater' ); ?></h1>

		<table class="widefat striped" style="max-width:820px;margin-bottom:1.5em;">
			<tbody>
				<tr>
					<th scope="row" style="width:180px;"><?php esc_html_e( 'Theme', 'sgp-updater' ); ?></th>
					<td><?php echo esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Repository', 'sgp-updater' ); ?></th>
					<td>
						<?php if ( '' === $repo ) : ?>
							<em><?php esc_html_e( 'none declared by the theme', 'sgp-updater' ); ?></em>
						<?php else : ?>
							<code><?php echo esc_html( $repo ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Branch', 'sgp-updater' ); ?></th>
					<td><code>main</code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'sgp-updater' ); ?></th>
					<td>
						<?php if ( $status['ok'] ) : ?>
							<span style="color:#008a20;font-weight:600;">&#10003;</span>
						<?php else : ?>
							<span style="color:#d63638;font-weight:600;">&#10007;</span>
						<?php endif; ?>
						<?php echo esc_html( $status['message'] ); ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( sgp_updater_token_is_constant() ) : ?>
			<p><?php esc_html_e( 'The access token is set in wp-config.php, so it cannot be changed here.', 'sgp-updater' ); ?></p>
		<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'sgp_updater' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sgp_updater_github_token"><?php esc_html_e( 'GitHub access token', 'sgp-updater' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="sgp_updater_github_token"
								name="sgp_updater_github_token"
								value="<?php echo esc_attr( get_option( 'sgp_updater_github_token', '' ) ); ?>"
								class="regular-text"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'Required for a private repository. Needs read access to the theme repository and nothing else.', 'sgp-updater' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		<?php endif; ?>

		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Updates appear under Dashboard → Updates and Appearance → Themes, the same as any other theme update. An update is offered whenever the Version header in the repository is higher than the installed version.', 'sgp-updater' ); ?>
		</p>
	</div>
	<?php
}
