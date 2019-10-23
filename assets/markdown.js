"use strict";

/*
 * Progressive enhancement for Markdown editors: adds a formatting toolbar and a
 * live preview to every textarea[data-markdown]. The preview is rendered by
 * preview.php, which reuses the server-side renderer so what you see is exactly
 * what gets published.
 */
(function () {
  var TOOLBAR = [
    { label: "<b>B</b>", wrap: ["**", "**"], sample: "粗体文字" },
    { label: "<i>i</i>", wrap: ["*", "*"], sample: "斜体文字" },
    { label: "<del>del</del>", wrap: ["~~", "~~"], sample: "删除文字" },
    { label: "H1", line: "## ", sample: "标题" },
    { label: "<b>“</b> 引用", line: "> ", sample: "引用内容" },
    { label: "<b>·</b> List", line: "- ", sample: "列表项" },
    { label: "1. 序号", line: "1. ", sample: "列表项" },
    { label: "<code>code</code>", wrap: ["`", "`"], sample: "code" },
    {
      label: "```<code>code</code>```",
      block: ["```\n", "\n```"],
      sample: "code",
      pad: true,
    },
    {
      label: '<a href="#">link</a>',
      wrap: ["[", "](https://)"],
      sample: "链接文字",
    },
    { label: "—", insert: "\n---\n" },
  ];

  function dispatchInput(textarea) {
    var event;
    try {
      event = new Event("input", { bubbles: true });
    } catch (error) {
      event = document.createEvent("Event");
      event.initEvent("input", true, false);
    }
    textarea.dispatchEvent(event);
  }

  function lineBounds(value, start, end) {
    var lineStart = start > 0 ? value.lastIndexOf("\n", start - 1) + 1 : 0;
    var lineEnd = value.indexOf("\n", end);
    return [lineStart, lineEnd === -1 ? value.length : lineEnd];
  }

  function prefixLines(block, marker, sample) {
    return block
      .split("\n")
      .map(function (text) {
        var trimmed = text.replace(/^\s+/, "");
        if (trimmed === "") {
          return marker + (sample || "");
        }
        var existing = marker.replace(/\s+$/, "");
        return trimmed.indexOf(existing) === 0 ? text : marker + trimmed;
      })
      .join("\n");
  }

  function applyAction(textarea, action) {
    var value = textarea.value;
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var selected = value.slice(start, end);
    var replaceStart = start;
    var replaceEnd = end;
    var inserted;
    var selectStart;
    var selectEnd;

    if (action.wrap) {
      var body = selected || action.sample || "";
      inserted = action.wrap[0] + body + action.wrap[1];
      selectStart = start + action.wrap[0].length;
      selectEnd = selectStart + body.length;
    } else if (action.block) {
      var blockBody = selected || action.sample || "";
      var open = action.block[0];
      var close = action.block[1];
      if (action.pad) {
        var before = value.slice(0, replaceStart);
        var after = value.slice(replaceEnd);
        if (before !== "" && before.slice(-1) !== "\n") {
          open = "\n" + open;
        }
        if (after !== "" && after.charAt(0) !== "\n") {
          close += "\n";
        }
      }
      inserted = open + blockBody + close;
      selectStart = replaceStart + open.length;
      selectEnd = selectStart + blockBody.length;
    } else if (action.line) {
      var bounds = lineBounds(value, start, end);
      replaceStart = bounds[0];
      replaceEnd = bounds[1];
      inserted = prefixLines(
        value.slice(bounds[0], bounds[1]),
        action.line,
        action.sample,
      );
      selectStart = replaceStart;
      selectEnd = replaceStart + inserted.length;
    } else {
      inserted = action.insert || "";
      selectStart = start + inserted.length;
      selectEnd = selectStart;
    }

    textarea.value =
      value.slice(0, replaceStart) + inserted + value.slice(replaceEnd);
    textarea.focus();
    textarea.setSelectionRange(selectStart, selectEnd);
    dispatchInput(textarea);
  }

  function buildToolbar(textarea) {
    var toolbar = document.createElement("div");
    toolbar.className = "markdown-toolbar";
    TOOLBAR.forEach(function (action) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "markdown-toolbar-button";
      button.innerHTML = action.label;
      button.setAttribute("aria-label", action.label + "（Markdown）");
      button.addEventListener("click", function () {
        applyAction(textarea, action);
      });
      toolbar.appendChild(button);
    });
    return toolbar;
  }

  function buildPreview(textarea, token) {
    var pane = document.createElement("div");
    pane.className = "markdown-preview markdown-body";
    pane.hidden = true;
    pane.setAttribute("aria-live", "polite");
    pane.setAttribute("aria-label", "Markdown 预览");

    var sequence = 0;
    var timer = null;
    var url = document.body
      ? document.body.getAttribute("data-preview-url")
      : null;

    function render() {
      if (!url || !window.fetch) {
        pane.innerHTML = '<p class="grey">预览暂时不可用。</p>';
        return;
      }
      var current = ++sequence;
      var body =
        "_csrf=" +
        encodeURIComponent(token) +
        "&content=" +
        encodeURIComponent(textarea.value);
      window
        .fetch(url, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          },
          body: body,
        })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("HTTP " + response.status);
          }
          return response.text();
        })
        .then(function (html) {
          if (current === sequence) {
            pane.innerHTML = html || '<p class="grey">还没有内容。</p>';
          }
        })
        .catch(function () {
          if (current === sequence) {
            pane.innerHTML = '<p class="grey">预览暂时不可用，请稍后重试。</p>';
          }
        });
    }

    textarea.addEventListener("input", function () {
      if (pane.hidden) {
        return;
      }
      window.clearTimeout(timer);
      timer = window.setTimeout(render, 400);
    });

    return { pane: pane, render: render };
  }

  function enhance(textarea) {
    if (
      textarea.getAttribute("data-markdown-ready") === "1" ||
      !textarea.form
    ) {
      return;
    }
    var tokenField = textarea.form.querySelector('input[name="_csrf"]');
    if (!tokenField) {
      return;
    }
    textarea.setAttribute("data-markdown-ready", "1");

    var preview = buildPreview(textarea, tokenField.value);
    var toolbar = buildToolbar(textarea);
    var toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "markdown-toggle";
    toggle.textContent = "Preview";
    toggle.setAttribute("aria-expanded", "false");
    toggle.addEventListener("click", function () {
      var opening = preview.pane.hidden;
      preview.pane.hidden = !opening;
      toggle.setAttribute("aria-expanded", opening ? "true" : "false");
      toggle.textContent = opening ? "关闭预览" : "预览";
      if (opening) {
        preview.render();
      }
    });
    toolbar.appendChild(toggle);

    textarea.parentNode.insertBefore(toolbar, textarea);
    textarea.parentNode.insertBefore(preview.pane, textarea.nextSibling);
  }

  function start() {
    Array.prototype.forEach.call(
      document.querySelectorAll("textarea[data-markdown]"),
      enhance,
    );
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
