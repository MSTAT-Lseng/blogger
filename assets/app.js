"use strict";
document.addEventListener("click", function (event) {
  var confirmButton = event.target.closest("[data-confirm]");
  if (
    confirmButton &&
    !window.confirm(confirmButton.getAttribute("data-confirm"))
  ) {
    event.preventDefault();
  }
});
document.addEventListener("change", function (event) {
  var input = event.target;
  if (
    !(input instanceof Element) ||
    !input.matches("input[type=file][data-image-preview]")
  ) {
    return;
  }
  var file = input.files && input.files[0];
  input.setCustomValidity("");
  if (!file) {
    return;
  }
  var allowed = (input.getAttribute("data-allowed") || "").split(",");
  if (allowed.indexOf(file.type) === -1) {
    input.setCustomValidity("请选择 JPG、PNG、GIF 或 WebP 格式的图片。");
    input.reportValidity();
    return;
  }
  var maxBytes = parseInt(input.getAttribute("data-max-bytes"), 10) || 0;
  if (maxBytes > 0 && file.size > maxBytes) {
    input.setCustomValidity(
      "图片不能超过 " + Math.round(maxBytes / 1048576) + " MB。",
    );
    input.reportValidity();
    return;
  }
  var preview = document.getElementById(
    input.getAttribute("data-image-preview"),
  );
  if (!preview || !window.URL || !URL.createObjectURL) {
    return;
  }
  if (preview.dataset.objectUrl) {
    URL.revokeObjectURL(preview.dataset.objectUrl);
  }
  var objectUrl = URL.createObjectURL(file);
  preview.dataset.objectUrl = objectUrl;
  preview.src = objectUrl;
});
document.addEventListener("keydown", function (event) {
  if (
    event.ctrlKey &&
    event.key === "Enter" &&
    event.target.matches("textarea")
  ) {
    var form = event.target.form;
    if (form && form.checkValidity()) {
      event.preventDefault();
      if (form.requestSubmit) {
        form.requestSubmit();
      } else {
        form
          .querySelector(
            'button[type="submit"], button:not([type]), input[type="submit"]',
          )
          .click();
      }
    }
  }
});
