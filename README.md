# WordPress multilingual without a plugin

One PHP file that gives translated WordPress posts their own `/id/` (or `/es/`, `/ja/`...) URLs,
switches WordPress to that language for the request, and links every version with hreflang. No
multilingual plugin, no cookie, no script: the URL alone decides the language.

The full walkthrough, with why each part is there, is in my article:
**[WordPress Multilingual Without a Plugin: /id/ URLs and hreflang in One PHP File](https://tejashingu.com/wordpress/wordpress-multilingual-without-plugin/)**

I'm [Tejas Hingu](https://tejashingu.com/about-me/), and this is the same code that runs the
Indonesian, Japanese, Spanish, Portuguese and Vietnamese versions of my blog, with the parts that
only make sense on my site taken out.

## What it does

- A translated post lives at `/<lang>/<its normal permalink>/`, for example
  `/id/user-experience/tombol-tidak-berfungsi-di-hp/`. Any permalink structure that ends in
  `%postname%` works.
- Each language gets its own home (`/id/`), paging (`/id/page/2/`) and feed (`/id/feed/`). The home
  answers 404 until that language has a published post.
- On those URLs it calls `switch_to_locale()`, so `<html lang>`, WordPress's own strings, your
  theme's strings and dates follow the language.
- Every version of a post prints hreflang links to all the others, plus `x-default`, and the same
  goes for `/` and `/id/`. Each page also states its language in a `content-language` meta tag,
  which Bing reads.
- Lists stay in one language: the English home, feeds, archives and previous/next links never show
  translations, and `/id/` shows only Indonesian posts. The admin, REST API, cron and WP-CLI still
  see every post.
- Each post has one URL. A translation opened without its `/id/`, or an English post opened under
  `/id/`, gets a 301 to the right URL. `?wmnp_lang=id` in a query string does nothing.
- A scheduled translation is published together with its scheduled original.

It doesn't translate pages, categories, tags or menus. If you need those, a multilingual plugin
starts to earn its weight.

## Install

1. Copy `multilingual-posts.php` into `wp-content/mu-plugins/` (create the folder if it isn't there).
   Must-use files load automatically. You can also `require` it from your theme's `functions.php`,
   but then it stops working if you switch themes.
2. Install WordPress's core language pack for each language. `switch_to_locale()` quietly does
   nothing without it. Pick the language once under **Settings > General > Site Language**, save,
   and switch back, or run `wp language core install id_ID`.
3. Visit **Settings > Permalinks** once (or just load any page: the file adds its rewrite rules on
   the first request).

## Add your languages

The file ships with English as the site language and Indonesian as the one translation language.
Change them with the `wmnp_languages` filter, from another mu-plugin file or your theme:

```php
add_filter( 'wmnp_languages', function ( $languages ) {
	$languages['es'] = array(
		'locale'      => 'es_ES',             // A core language pack locale.
		'hreflang'    => 'es',
		'name'        => 'Español',           // Shown in the editor and the language links.
		'read'        => 'Leer en español',   // Link text on the other versions.
		'home'        => 'Inicio',            // First breadcrumb (Yoast SEO).
		'date_format' => 'j \d\e F \d\e Y',
		'title'       => 'My blog in Spanish',   // The /es/ home's title.
		'description' => 'Articles in Spanish.', // The /es/ home's meta description.
	);
	return $languages;
} );
```

The first key is the site language. Only `locale`, `hreflang` and `name` are required. Watch the
locale names: Japanese is plain `ja`, not `ja_JP`, because that's what the language pack is called.

## Mark a post as a translation

Every post gets a **Language** box in the editor sidebar. Pick the language and the post it
translates, then save. Both posts now link to each other with hreflang. When a script creates the
post, set the same two meta fields with `meta_input`:

```php
wp_insert_post( array(
	'post_title'  => 'Tombol tidak berfungsi di HP',
	'post_status' => 'publish',
	'meta_input'  => array(
		'_wmnp_lang'           => 'id',
		'_wmnp_translation_of' => 123, // The original post's ID.
	),
) );
```

## With or without Yoast SEO

Yoast SEO treats `/id/` as the site home, so it points that page's canonical, `og:url` and schema at
`/`, which tells Google not to index `/id/` at all.

**Settings > Reading > Multilingual posts** has a checkbox for this:

- **Yoast SEO active:** ticked by default. The file corrects Yoast's canonical, `og:url`, title,
  description, `WebPage` schema, `og:locale` and breadcrumbs on each language home.
- **No Yoast SEO:** the box is greyed out, and the file prints the language home's `<title>`, meta
  description and canonical itself. On single posts WordPress core's own canonical is already right.
- **Another SEO plugin:** add `add_filter( 'wmnp_print_home_tags', '__return_false' );` so the file
  doesn't print a second canonical, and correct the language home through that plugin's filters.

## Show links to the other languages

hreflang is for search engines. For readers, put this in your single post template, for example
under the title:

```php
<?php if ( function_exists( 'wmnp_the_translation_links' ) ) { wmnp_the_translation_links(); } ?>
```

It prints "Baca dalam Bahasa Indonesia" on the English post and "Read in English" on the
translation. For your own markup, `wmnp_translation_links()` returns the URLs and labels.

## Tested with

WordPress 7.1, PHP 8.5, Yoast SEO 28.8 and without it, on a clean install with the
`/%category%/%postname%/`, `/%postname%/` and `/%year%/%monthnum%/%postname%/` permalink
structures. It needs WordPress 6.5 or newer and PHP 7.4 or newer.

## License

GPL-2.0-or-later, like WordPress. See [LICENSE](LICENSE).

Questions or a setup this doesn't handle? Tell me in the comments on
[the article](https://tejashingu.com/wordpress/wordpress-multilingual-without-plugin/).
