/**
 * LFX Multilingual — 文章编辑页「多语言译文」面板
 *
 * 三件事：
 *   1. 标签页切换（English / 简体 / 繁體）。
 *   2. 切到中文标签时才懒初始化可视化编辑器：隐藏容器里初始化 TinyMCE 会量到
 *      0 宽高，工具条错位。JS 失效时退回可编辑的 HTML 文本框，保存照样有效。
 *   3. 保存前把 TinyMCE 内容同步回 textarea：经典编辑器走 submit 事件，
 *      区块编辑器（Gutenberg）走 core/editor 的 isSavingPost 状态监听。
 */
(function () {
  "use strict";

  var initialized = {};

  /** 懒初始化某个面板里的正文编辑器 */
  function initPanel(panel) {
    if (!panel || !window.wp || !wp.editor || !wp.editor.initialize) return;

    var areas = panel.querySelectorAll('textarea[id$="_body"]');
    Array.prototype.forEach.call(areas, function (area) {
      if (initialized[area.id]) return;
      initialized[area.id] = true;

      wp.editor.initialize(area.id, {
        tinymce: {
          wpautop: false,
          toolbar1:
            "formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo",
          toolbar2: ""
        },
        quicktags: false,
        mediaButtons: false
      });

      /* 编辑器每改一次就写回 textarea：Gutenberg 采集表单数据时不保证先 triggerSave */
      var editor = window.tinymce && window.tinymce.get(area.id);
      if (editor) {
        editor.on("change keyup undo redo SetContent", function () {
          editor.save();
        });
      }
    });
  }

  /** 切换标签页 */
  function activate(lang) {
    var tabs = document.querySelectorAll(".posttrans__tab");
    var panels = document.querySelectorAll(".posttrans__panel");

    Array.prototype.forEach.call(tabs, function (tab) {
      tab.classList.toggle("is-active", tab.getAttribute("data-posttrans-tab") === lang);
    });

    Array.prototype.forEach.call(panels, function (panel) {
      var active = panel.getAttribute("data-posttrans-panel") === lang;
      panel.classList.toggle("is-active", active);
      /*
       * 原文那个面板里没有编辑器（它就是页面顶部的标题/摘要/正文），
       * initPanel 找不到 textarea 会自己返回，所以这里不用判断是哪个语言 ——
       * 判断了反而会把「原文不是英文」的站点写死。
       */
      if (active) initPanel(panel);
    });
  }

  document.addEventListener("click", function (event) {
    var tab = event.target.closest && event.target.closest(".posttrans__tab");
    if (!tab) return;
    event.preventDefault();
    activate(tab.getAttribute("data-posttrans-tab"));
  });

  /* 从文章列表点某个语言的入口进来（?pt_lang=xx）：直接切到那个标签页 */
  (function openFromList() {
    var wanted = (window.ptAdmin && window.ptAdmin.openLang) || "";
    if (!wanted) return;
    var ready = function () {
      activate(wanted);
      var panel = document.querySelector('.posttrans__panel[data-posttrans-panel="' + wanted + '"]');
      if (panel && panel.scrollIntoView) panel.scrollIntoView({ block: "center", behavior: "smooth" });
    };
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", ready);
    } else {
      ready();
    }
  })();

  /* 保存前同步：经典编辑器 */
  function syncAll() {
    if (window.tinymce && typeof window.tinymce.triggerSave === "function") {
      window.tinymce.triggerSave();
    }
  }

  document.addEventListener("submit", syncAll, true);

  /* 保存前同步：区块编辑器（REST 保存 +  Metabox 表单提交两条路径都覆盖） */
  if (window.wp && wp.data && typeof wp.data.subscribe === "function") {
    var wasSaving = false;
    wp.data.subscribe(function () {
      var editor = wp.data.select("core/editor");
      if (!editor || typeof editor.isSavingPost !== "function") return;
      var saving = editor.isSavingPost();
      if (saving && !wasSaving) syncAll();
      wasSaving = saving;
    });
  }
})();
