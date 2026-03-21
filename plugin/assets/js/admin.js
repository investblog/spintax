/**
 * Spintax admin JavaScript.
 *
 * Handles: preview AJAX, regenerate cache, copy shortcode, variable rows.
 */
/* global jQuery, spintaxAdmin */
(function ($) {
  "use strict";

  // --- Preview ---
  $(document).on("click", ".spintax-regenerate-preview", function () {
    var $btn = $(this);
    var postId = $btn.data("post-id");
    var $output = $(".spintax-preview-output");
    var $validation = $(".spintax-preview-validation");
    var $spinner = $btn.siblings(".spinner");

    $btn.prop("disabled", true);
    $spinner.addClass("is-active");
    $validation.empty();

    // Send current editor content (not yet saved) for live preview.
    var editorContent = "";
    if (typeof wp !== "undefined" && wp.editor && wp.editor.getContent) {
      editorContent = wp.editor.getContent("content");
    } else {
      var $textarea = $("#content");
      if ($textarea.length) {
        editorContent = $textarea.val();
      }
    }

    $.post(spintaxAdmin.ajaxUrl, {
      action: "spintax_preview",
      nonce: spintaxAdmin.nonce,
      post_id: postId,
      content: editorContent,
    })
      .done(function (res) {
        if (res.success) {
          $output.html(res.data.html || "<em>Empty output</em>");

          // Show validation errors/warnings.
          var v = res.data.validation || {};
          if (v.errors && v.errors.length) {
            v.errors.forEach(function (e) {
              $validation.append(
                '<div class="notice notice-error"><p>' +
                  escHtml(e.message) +
                  "</p></div>"
              );
            });
          }
          if (v.warnings && v.warnings.length) {
            v.warnings.forEach(function (w) {
              $validation.append(
                '<div class="notice notice-warning"><p>' +
                  escHtml(w.message) +
                  "</p></div>"
              );
            });
          }
        } else {
          $output.html(
            "<em>" + (res.data || spintaxAdmin.i18n.error) + "</em>"
          );
        }
      })
      .fail(function () {
        $output.html("<em>" + spintaxAdmin.i18n.error + "</em>");
      })
      .always(function () {
        $btn.prop("disabled", false);
        $spinner.removeClass("is-active");
      });
  });

  // --- Regenerate public cache ---
  $(document).on("click", ".spintax-regenerate-public", function () {
    var $btn = $(this);
    var postId = $btn.data("post-id");
    var originalText = $btn.text();

    $btn.prop("disabled", true).text(spintaxAdmin.i18n.regenerating);

    $.post(spintaxAdmin.ajaxUrl, {
      action: "spintax_regenerate",
      nonce: spintaxAdmin.nonce,
      post_id: postId,
    })
      .done(function (res) {
        $btn.text(res.success ? spintaxAdmin.i18n.regenerated : spintaxAdmin.i18n.error);
        setTimeout(function () {
          $btn.text(originalText).prop("disabled", false);
        }, 2000);
      })
      .fail(function () {
        $btn.text(spintaxAdmin.i18n.error);
        setTimeout(function () {
          $btn.text(originalText).prop("disabled", false);
        }, 2000);
      });
  });

  // --- Copy shortcode to clipboard ---
  $(document).on("click", ".spintax-copy-shortcode", function () {
    var text = $(this).text().trim();
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () {
        showCopied($(this));
      }.bind(this));
    }
  });

  function showCopied($el) {
    var $tip = $('<span class="spintax-copied">' + spintaxAdmin.i18n.copied + '</span>');
    $el.after($tip);
    setTimeout(function () { $tip.fadeOut(300, function () { $tip.remove(); }); }, 1000);
  }

  // --- Global variables: add/remove rows ---
  $(document).on("click", ".spintax-add-row", function () {
    var $tbody = $("#spintax-variables-table tbody");
    $tbody.append(
      '<tr>' +
        '<td><input type="text" name="spintax_var_names[]" value="" class="regular-text" placeholder="name"></td>' +
        '<td><input type="text" name="spintax_var_values[]" value="" class="large-text" placeholder="value"></td>' +
        '<td><button type="button" class="button spintax-remove-row">&times;</button></td>' +
      '</tr>'
    );
  });

  $(document).on("click", ".spintax-remove-row", function () {
    $(this).closest("tr").remove();
  });

  // --- Helpers ---
  function escHtml(str) {
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }
})(jQuery);
