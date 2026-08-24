<?php
/**
 * Plugin Name: SGP Updater
 * Plugin URI:  https://github.com/SGP-Design/sgp-updater
 * Description: Keeps the active SGP-built theme updated from its GitHub repository, using WordPress's own update flow.
 * Version:     1.0.2
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

define( 'SGP_UPDATER_VERSION', '1.0.2' );

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
		__( 'SGP Updater', 'sgp-updater' ),
		__( 'SGP Updater', 'sgp-updater' ),
		'manage_options',
		'sgp-updater',
		'sgp_updater_render_settings_page'
	);
}
add_action( 'admin_menu', 'sgp_updater_admin_menu' );

/**
 * Add a Settings link under the plugin's name on the Plugins screen.
 *
 * Without this the settings page is only reachable from the Settings menu,
 * which is not where anyone looks after activating a plugin.
 *
 * @param array $links Existing action links.
 * @return array
 */
function sgp_updater_action_links( $links ) {
	$status = sgp_updater_connection_status();

	// While the connection is broken the link is the next thing to do, so it
	// says so and is emphasised. "Settings" reads as optional configuration
	// and is easy to skip past on a screen full of plugin rows.
	$label = $status['ok']
		? esc_html__( 'Settings', 'sgp-updater' )
		: '<strong>' . esc_html__( 'Finish setup', 'sgp-updater' ) . '</strong>';

	$settings = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=sgp-updater' ) ),
		$label
	);

	array_unshift( $links, $settings );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sgp_updater_action_links' );

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
 * Connection status, cached.
 *
 * The status is shown on the Plugins, Themes, Dashboard and Updates screens, so
 * an uncached check would make two GitHub API calls on every one of those page
 * loads - and a slow or unreachable host would stall the admin for the length
 * of the timeout. Five minutes is short enough that a token fix shows up almost
 * immediately and long enough that normal admin use costs nothing.
 *
 * Saving the token and the Re-check button both clear it, so the two moments
 * when someone is actually waiting for an answer always get a live result.
 *
 * @param bool $fresh Skip the cache and ask GitHub now.
 * @return array{ok:bool,message:string,fix:string}
 */
function sgp_updater_connection_status( $fresh = false ) {
	if ( ! $fresh ) {
		$cached = get_transient( 'sgp_updater_status' );

		if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
			return $cached;
		}
	}

	$status = sgp_updater_check_connection();

	set_transient( 'sgp_updater_status', $status, 5 * MINUTE_IN_SECONDS );

	return $status;
}

/**
 * Drop the cached status whenever the token changes.
 *
 * @return void
 */
function sgp_updater_flush_status() {
	delete_transient( 'sgp_updater_status' );
}
add_action( 'update_option_sgp_updater_github_token', 'sgp_updater_flush_status' );
add_action( 'add_option_sgp_updater_github_token', 'sgp_updater_flush_status' );

/**
 * Ask GitHub whether the repository is reachable with the current credentials.
 *
 * Used only by the settings screen, to separate a token problem from a
 * connectivity problem from a header problem.
 *
 * @return array{ok:bool,message:string}
 */
function sgp_updater_check_connection() {
	$repo = sgp_updater_theme_repo_url();

	if ( '' === $repo ) {
		return array(
			'ok'      => false,
			'message' => __( 'The active theme has no "GitHub Theme URI" header, so there is no repository to check.', 'sgp-updater' ),
			'fix'     => __( 'This is a theme problem, not a token problem. The theme needs the header before it can be updated from GitHub.', 'sgp-updater' ),
		);
	}

	$path = wp_parse_url( $repo, PHP_URL_PATH );
	if ( ! is_string( $path ) || ! preg_match( '#^/([^/]+)/([^/]+)#', $path, $m ) ) {
		return array(
			'ok'      => false,
			'message' => __( 'The theme\'s "GitHub Theme URI" header is not a recognisable owner/repository address.', 'sgp-updater' ),
			'fix'     => __( 'It should read like SGP-Design/example-website.', 'sgp-updater' ),
		);
	}

	$slug  = "{$m[1]}/{$m[2]}";
	$token = sgp_updater_github_token();

	// Stage one: can we see the repository at all? This only needs the
	// Metadata permission, which GitHub grants automatically.
	$repo_call = sgp_updater_github_get( "https://api.github.com/repos/{$slug}" );

	if ( is_wp_error( $repo_call ) ) {
		return array(
			'ok'      => false,
			/* translators: %s: error message returned by WordPress. */
			'message' => sprintf( __( 'Could not reach github.com: %s', 'sgp-updater' ), $repo_call->get_error_message() ),
			'fix'     => __( 'The site could not make an outbound connection. That is a hosting question rather than a token question.', 'sgp-updater' ),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $repo_call );

	if ( 401 === $code ) {
		return array(
			'ok'      => false,
			'message' => __( 'GitHub rejected the token.', 'sgp-updater' ),
			'fix'     => __( 'The token is wrong, was truncated when pasted, or has expired. Generate a new one and paste it again.', 'sgp-updater' ),
		);
	}

	if ( 404 === $code ) {
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				/* translators: %s: owner/repository. */
				'message' => sprintf( __( '%s is private and no token has been saved yet.', 'sgp-updater' ), $slug ),
				'fix'     => __( 'Create a token below and paste it in.', 'sgp-updater' ),
			);
		}

		return array(
			'ok'      => false,
			/* translators: %s: owner/repository. */
			'message' => sprintf( __( 'The token cannot see %s.', 'sgp-updater' ), $slug ),
			'fix'     => __( 'Usually the Resource owner was left as your personal account instead of the organisation, or this repository was not ticked under Repository access.', 'sgp-updater' ),
		);
	}

	if ( 200 !== $code ) {
		return array(
			'ok'      => false,
			/* translators: %d: HTTP status code. */
			'message' => sprintf( __( 'GitHub returned an unexpected status (%d).', 'sgp-updater' ), $code ),
			'fix'     => '',
		);
	}

	// Stage two: can we actually read files? Downloading the theme needs the
	// Contents permission, and seeing the repository does not imply having it.
	// Checking only stage one reports a healthy connection for a token that
	// cannot download a single update.
	$contents = sgp_updater_github_get( "https://api.github.com/repos/{$slug}/contents" );

	if ( is_wp_error( $contents ) ) {
		return array(
			'ok'      => false,
			/* translators: %s: error message returned by WordPress. */
			'message' => sprintf( __( 'Could not reach github.com: %s', 'sgp-updater' ), $contents->get_error_message() ),
			'fix'     => '',
		);
	}

	$contents_code = (int) wp_remote_retrieve_response_code( $contents );

	if ( 200 !== $contents_code ) {
		return array(
			'ok'      => false,
			/* translators: %s: owner/repository. */
			'message' => sprintf( __( 'The token can see %s but cannot read its files.', 'sgp-updater' ), $slug ),
			'fix'     => __( 'The token is missing the Contents permission. Edit it on GitHub, and under Repository permissions set Contents to Read-only.', 'sgp-updater' ),
		);
	}

	return array(
		'ok'      => true,
		/* translators: %s: owner/repository. */
		'message' => sprintf( __( 'Connected to %s and able to download updates.', 'sgp-updater' ), $slug ),
		'fix'     => '',
	);
}

/**
 * GET a GitHub API URL with the current token attached.
 *
 * @param string $url Full API URL.
 * @return array|WP_Error
 */
function sgp_updater_github_get( $url ) {
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

	return wp_remote_get( $url, $args );
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
	$status = sgp_updater_connection_status( true );
	$token  = sgp_updater_github_token();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SGP Updater', 'sgp-updater' ); ?></h1>

		<div class="notice <?php echo $status['ok'] ? 'notice-success' : 'notice-error'; ?> inline" style="margin:1em 0;padding:12px;">
			<p style="margin:0;font-size:14px;">
				<strong>
					<?php if ( $status['ok'] ) : ?>
						<span style="color:#008a20;">&#10003;</span> <?php esc_html_e( 'Connected', 'sgp-updater' ); ?>
					<?php else : ?>
						<span style="color:#d63638;">&#10007;</span> <?php esc_html_e( 'Not connected', 'sgp-updater' ); ?>
					<?php endif; ?>
				</strong>
				&mdash; <?php echo esc_html( $status['message'] ); ?>
			</p>
			<?php if ( ! empty( $status['fix'] ) ) : ?>
				<p style="margin:.5em 0 0;"><?php echo esc_html( $status['fix'] ); ?></p>
			<?php endif; ?>
			<p style="margin:.75em 0 0;">
				<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=sgp-updater&recheck=1' ) ); ?>">
					<?php esc_html_e( 'Re-check connection', 'sgp-updater' ); ?>
				</a>
			</p>
		</div>

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
			</tbody>
		</table>

		<?php if ( sgp_updater_token_is_constant() ) : ?>
			<p><?php esc_html_e( 'The access token is set in wp-config.php, so it cannot be changed here.', 'sgp-updater' ); ?></p>
		<?php else : ?>

			<h2><?php esc_html_e( 'Access token', 'sgp-updater' ); ?></h2>

			<?php if ( '' === $token ) : ?>
				<p style="max-width:820px;">
					<?php esc_html_e( 'The theme lives in a private repository, so the site needs a read-only GitHub token to download updates. It takes about a minute to create.', 'sgp-updater' ); ?>
				</p>
			<?php endif; ?>

			<div style="max-width:820px;background:#fff;border:1px solid #c3c4c7;padding:12px 18px;margin-bottom:1.5em;">
				<p style="margin-top:0;">
					<a class="button button-secondary" href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Create a token on GitHub', 'sgp-updater' ); ?>
					</a>
				</p>
				<p style="margin-bottom:.5em;"><strong><?php esc_html_e( 'Set these four things:', 'sgp-updater' ); ?></strong></p>
				<ol style="margin:0 0 .5em 1.4em;">
					<li>
						<strong><?php esc_html_e( 'Resource owner', 'sgp-updater' ); ?></strong>
						&mdash; <?php esc_html_e( 'change it to the organisation that owns the repository. It defaults to your personal account, and a personal token cannot see the organisation\'s private repositories.', 'sgp-updater' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Repository access', 'sgp-updater' ); ?></strong>
						&mdash; <?php esc_html_e( 'choose Only select repositories, then tick this theme\'s repository and the sgp-updater repository.', 'sgp-updater' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Repository permissions → Contents → Read-only', 'sgp-updater' ); ?></strong>
						&mdash; <?php esc_html_e( 'this is the one people miss. Without it the token can see the repository but cannot download a single file, and updates never appear.', 'sgp-updater' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Expiration', 'sgp-updater' ); ?></strong>
						&mdash; <?php esc_html_e( 'set a calendar reminder for a week before it lapses. Updates stop silently when a token expires.', 'sgp-updater' ); ?>
					</li>
				</ol>
				<p style="margin-bottom:0;"><?php esc_html_e( 'GitHub shows the token once. Copy it before leaving the page.', 'sgp-updater' ); ?></p>
			</div>

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
								placeholder="github_pat_..."
							/>
							<p class="description">
								<?php esc_html_e( 'Saving re-checks the connection straight away and reports the result above.', 'sgp-updater' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save and check connection', 'sgp-updater' ) ); ?>
			</form>
		<?php endif; ?>

		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Updates appear under Dashboard → Updates and Appearance → Themes, the same as any other theme update. An update is offered whenever the Version header in the repository is higher than the installed version.', 'sgp-updater' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Warn on the screens where someone would expect an update to appear.
 *
 * Without this the failure is silent: the theme simply never offers an update
 * and nothing explains why. The notice only shows to users who can fix it, and
 * only on the screens where the absence would be noticed.
 *
 * @return void
 */
function sgp_updater_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'themes', 'update-core', 'dashboard' ), true ) ) {
		return;
	}

	$status = sgp_updater_connection_status();
	if ( $status['ok'] ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'SGP Updater is not connected.', 'sgp-updater' ); ?></strong>
			<?php echo esc_html( $status['message'] ); ?>
			<?php if ( ! empty( $status['fix'] ) ) : ?>
				<?php echo esc_html( $status['fix'] ); ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=sgp-updater' ) ); ?>">
				<?php esc_html_e( 'Fix this', 'sgp-updater' ); ?>
			</a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'sgp_updater_admin_notice' );
