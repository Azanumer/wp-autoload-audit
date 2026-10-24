# WP Autoload Audit

Finds autoloaded-option bloat in `wp_options` that slows down **every** WordPress page load. Scans all `autoload=yes` rows, ranks them by size, flags common bloat patterns, and offers one-click cleanup of stuck transients.

## Why

WordPress loads every `autoload=yes` option into memory on each request. A bloated options table (rewrites, stuck transients, cache blobs) adds tens of milliseconds to TTFB on every hit.

## What it shows

- Total autoloaded row count and size (with a 1 MB health warning)
- Top 100 largest autoloaded options with size flags (>100 KB ⚠️, filterable via `waa_size_warning_bytes`)
- Flagged bloat patterns: stuck autoloaded transients, oversized `rewrite_rules`, update transients, WooCommerce/plugin blobs
- Autoloaded transients with a one-click **Delete stuck transients** button (nonce-protected)

## Safety

- Read-only scan; the only writes are the transient cleanup and the opt-in "disable autoload" action
- "Disable autoload" is restricted to allow-listed name patterns (`waa_safe_to_disable_autoload` filter) — core/update options can never be touched
- Filters: `waa_flag_option`, `waa_size_warning_bytes`, `waa_total_warning_bytes`

## Install

1. Copy the plugin folder to `wp-content/plugins/wp-autoload-audit/`
2. Activate, then open **Tools → Autoload Audit**

## License

MIT
