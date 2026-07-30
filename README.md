# SGA Core

Keeps an SGP-built WordPress theme updated from its GitHub repository, using WordPress's own update flow. Updates appear under **Dashboard → Updates** and **Appearance → Themes** exactly like any other theme update — there is no separate interface to learn.

Built so SGP can deploy client sites from GitHub without uploading zip files by hand, and without a per-site or per-year licence.

## How it works

The plugin reads a `GitHub Theme URI` header from the active theme's `style.css`:

```
GitHub Theme URI: https://github.com/SGP-Design/igs-website
```

It then watches the `main` branch of that repository. Whenever the `Version:` header there is higher than the installed version, WordPress offers an update.

So the release process is: bump `Version:` in `style.css`, commit, push. Nothing else.

The header name is deliberately the same one [Git Updater](https://github.com/afragen/git-updater) uses, so a site can be moved between the two without editing the theme.

The plugin also keeps *itself* updated from `SGP-Design/sga-core` the same way.

## Installation

1. Download this repository as a zip
2. **Plugins → Add New → Upload Plugin**, then activate
3. **Settings → SGP Updates**, and paste a GitHub access token

The token is only needed for private repositories. It needs read access to the theme repository and nothing else.

### Storing the token outside the database

If `wp-config.php` can be edited, prefer:

```php
define( 'SGA_GITHUB_TOKEN', 'ghp_your_token_here' );
```

A constant keeps the token out of the database, so a database export doesn't leak it. The plugin prefers the constant when both are present, and hides the settings field.

## The settings screen

**Settings → SGP Updates** reports the active theme and version, the repository it found, and whether it can actually reach that repository. The connection line distinguishes the failures that look identical from the outside:

| What it says | What it means |
| --- | --- |
| Connected | Working |
| Could not reach github.com | The host is blocking outbound connections |
| GitHub rejected the token | Token is wrong or expired |
| Repository not found | Wrong address, or the token has no access to it |
| No `GitHub Theme URI` header | The theme doesn't declare a repository |

## What it does not touch

Only the theme directory, and only through WordPress's own updater. The database is never involved, so pages, media, menus, settings, and form submissions are unaffected by an update.

Because an update replaces the whole theme directory, anything edited directly on the server — through WordPress's built-in file editor, or over FTP — is overwritten on the next update. The repository has to be the only place changes are made.

## Credits

The update mechanism is [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) by Yahnis Elsts (MIT), vendored in `plugin-update-checker/`. This plugin is the configuration around it.

## Requirements

WordPress 6.0+, PHP 8.0+.
