/**
 * Migration script for game statuses
 */
jQuery(document).ready(function ($) {
  // Elements
  const $startButton = $("#mvb-start-migration");
  const $progressBar = $("#mvb-migration-progress");
  const $progressInner = $(".mvb-progress-bar-inner");
  const $progressStatus = $(".mvb-progress-status");
  const $resultContainer = $("#mvb-migration-result");

  // Configuration
  const batchSize = 5; // Reduced batch size to prevent timeouts
  let isProcessing = false;
  let totalProcessed = 0;
  let totalMigrated = 0;
  let totalSkipped = 0;
  let totalErrors = 0;
  let totalGames = 0;

  // Start migration
  $startButton.on("click", function () {
    if (isProcessing) {
      return;
    }

    isProcessing = true;
    $startButton.prop("disabled", true);
    $progressBar.show();
    $resultContainer.hide().empty();
    $progressStatus.text(MVBMigration.i18n.processing);
    $progressInner.css("width", "0%");

    // Reset counters
    totalProcessed = 0;
    totalMigrated = 0;
    totalSkipped = 0;
    totalErrors = 0;
    totalGames = 0;

    // Start the migration process
    processBatch(0);
  });

  // Process a batch of games
  function processBatch(offset) {
    $.ajax({
      url: MVBMigration.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "mvb_migrate_statuses",
        nonce: MVBMigration.nonce,
        batch_size: batchSize,
        offset: offset,
      },
      timeout: 120000, // 2 minute timeout
      success: function (response) {
        if (response.success) {
          const data = response.data;

          // Update counters
          totalProcessed += data.processed;
          totalMigrated += data.migrated;
          totalSkipped += data.skipped;
          totalErrors += data.errors;

          // First time we get the total
          if (totalGames === 0) {
            totalGames = data.total;
          }

          // Update progress
          const progress = Math.min(
            Math.round((totalProcessed / totalGames) * 100),
            100
          );
          $progressInner.css("width", progress + "%");
          $progressStatus.html(`Processing: ${totalProcessed}/${totalGames} (${progress}%)<br>
                                         Migrated: ${totalMigrated}, Skipped: ${totalSkipped}, Errors: ${totalErrors}`);

          // Log memory usage
          console.log(`Memory usage: ${data.memory_usage.toFixed(2)} MB`);

          // Check if we need to process more
          if (!data.complete) {
            // Process next batch with a small delay to allow browser to breathe
            setTimeout(function () {
              processBatch(data.next_offset);
            }, 500);
          } else {
            // Migration complete
            migrationComplete();
          }

          // Display any error messages
          if (data.error_messages && data.error_messages.length > 0) {
            displayErrors(data.error_messages);
          }
        } else {
          // Handle error
          handleError(
            response.data ? response.data.message : MVBMigration.i18n.error
          );
        }
      },
      error: function (xhr, status, error) {
        // Handle AJAX error
        let errorMessage = MVBMigration.i18n.error;
        if (status === "timeout") {
          errorMessage = "The request timed out. Try reducing the batch size.";
        } else if (
          xhr.responseJSON &&
          xhr.responseJSON.data &&
          xhr.responseJSON.data.message
        ) {
          errorMessage = xhr.responseJSON.data.message;
        } else if (error) {
          errorMessage = error;
        }

        handleError(errorMessage);
      },
    });
  }

  // Display error messages
  function displayErrors(errors) {
    if (!errors || !errors.length) {
      return;
    }

    let $errorList = $resultContainer.find(".mvb-error-list");
    if (!$errorList.length) {
      $resultContainer.append(
        '<h3>Errors:</h3><ul class="mvb-error-list"></ul>'
      );
      $errorList = $resultContainer.find(".mvb-error-list");
    }

    errors.forEach(function (error) {
      $errorList.append(`<li>${error}</li>`);
    });

    $resultContainer.show();
  }

  // Handle error in the migration process
  function handleError(message) {
    isProcessing = false;
    $startButton.prop("disabled", false);
    $progressStatus.html(`<span style="color: red;">${message}</span>`);
    $resultContainer
      .append(`<div class="notice notice-error"><p>${message}</p></div>`)
      .show();

    console.error("Migration error:", message);
  }

  // Migration complete
  function migrationComplete() {
    isProcessing = false;
    $startButton.prop("disabled", false);
    $progressStatus.html(`<span style="color: green;">Migration complete!</span><br>
                             Processed: ${totalProcessed}, Migrated: ${totalMigrated}, Skipped: ${totalSkipped}, Errors: ${totalErrors}`);

    $resultContainer
      .prepend(
        `<div class="notice notice-success">
            <p>${MVBMigration.i18n.complete}</p>
            <p>Total games processed: ${totalProcessed}<br>
            Successfully migrated: ${totalMigrated}<br>
            Skipped: ${totalSkipped}<br>
            Errors: ${totalErrors}</p>
        </div>`
      )
      .show();
  }
});
