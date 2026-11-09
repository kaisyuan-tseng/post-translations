=== Post Translations ===
Contributors: kaisyuan-tseng
Tags: translation, multilingual, i18n, meta, headless, polylang-alternative
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep one post per article and attach every language to it. The posts list gives each language its own entry; each entry opens a full-page editor in that language.

== Description ==

Out of the box, a WordPress post has one title, one excerpt and one body. The usual
way to publish it in several languages (Polylang, WPML) is *one post per language*:
the posts list fills up with near-duplicates, slugs have to be kept in sync, and
sticky flags, categories and revisions all multiply.

This plugin takes the opposite route: **one post, many languages.** The original
lives in the native fields; every other language is stored as post meta. All
languages share the same slug, so localized URLs differ only by the language prefix
your front end chooses.

What you get in the admin:

* **A "Languages" column in the posts list.** One entry per language. A solid chip
  with a dot means the translation exists; a dashed chip means it does not yet.
* **A full-page editor per language.** Click a language and you get that language's
  title, excerpt and body in one screen. Saving writes only the translation — the
  original is never touched. Handy when each language needs its own SEO title and
  meta description.
* **A sidebar panel in the block editor** for quick edits without leaving the post.
  A classic-editor meta box is included as well.
* **A "missing translation" filter** at the top of the posts list.

== Generic by design ==

The plugin implements the *mechanism*; every site decision is injected through a
filter. It ships with no languages, no URL scheme and no assumptions about your
stack — until you declare a language, nothing is shown (and the admin tells you how).

* `pt_languages` — the language list: `code => [ label, short, meta_prefix? ]`.
* `pt_base_language` — which language is the original (defaults to `en / English / EN`).
  The original can be any language, including Chinese or Arabic.
* `pt_fields` — fields to translate, default `title / excerpt / body`.
* `pt_field_labels` — field labels in the admin.
* `pt_field_hints` — per-field help text and an optional character range.
* `pt_rest_field` — REST field name, default `pt_translations`.
* `pt_preview_url` — optional; show the front-end URL of the translation being edited.

`examples/site-config.php` in the plugin folder is a ready-to-copy configuration file.

== REST ==

* Every post gains a `pt_translations` field:
  `{ "zh-hans": { "title": "", "excerpt": "", "body": "" }, ... }` — readable and writable.
* Dedicated endpoint `GET|POST /wp-json/post-translations/v1/posts/<id>/translations`.
  It writes meta only: `post_modified` is untouched and no revision is created.

== Installation ==

1. Upload the zip through *Plugins → Add New → Upload Plugin*, then activate.
2. Copy `examples/site-config.php` to `wp-content/mu-plugins/` and edit the language
   list for your site. Until then the plugin stays invisible and shows a notice
   explaining what to declare.
3. Open *Posts*, then click a language in the "Languages" column to start translating.

== Frequently Asked Questions ==

= Will I lose data if I deactivate the plugin? =

No. Translations are ordinary post meta (`pt_<language>_title` and friends). The
plugin only adds the UI and the REST surface on top of it.

= Can the original language be something other than English? =

Yes — set `pt_base_language`. The original is always the post's own fields, so
"original" simply means "the one that is not stored in meta".

Note that switching the original language of an existing site means moving content:
the translations have to be written back into the native fields, and the previous
original moved into meta. Plan for a migration.

= Do all posts have to share the same original language? =

At the moment, yes: the original language is a site-wide setting. A per-post
override (some posts written in Chinese, others in English) is not supported yet.

= Is the HTML in a translation sanitized? =

The body goes through `wp_kses_post` (common markup kept, scripts stripped); title
and excerpt go through `sanitize_text_field`.

= Does it work with right-to-left languages? =

The admin styles use logical properties (`margin-inline-start` and friends), so the
UI mirrors correctly under an RTL locale.

== Changelog ==

= 1.5.0 =
* Every user-facing string is now translatable (`post-translations` text domain),
  with a POT template and a bundled Simplified Chinese translation.
* Admin CSS switched to logical properties so the UI mirrors under RTL locales.
* Plugin header, readme and inline documentation translated to English.

= 1.4.2 =
* Fixed: the classic-editor script hard-coded `en` as the original language, so the
  visual editor never initialised when the original was another language.

= 1.4.1 =
* Fixed: the "missing translation" filter could push an empty string into
  `meta_query` when merging with an existing one.
* Asset version numbers now follow a single `PT_VERSION` constant.

= 1.4.0 =
* Generic rewrite: languages, fields, REST field name and front-end URL are all
  injected through filters; the plugin carries no site assumptions.
* Added the per-language full-page editor.
* The posts list column now lists one entry per language.

= 1.3.0 =
* Added the per-language editor screen with character counters.

= 1.2.0 =
* Added the language column to the posts list.

= 1.0.0 =
* First release: per-post translation meta plus a REST field.
