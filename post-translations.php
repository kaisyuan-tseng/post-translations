<?php
/**
 * Plugin Name: Post Translations
 * Description: Attach multiple language versions (title, excerpt and body) to a single post. The posts list gives every language its own entry, and each entry opens a full-page editor for that language. Translations are stored in post meta and exposed over REST for headless front ends. The plugin makes no assumption about languages, site paths or stack — the language list is injected through the pt_languages filter.
 * Version: 1.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: kaisyuan-tseng
 * Text Domain: post-translations
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * ── How it stays generic ──────────────────────────────────────────────
 * The plugin only implements the mechanism; every site-specific decision is
 * injected through a filter, so it works on any install:
 *
 *   pt_languages      array   language list: code => [label, short, meta_prefix?]
 *                             nothing is shown until this is declared
 *   pt_base_language  array   the original language: code / label / short
 *                             (defaults to en / English / EN)
 *   pt_fields         array   fields to translate (default title / excerpt / body)
 *   pt_field_labels   array   field labels shown in the admin
 *   pt_field_hints    array   per-field help text and character range
 *   pt_rest_field     string  REST field name (default pt_translations)
 *   pt_preview_url    string  optional front-end URL for the editor screen
 *
 * Site config for this repository lives in
 * wp-content/mu-plugins/post-translations-config.php.
 *
 * ── Storage ───────────────────────────────────────────────────────────
 *   The original (base language) is the post itself: post_title,
 *   post_excerpt, post_content. Translations live in post meta as
 *   <meta_prefix><field>, e.g. pt_zh_hans_title for zh-hans.
 *
 * ── REST ──────────────────────────────────────────────────────────────
 *   GET/POST /wp-json/post-translations/v1/posts/<id>/translations
 *   { "zh-hans": { "title": "", "excerpt": "", "body": "" }, ... }
 *   Writes only touch meta: post_modified stays as it was and no revision
 *   is created.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Load bundled translations (languages/*.mo). */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'post-translations', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/** 版本号：只在这里改，后台资源的 cache busting 跟着走 */
if ( ! defined( 'PT_VERSION' ) ) {
	define( 'PT_VERSION', '1.5.0' );
}

/**
 * 语言清单：完全由站点通过 pt_languages 过滤器声明，插件不含任何具体语言。
 *
 * 每一项支持：
 *   label       后台显示名（如「简体中文」）
 *   short       列表列里的简称（如「简中」）
 *   meta_prefix meta 键前缀，默认 pt_<code>_
 *
 * @return array<string, array{label:string, short:string, meta_prefix:string}>
 */
function pt_langs() {
	$declared = apply_filters( 'pt_languages', array() );
	$out      = array();

	foreach ( (array) $declared as $code => $conf ) {
		$code = (string) $code;
		if ( '' === $code ) {
			continue;
		}
		$conf = (array) $conf;

		$out[ $code ] = array(
			'label'       => isset( $conf['label'] ) ? (string) $conf['label'] : $code,
			'short'       => isset( $conf['short'] ) ? (string) $conf['short'] : strtoupper( $code ),
			'meta_prefix' => isset( $conf['meta_prefix'] ) && '' !== $conf['meta_prefix']
				? (string) $conf['meta_prefix']
				: 'pt_' . str_replace( '-', '_', $code ) . '_',
		);
	}

	return $out;
}

/** 原文语言（也就是文章本身的那些字段，没有 meta） */
function pt_base_language() {
	$base = (array) apply_filters(
		'pt_base_language',
		array(
			'code'  => 'en',
			'label' => 'English',
			'short' => 'EN',
		)
	);

	return array(
		'code'  => isset( $base['code'] ) ? (string) $base['code'] : 'en',
		'label' => isset( $base['label'] ) ? (string) $base['label'] : 'English',
		'short' => isset( $base['short'] ) ? (string) $base['short'] : 'EN',
	);
}

/** 每个语言要翻译的字段 */
function pt_fields() {
	$fields = (array) apply_filters( 'pt_fields', array( 'title', 'excerpt', 'body' ) );
	$fields = array_values( array_filter( array_map( 'strval', $fields ) ) );

	return $fields ? $fields : array( 'title', 'excerpt', 'body' );
}

/** 语言 + 字段 → meta 键 */
function pt_meta_key( $lang, $field ) {
	$langs = pt_langs();

	if ( ! isset( $langs[ $lang ] ) ) {
		return '';
	}

	return $langs[ $lang ]['meta_prefix'] . $field;
}

/** REST 字段名（默认 pt_translations） */
function pt_rest_field() {
	return (string) apply_filters( 'pt_rest_field', 'pt_translations' );
}

/** 某语言是否已有译文（以正文为准） */
function pt_has_translation( $post_id, $lang ) {
	$key = pt_meta_key( $lang, 'body' );
	if ( '' === $key ) {
		return false;
	}

	return '' !== trim( (string) get_post_meta( $post_id, $key, true ) );
}

/* ============================================================== REST 字段 */

/** 读取某篇文章的全部译文（只返回有内容的语言） */
function pt_get_translations( $post_id ) {
	$post_id = (int) $post_id;
	$out     = array();

	foreach ( pt_langs() as $lang => $conf ) {
		$item = array();
		$any  = false;

		foreach ( pt_fields() as $field ) {
			$value          = (string) get_post_meta( $post_id, $conf['meta_prefix'] . $field, true );
			$item[ $field ] = $value;
			if ( '' !== $value ) {
				$any = true;
			}
		}

		if ( $any ) {
			$out[ $lang ] = $item;
		}
	}

	return $out;
}

/** 写入一篇译文（合并语义：只写给出的字段） */
function pt_apply_translation( $post_id, $lang, $fields ) {
	$langs = pt_langs();

	if ( ! isset( $langs[ $lang ] ) || ! is_array( $fields ) ) {
		return;
	}

	foreach ( pt_fields() as $key ) {
		if ( ! array_key_exists( $key, $fields ) ) {
			continue;
		}
		$raw = $fields[ $key ];
		if ( ! is_string( $raw ) ) {
			continue;
		}
		pt_write_meta( $post_id, $langs[ $lang ]['meta_prefix'] . $key, $raw );
	}
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_field(
			'post',
			pt_rest_field(),
			array(
				'get_callback'    => static function ( $post ) {
					return pt_get_translations( (int) $post['id'] );
				},
				'update_callback' => static function ( $value, $post ) {
					if ( ! is_array( $value ) ) {
						return;
					}
					foreach ( $value as $lang => $fields ) {
						pt_apply_translation( $post->ID, $lang, $fields );
					}
				},
				'schema'          => array(
					'description' => 'Per-language translations stored on this single post.',
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);

		/*
		 * 专用端点：区块编辑器的侧边栏面板用它读写译文。
		 * 不经过 /wp/v2/posts/{id}，所以保存译文不会改 post_modified、不产生修订版本。
		 */
		register_rest_route(
			'post-translations/v1',
			'/posts/(?P<id>\d+)/translations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function ( $request ) {
						return current_user_can( 'edit_post', (int) $request['id'] );
					},
					'callback'            => static function ( $request ) {
						return rest_ensure_response( pt_get_translations( (int) $request['id'] ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => static function ( $request ) {
						return current_user_can( 'edit_post', (int) $request['id'] );
					},
					'callback'            => static function ( $request ) {
						$id   = (int) $request['id'];
						$data = $request->get_json_params();
						if ( ! is_array( $data ) ) {
							$data = $request->get_params();
						}
						unset( $data['id'], $data['_locale'] );
						foreach ( $data as $lang => $fields ) {
							pt_apply_translation( $id, $lang, $fields );
						}
						return rest_ensure_response( pt_get_translations( $id ) );
					},
				),
			)
		);
	}
);

/** 统一写入：正文保 HTML（过 wp_kses_post），标题摘要去标签；空值删 meta */
function pt_write_meta( $post_id, $meta_key, $raw ) {
	$is_body = substr( $meta_key, -5 ) === '_body';
	$clean   = $is_body ? wp_kses_post( trim( $raw ) ) : sanitize_text_field( $raw );
	if ( '' === $clean ) {
		delete_post_meta( $post_id, $meta_key );
	} else {
		update_post_meta( $post_id, $meta_key, $clean );
	}
}

/* ========================================================== 编辑页面板 */

add_action(
	'add_meta_boxes',
	static function () {
		add_meta_box(
			'posttrans-box',
			__( 'Translations', 'post-translations' ),
			'pt_render_box',
			array( 'post' ),
			'normal',
			'high'
		);
	}
);

/** 字段 → 标签（可过滤，插件本身不写死业务含义） */
function pt_field_labels() {
	return array_merge(
		array(
			'title'   => __( 'Title', 'post-translations' ),
			'excerpt' => __( 'Excerpt', 'post-translations' ),
			'body'    => __( 'Body', 'post-translations' ),
		),
		(array) apply_filters( 'pt_field_labels', array() )
	);
}

/** 渲染「多语言译文」面板：原文一个标签页，每种译文各一个 */
function pt_render_box( $post ) {
	$langs  = pt_langs();
	$base   = pt_base_language();
	$labels = pt_field_labels();
	$fields = pt_fields();

	wp_nonce_field( 'pt_save_' . $post->ID, 'pt_nonce' );
	?>
	<div class="posttrans">
		<p class="posttrans__intro">
			<?php
			printf(
				/* translators: %s: label of the original language, e.g. English */
				esc_html__( 'The original (%s) is the title, excerpt and content in the editor above. Fill in one tab per language; any language left empty falls back to the original on the front end.', 'post-translations' ),
				esc_html( $base['label'] )
			);
			?>
		</p>

		<div class="posttrans__tabs" role="tablist">
			<button type="button" class="posttrans__tab is-active" data-posttrans-tab="<?php echo esc_attr( $base['code'] ); ?>">
				<?php
				printf(
					/* translators: %s: label of the original language */
					esc_html__( '%s (original)', 'post-translations' ),
					esc_html( $base['label'] )
				);
				?>
			</button>
			<?php foreach ( $langs as $lang => $conf ) : ?>
				<button type="button" class="posttrans__tab" data-posttrans-tab="<?php echo esc_attr( $lang ); ?>">
					<?php echo esc_html( $conf['label'] ); ?>
					<?php if ( pt_has_translation( $post->ID, $lang ) ) : ?>
						<span class="posttrans__dot" title="<?php esc_attr_e( 'Translation exists', 'post-translations' ); ?>"></span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="posttrans__panel is-active" data-posttrans-panel="<?php echo esc_attr( $base['code'] ); ?>">
			<p class="posttrans__hint">
				<?php
				printf(
					wp_kses(
						/* translators: %s: name of the filter, wrapped in <code> */
						__( 'The original content lives in the editor above — nothing to fill in here. Languages are declared through the %s filter.', 'post-translations' ),
						array( 'code' => array() )
					),
					'<code>pt_languages</code>'
				);
				?>
			</p>
		</div>

		<?php
		foreach ( $langs as $lang => $conf ) :
			?>
			<div class="posttrans__panel" data-posttrans-panel="<?php echo esc_attr( $lang ); ?>">
				<?php
				foreach ( $fields as $field ) :
					$key   = $conf['meta_prefix'] . $field;
					$value = (string) get_post_meta( $post->ID, $key, true );
					$label = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
					?>
					<p class="posttrans__field">
						<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
						<?php if ( 'body' === $field ) : ?>
							<?php
							/*
							 * 这里只输出 textarea，可视化编辑器由 assets/admin.js 在切到该标签页时
							 * 用 wp.editor.initialize() 懒初始化：隐藏容器里初始化 TinyMCE 会量到 0 宽高。
							 * 必须 wpautop=false，否则保存时会把正文里的 <p> 全部剥掉。
							 */
							wp_editor(
								$value,
								$key,
								array(
									'textarea_name' => $key,
									'textarea_rows' => 16,
									'media_buttons' => false,
									'quicktags'     => false,
									'tinymce'       => false,
								)
							);
							?>
						<?php elseif ( 'excerpt' === $field ) : ?>
							<textarea
								class="widefat"
								rows="3"
								id="<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $key ); ?>"
							><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input
								type="text"
								class="widefat"
								id="<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $key ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
							/>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/* ============================================================ 保存 */

add_action(
	'save_post',
	static function ( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['pt_nonce'] ) ) {
			return; // 不是本面板提交的表单（如 REST 保存）——不碰译文
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pt_nonce'] ) ), 'pt_save_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$fields = pt_fields();
		foreach ( pt_langs() as $conf ) {
			foreach ( $fields as $key ) {
				$field = $conf['meta_prefix'] . $key;
				if ( ! isset( $_POST[ $field ] ) ) {
					continue;
				}
				pt_write_meta( $post_id, $field, wp_unslash( $_POST[ $field ] ) );
			}
		}
	},
	10,
	2
);

/* ====================================================== 单语言编辑页 */

/**
 * 某个语言的整篇编辑页地址。
 *
 * 点列表里的语言入口就落到这里：标题、摘要、正文全是这一语言的内容，
 * 保存只写文章 meta，原文（英文）一个字都不动。
 * 这样分语种写 SEO 文案时不用在两个框之间来回对照。
 */
function pt_editor_url( $post_id, $lang ) {
	return add_query_arg(
		array(
			'page' => 'pt-editor',
			'post' => (int) $post_id,
			'lang' => $lang,
		),
		admin_url( 'admin.php' )
	);
}

add_action(
	'admin_menu',
	static function () {
		$hook = add_submenu_page(
			null,          // 父级为 null：不出现在菜单里，只能从语言列进来
			__( 'Edit translation', 'post-translations' ),
			__( 'Edit translation', 'post-translations' ),
			'edit_posts',
			'pt-editor',
			'pt_render_editor_page'
		);
		if ( ! $hook ) {
			return;
		}
		add_action( 'load-' . $hook, 'pt_handle_editor_save' );
		add_action(
			'load-' . $hook,
			static function () {
				/* 让左侧「文章」菜单保持高亮，不显得像掉进了别的模块 */
				add_filter(
					'parent_file',
					static function () {
						return 'edit.php';
					}
				);
			}
		);
	}
);

/** 保存译文（PRG：写库后 302 回本页，刷新不会重复提交） */
function pt_handle_editor_save() {
	if ( ! isset( $_POST['pt_editor_save'] ) ) {
		return;
	}

	$post_id = isset( $_POST['pt_post'] ) ? (int) $_POST['pt_post'] : 0;
	$lang    = isset( $_POST['pt_lang'] ) ? sanitize_key( wp_unslash( $_POST['pt_lang'] ) ) : '';
	$langs   = pt_langs();

	if ( ! $post_id || ! isset( $langs[ $lang ] ) ) {
		wp_die( esc_html__( 'Missing parameters.', 'post-translations' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You are not allowed to edit this post.', 'post-translations' ) );
	}
	check_admin_referer( 'pt_editor_' . $post_id . '_' . $lang );

	foreach ( pt_fields() as $field ) {
		if ( ! isset( $_POST[ 'pt_' . $field ] ) ) {
			continue;
		}
		pt_write_meta(
			$post_id,
			$langs[ $lang ]['meta_prefix'] . $field,
			wp_unslash( $_POST[ 'pt_' . $field ] )
		);
	}

	wp_safe_redirect( add_query_arg( 'updated', '1', pt_editor_url( $post_id, $lang ) ) );
	exit;
}

/**
 * 每个字段的说明与字数建议（可选，由站点通过 pt_field_hints 注入）。
 *
 * 返回 field => [ 'text' => '说明文字', 'min' => 70, 'max' => 160 ]。
 * 带 min/max 的字段会显示实时字符计数并按区间着色。
 */
function pt_field_hints() {
	return (array) apply_filters( 'pt_field_hints', array() );
}

/** 该语言在前端对应的地址（可选，站点通过 pt_preview_url 注入；返回空串则不显示） */
function pt_preview_url( $post, $lang ) {
	return (string) apply_filters( 'pt_preview_url', '', $post, $lang );
}

function pt_render_editor_page() {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	$post    = get_post( $post_id );
	$langs   = pt_langs();
	$base    = pt_base_language();

	if ( ! $post || 'post' !== $post->post_type ) {
		printf(
			'<div class="wrap"><h1>%s</h1><p>%s</p></div>',
			esc_html__( 'Edit translation', 'post-translations' ),
			esc_html__( 'The post does not exist or has been deleted.', 'post-translations' )
		);
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You are not allowed to edit this post.', 'post-translations' ) );
	}

	$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
	if ( ! isset( $langs[ $lang ] ) ) {
		$keys = array_keys( $langs );
		$lang = $keys ? (string) $keys[0] : '';
	}
	if ( '' === $lang ) {
		printf(
			'<div class="wrap"><h1>%s</h1><p>%s</p></div>',
			esc_html__( 'Edit translation', 'post-translations' ),
			wp_kses(
				/* translators: %s: name of the filter, wrapped in <code> */
				sprintf( __( 'No languages have been declared yet. Add them through the %s filter.', 'post-translations' ), '<code>pt_languages</code>' ),
				array( 'code' => array() )
			)
		);
		return;
	}

	$conf   = $langs[ $lang ];
	$labels = pt_field_labels();
	$hints  = pt_field_hints();
	$fields = pt_fields();
	$values = array();

	foreach ( $fields as $field ) {
		$values[ $field ] = (string) get_post_meta( $post_id, $conf['meta_prefix'] . $field, true );
	}

	$preview = pt_preview_url( $post, $lang );
	?>
	<div class="wrap pt-ed">
		<h1 class="wp-heading-inline">
			<?php
			printf(
				/* translators: %s: language label, e.g. French */
				esc_html__( 'Edit %s translation', 'post-translations' ),
				esc_html( $conf['label'] )
			);
			?>
		</h1>
		<a class="page-title-action" href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>">
			<?php
			printf(
				/* translators: %s: label of the original language */
				esc_html__( 'Edit %s original', 'post-translations' ),
				esc_html( $base['label'] )
			);
			?>
		</a>
		<hr class="wp-header-end" />

		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				printf(
					/* translators: %s: language label */
					esc_html__( 'Saved the %s translation.', 'post-translations' ),
					esc_html( $conf['label'] )
				);
				?>
			</p></div>
		<?php endif; ?>

		<p class="pt-ed__source">
			<?php esc_html_e( 'Original:', 'post-translations' ); ?>
			<strong><?php echo esc_html( get_the_title( $post ) ); ?></strong>
			<?php if ( '' !== $preview ) : ?>
				<span class="pt-ed__sep">·</span>
				<?php esc_html_e( 'Front-end URL:', 'post-translations' ); ?>
				<a href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( wp_parse_url( $preview, PHP_URL_PATH ) ); ?></code></a>
			<?php endif; ?>
		</p>

		<nav class="pt-ed__langs">
			<a class="pt-ed__lang" href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>">
				<?php echo esc_html( $base['label'] ); ?><small><?php esc_html_e( 'original', 'post-translations' ); ?></small>
			</a>
			<?php foreach ( $langs as $code => $item ) : ?>
				<a class="pt-ed__lang<?php echo $code === $lang ? ' is-current' : ''; ?>"
					href="<?php echo esc_url( pt_editor_url( $post_id, $code ) ); ?>">
					<?php echo esc_html( $item['label'] ); ?>
					<?php if ( pt_has_translation( $post_id, $code ) ) : ?>
						<span class="pt-ed__dot" title="<?php esc_attr_e( 'Has content', 'post-translations' ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<form method="post" action="<?php echo esc_url( pt_editor_url( $post_id, $lang ) ); ?>" class="pt-ed__form">
			<?php wp_nonce_field( 'pt_editor_' . $post_id . '_' . $lang ); ?>
			<input type="hidden" name="pt_editor_save" value="1" />
			<input type="hidden" name="pt_post" value="<?php echo (int) $post_id; ?>" />
			<input type="hidden" name="pt_lang" value="<?php echo esc_attr( $lang ); ?>" />

			<?php
			foreach ( $fields as $field ) :
				$id    = 'pt_' . $field;
				$label = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
				$hint  = isset( $hints[ $field ] ) ? (array) $hints[ $field ] : array();
				?>
				<div class="pt-ed__row">
					<label class="pt-ed__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>

					<?php if ( 'body' === $field ) : ?>
						<?php
						/*
						 * wpautop 必须关掉：默认的经典编辑器靠前端 wpautop 把空行变成 <p>，
						 * 所以它保存时会把 <p> 全部剥掉。译文是原样输出给前端的，
						 * 一旦被剥就没有段落结构了 —— 这里要求编辑器保留标签。
						 */
						wp_editor(
							$values[ $field ],
							$id,
							array(
								'textarea_name' => $id,
								'textarea_rows' => 22,
								'media_buttons' => false,
								'quicktags'     => true,
								'tinymce'       => array(
									'wpautop'          => false,
									'wp_autoresize_on' => true,
								),
							)
						);
						?>
					<?php elseif ( 'title' === $field ) : ?>
						<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>"
							class="pt-ed__title" value="<?php echo esc_attr( $values[ $field ] ); ?>"
							placeholder="<?php echo esc_attr( $label ); ?>" autocomplete="off" />
					<?php elseif ( 'excerpt' === $field ) : ?>
						<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>"
							class="pt-ed__excerpt" rows="3"
							placeholder="<?php echo esc_attr( $label ); ?>"><?php echo esc_textarea( $values[ $field ] ); ?></textarea>
					<?php else : ?>
						<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>"
							class="pt-ed__title" value="<?php echo esc_attr( $values[ $field ] ); ?>"
							placeholder="<?php echo esc_attr( $label ); ?>" autocomplete="off" />
					<?php endif; ?>

					<?php if ( ! empty( $hint['text'] ) || isset( $hint['max'] ) ) : ?>
						<p class="pt-ed__hint">
							<?php echo wp_kses_post( isset( $hint['text'] ) ? $hint['text'] : '' ); ?>
							<?php if ( isset( $hint['max'] ) || isset( $hint['min'] ) ) : ?>
								<span class="pt-ed__count"
									data-for="<?php echo esc_attr( $id ); ?>"
									data-min="<?php echo esc_attr( isset( $hint['min'] ) ? (int) $hint['min'] : 0 ); ?>"
									data-max="<?php echo esc_attr( isset( $hint['max'] ) ? (int) $hint['max'] : 0 ); ?>"></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<p class="pt-ed__actions">
				<button type="submit" class="button button-primary button-large">
					<?php
					printf(
						/* translators: %s: language label */
						esc_html__( 'Save %s translation', 'post-translations' ),
						esc_html( $conf['label'] )
					);
					?>
				</button>
				<span class="pt-ed__note">
					<?php
					printf(
						/* translators: %s: label of the original language */
						esc_html__( 'Only this translation is written; the %s original stays untouched.', 'post-translations' ),
						esc_html( $base['label'] )
					);
					?>
				</span>
			</p>
		</form>
	</div>
	<script>
	window.ptEdI18n = <?php echo wp_json_encode(
		array(
			'charOne'  => __( '%s character', 'post-translations' ),
			'charMany' => __( '%s characters', 'post-translations' ),
		)
	); ?>;
	(function () {
		function refresh(box) {
			var field = document.getElementById(box.getAttribute("data-for"));
			if (!field) return;
			var n = (field.value || "").trim().length;
			var min = parseInt(box.getAttribute("data-min"), 10) || 0;
			var max = parseInt(box.getAttribute("data-max"), 10) || 0;
			var tpl = n === 1 ? window.ptEdI18n.charOne : window.ptEdI18n.charMany;
			box.textContent = tpl.replace("%s", n);
			box.className = "pt-ed__count";
			if (max && n > max) box.className += " is-long";
			else if (min && n < min) box.className += " is-short";
			else if (n > 0) box.className += " is-ok";
		}
		Array.prototype.forEach.call(document.querySelectorAll(".pt-ed__count"), function (box) {
			var field = document.getElementById(box.getAttribute("data-for"));
			if (!field) return;
			field.addEventListener("input", function () { refresh(box); });
			refresh(box);
		});
		/* 提交前把可视化编辑器的内容同步回 textarea */
		document.addEventListener("submit", function () {
			if (window.tinymce && typeof window.tinymce.triggerSave === "function") window.tinymce.triggerSave();
		}, true);
	})();
	</script>
	<?php
}

/* ============================================================ 列表语言列 */

/**
 * 列表列用的语言清单：原文语言（文章本体）+ 各译文语言。
 *
 * 原文语言的入口是文章本身；其它语言进「单语言整篇编辑页」。
 *
 * @return array<string, array{label:string, short:string, base:bool, has:bool, url:string}>
 */
function pt_column_langs( $post_id ) {
	$post_id = (int) $post_id;
	$base    = pt_base_language();

	$out = array(
		$base['code'] => array(
			'label' => $base['label'],
			'short' => $base['short'],
			'base'  => true,
			'has'   => true,
			'url'   => (string) get_edit_post_link( $post_id, 'raw' ),
		),
	);

	foreach ( pt_langs() as $lang => $conf ) {
		$out[ $lang ] = array(
			'label' => $conf['label'],
			'short' => $conf['short'],
			'base'  => false,
			'has'   => pt_has_translation( $post_id, $lang ),
			'url'   => pt_editor_url( $post_id, $lang ),
		);
	}

	return $out;
}

add_filter(
	'manage_post_posts_columns',
	static function ( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['pt_lang'] = __( 'Languages', 'post-translations' );
			}
		}
		if ( ! isset( $out['pt_lang'] ) ) {
			$out['pt_lang'] = __( 'Languages', 'post-translations' );
		}
		return $out;
	}
);

add_action(
	'manage_post_posts_custom_column',
	static function ( $column, $post_id ) {
		if ( 'pt_lang' !== $column ) {
			return;
		}

		echo '<div class="pt-langs">';
		foreach ( pt_column_langs( $post_id ) as $lang => $conf ) {
			if ( $conf['base'] ) {
				$class = 'pt-chip is-base';
				$title = sprintf(
					/* translators: %s: label of the original language */
					__( 'Edit the %s original', 'post-translations' ),
					$conf['label']
				);
			} elseif ( $conf['has'] ) {
				$class = 'pt-chip is-on';
				$title = sprintf(
					/* translators: %s: language label */
					__( 'Edit %s translation', 'post-translations' ),
					$conf['label']
				);
			} else {
				$class = 'pt-chip is-off';
				$title = sprintf(
					/* translators: %s: language label */
					__( 'Add %s translation', 'post-translations' ),
					$conf['label']
				);
			}

			printf(
				'<a class="%s" href="%s" title="%s">%s<span class="pt-chip__label">%s</span></a>',
				esc_attr( $class ),
				esc_url( $conf['url'] ),
				esc_attr( $title ),
				( ! $conf['base'] && $conf['has'] ) ? '<span class="pt-chip__dot" aria-hidden="true"></span>' : '',
				esc_html( $conf['short'] )
			);
		}
		echo '</div>';
	},
	10,
	2
);

/** 语言列样式（列表页与「自定义字段」无关，只在 edit.php 出现） */
add_action(
	'admin_head-edit.php',
	static function () {
		echo '<style>
.column-pt_lang { width: 168px; }
.pt-langs { display: flex; flex-wrap: wrap; gap: 4px; }
.pt-chip { display: inline-flex; align-items: center; gap: 4px; padding: 1px 7px; border-radius: 3px; border: 1px solid transparent; font-size: 11px; line-height: 1.8; text-decoration: none; white-space: nowrap; }
.pt-chip__dot { width: 5px; height: 5px; border-radius: 50%; background: #00a32a; }
.pt-chip.is-base { background: #f6f7f7; border-color: #dcdcde; color: #1d2327; font-weight: 600; }
.pt-chip.is-base:hover { background: #f0f0f1; border-color: #2271b1; color: #2271b1; }
.pt-chip.is-on { background: #f0f6fc; border-color: #c5d9ed; color: #0a4b78; }
.pt-chip.is-on:hover { background: #e3f0fb; border-color: #2271b1; color: #2271b1; }
.pt-chip.is-off { background: #fff; border-style: dashed; border-color: #c3c4c7; color: #8c8f94; }
.pt-chip.is-off:hover { border-style: solid; border-color: #2271b1; color: #2271b1; }
</style>';
	}
);

/** 列表顶部：按译文缺失筛选 */
add_action(
	'restrict_manage_posts',
	static function ( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['pt_missing'] ) ? sanitize_key( wp_unslash( $_GET['pt_missing'] ) ) : '';
		printf(
			'<select name="pt_missing"><option value="">%s</option>',
			esc_html__( 'All translation statuses', 'post-translations' )
		);
		foreach ( pt_langs() as $lang => $conf ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $lang ),
				selected( $current, $lang, false ),
				esc_html(
					sprintf(
						/* translators: %s: language label */
						__( 'Missing %s translation', 'post-translations' ),
						$conf['label']
					)
				)
			);
		}
		echo '</select>';
	}
);

add_action(
	'pre_get_posts',
	static function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		if ( 'post' !== $post_type ) {
			return;
		}
		$lang  = isset( $_GET['pt_missing'] ) ? sanitize_key( wp_unslash( $_GET['pt_missing'] ) ) : '';
		$langs = pt_langs();
		if ( '' === $lang || ! isset( $langs[ $lang ] ) ) {
			return;
		}
		/*
		 * 注意：(array) $query->get('meta_query') 在没设过时是 ['']（WP_Query 返回空串），
		 * 直接 array_merge 会在数组里塞进一个字符串元素，meta_query 解析会出错。
		 * 先把非数组元素过滤掉再追加。
		 */
		$meta_query = array_values( array_filter( (array) $query->get( 'meta_query' ), 'is_array' ) );
		$meta_query[] = array(
			'key'     => $langs[ $lang ]['meta_prefix'] . 'body',
			'compare' => 'NOT EXISTS',
		);

		$query->set( 'meta_query', $meta_query );
	}
);

/* ============================================ 区块编辑器：侧边栏面板 */

/**
 * 区块编辑器里经典 metabox 的可发现性很差（默认折叠在编辑器最底部），
 * 所以再提供一个原生侧边栏面板 + 全屏弹窗，作为主入口。
 */
add_action(
	'enqueue_block_editor_assets',
	static function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script(
			'posttrans-editor',
			plugins_url( 'assets/editor.js', __FILE__ ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ),
			PT_VERSION,
			true
		);

		$langs = array();
		foreach ( pt_langs() as $code => $conf ) {
			$langs[] = array(
				'code'  => $code,
				'label' => $conf['label'],
				'short' => $conf['short'],
			);
		}
		/*
		 * open_lang：从列表页点某个语言的编辑入口进来时带着 ?pt_lang=xx，
		 * 前端据此直接弹出该语言的编辑框，少一次点击。
		 */
		$open_lang = isset( $_GET['pt_lang'] ) ? sanitize_key( wp_unslash( $_GET['pt_lang'] ) ) : '';
		wp_add_inline_script(
			'posttrans-editor',
			'window.ptEditor = ' . wp_json_encode(
				array(
					'langs'      => $langs,
					'root'       => esc_url_raw( rest_url( 'post-translations/v1/' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'openLang'   => $open_lang,
					'editorBase' => admin_url( 'admin.php?page=pt-editor' ),
				)
			) . ';',
			'before'
		);
	}
);

/* ========================================================== 后台资源 */

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		/* 单语言编辑页：只借可视化编辑器，不加载 metabox 那套 */
		if ( 'admin_page_pt-editor' === $hook ) {
			wp_enqueue_editor();
			wp_register_style( 'posttrans-editor-page', false, array(), PT_VERSION );
			wp_enqueue_style( 'posttrans-editor-page' );
			wp_add_inline_style( 'posttrans-editor-page', pt_editor_page_css() );
			return;
		}

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		wp_enqueue_editor();
		wp_enqueue_script(
			'posttrans-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array( 'jquery', 'wp-editor' ),
			PT_VERSION,
			true
		);
		/* 经典编辑器：从列表页带着 ?pt_lang= 进来时，自动切到对应语言标签 */
		wp_add_inline_script(
			'posttrans-admin',
			'window.ptAdmin = ' . wp_json_encode(
				array(
					'openLang' => isset( $_GET['pt_lang'] ) ? sanitize_key( wp_unslash( $_GET['pt_lang'] ) ) : '',
				)
			) . ';',
			'before'
		);
		wp_register_style( 'posttrans-admin', false, array(), PT_VERSION );
		wp_enqueue_style( 'posttrans-admin' );
		wp_add_inline_style( 'posttrans-admin', pt_admin_css() );
	}
);

/** 单语言编辑页样式：标题框做成经典编辑器的大标题，其余保持后台原生观感 */
function pt_editor_page_css() {
	return '
.pt-ed { max-width: 1040px; }
.pt-ed .wp-heading-inline { margin-inline-end: 10px; }
.pt-ed__source { margin: 14px 0 0; color: #50575e; }
.pt-ed__source a { text-decoration: none; }
.pt-ed__sep { margin: 0 8px; color: #c3c4c7; }
.pt-ed__langs { display: flex; flex-wrap: wrap; gap: 2px; margin: 18px 0 22px; border-bottom: 1px solid #dcdcde; }
.pt-ed__lang { margin-bottom: -1px; padding: 9px 15px; border: 1px solid transparent; border-bottom: 0; color: #50575e; font-size: 13px; text-decoration: none; }
.pt-ed__lang small { margin-inline-start: 5px; color: #8c8f94; font-size: 11px; }
.pt-ed__lang:hover { color: #2271b1; }
.pt-ed__lang.is-current { border-color: #dcdcde; border-radius: 3px 3px 0 0; background: #fff; color: #1d2327; font-weight: 600; }
.pt-ed__dot { display: inline-block; width: 6px; height: 6px; margin-inline-start: 6px; border-radius: 50%; background: #00a32a; vertical-align: middle; }
.pt-ed__row { margin: 0 0 24px; }
.pt-ed__label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #1d2327; }
.pt-ed__title { width: 100%; padding: 9px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 21px; line-height: 1.4; }
.pt-ed__title:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
.pt-ed__excerpt { width: 100%; padding: 9px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px; line-height: 1.6; }
.pt-ed__excerpt:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
.pt-ed__hint { margin: 6px 0 0; color: #646970; font-size: 12px; }
.pt-ed__count { margin-inline-start: 6px; }
.pt-ed__count.is-ok { color: #007017; }
.pt-ed__count.is-short { color: #996800; }
.pt-ed__count.is-long { color: #b32d2e; }
.pt-ed .wp-editor-wrap { width: 100%; }
.pt-ed__actions { display: flex; align-items: center; gap: 14px; margin: 6px 0 40px; }
.pt-ed__note { color: #646970; font-size: 12px; }
';
}

/** 面板样式（内联，省一个请求） */
function pt_admin_css() {
	return '
.posttrans__intro { max-width: 78ch; margin: 4px 0 12px; color: #50575e; }
.posttrans__hint { max-width: 78ch; color: #646970; font-size: 12px; line-height: 1.6; }
.posttrans__tabs { display: flex; gap: 4px; margin: 0 0 12px; border-bottom: 1px solid #dcdcde; }
.posttrans__tab { position: relative; margin: 0 0 -1px; padding: 8px 14px; border: 1px solid transparent; border-bottom: 0; background: none; color: #50575e; font-size: 13px; line-height: 1.4; cursor: pointer; }
.posttrans__tab:hover { color: #2271b1; }
.posttrans__tab.is-active { border-color: #dcdcde; background: #fff; color: #1d2327; font-weight: 600; }
.posttrans__dot { display: inline-block; width: 6px; height: 6px; margin-inline-start: 6px; border-radius: 50%; background: #00a32a; vertical-align: middle; }
.posttrans__panel { display: none; padding-top: 4px; }
.posttrans__panel.is-active { display: block; }
.posttrans__field { margin: 0 0 12px; }
.posttrans__field > label { display: block; margin-bottom: 4px; font-weight: 600; }
.posttrans .wp-editor-wrap { margin-bottom: 8px; }
';
}

/* ====================================================== 未配置语言提示 */

add_action(
	'admin_notices',
	static function () {
		if ( pt_langs() ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong>Post Translations</strong> —
				<?php
				printf(
					wp_kses(
						/* translators: %s: name of the filter, wrapped in <code> */
						__( 'no languages have been declared yet, so no translation UI is shown. Declare them through the %s filter, for example:', 'post-translations' ),
						array( 'code' => array() )
					),
					'<code>pt_languages</code>'
				);
				?>
			</p>
			<pre>add_filter( 'pt_languages', function ( $langs ) {
	$langs['fr'] = array(
		'label' => 'Français',   // shown in the admin
		'short' => 'FR',         // shown in the posts list column
	);
	return $langs;
} );</pre>
		</div>
		<?php
	}
);
