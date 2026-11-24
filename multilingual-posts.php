<?php
/**
 * Plugin Name:       Multilingual Posts (single file)
 * Description:       Translated posts under /<lang>/ URLs with hreflang, without a multilingual plugin. One file: drop it into wp-content/mu-plugins/.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Tejas Hingu
 * Author URI:        https://tejashingu.com/about-me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wmnp
 *
 * WordPress multilingual without a multilingual plugin, as described in
 * https://tejashingu.com/wordpress/wordpress-multilingual-without-plugin/
 *
 * A translation is an ordinary post with two meta fields, set in the Language box on the editor:
 *
 * - _wmnp_lang:           its language code, such as "id";
 * - _wmnp_translation_of: the ID of the post it translates (optional).
 *
 * A translated post gets:
 *
 * - The URL /<lang>/<permalink>/, plus /<lang>/ (its own home), /<lang>/page/N/ and /<lang>/feed/.
 *   Any other URL for a translated post, and a site-language post under /<lang>/, redirect (301).
 * - The language's locale while the page renders (switch_to_locale): <html lang>, theme and core
 *   strings, and dates.
 * - hreflang links between the original and its translations, on every version, plus x-default.
 * - Same-language posts only in lists, feeds and previous/next links.
 * - SEO tags on the language home: through Yoast SEO's filters when Yoast is active, or printed by
 *   this file otherwise (Settings > Reading has the switch).
 *
 * No cookie, script, style or language detection: the URL alone decides the language. Only posts
 * are translated, not pages, categories or menus. Each language needs WordPress's core language
 * pack installed (Settings > General > Site Language, or `wp language core install id_ID`), or
 * switch_to_locale() does nothing.
 *
 * Languages are set with the wmnp_languages filter (see wmnp_languages() below). The permalink
 * structure is assumed to end in %postname% (for example /%category%/%postname%/ or /%postname%/).
 *
 * @package wmnp
 */

defined( 'ABSPATH' ) || exit;

define( 'WMNP_LANG_KEY', '_wmnp_lang' );
define( 'WMNP_TRANSLATION_OF_KEY', '_wmnp_translation_of' );

/*
 * Languages and the index of translated posts.
 */

/**
 * The site's languages. The first one is the site language; the rest are the languages posts
 * can be translated into. Add your own with the wmnp_languages filter, for example:
 *
 *     add_filter( 'wmnp_languages', function ( $languages ) {
 *         $languages['es'] = array(
 *             'locale'      => 'es_ES',        // A core language pack locale.
 *             'hreflang'    => 'es',
 *             'name'        => 'Español',      // Shown in the editor and language links.
 *             'read'        => 'Leer en español',
 *             'home'        => 'Inicio',       // Breadcrumb label (Yoast).
 *             'date_format' => 'j \d\e F \d\e Y',
 *             'title'       => 'My blog in Spanish',      // The language home's title.
 *             'description' => 'Articles in Spanish.',    // The language home's description.
 *         );
 *         return $languages;
 *     } );
 *
 * Only 'locale', 'hreflang' and 'name' are required.
 *
 * @return array<string, array<string, string>>
 */
function wmnp_languages() {
	$languages = array(
		'en' => array(
			'locale'   => 'en_US',
			'hreflang' => 'en',
			'name'     => 'English',
			'read'     => 'Read in English',
		),
		'id' => array(
			'locale'      => 'id_ID',
			'hreflang'    => 'id',
			'name'        => 'Bahasa Indonesia',
			'read'        => 'Baca dalam Bahasa Indonesia',
			'home'        => 'Beranda',
			'date_format' => 'j F Y',
		),
	);

	/**
	 * Filters the site's languages. The first key is the site language.
	 *
	 * @param array<string, array<string, string>> $languages Code => locale, hreflang, labels.
	 */
	return (array) apply_filters( 'wmnp_languages', $languages );
}

/**
 * The site language's code.
 *
 * @return string
 */
function wmnp_default_language() {
	return (string) array_key_first( wmnp_languages() );
}

/**
 * Whether a code is a translation language (known, and not the site language).
 *
 * @param mixed $code Language code.
 * @return bool
 */
function wmnp_is_translation_language( $code ) {
	return is_string( $code ) && wmnp_default_language() !== $code && isset( wmnp_languages()[ $code ] );
}

/**
 * Translated posts: post ID => language, original post ID and status. Kept in one autoloaded
 * option, so pages never query post meta to find translations.
 *
 * @return array<int, array{lang: string, of: int, status: string}>
 */
function wmnp_index() {
	$index = get_option( 'wmnp_posts' );

	return is_array( $index ) ? $index : wmnp_rebuild_index();
}

/**
 * Rebuild the index from post meta. Runs only when a language field or a translated post's
 * status changes.
 *
 * @return array<int, array{lang: string, of: int, status: string}>
 */
function wmnp_rebuild_index() {
	global $wpdb;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the result is stored in an option.
		$wpdb->prepare(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'post' AND pm.meta_key IN (%s, %s)",
			WMNP_LANG_KEY,
			WMNP_TRANSLATION_OF_KEY
		)
	);

	$found = array();
	foreach ( (array) $rows as $row ) {
		$id                     = (int) $row->post_id;
		$found[ $id ]           = ( $found[ $id ] ?? array() ) + array( 'status' => (string) $row->post_status );
		$field                  = WMNP_LANG_KEY === $row->meta_key ? 'lang' : 'of';
		$found[ $id ][ $field ] = $row->meta_value;
	}

	$index = array();
	foreach ( $found as $id => $entry ) {
		if ( isset( $entry['lang'] ) && wmnp_is_translation_language( $entry['lang'] ) ) {
			$index[ $id ] = array(
				'lang'   => (string) $entry['lang'],
				'of'     => (int) ( $entry['of'] ?? 0 ),
				'status' => $entry['status'],
			);
		}
	}
	ksort( $index );

	update_option( 'wmnp_posts', $index, true );

	return $index;
}

/**
 * A language field was added, changed or removed.
 *
 * @param int|int[] $meta_id   Meta ID(s).
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 */
function wmnp_meta_changed( $meta_id, $object_id, $meta_key ) {
	if ( in_array( $meta_key, array( WMNP_LANG_KEY, WMNP_TRANSLATION_OF_KEY ), true ) ) {
		wmnp_rebuild_index();
	}
}
add_action( 'added_post_meta', 'wmnp_meta_changed', 10, 3 );
add_action( 'updated_post_meta', 'wmnp_meta_changed', 10, 3 );
add_action( 'deleted_post_meta', 'wmnp_meta_changed', 10, 3 );

/**
 * A translated post changed status, or was deleted: refresh the index.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function wmnp_on_transition( $new_status, $old_status, $post ) {
	if ( $new_status !== $old_status && isset( wmnp_index()[ $post->ID ] ) ) {
		wmnp_rebuild_index();
	}
}
add_action( 'transition_post_status', 'wmnp_on_transition', 0, 3 );

/**
 * A translated post was deleted.
 *
 * @param int $post_id Post ID.
 */
function wmnp_on_delete( $post_id ) {
	if ( isset( wmnp_index()[ (int) $post_id ] ) ) {
		wmnp_rebuild_index();
	}
}
add_action( 'deleted_post', 'wmnp_on_delete' );

/**
 * A post's language code.
 *
 * @param WP_Post|int $post Post or ID.
 * @return string
 */
function wmnp_post_language( $post ) {
	$id = $post instanceof WP_Post ? $post->ID : (int) $post;

	return wmnp_index()[ $id ]['lang'] ?? wmnp_default_language();
}

/**
 * IDs of translated posts in one language, or in every translation language.
 *
 * @param string $lang      Language code, or '' for all of them.
 * @param bool   $published Only published posts.
 * @return int[]
 */
function wmnp_translated_ids( $lang = '', $published = false ) {
	$ids = array();

	foreach ( wmnp_index() as $id => $entry ) {
		if ( ( '' === $lang || $lang === $entry['lang'] ) && ( ! $published || 'publish' === $entry['status'] ) ) {
			$ids[] = (int) $id;
		}
	}

	return $ids;
}

/**
 * The language of the current request: set by the /<lang>/ rewrite rules, otherwise the site language.
 *
 * @param string|null $set Language code to set (from parse_request only).
 * @return string
 */
function wmnp_request_language( $set = null ) {
	static $lang = null;

	if ( null !== $set ) {
		$lang = $set;
	}

	return $lang ?? wmnp_default_language();
}

/*
 * URLs and routing.
 */

/**
 * Put translated posts under /<lang>/.
 *
 * @param string  $permalink Permalink.
 * @param WP_Post $post      Post.
 * @return string
 */
function wmnp_post_link( $permalink, $post ) {
	$lang = wmnp_post_language( $post );
	$home = home_url( '/' );

	// Drafts have plain ?p= links, which stay as they are.
	if ( ! wmnp_is_translation_language( $lang ) || ! str_starts_with( $permalink, $home ) || str_contains( $permalink, '?' ) ) {
		return $permalink;
	}

	return $home . $lang . '/' . substr( $permalink, strlen( $home ) );
}
add_filter( 'post_link', 'wmnp_post_link', 10, 2 );

/**
 * Rewrite rules for each translation language, in match order. The post rule hands the rest of
 * the path to WordPress's own permalink matching through the wmnp_path query var.
 *
 * @return array<string, string> Regex => query.
 */
function wmnp_rules() {
	$rules = array();

	foreach ( array_keys( wmnp_languages() ) as $code ) {
		if ( ! wmnp_is_translation_language( $code ) ) {
			continue;
		}

		$c = preg_quote( $code, '#' );
		$q = 'index.php?wmnp_lang=' . $code;

		$rules[ "{$c}/?$" ]                                    = $q;
		$rules[ "{$c}/page/?([0-9]{1,})/?$" ]                  = $q . '&paged=$matches[1]';
		$rules[ "{$c}/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$" ] = $q . '&feed=$matches[1]';
		$rules[ "{$c}/(.+)$" ]                                 = $q . '&wmnp_path=$matches[1]';
	}

	return $rules;
}

/**
 * Register the rules, ahead of WordPress's own.
 */
function wmnp_rewrite() {
	foreach ( wmnp_rules() as $regex => $query ) {
		add_rewrite_rule( $regex, $query, 'top' );
	}
}
add_action( 'init', 'wmnp_rewrite' );

/**
 * Flush once when the stored rules lack ours (first load, or a language was added).
 */
function wmnp_maybe_flush() {
	$rules = get_option( 'rewrite_rules' );

	if ( is_array( $rules ) && $rules && array_diff_key( wmnp_rules(), $rules ) ) {
		flush_rewrite_rules( false );
	}
}
add_action( 'init', 'wmnp_maybe_flush', 99 );

/**
 * Add the query vars.
 *
 * @param string[] $vars Public query vars.
 * @return string[]
 */
function wmnp_query_vars( $vars ) {
	$vars[] = 'wmnp_lang';
	$vars[] = 'wmnp_path';

	return $vars;
}
add_filter( 'query_vars', 'wmnp_query_vars' );

/**
 * Read the language from the matched rule and switch the locale for the rest of the request.
 * The query vars only count when one of our rules set them, so ?wmnp_lang=id does nothing.
 *
 * For a post URL, the path after /<lang>/ is matched against WordPress's own rules, so any
 * permalink structure that ends in %postname% works.
 *
 * @param WP $wp Request.
 */
function wmnp_parse_request( $wp ) {
	$code = $wp->query_vars['wmnp_lang'] ?? '';

	if ( ! isset( wmnp_rules()[ (string) $wp->matched_rule ] ) || ! wmnp_is_translation_language( $code ) ) {
		unset( $wp->query_vars['wmnp_lang'], $wp->query_vars['wmnp_path'] );
		return;
	}

	if ( isset( $wp->query_vars['wmnp_path'] ) ) {
		$path = (string) $wp->query_vars['wmnp_path'];
		$vars = wmnp_match_path( $path );
		unset( $wp->query_vars['wmnp_path'] );

		// Not a post URL: look it up as a post slug, which ends in a 404 in this language unless
		// that post exists (then the redirect below sends it to its own URL).
		if ( null === $vars ) {
			$slug = sanitize_title( basename( untrailingslashit( $path ) ) );
			$vars = array( 'name' => '' !== $slug ? $slug : 'wmnp-not-found' );
		}

		$wp->query_vars = array_merge( $wp->query_vars, $vars );
	}

	wmnp_request_language( $code );
	switch_to_locale( wmnp_languages()[ $code ]['locale'] );
}
add_action( 'parse_request', 'wmnp_parse_request' );

/**
 * Match a path against WordPress's own rewrite rules, the way WP::parse_request() does, and keep
 * only results that point to a single post or its feed or comment pages.
 *
 * @param string $path Path after /<lang>/.
 * @return array<string, string>|null Query vars, or null when nothing matched.
 */
function wmnp_match_path( $path ) {
	global $wp_rewrite;

	$path = ltrim( $path, '/' );

	foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $match => $query ) {
		if ( isset( wmnp_rules()[ $match ] ) || ! preg_match( "#^$match#", $path, $matches ) ) {
			continue;
		}

		$query = preg_replace( '!^.+\?!', '', $query );
		$query = addslashes( WP_MatchesMapRegex::apply( $query, $matches ) );
		parse_str( $query, $vars );

		// Keep looking past rules that aren't for a post, like the catch-all page rule WordPress puts
		// first when the structure starts with %category% (core skips it too when no page matches).
		if ( ! empty( $vars['name'] ) && empty( $vars['attachment'] ) ) {
			return array_intersect_key( (array) $vars, array_flip( array( 'name', 'category_name', 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'author_name', 'feed', 'cpage', 'page', 'withcomments' ) ) );
		}
	}

	return null;
}

/**
 * Keep each language's lists to itself.
 *
 * Only front-end listings are touched: the main query and queries made while the page renders,
 * never the admin, REST, cron or WP-CLI, which see every post. A query can also ask for one
 * language with a wmnp_lang argument.
 *
 * @param WP_Query $query Query.
 */
function wmnp_pre_get_posts( WP_Query $query ) {
	$lang = $query->get( 'wmnp_lang' );

	if ( '' === $lang || null === $lang ) {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_is_serving_rest_request() ) {
			return;
		}

		if ( ! $query->is_main_query() && ! did_action( 'template_redirect' ) ) {
			return;
		}

		$lang = wmnp_request_language();
	}

	if ( ! is_string( $lang ) || ! isset( wmnp_languages()[ $lang ] ) || $query->is_singular() || $query->get( 'post__in' ) || $query->get( 'p' ) || $query->get( 'name' ) ) {
		return;
	}

	$types = array_filter( (array) $query->get( 'post_type' ) );
	if ( $types && ! array_intersect( $types, array( 'post', 'any' ) ) ) {
		return;
	}

	if ( wmnp_is_translation_language( $lang ) ) {
		$ids = wmnp_translated_ids( $lang );
		$query->set( 'post__in', $ids ? $ids : array( 0 ) ); // An empty post__in means "everything".
		$query->set( 'ignore_sticky_posts', true );
		return;
	}

	$translated = wmnp_translated_ids();
	if ( $translated ) {
		$query->set( 'post__not_in', array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $translated ) );
	}
}
add_action( 'pre_get_posts', 'wmnp_pre_get_posts' );

/**
 * Previous and next post links stay in the post's language.
 *
 * @param string  $where          WHERE clause.
 * @param bool    $in_same_term   Unused.
 * @param mixed   $excluded_terms Unused.
 * @param string  $taxonomy       Unused.
 * @param WP_Post $post           Post the links are for.
 * @return string
 */
function wmnp_adjacent_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
	$lang = $post instanceof WP_Post ? wmnp_post_language( $post ) : wmnp_default_language();
	$same = wmnp_is_translation_language( $lang );
	$ids  = wmnp_translated_ids( $same ? $lang : '' );

	if ( ! $ids ) {
		return $same ? $where . ' AND 1 = 0' : $where;
	}

	return $where . ' AND p.ID ' . ( $same ? 'IN' : 'NOT IN' ) . ' (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
}
add_filter( 'get_previous_post_where', 'wmnp_adjacent_where', 10, 5 );
add_filter( 'get_next_post_where', 'wmnp_adjacent_where', 10, 5 );

/**
 * One URL per post: redirect a post opened under the wrong language's URL, and answer 404 for a
 * language home with nothing published yet.
 */
function wmnp_template_redirect() {
	$lang = wmnp_request_language();

	if ( is_singular( 'post' ) ) {
		$post = get_queried_object();

		if ( $post instanceof WP_Post && 'publish' === $post->post_status && wmnp_post_language( $post ) !== $lang ) {
			wp_safe_redirect( get_permalink( $post ), 301, 'wmnp' );
			exit;
		}

		return;
	}

	if ( wmnp_is_translation_language( $lang ) && is_home() && ! have_posts() ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
add_action( 'template_redirect', 'wmnp_template_redirect', 1 );

/**
 * A URL on this site moved into the current request's language: / becomes /id/.
 *
 * @param string $url URL on this site.
 * @return string
 */
function wmnp_localize_url( $url ) {
	$lang = wmnp_request_language();
	$home = home_url( '/' );

	if ( ! wmnp_is_translation_language( $lang ) || ! is_string( $url ) || ! str_starts_with( $url, $home ) || str_starts_with( $url, $home . $lang . '/' ) ) {
		return $url;
	}

	return $home . $lang . '/' . substr( $url, strlen( $home ) );
}

/**
 * The feed link on translated pages points to that language's feed.
 *
 * @param string $output Feed URL.
 * @return string
 */
function wmnp_feed_link( $output ) {
	return trailingslashit( $output ) === trailingslashit( home_url( '/feed/' ) ) ? wmnp_localize_url( $output ) : $output;
}
add_filter( 'feed_link', 'wmnp_feed_link' );

/**
 * Dates in the language's usual order.
 *
 * @param mixed $format Stored format (false to read the option).
 * @return mixed
 */
function wmnp_date_format( $format ) {
	$own = wmnp_field( 'date_format' );

	return '' !== $own ? $own : $format;
}
add_filter( 'pre_option_date_format', 'wmnp_date_format' );

/*
 * Translations of a post, hreflang and language links.
 */

/**
 * The published versions of a post, by language: the original and each translation of it.
 * Empty when the post has none.
 *
 * @param WP_Post|int $post Post or ID.
 * @return array<string, WP_Post>
 */
function wmnp_translations( $post ) {
	$post = get_post( $post );

	if ( ! $post || 'post' !== $post->post_type ) {
		return array();
	}

	$index = wmnp_index();
	$root  = isset( $index[ $post->ID ] ) ? (int) $index[ $post->ID ]['of'] : $post->ID;
	$group = array();

	if ( $root && ! isset( $index[ $root ] ) ) {
		$group[ wmnp_default_language() ] = get_post( $root );
	}

	foreach ( $index as $id => $entry ) {
		if ( $root && $root === $entry['of'] && 'publish' === $entry['status'] && ! isset( $group[ $entry['lang'] ] ) ) {
			$group[ $entry['lang'] ] = get_post( $id );
		}
	}

	$group[ wmnp_post_language( $post ) ] = $post;

	$group = array_filter(
		$group,
		static function ( $item ) {
			return $item instanceof WP_Post && 'post' === $item->post_type && 'publish' === $item->post_status && '' === $item->post_password;
		}
	);

	return count( $group ) > 1 ? $group : array();
}

/**
 * Links to the other language versions of a post, for a theme template.
 *
 * @param WP_Post|int|null $post Post, ID, or null for the current post.
 * @return array<int, array{url: string, hreflang: string, name: string, label: string}>
 */
function wmnp_translation_links( $post = null ) {
	$post      = get_post( $post );
	$languages = wmnp_languages();
	$links     = array();

	if ( ! $post ) {
		return $links;
	}

	foreach ( wmnp_translations( $post ) as $lang => $item ) {
		if ( $item->ID !== $post->ID ) {
			$links[] = array(
				'url'      => (string) get_permalink( $item ),
				'hreflang' => $languages[ $lang ]['hreflang'],
				'name'     => $languages[ $lang ]['name'],
				'label'    => $languages[ $lang ]['read'] ?? $languages[ $lang ]['name'],
			);
		}
	}

	return $links;
}

/**
 * Print the language links for the current post, for example under the title in single.php:
 * <?php if ( function_exists( 'wmnp_the_translation_links' ) ) { wmnp_the_translation_links(); } ?>
 */
function wmnp_the_translation_links() {
	$links = wmnp_translation_links();

	if ( ! $links ) {
		return;
	}

	echo '<p class="wmnp-translations">';
	foreach ( $links as $i => $link ) {
		printf(
			'%1$s<a href="%2$s" hreflang="%3$s" lang="%3$s">%4$s</a>',
			$i ? ' · ' : '',
			esc_url( $link['url'] ),
			esc_attr( $link['hreflang'] ),
			esc_html( $link['label'] )
		);
	}
	echo '</p>';
}

/**
 * Alternate URLs of the current page by language, or an empty array.
 *
 * @return array<string, string>
 */
function wmnp_alternate_urls() {
	$urls = array();

	if ( is_singular( 'post' ) ) {
		foreach ( wmnp_translations( get_queried_object() ) as $lang => $item ) {
			$urls[ $lang ] = (string) get_permalink( $item );
		}
	} elseif ( is_home() && ! is_paged() && ! is_feed() && ! is_search() ) {
		$home = home_url( '/' );

		foreach ( array_keys( wmnp_languages() ) as $lang ) {
			if ( ! wmnp_is_translation_language( $lang ) ) {
				$urls[ $lang ] = $home;
			} elseif ( wmnp_translated_ids( $lang, true ) ) {
				$urls[ $lang ] = $home . $lang . '/';
			}
		}
	}

	return count( $urls ) > 1 ? $urls : array();
}

/**
 * hreflang links, including x-default (the site-language version).
 */
function wmnp_hreflang() {
	$urls      = wmnp_alternate_urls();
	$languages = wmnp_languages();
	$default   = wmnp_default_language();

	foreach ( $urls as $lang => $url ) {
		printf( '<link rel="alternate" hreflang="%1$s" href="%2$s">' . "\n", esc_attr( $languages[ $lang ]['hreflang'] ), esc_url( $url ) );
	}

	if ( isset( $urls[ $default ] ) ) {
		printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( $urls[ $default ] ) );
	}
}
add_action( 'wp_head', 'wmnp_hreflang', 5 );

/**
 * The page's language as a content-language meta tag. Bing reads this and <html lang> rather
 * than hreflang.
 */
function wmnp_content_language() {
	$lang = wmnp_request_language();

	printf( '<meta http-equiv="content-language" content="%s">' . "\n", esc_attr( wmnp_languages()[ $lang ]['hreflang'] ?? $lang ) );
}
add_action( 'wp_head', 'wmnp_content_language', 1 );

/**
 * A field of the current request's language, or '' on site-language pages.
 *
 * @param string $field Field name.
 * @return string
 */
function wmnp_field( $field ) {
	$lang = wmnp_request_language();

	return wmnp_is_translation_language( $lang ) ? (string) ( wmnp_languages()[ $lang ][ $field ] ?? '' ) : '';
}

/**
 * Whether a translation language's home (/id/) is being shown.
 *
 * @return bool
 */
function wmnp_is_language_home() {
	return is_home() && wmnp_is_translation_language( wmnp_request_language() );
}

/*
 * SEO tags on the language home: Yoast SEO, or this file's own.
 */

/**
 * Whether the Yoast SEO fixes are on. The Settings > Reading box decides; until it's saved, they
 * follow whether Yoast SEO is active.
 *
 * @return bool
 */
function wmnp_use_yoast() {
	$yoast = defined( 'WPSEO_VERSION' );
	$saved = get_option( 'wmnp_yoast', 'auto' );

	return 'auto' === $saved ? $yoast : ( '1' === $saved && $yoast );
}

/**
 * Register the hooks for whichever mode is on, once plugins have loaded.
 */
function wmnp_seo_hooks() {
	if ( wmnp_use_yoast() ) {
		add_filter( 'wpseo_canonical', 'wmnp_yoast_home_url' );
		add_filter( 'wpseo_opengraph_url', 'wmnp_yoast_home_url' );
		add_filter( 'wpseo_adjacent_rel_url', 'wmnp_yoast_home_url' );
		add_filter( 'wpseo_title', 'wmnp_home_title' );
		add_filter( 'wpseo_opengraph_title', 'wmnp_home_title' );
		add_filter( 'wpseo_metadesc', 'wmnp_home_description' );
		add_filter( 'wpseo_opengraph_desc', 'wmnp_home_description' );
		add_filter( 'wpseo_schema_webpage', 'wmnp_yoast_schema_webpage' );
		add_filter( 'wpseo_og_locale', 'wmnp_yoast_og_locale' );
		add_filter( 'wpseo_breadcrumb_links', 'wmnp_yoast_breadcrumbs' );
		return;
	}

	/**
	 * Filters whether this file prints the language home's title, description and canonical
	 * itself. On by default when Yoast SEO isn't active; return false when another SEO plugin
	 * prints those tags (then correct them through that plugin's filters).
	 *
	 * @param bool $print Whether to print them.
	 */
	if ( defined( 'WPSEO_VERSION' ) || ! apply_filters( 'wmnp_print_home_tags', true ) ) {
		return; // Yoast is active but its fixes were switched off: leave its tags alone.
	}

	add_filter( 'pre_get_document_title', 'wmnp_document_title' );
	add_action( 'wp_head', 'wmnp_home_tags', 2 );
}
// From mu-plugins this waits for Yoast to load; from a theme's functions.php it runs at once.
if ( did_action( 'plugins_loaded' ) ) {
	wmnp_seo_hooks();
} else {
	add_action( 'plugins_loaded', 'wmnp_seo_hooks' );
}

/**
 * Title of the language home, or the title it was given.
 *
 * @param string $title Title.
 * @return string
 */
function wmnp_home_title( $title ) {
	$own = wmnp_is_language_home() ? wmnp_field( 'title' ) : '';

	return '' !== $own ? $own : $title;
}

/**
 * Description of the language home, or the description it was given.
 *
 * @param string $description Description.
 * @return string
 */
function wmnp_home_description( $description ) {
	$own = wmnp_is_language_home() ? wmnp_field( 'description' ) : '';

	return '' !== $own ? $own : $description;
}

/**
 * Yoast treats /id/ as the site home (is_home() is true there), so its canonical, og:url and
 * paging links would point to /. Move them to /id/.
 *
 * @param string $url URL.
 * @return string
 */
function wmnp_yoast_home_url( $url ) {
	return wmnp_is_language_home() ? wmnp_localize_url( $url ) : $url;
}

/**
 * The language home's WebPage schema node: its own URL, name and description.
 *
 * @param array $data WebPage schema.
 * @return array
 */
function wmnp_yoast_schema_webpage( $data ) {
	if ( ! wmnp_is_language_home() ) {
		return $data;
	}

	foreach ( array( '@id', 'url' ) as $key ) {
		if ( isset( $data[ $key ] ) ) {
			$data[ $key ] = wmnp_localize_url( $data[ $key ] );
		}
	}

	if ( '' !== wmnp_field( 'title' ) ) {
		$data['name'] = wmnp_field( 'title' );
	}

	if ( '' !== wmnp_field( 'description' ) ) {
		$data['description'] = wmnp_field( 'description' );
	}

	return $data;
}

/**
 * og:locale in the page's language.
 *
 * @param string $locale Locale.
 * @return string
 */
function wmnp_yoast_og_locale( $locale ) {
	$own = wmnp_field( 'locale' );

	return '' !== $own ? $own : $locale;
}

/**
 * The breadcrumb's first link goes to the language home.
 *
 * @param array $links Breadcrumb links.
 * @return array
 */
function wmnp_yoast_breadcrumbs( $links ) {
	$label = wmnp_field( 'home' );

	if ( '' !== $label && isset( $links[0]['url'] ) && trailingslashit( $links[0]['url'] ) === home_url( '/' ) ) {
		$links[0]['url']  = wmnp_localize_url( home_url( '/' ) );
		$links[0]['text'] = $label;
	}

	return $links;
}

/**
 * Without Yoast: the language home's <title>, when the language has one.
 *
 * @param string $title Title ('' lets WordPress build it).
 * @return string
 */
function wmnp_document_title( $title ) {
	return wmnp_home_title( $title );
}

/**
 * Without Yoast: a canonical and a description on the language home. WordPress prints a
 * canonical on single posts itself (from get_permalink(), so already under /id/), but none on
 * the home page.
 */
function wmnp_home_tags() {
	if ( ! wmnp_is_language_home() || is_paged() ) {
		return;
	}

	printf( '<link rel="canonical" href="%s">' . "\n", esc_url( home_url( '/' . wmnp_request_language() . '/' ) ) );

	$description = wmnp_field( 'description' );
	if ( '' !== $description ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
	}
}

/**
 * The switch on Settings > Reading.
 */
function wmnp_settings() {
	register_setting(
		'reading',
		'wmnp_yoast',
		array(
			'type'              => 'string',
			'sanitize_callback' => static function ( $value ) {
				return '1' === $value ? '1' : '0';
			},
			'default'           => 'auto',
		)
	);

	add_settings_field( 'wmnp_yoast', __( 'Multilingual posts', 'wmnp' ), 'wmnp_settings_field', 'reading' );
}
add_action( 'admin_init', 'wmnp_settings' );

/**
 * The checkbox, with a hidden 0 so unticking saves.
 */
function wmnp_settings_field() {
	$yoast = defined( 'WPSEO_VERSION' );
	?>
	<input type="hidden" name="wmnp_yoast" value="0">
	<label>
		<input type="checkbox" name="wmnp_yoast" value="1" <?php checked( wmnp_use_yoast() ); ?> <?php disabled( ! $yoast ); ?>>
		<?php esc_html_e( 'Use the Yoast SEO fixes on language homes (canonical, og:url, title, description, schema, og:locale, breadcrumbs)', 'wmnp' ); ?>
	</label>
	<p class="description">
		<?php
		if ( ! $yoast ) {
			esc_html_e( 'Yoast SEO is not active, so this file prints the language home\'s title, description and canonical itself.', 'wmnp' );
		} elseif ( ! wmnp_use_yoast() ) {
			esc_html_e( 'Yoast SEO is active but the fixes are off: Yoast will point each language home\'s canonical to the site home, which tells search engines not to index it.', 'wmnp' );
		} else {
			esc_html_e( 'Yoast SEO is active. Untick this only if another plugin already corrects these tags.', 'wmnp' );
		}
		?>
	</p>
	<?php
}

/*
 * Publishing together.
 */

/**
 * A translation goes live with its original: when a scheduled original is published, its
 * scheduled translations are published too.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function wmnp_publish_with_original( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type ) {
		return;
	}

	$index = wmnp_index();
	if ( isset( $index[ $post->ID ] ) ) {
		return;
	}

	foreach ( $index as $id => $entry ) {
		if ( $post->ID === $entry['of'] && 'future' === $entry['status'] ) {
			wp_publish_post( $id );
		}
	}
}
add_action( 'transition_post_status', 'wmnp_publish_with_original', 20, 3 );

/*
 * The Language box on the post editor.
 */

/**
 * Add the box.
 */
function wmnp_add_meta_box() {
	add_meta_box( 'wmnp-language', __( 'Language', 'wmnp' ), 'wmnp_meta_box', 'post', 'side' );
}
add_action( 'add_meta_boxes_post', 'wmnp_add_meta_box' );

/**
 * The box: the post's language and, for a translation, the post it translates.
 *
 * @param WP_Post $post Post.
 */
function wmnp_meta_box( $post ) {
	$lang      = wmnp_post_language( $post );
	$of        = (int) get_post_meta( $post->ID, WMNP_TRANSLATION_OF_KEY, true );
	$originals = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'post__not_in'   => array_merge( array( $post->ID ), wmnp_translated_ids() ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	wp_nonce_field( 'wmnp_save', 'wmnp_nonce' );
	?>
	<p>
		<label for="wmnp-lang"><?php esc_html_e( 'Language of this post', 'wmnp' ); ?></label><br>
		<select id="wmnp-lang" name="wmnp_lang">
			<?php foreach ( wmnp_languages() as $code => $language ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang, $code ); ?>><?php echo esc_html( $language['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="wmnp-translation-of"><?php esc_html_e( 'Translation of', 'wmnp' ); ?></label><br>
		<select id="wmnp-translation-of" name="wmnp_translation_of" style="max-width:100%">
			<option value="0"><?php esc_html_e( 'None', 'wmnp' ); ?></option>
			<?php foreach ( $originals as $original ) : ?>
				<option value="<?php echo (int) $original->ID; ?>" <?php selected( $of, $original->ID ); ?>><?php echo esc_html( wp_strip_all_tags( get_the_title( $original ) ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p class="description"><?php esc_html_e( 'Only used when the language is not the site language. The two posts then link to each other with hreflang.', 'wmnp' ); ?></p>
	<?php
}

/**
 * Save the box. A translation needs a translation language; the original must be a post in the
 * site language.
 *
 * @param int $post_id Post ID.
 */
function wmnp_save_meta_box( $post_id ) {
	if ( ! isset( $_POST['wmnp_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wmnp_nonce'] ) ), 'wmnp_save' ) ) {
		return;
	}

	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$lang = isset( $_POST['wmnp_lang'] ) ? sanitize_key( wp_unslash( $_POST['wmnp_lang'] ) ) : '';
	$of   = isset( $_POST['wmnp_translation_of'] ) ? absint( wp_unslash( $_POST['wmnp_translation_of'] ) ) : 0;

	if ( ! wmnp_is_translation_language( $lang ) ) {
		delete_post_meta( $post_id, WMNP_LANG_KEY );
		delete_post_meta( $post_id, WMNP_TRANSLATION_OF_KEY );
		return;
	}

	update_post_meta( $post_id, WMNP_LANG_KEY, $lang );

	if ( $of && $of !== $post_id && 'post' === get_post_type( $of ) && ! wmnp_is_translation_language( wmnp_post_language( $of ) ) ) {
		update_post_meta( $post_id, WMNP_TRANSLATION_OF_KEY, $of );
	} else {
		delete_post_meta( $post_id, WMNP_TRANSLATION_OF_KEY );
	}
}
add_action( 'save_post_post', 'wmnp_save_meta_box' );
