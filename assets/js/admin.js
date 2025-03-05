/**
 * MVB Admin JavaScript
 */
jQuery(document).ready(function ($) {
  // Test API Connection
  $("#mvb-test-connection").on("click", function () {
    const $button = $(this);
    const $result = $("#mvb-connection-result");

    $button.prop("disabled", true);
    $result.html(
      '<span class="spinner is-active"></span> Testing connection...'
    );

    $.ajax({
      url: MVBAdmin.ajaxUrl,
      type: "POST",
      data: {
        action: "mvb_test_igdb_connection",
        nonce: MVBAdmin.nonce,
      },
      success: function (response) {
        if (response.success) {
          $result.html(
            '<div class="notice notice-success inline"><p>' +
              response.data.message +
              "</p></div>"
          );
        } else {
          $result.html(
            '<div class="notice notice-error inline"><p>' +
              response.data.message +
              "</p></div>"
          );
        }
      },
      error: function () {
        $result.html(
          '<div class="notice notice-error inline"><p>Connection failed. Please try again.</p></div>'
        );
      },
      complete: function () {
        $button.prop("disabled", false);
      },
    });
  });

  // Sync Companies
  $("#mvb-sync-companies").on("click", function () {
    const $button = $(this);
    const $result = $("#mvb-sync-result");

    $button.prop("disabled", true);
    $button.text(MVBAdmin.i18n.syncing);
    $result.html(
      '<span class="spinner is-active"></span> ' + MVBAdmin.i18n.syncStarted
    );

    $.ajax({
      url: MVBAdmin.ajaxUrl,
      type: "POST",
      data: {
        action: "mvb_sync_companies",
        nonce: MVBAdmin.nonce,
      },
      success: function (response) {
        if (response.success) {
          $result.html(
            '<div class="notice notice-success inline"><p>' +
              response.data.message +
              "</p></div>"
          );

          if (
            response.data.details &&
            response.data.details.errors &&
            response.data.details.errors.length > 0
          ) {
            let errorHtml =
              '<div class="notice notice-warning inline"><p>' +
              MVBAdmin.i18n.syncErrors +
              "</p><ul>";
            response.data.details.errors.forEach(function (error) {
              errorHtml += "<li>" + error + "</li>";
            });
            errorHtml += "</ul></div>";
            $result.append(errorHtml);
          }
        } else {
          $result.html(
            '<div class="notice notice-error inline"><p>' +
              response.data.message +
              "</p></div>"
          );
        }
      },
      error: function () {
        $result.html(
          '<div class="notice notice-error inline"><p>' +
            MVBAdmin.i18n.syncError +
            "</p></div>"
        );
      },
      complete: function () {
        $button.prop("disabled", false);
        $button.text(MVBAdmin.i18n.syncCompanies);
      },
    });
  });

  // Sync Platforms
  $("#mvb-sync-platforms").on("click", function () {
    const $button = $(this);
    const $result = $("#mvb-platforms-result");

    $button.prop("disabled", true);
    $button.text(MVBAdmin.i18n.syncing);
    $result.html(
      '<span class="spinner is-active"></span> ' + MVBAdmin.i18n.syncStarted
    );

    $.ajax({
      url: MVBAdmin.ajaxUrl,
      type: "POST",
      data: {
        action: "mvb_sync_platforms",
        nonce: MVBAdmin.nonce,
      },
      success: function (response) {
        if (response.success) {
          $result.html(
            '<div class="notice notice-success inline"><p>' +
              response.data.message +
              "</p></div>"
          );

          if (
            response.data.details &&
            response.data.details.errors &&
            response.data.details.errors.length > 0
          ) {
            let errorHtml =
              '<div class="notice notice-warning inline"><p>' +
              MVBAdmin.i18n.syncErrors +
              "</p><ul>";
            response.data.details.errors.forEach(function (error) {
              errorHtml += "<li>" + error + "</li>";
            });
            errorHtml += "</ul></div>";
            $result.append(errorHtml);
          }
        } else {
          $result.html(
            '<div class="notice notice-error inline"><p>' +
              response.data.message +
              "</p></div>"
          );
        }
      },
      error: function () {
        $result.html(
          '<div class="notice notice-error inline"><p>' +
            MVBAdmin.i18n.syncError +
            "</p></div>"
        );
      },
      complete: function () {
        $button.prop("disabled", false);
        $button.text(MVBAdmin.i18n.syncPlatforms);
      },
    });
  });

  // Check if we're on the edit screen for videogames
  if ($("body").hasClass("post-type-videogame")) {
    // Make sure inline-edit-post is loaded
    if (typeof inlineEditPost === "undefined") {
      console.warn("Quick edit functionality is not available on this page.");
      return; // Exit early if the script is not available
    }
  }
});
