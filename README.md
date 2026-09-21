# SGP Updater

Keeps an SGP-built WordPress theme connected to its private GitHub repository through WordPress's normal theme update flow.

For new client sites, SGP Updater also handles the first theme connection. The designer does not upload a client theme zip.

## New client site setup

1. Install and activate SGP Updater on the staged WordPress site.
2. Open **Settings → SGP Updater** or click **Finish setup** on the Plugins screen.
3. Enter the client repository. The short repository name is enough, for example:
   `halaakwa-website`
4. Create a fine-grained GitHub token using the instructions on the setup screen:
   - **Resource owner:** `SGP-Design`
   - **Repository access:** only this client's theme repository
   - **Repository permissions → Contents:** Read-only
   - **Expiration:** choose intentionally and track it
5. Paste the token and click **Connect and prepare theme**.

SGP Updater verifies both repository access and file-download permission before continuing.

When the connection passes, it automatically creates and activates a tiny bootstrap theme at version `0.0.0`. The bootstrap uses the same theme folder and `GitHub Theme URI` as the eventual client theme.

The first real client build then arrives through the same WordPress theme update flow used for every later release. No client theme zip is required.

## Bootstrap theme convention

Client repositories must follow the standard naming convention:

```
SGP-Design/<slug>-website
```

The bootstrap theme folder becomes:

```
<slug>
```

For example:

```
SGP-Design/halaakwa-website → wp-content/themes/halaakwa
```

That folder identity is what allows the `0.0.0` bootstrap to be replaced by the real client theme through a normal WordPress update.

SGP Updater refuses to overwrite an existing theme folder if it belongs to a different repository.

## How later updates work

The active client theme declares its repository in `style.css`:

```
GitHub Theme URI: https://github.com/SGP-Design/example-website
```

SGP Updater watches that repository's `main` branch. Whenever the `Version:` header there is higher than the installed version, WordPress offers a normal theme update under **Dashboard → Updates** and **Appearance → Themes**.

The release process is therefore:

```
bump Version: → merge approved build to main → update appears in WordPress
```

No tags or GitHub Releases are used for client theme delivery.

The plugin also keeps itself updated from `SGP-Design/sgp-updater`.

## The GitHub token

The token is only needed because client theme repositories are private. It should have read access to one client theme repository and nothing else.

The setup screen tests two separate things:

1. Can the token see the repository?
2. Can the token read repository contents?

That distinction catches the common case where a token has metadata access but is missing **Contents: Read-only**.

### Storing the token outside the database

If `wp-config.php` can be edited, prefer:

```php
define( 'SGP_GITHUB_TOKEN', 'github_pat_...' );
```

SGP Updater prefers the constant over the stored option.

On hosts where file access is unavailable, the wp-admin field remains the supported setup path.

## Connection status

**Settings → SGP Updater** reports the active theme, repository, branch and connection status.

Typical failures are explained directly in the screen:

| Status | Meaning |
| --- | --- |
| Connected | The site can see and download the client theme |
| Could not reach github.com | The host is blocking outbound connections |
| GitHub rejected the token | The token is invalid or expired |
| Repository not found | Wrong repository or token scope |
| Token can see repo but cannot read files | Contents permission is missing |

## Safety boundaries

SGP Updater only manages the theme directory through the WordPress theme update mechanism.

Theme updates do not replace the WordPress database, so pages, media, settings and form submissions remain intact.

Files should never be edited directly on the server. A theme update replaces the theme directory, so server-side edits will be lost.

During first-run bootstrap setup, SGP Updater will not overwrite an existing theme folder unless that folder already declares the same client repository.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Client repository following the `<slug>-website` convention

## Why this repository is public

SGP Updater updates itself from this repository. Keeping the updater public means it can self-update without using a client's private GitHub credential.

Client tokens therefore remain scoped to one private client theme repository only.

**Client themes stay private. This repository is the delivery tool, not the work.**
