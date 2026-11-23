<?php
/**
 * Front-end bloat off (TASK-010 item 5, token `bloat`): the emoji detection loader + emoji CSS and the oEmbed discovery links
 * in `<head>`. Saves about 4 KB gz of HTML per page and one canvas probe per load.
 *
 * Exactly these core callbacks are unhooked (nothing SEO related: no title, meta, canonical, schema, hreflang, breadcrumbs):
 * `print_emoji_detection_script` (wp_head 7), `print_emoji_styles` (wp_print_styles; `wp_enqueue_emoji_styles` then returns early),
 * `wp_oembed_add_discovery_links` (wp_head 4 and 10), `wp_oembed_add_host_js` (wp_head). The oEmbed REST endpoint itself, feeds,
 * e-mails (`wp_staticize_emoji*`), embeds (`embed_head`) and the whole admin / editor stay untouched.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Performance;

final class Bloat {

	public function register(): void {
		if ( is_admin() || Switches::off( 'bloat' ) ) {
			return;
		}

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	}
}
