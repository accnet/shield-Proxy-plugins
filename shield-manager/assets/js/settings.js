jQuery(document).ready(function ($) {
  function showAlert(title, text, type) {
    return Swal.fire({
      title: title,
      text: text,
      type: type,
      confirmButtonText: "OK",
    });
  }

  function showError(text) {
    return showAlert("Error", text, "error");
  }

  function showConfirm(message) {
    return Swal.fire({
      title: "Are you sure?",
      html: message,
      type: "warning",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: "Yes!",
    });
  }

  function showSuccess(message) {
    return Swal.fire("Success!", message, "success");
  }

  $("#paypal_card_icons").select2();
  $("#paypal_disable_funding").select2();
  $("#stripe_card_icons").select2();
  const urlParams = new URLSearchParams(window.location.search);
  const tabParam = urlParams.get("tab");
  if (tabParam) {
    const tabValue = "#" + tabParam;
    const tabShow = new bootstrap.Tab(document.querySelector(tabValue));
    tabShow.show();
  }

  $(".nav-link").on("click", function (event) {
    event.preventDefault();
    const tabTarget = $(this).attr("id");
    const sanitizedTabTarget = tabTarget.replace("#", "");

    urlParams.set("tab", sanitizedTabTarget);
    const newUrl = window.location.pathname + "?" + urlParams.toString();

    window.history.pushState({}, "", newUrl);
  });

  function handleFormSubmit(formId, commandName) {
    const form = $(formId);
    const submitButton = form.find('[type="submit"]');
    submitButton.html(
      `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> SAVE...`
    );
    $method = commandName == "update_paypal_settings" ? "paypal" : "stripe";
    const formData = new FormData(form[0]);
    const settings = Object.fromEntries(formData);
    form.find("select.multiselect").each(function () {
      let name = $(this).prop("name");
      settings[name] = $("#" + $method + "_" + name).val().length ? $("#" + $method + "_" + name).val() : null;
    });
    form.find('[type="checkbox"]:not(:checked)').each(function () {
      settings[$(this).prop("name")] = "no";
    });

    var data = {
      action: "cards_shield_settings",
      command: commandName,
      nonce: ShieldSettings.nonce,
      settings,
    };

    jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
      var dataJson = JSON.parse(response);
      submitButton.html(`SAVE`);
      if (dataJson.success) {
        notification({
          title: "Thành công",
          message: "Các tùy chọn đã được lưu",
          type: "success",
        });
      } else {
        notification({
          title: "Thất bại",
          message: "Không có thay đổi hoặc lưu không thành công",
          type: "error",
        });
      }
    });
  }

  $("#paypal_settings").submit(function (event) {
    event.preventDefault();
    handleFormSubmit("#paypal_settings", "update_paypal_settings");
  });

  $("#stripe_settings").submit(function (event) {
    event.preventDefault();
    handleFormSubmit("#stripe_settings", "update_stripe_settings");
  });
  function toggleSyncTrackingLoading(isOn) {
    $("#sync-spinner").css("display", isOn ? "inline-block" : "none");
    $("#sync-tracking-info-btn").attr("disabled", isOn);
  }

  function syncTrackingInfo() {
    var syncCount = $("#sync-count").val();
    if (parseInt(syncCount) === 0) {
      showError("Don't have unsynced orders");
      return;
    }
    toggleSyncTrackingLoading(true);

    var data = {
      action: "WOOTIFY_gateway_paypal_action",
      command: "syncTrackingInfo",
    };
    jQuery.ajaxSetup({ timeout: 100000 });
    jQuery
      .post(cs_ajax_object.ajax_url, data, function (response) {
        var responseJson = JSON.parse(response);
        if (responseJson.success) {
          showSuccess("Sync tracking info successfully!").then(function () {
            location.reload();
          });
        } else {
          showError(responseJson.error);
        }
      })
      .fail(function () {
        showError("Error when sync tracking info. Please try again after!");
      })
      .always(function () {
        toggleSyncTrackingLoading(false);
      });
  }
  $(document).on("click", "#sync-tracking-info-btn", function () {
    syncTrackingInfo();
  });

  $("#connection_settings").submit(function (event) {
    event.preventDefault();
    const settings = {
      default_bootstrap_token: $("#default_bootstrap_token").val().trim(),
    };
    const data = {
      action: "cards_shield_settings",
      command: "update_connection_settings",
      nonce: ShieldSettings.nonce,
      settings,
    };
    jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
      const dataJson = JSON.parse(response);
      if (dataJson.success) {
        notification({
          title: "Thanh cong",
          message: "Da luu cau hinh ket noi mac dinh",
          type: "success",
        });
      } else {
        notification({
          title: "That bai",
          message: "Khong the luu token mac dinh",
          type: "error",
        });
      }
    });
  });

  // SaaS Connect Form
  $(document).on("submit", "#saas-connect-form", function (event) {
    event.preventDefault();
    const submitButton = $(this).find('[type="submit"]');
    submitButton.html(
      `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> CONNECTING...`
    );
    submitButton.prop("disabled", true);

    const data = {
      action: "cards_shield_saas_connect",
      nonce: ShieldSettings.nonce,
      saas_url: $("#saas_url").val().trim(),
      connect_key: $("#connect_key").val().trim(),
    };

    jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
      submitButton.html(`Establish SaaS Connection`);
      submitButton.prop("disabled", false);
      if (response.success) {
        showSuccess(response.data.message || "Connected to SaaS successfully!").then(function () {
          location.reload();
        });
      } else {
        showError(response.data.message || "Connection failed. Please check your key and URL.");
      }
    });
  });

  // SaaS Disconnect Button
  $(document).on("click", "#saas-disconnect-btn", function () {
    showConfirm("Are you sure you want to disconnect from SaaS? This will unlock local rotation configuration editing.").then(function (result) {
      if (result.value) {
        const disconnectBtn = $("#saas-disconnect-btn");
        disconnectBtn.html(
          `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> DISCONNECTING...`
        );
        disconnectBtn.prop("disabled", true);

        const data = {
          action: "cards_shield_saas_disconnect",
          nonce: ShieldSettings.nonce,
        };

        jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
          disconnectBtn.html(`Disconnect & Unlock Local Configuration`);
          disconnectBtn.prop("disabled", false);

          if (response.success) {
            showSuccess(response.data.message || "Disconnected from SaaS.").then(function () {
              location.reload();
            });
          } else {
            showError("Failed to disconnect from SaaS.");
          }
        });
      }
    });
  });
});


