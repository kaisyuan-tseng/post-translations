<?php
/**
 * Post Translations — example site configuration
 *
 * Copy this file into wp-content/mu-plugins/ and edit the language list.
 * The plugin itself is generic; everything site-specific lives here:
 * the languages, which one is the original, the field help texts, and the
 * rule for building the front-end URL shown in the editor.
 *
 * The plugin activates fine without this file — it just shows no translation
 * UI until a language is declared.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------ Languages */

add_filter(
	'pt_languages',
	static function ( $langs ) {
		/*
		 * The key is the language code and shows up in the REST field
		 * ({ "zh-hans": {...} }).
		 *   label       name shown in the admin
		 *   short       abbreviation used in the posts list column
		 *   meta_prefix prefix of the post meta keys (defaults to pt_<code>_)
		 */
		$langs['zh-hans'] = array(
			'label'       => '简体中文',
			'short'       => '简中',
			'meta_prefix' => 'pt_zh_hans_',
		);
		$langs['zh-hant'] = array(
			'label'       => '繁體中文',
			'short'       => '繁中',
			'meta_prefix' => 'pt_zh_hant_',
		);

		return $langs;
	}
);

/* --------------------------------------------------- Original language */

add_filter(
	'pt_base_language',
	static function () {
		return array(
			'code'  => 'en',
			'label' => 'English',
			'short' => 'EN',
		);
	}
);

/* ------------------------------------------- Field help (SEO semantics) */

/*
 * `text` accepts HTML (sanitised with wp_kses_post).
 * Add min / max to show a live character counter: green inside the range,
 * amber when short, red when over.
 */
add_filter(
	'pt_field_hints',
	static function ( $hints ) {
		$hints['title'] = array(
			'text' => 'Used as <code>&lt;title&gt;</code>, <code>og:title</code> and the page H1.',
			'max'  => 60,
		);
		$hints['excerpt'] = array(
			'text' => 'Used as <code>meta description</code> and <code>og:description</code>.',
			'min'  => 70,
			'max'  => 160,
		);

		return $hints;
	}
);

/* ------------------------------------------------- Front-end URL preview */

/*
 * On a headless site the WordPress permalink is not the URL visitors see,
 * so the editor builds it from the front-end route. Return an empty string
 * to hide the line entirely.
 *
 * The example below matches an Astro site that serves
 * /{language prefix}/resources/{category slug}/{post slug}, with the static
 * front end on :80 and WordPress on :8081 — hence dropping the port.
 */
add_filter(
	'pt_preview_url',
	static function ( $url, $post, $lang ) {
		$prefixes = array(
			'zh-hans' => '/zh-hans',
			'zh-hant' => '/zh-hant',
		);
		$prefix = isset( $prefixes[ $lang ] ) ? $prefixes[ $lang ] : '';

		$terms = get_the_category( $post->ID );
		$cat   = ! empty( $terms ) ? $terms[0]->slug : 'uncategorized';
		$base  = (string) apply_filters( 'pt_preview_base', preg_replace( '#:\d+/?$#', '', home_url() ) );

		return $base . $prefix . '/resources/' . $cat . '/' . $post->post_name;
	},
	10,
	3
);
