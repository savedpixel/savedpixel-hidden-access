# SavedPixel Hidden Access

Replace the default WordPress login entry points with a private URL and aggressively hide public access to the site.

## What It Does

SavedPixel Hidden Access creates a private login slug and reroutes WordPress login, logout, lost-password, and registration URLs through that private entry point. It also blocks direct guest access to `wp-login.php` and `/wp-admin/`, hides anonymous REST access, disables XML-RPC, and blanks normal public frontend requests.

## Key Workflows

- Generate and manage a private login slug from wp-admin.
- Copy or open the current private login URL from the settings page.
- Use the branded private login screen instead of the default `wp-login.php` route.
- Reduce unauthenticated discovery of login and API entry points.

## Features

- Private, configurable login slug generated on activation.
- Rewrite-based login entry point that still loads the core WordPress login flow.
- Direct guest requests to `wp-login.php` return a `404`.
- Guest probes to `/wp-admin/` are blocked, while logged-in admin access, AJAX, cron, and allowed admin assets remain available.
- Core WordPress login, logout, lost-password, registration, and `site_url()` login references are rewritten to the private login URL.
- Branded login screen with a custom "Secure Access Portal" title and styling.
- Copy and open actions for the active private login URL in the settings page.
- Anonymous REST requests are hidden with a `404`.
- XML-RPC is disabled.
- Public frontend requests are blanked with an empty `200` response unless the request is for the private login path.

## Admin Page

The settings page is intentionally small: it lets you edit the custom login slug, view the current private login URL, copy it to the clipboard, and open it in a new tab. Saving a changed slug refreshes the rewrite rules so the new private entry point becomes active.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later

## Installation

1. Upload the `savedpixel-hidden-access` folder to `wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Open **SavedPixel > Hidden Access**.
4. Save the private login URL somewhere secure before relying on the plugin.

## Usage Notes

- This plugin is suited to private or tightly controlled installs. It blanks normal frontend requests, so it is not appropriate for a public content site.
- Anyone who needs to log in must use the private URL shown in the plugin settings.
- If you change the slug, the old login URL stops being valid after the rewrite rules are flushed.

## Author

**Byron Jacobs**  
[GitHub](https://github.com/savedpixel)

## License

GPL-2.0-or-later
