<?php
/**
 * Plugin Name: SGP Updater
 * Plugin URI:  https://github.com/SGP-Design/sgp-updater
 * Description: Keeps the active SGP-built theme updated from its GitHub repository, using WordPress's own update flow.
 * Version:     1.1.0
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

define( 'SGP_UPDATER_VERSION', '1.1.0' );

/**
 * Normalize a client theme repository value to a GitHub URL.
 *
 * Accepts a full URL, owner/repository, or just the SGP client repository name.
 *
 * @param mixed $value Repository value.
 * @return string
 */
function sgp_updater_normalize_repo_url( $value ) {
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return '';
	}

	$value = trim( $value );
	$value = preg_replace( '#\.git$#i', '', $value );

	if ( preg_match( '#^https?://#i', $value ) ) {
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'github.com' !== strtolower( $parts['host'] ) ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
	} else {
		$path = trim( $value, '/' );
	}

	if ( false === strpos( $path, '/' ) ) {
		$path = 'SGP-Design/' . $path;
	}

	if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $path ) ) {
		return '';
	}

	return 'https://github.com/' . $path;
}

/**
 * Repository URL declared by the active theme itself.
 *
 * This is deliberately separate from the configured repository. Before the
 * bootstrap theme exists, the active WordPress theme must never be treated as
 * the target of the client repository updater.
 *
 * @return string
 */
function sgp_updater_active_theme_repo_url() {
	$headers = get_file_data(
		get_template_directory() . '/style.css',
		array( 'repo' => 'GitHub Theme URI' )
	);

	$uri = isset( $headers['repo'] ) ? $headers['repo'] : '';

	return sgp_updater_normalize_repo_url( $uri );
}

/**
 * Client repository saved during SGP Updater setup.
 *
 * @return string
 */
function sgp_updater_configured_repo_url() {
	return sgp_updater_normalize_repo_url( get_option( 'sgp_updater_theme_repo_url', '' ) );
}

/**
 * Repository used by the setup/status UI.
 *
 * A repository explicitly selected during setup wins. Existing sites that
 * predate this setup field continue to fall back to the active theme header.
 *
 * @return string
 */
function sgp_updater_theme_repo_url() {
	$configured = sgp_updater_configured_repo_url();

	return '' !== $configured ? $configured : sgp_updater_active_theme_repo_url();
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
 * Force an update checker to read the branch, ignoring releases and tags.
 *
 * With the branch set to "main", plugin-update-checker tries the latest
 * GitHub Release first, then the highest tag, and only then the branch. So a
 * repository carrying an old release reports that release as the newest
 * version no matter what the branch says - which is how this plugin sat at
 * 1.0.1 while main was several versions ahead.
 *
 * The documented release process here is "bump Version, commit, push". No
 * tags, no releases, no build step. This makes the checker behave that way,
 * for the theme and for the plugin alike, so a stray tag left in a repository
 * can never quietly become the update source.
 *
 * @param object $checker A plugin or theme update checker.
 * @return void
 */
function sgp_updater_use_branch_only( $checker ) {
	add_filter(
		'puc_vcs_update_detection_strategies-' . $checker->slug,
		function ( $strategies ) {
			// Keys defined by Puc\v5\Vcs\Api: 'latest_release', 'latest_tag',
			// 'stable_tag', 'branch'. Everything but the branch goes.
			return array_intersect_key( $strategies, array( 'branch' => true ) );
		}
	);
}

/**
 * Wire the active theme up to its GitHub repository.
 */
function sgp_updater_init_theme_updater() {
	$repo = sgp_updater_active_theme_repo_url();

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

	sgp_updater_use_branch_only( $checker );

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

	// No token. This repository is public precisely so the plugin can update
	// itself without one - which keeps the token a client site holds scoped to
	// that client's own theme repository, and nothing of SGP's. Sending the
	// theme token here would re-create the coupling the public repo removes.
	sgp_updater_use_branch_only( $checker );

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
 * Register the updater setup settings.
 */
function sgp_updater_register_settings() {
	register_setting(
		'sgp_updater',
		'sgp_updater_theme_repo_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sgp_updater_sanitize_repo_url',
			'default'           => '',
			'show_in_rest'      => false,
		)
	);

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
 * Normalize the repository field saved from the setup screen.
 *
 * @param mixed $value Raw repository value.
 * @return string
 */
function sgp_updater_sanitize_repo_url( $value ) {
	return sgp_updater_normalize_repo_url( $value );
}


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
add_action( 'update_option_sgp_updater_theme_repo_url', 'sgp_updater_flush_status' );
add_action( 'add_option_sgp_updater_theme_repo_url', 'sgp_updater_flush_status' );

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
			'message' => __( 'No client theme repository has been selected yet.', 'sgp-updater' ),
			'fix'     => __( 'Choose the client repository below, then connect the site.', 'sgp-updater' ),
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
 * Derive the client theme folder from the standard <slug>-website repository.
 *
 * @param string $repo Repository URL.
 * @return string|WP_Error
 */
function sgp_updater_bootstrap_theme_slug( $repo ) {
	$path = wp_parse_url( $repo, PHP_URL_PATH );
	$name = is_string( $path ) ? basename( trim( $path, '/' ) ) : '';
	$name = preg_replace( '/\.git$/i', '', $name );

	if ( ! str_ends_with( $name, '-website' ) ) {
		return new WP_Error(
			'sgp_updater_repo_name',
			__( 'Client repositories must use the standard <slug>-website name before SGP Updater can prepare the theme.', 'sgp-updater' )
		);
	}

	$slug = substr( $name, 0, -strlen( '-website' ) );

	if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $slug ) ) {
		return new WP_Error(
			'sgp_updater_theme_slug',
			__( 'The repository name does not produce a valid SGP theme folder. Use the same lowercase slug used when the client site was scaffolded.', 'sgp-updater' )
		);
	}

	return $slug;
}

/**
 * Create and activate the tiny placeholder theme that gives WordPress a target
 * for the first real client-theme update.
 *
 * The folder and GitHub Theme URI are identical to the eventual client theme,
 * so version 0.0.0 is replaced through the normal SGP Updater path rather than
 * through a one-off theme upload.
 *
 * @param string $repo Repository URL.
 * @return array|WP_Error
 */
function sgp_updater_prepare_bootstrap_theme( $repo ) {
	$repo = sgp_updater_normalize_repo_url( $repo );
	if ( '' === $repo ) {
		return new WP_Error( 'sgp_updater_repo_missing', __( 'A valid client repository is required.', 'sgp-updater' ) );
	}

	$slug = sgp_updater_bootstrap_theme_slug( $repo );
	if ( is_wp_error( $slug ) ) {
		return $slug;
	}

	$theme_root = get_theme_root();
	$theme_dir  = trailingslashit( $theme_root ) . $slug;
	$style_path = trailingslashit( $theme_dir ) . 'style.css';
	$index_path = trailingslashit( $theme_dir ) . 'index.php';

	if ( is_dir( $theme_dir ) ) {
		if ( ! is_file( $style_path ) ) {
			return new WP_Error(
				'sgp_updater_theme_conflict',
				sprintf( __( 'The theme folder %s already exists but is not a valid theme. SGP Updater will not overwrite it.', 'sgp-updater' ), $slug )
			);
		}

		$headers = get_file_data( $style_path, array( 'repo' => 'GitHub Theme URI' ) );
		$existing_repo = sgp_updater_normalize_repo_url( isset( $headers['repo'] ) ? $headers['repo'] : '' );

		if ( $repo !== $existing_repo ) {
			return new WP_Error(
				'sgp_updater_theme_conflict',
				sprintf( __( 'The theme folder %s already belongs to a different theme. SGP Updater will not overwrite it.', 'sgp-updater' ), $slug )
			);
		}

		switch_theme( $slug );
		delete_transient( 'sgp_updater_status' );

		return array( 'slug' => $slug, 'created' => false );
	}

	if ( ! wp_mkdir_p( $theme_dir ) ) {
		return new WP_Error(
			'sgp_updater_theme_directory',
			__( 'WordPress could not create the client theme folder. Check filesystem permissions on wp-content/themes.', 'sgp-updater' )
		);
	}

	$display_name = ucwords( str_replace( '_', ' ', $slug ) ) . ' (SGP Bootstrap)';
	$style = "/*\n"
		. "Theme Name: " . $display_name . "\n"
		. "Description: Temporary SGP bootstrap theme. The first client build replaces this through SGP Updater.\n"
		. "Version: 0.0.0\n"
		. "Requires at least: 6.0\n"
		. "Requires PHP: 8.0\n"
		. "Author: Strategic Growth Partners\n"
		. "GitHub Theme URI: " . $repo . "\n"
		. "*/\n\n"
		. "html,body{margin:0;min-height:100%;font-family:system-ui,sans-serif;background:#fff;color:#111}\n"
		. ".sgp-bootstrap{min-height:100vh;display:grid;place-items:center;padding:2rem;text-align:center;box-sizing:border-box}\n";

	$index = "<?php\n"
		. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
		. "?>\n"
		. "<!doctype html><html <?php language_attributes(); ?>><head><meta charset=\"<?php bloginfo( 'charset' ); ?>\">"
		. "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><?php wp_head(); ?></head>"
		. "<body <?php body_class(); ?>><main class=\"sgp-bootstrap\"><div><h1>SGP client theme connected</h1>"
		. "<p>The first approved client build will replace this bootstrap theme through SGP Updater.</p></div></main>"
		. "<?php wp_footer(); ?></body></html>\n";

	if ( false === file_put_contents( $style_path, $style, LOCK_EX ) || false === file_put_contents( $index_path, $index, LOCK_EX ) ) {
		if ( is_file( $style_path ) ) {
			unlink( $style_path );
		}
		if ( is_file( $index_path ) ) {
			unlink( $index_path );
		}
		if ( is_dir( $theme_dir ) ) {
			rmdir( $theme_dir );
		}

		return new WP_Error(
			'sgp_updater_theme_write',
			__( 'WordPress could not write the bootstrap theme files. Check filesystem permissions on wp-content/themes.', 'sgp-updater' )
		);
	}

	wp_clean_themes_cache( true );
	switch_theme( $slug );
	delete_transient( 'sgp_updater_status' );

	return array( 'slug' => $slug, 'created' => true );
}

/**
 * After a successful first-run connection, prepare the bootstrap theme
 * automatically. This turns repository + token into the only setup step.
 *
 * @return void
 */
function sgp_updater_finish_setup_after_save() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! isset( $_GET['page'], $_GET['settings-updated'] ) || 'sgp-updater' !== $_GET['page'] || 'true' !== $_GET['settings-updated'] ) {
		return;
	}

	$status = sgp_updater_connection_status( true );
	if ( ! $status['ok'] ) {
		return;
	}

	$repo = sgp_updater_theme_repo_url();
	if ( '' !== sgp_updater_active_theme_repo_url() && $repo === sgp_updater_active_theme_repo_url() ) {
		return;
	}

	$result = sgp_updater_prepare_bootstrap_theme( $repo );
	$key = 'sgp_updater_setup_notice_' . get_current_user_id();

	if ( is_wp_error( $result ) ) {
		set_transient( $key, array( 'ok' => false, 'message' => $result->get_error_message() ), MINUTE_IN_SECONDS );
		return;
	}

	set_transient(
		$key,
		array(
			'ok'      => true,
			'message' => __( 'Connected. SGP Updater prepared and activated the client bootstrap theme. The first real theme version can now arrive through the normal WordPress update flow.', 'sgp-updater' ),
		),
		MINUTE_IN_SECONDS
	);
}
add_action( 'admin_init', 'sgp_updater_finish_setup_after_save', 20 );

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
	$notice_key = 'sgp_updater_setup_notice_' . get_current_user_id();
	$setup_notice = get_transient( $notice_key );
	if ( false !== $setup_notice ) {
		delete_transient( $notice_key );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SGP Updater', 'sgp-updater' ); ?></h1>

		<?php if ( is_array( $setup_notice ) && isset( $setup_notice['message'] ) ) : ?>
			<div class="notice <?php echo ! empty( $setup_notice['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline" style="margin:1em 0;padding:12px;">
				<p style="margin:0;"><?php echo esc_html( $setup_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

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

		<h2><?php esc_html_e( 'Connect this site to its client theme', 'sgp-updater' ); ?></h2>
		<p style="max-width:820px;">
			<?php esc_html_e( 'Choose the private client repository and provide the read-only GitHub token once. After the connection passes, SGP Updater prepares the correctly named bootstrap theme automatically so the first real build arrives through the same update flow as every later release.', 'sgp-updater' ); ?>
		</p>

		<?php if ( ! sgp_updater_token_is_constant() && '' === $token ) : ?>
			<div style="max-width:820px;background:#fff;border:1px solid #c3c4c7;padding:12px 18px;margin-bottom:1.5em;">
				<p style="margin-top:0;">
					<a class="button button-secondary" href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Create a token on GitHub', 'sgp-updater' ); ?>
					</a>
				</p>
				<p style="margin-bottom:.5em;"><strong><?php esc_html_e( 'Set these four things:', 'sgp-updater' ); ?></strong></p>
				<ol style="margin:0 0 .5em 1.4em;">
					<li><strong><?php esc_html_e( 'Resource owner', 'sgp-updater' ); ?></strong> — <?php esc_html_e( 'SGP-Design.', 'sgp-updater' ); ?></li>
					<li><strong><?php esc_html_e( 'Repository access', 'sgp-updater' ); ?></strong> — <?php esc_html_e( 'Only select repositories, then choose this client theme repository only.', 'sgp-updater' ); ?></li>
					<li><strong><?php esc_html_e( 'Repository permissions → Contents → Read-only', 'sgp-updater' ); ?></strong> — <?php esc_html_e( 'this lets WordPress download the theme without granting write access.', 'sgp-updater' ); ?></li>
					<li><strong><?php esc_html_e( 'Expiration', 'sgp-updater' ); ?></strong> — <?php esc_html_e( 'set a calendar reminder before it lapses.', 'sgp-updater' ); ?></li>
				</ol>
				<p style="margin-bottom:0;"><?php esc_html_e( 'GitHub shows the token once. Copy it before leaving the page.', 'sgp-updater' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'sgp_updater' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sgp_updater_theme_repo_url"><?php esc_html_e( 'Client repository', 'sgp-updater' ); ?></label></th>
					<td>
						<input
							type="text"
							id="sgp_updater_theme_repo_url"
							name="sgp_updater_theme_repo_url"
							value="<?php echo esc_attr( '' !== sgp_updater_configured_repo_url() ? sgp_updater_configured_repo_url() : $repo ); ?>"
							class="regular-text"
							placeholder="halaakwa-website"
						/>
						<p class="description"><?php esc_html_e( 'Repository name is enough; SGP-Design/ and the GitHub URL are filled in automatically.', 'sgp-updater' ); ?></p>
					</td>
				</tr>
				<?php if ( sgp_updater_token_is_constant() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'GitHub access token', 'sgp-updater' ); ?></th>
						<td><?php esc_html_e( 'Set in wp-config.php.', 'sgp-updater' ); ?></td>
					</tr>
				<?php else : ?>
					<tr>
						<th scope="row"><label for="sgp_updater_github_token"><?php esc_html_e( 'GitHub access token', 'sgp-updater' ); ?></label></th>
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
						</td>
					</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( '' === sgp_updater_active_theme_repo_url() ? __( 'Connect and prepare theme', 'sgp-updater' ) : __( 'Save and check connection', 'sgp-updater' ) ); ?>
		</form>

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
