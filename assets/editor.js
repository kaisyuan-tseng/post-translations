/**
 * LFX Multilingual — 区块编辑器侧边栏面板
 *
 * 为什么不用经典 metabox 当主入口：区块编辑器把 metabox 折叠在画布最底部，
 * 默认几乎不可见。这里用原生的 PluginDocumentSettingPanel（右侧「文章」设置栏）
 * + 全屏 Modal，打开就能编辑三种语言，保存不刷新页面。
 *
 * 数据：GET/POST /wp-json/post-translations/v1/posts/{id}/translations
 *       走专用端点，不碰 post_modified、不产生修订版本。
 */
(function () {
  "use strict";

  var wp = window.wp;
  if (!wp || !wp.plugins || !wp.element) return;

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;

  var registerPlugin = wp.plugins.registerPlugin;
  var Panel = wp.editor && wp.editor.PluginDocumentSettingPanel;
  if (!Panel) return;

  var c = wp.components;
  var Button = c.Button;
  var Modal = c.Modal;
  var TextControl = c.TextControl;
  var TextareaControl = c.TextareaControl;
  var Spinner = c.Spinner;
  /* Flex / FlexItem 是较新的组件；缺了就退回普通 div，面板不至于整块挂掉 */
  var Flex = c.Flex || "div";
  var FlexItem = c.FlexItem || "div";

  var apiFetch = wp.apiFetch;
  var select = wp.data.select;
  var dispatch = wp.data.dispatch;

  var __ = wp.i18n.__;
  var sprintf = wp.i18n.sprintf;

  var CONF = window.ptEditor || {};
  var LANGS = CONF.langs || [];

  /** 走专用端点，带上 nonce（区块编辑器里 wp-api-fetch 已注册中间件，这里不重复加） */
  function request(url, data) {
    return apiFetch({
      url: CONF.root + "posts/" + select("core/editor").getCurrentPostId() + "/translations",
      method: data ? "POST" : "GET",
      data: data,
      headers: data ? { "Content-Type": "application/json" } : {}
    });
  }

  /** 单语言整篇编辑页的地址（标题 / 摘要 / 正文都在那一页里） */
  function editorPageUrl(code) {
    var id = select("core/editor").getCurrentPostId();
    return (CONF.editorBase || "admin.php?page=pt-editor") + "&post=" + id + "&lang=" + code;
  }

  function notify(message, status) {
    var notices = dispatch("core/notices");
    if (notices && notices.createNotice) {
      notices.createNotice(status || "success", message, { type: "snackbar" });
    }
  }

  /** 一个语言的状态行：绿点/空心点 + 名称 + 状态 + 编辑按钮 */
  function LangRow(props) {
    var lang = props.lang;
    var tr = props.data[lang.code];
    var filled = !!(tr && (tr.title || tr.body));
    return el(
      Flex,
      { align: "center", gap: 2, style: { marginBottom: "8px" } },
      el(FlexItem, null,
        el("span", {
          style: {
            display: "inline-block",
            width: "7px",
            height: "7px",
            marginInlineEnd: "7px",
            borderRadius: "50%",
            verticalAlign: "middle",
            background: filled ? "#00a32a" : "transparent",
            border: filled ? "none" : "1px solid #8c8f94"
          }
        }),
        el("span", { style: { fontSize: "12px" } },
          lang.label,
          el("span", { style: { color: "#787c82", marginInlineStart: "6px" } },
            filled ? (tr.title || __("(no title)", "post-translations")) : __("Not translated", "post-translations"))
        )
      ),
      el(FlexItem, null,
        el(Button, {
          variant: "secondary",
          size: "small",
          onClick: function () { props.onEdit(lang.code); }
        }, filled ? __("Edit", "post-translations") : __("Add", "post-translations")),
        el("a", {
          href: editorPageUrl(lang.code),
          style: { marginInlineStart: "8px", fontSize: "11.5px", whiteSpace: "nowrap" },
          title: __("Open the full-page editor to change title, excerpt and body together.", "post-translations")
        }, __("Full page", "post-translations"))
      )
    );
  }

  /** 编辑弹窗 */
  function EditModal(props) {
    var lang = props.lang;
    var initial = props.initial || {};
    var s1 = useState(initial.title || "");
    var title = s1[0], setTitle = s1[1];
    var s2 = useState(initial.excerpt || "");
    var excerpt = s2[0], setExcerpt = s2[1];
    var s3 = useState(initial.body || "");
    var body = s3[0], setBody = s3[1];
    var s4 = useState(false);
    var saving = s4[0], setSaving = s4[1];
    var s5 = useState(null);
    var error = s5[0], setError = s5[1];

    function submit() {
      setSaving(true);
      setError(null);
      var payload = {};
      payload[lang.code] = { title: title, excerpt: excerpt, body: body };
      request(null, payload)
        .then(function (res) {
          setSaving(false);
          props.onSaved(res || {});
          notify(sprintf(__("%s translation saved.", "post-translations"), lang.label));
        })
        .catch(function (e) {
          setSaving(false);
          setError((e && e.message) || __("Could not save the translation.", "post-translations"));
        });
    }

    return el(Modal, {
      title: sprintf(__("Edit %s translation", "post-translations"), lang.label),
      onRequestClose: props.onClose,
      size: "large",
      style: { maxWidth: "820px", width: "92vw" },
      footer: el(Fragment, null,
        el(Button, { variant: "tertiary", onClick: props.onClose, disabled: saving }, __("Cancel", "post-translations")),
        el(Button, { variant: "primary", onClick: submit, isBusy: saving, disabled: saving },
          saving ? __("Saving…", "post-translations") : __("Save translation", "post-translations")),
        el(Button, {
          variant: "link",
          isDestructive: true,
          disabled: saving,
          onClick: function () { setTitle(""); setExcerpt(""); setBody(""); }
        }, __("Clear", "post-translations"))
      )
    },
      error ? el("div", {
        style: { marginBottom: "12px", padding: "8px 12px", borderLeft: "4px solid #d63638", background: "#fcf0f1", fontSize: "13px" }
      }, error) : null,
      el("p", { style: { marginTop: 0, color: "#50575e", fontSize: "12px" } },
        sprintf(
          __("This %s version shares the same URL as the original — only the language prefix differs. Empty fields fall back to the original on the front end. HTML is allowed in the body.", "post-translations"),
          lang.label
        )),
      el(TextControl, {
        label: __("Title", "post-translations"),
        value: title,
        onChange: setTitle,
        placeholder: __("Leave empty to use the original title", "post-translations"),
        __nextHasNoMarginBottom: true
      }),
      el("div", { style: { height: "12px" } }),
      el(TextareaControl, {
        label: __("Excerpt", "post-translations"),
        value: excerpt,
        onChange: setExcerpt,
        rows: 3,
        placeholder: __("Leave empty to use the original excerpt", "post-translations"),
        __nextHasNoMarginBottom: true
      }),
      el("div", { style: { height: "12px" } }),
      el(TextareaControl, {
        label: __("Body (HTML)", "post-translations"),
        value: body,
        onChange: setBody,
        rows: 16,
        style: { fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace", fontSize: "12.5px", lineHeight: "1.6" },
        placeholder: "<h2>Section title</h2>\n<p>Paragraph text…</p>",
        __nextHasNoMarginBottom: true
      })
    );
  }

  /* 从文章列表点某个语言的入口进来时带着 ?pt_lang=xx，读到数据后自动弹出该语言 */
  var pendingLang = CONF.openLang || "";

  function PanelBody() {
    var s1 = useState(true);
    var loading = s1[0], setLoading = s1[1];
    var s2 = useState({});
    var data = s2[0], setData = s2[1];
    var s3 = useState(null);
    var editing = s3[0], setEditing = s3[1];
    var s4 = useState(null);
    var error = s4[0], setError = s4[1];

    function load() {
      var postId = select("core/editor").getCurrentPostId();
      if (!postId) {
        /* 新文章还没落库，没有 post id 就没地方存译文 */
        setLoading(false);
        setError(__("Save the post as a draft first, then come back to edit the translation.", "post-translations"));
        return;
      }
      setLoading(true);
      setError(null);
      request(null, null)
        .then(function (res) {
          setData(res || {});
          setLoading(false);
          if (pendingLang) {
            var wanted = pendingLang;
            pendingLang = "";
            for (var i = 0; i < LANGS.length; i++) {
              if (LANGS[i].code === wanted) setEditing(wanted);
            }
          }
        })
        .catch(function (e) { setError((e && e.message) || __("Could not load translations.", "post-translations")); setLoading(false); });
    }

    useEffect(load, []);

    var done = LANGS.filter(function (l) { return data[l.code] && (data[l.code].title || data[l.code].body); }).length;

    var editLang = null;
    for (var i = 0; i < LANGS.length; i++) {
      if (LANGS[i].code === editing) editLang = LANGS[i];
    }

    return el(Fragment, null,
      el(Panel, {
        name: "posttrans-panel",
        title: __("Translations", "post-translations"),
        className: "posttrans-panel"
      },
        loading
          ? el("div", { style: { padding: "10px 0" } }, el(Spinner, null))
          : el(Fragment, null,
              LANGS.map(function (lang) {
                return el(LangRow, {
                  key: lang.code,
                  lang: lang,
                  data: data,
                  onEdit: setEditing
                });
              }),
              el("p", { style: { margin: "10px 0 0", color: "#787c82", fontSize: "11.5px", lineHeight: "1.6" } },
                sprintf(
                  __("%1$d of %2$d translations filled in. Rebuild the site for changes to reach the front end.", "post-translations"),
                  done,
                  LANGS.length
                )),
              error ? el("p", { style: { color: "#d63638", fontSize: "12px" } }, error) : null,
              el(Button, { variant: "link", onClick: load, style: { paddingInlineStart: 0 } }, __("Reload", "post-translations"))
            )
      ),
      editLang
        ? el(EditModal, {
            key: editLang.code,
            lang: editLang,
            initial: data[editLang.code] || {},
            onClose: function () { setEditing(null); },
            onSaved: function (res) { setData(res || {}); setEditing(null); }
          })
        : null
    );
  }

  registerPlugin("posttrans", { render: PanelBody });
})();
