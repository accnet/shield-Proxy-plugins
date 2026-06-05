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

  function postSaasAction(action, buttonSelector, loadingLabel, idleLabel, successFallback, errorFallback) {
    const btn = $(buttonSelector);
    btn.html(`<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${loadingLabel}`);
    btn.prop("disabled", true);

    const data = {
      action: action,
      nonce: ShieldSettings.nonce,
    };

    jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
      btn.html(idleLabel);
      btn.prop("disabled", false);

      if (response.success) {
        showSuccess((response.data && response.data.message) || successFallback).then(function () {
          location.reload();
        });
      } else {
        showError((response.data && response.data.message) || errorFallback);
      }
    });
  }

  function postSaasToggle(action, toggle, successFallback, errorFallback) {
    const $toggle = $(toggle);
    $toggle.prop("disabled", true);

    jQuery.post(cs_ajax_object.ajax_url, {
      action: action,
      nonce: ShieldSettings.nonce,
    }, function (response) {
      $toggle.prop("disabled", false);
      if (response.success) {
        showSuccess((response.data && response.data.message) || successFallback).then(function () {
          location.reload();
        });
      } else {
        $toggle.prop("checked", !$toggle.prop("checked"));
        showError((response.data && response.data.message) || errorFallback);
      }
    });
  }

  $(document).on("change", "#saas-sync-toggle", function () {
    const toggle = this;
    const turnOn = $(toggle).prop("checked");
    const message = turnOn
      ? "Activate SaaS sync again using the saved SaaS URL, connection key, and HMAC secret?"
      : "Disable SaaS sync temporarily? Current SaaS URL, connection key, HMAC secret, and synced rotation settings will be kept.";

    showConfirm(message).then(function (result) {
      if (!result.value) {
        $(toggle).prop("checked", !turnOn);
        return;
      }

      postSaasToggle(
        turnOn ? "cards_shield_saas_resume" : "cards_shield_saas_disconnect",
        toggle,
        turnOn ? "SaaS sync activated again." : "SaaS sync disabled temporarily.",
        turnOn ? "Failed to activate SaaS sync." : "Failed to disable SaaS sync."
      );
    });
  });

  $(document).on("click", "#saas-reset-btn", function () {
    showConfirm("Reset SaaS connection credentials? Existing local rotation/proxy settings will be kept, but the saved SaaS URL, connection key, and HMAC secret will be removed.").then(function (result) {
      if (result.value) {
        postSaasAction(
          "cards_shield_saas_reset",
          "#saas-reset-btn",
          "RESETTING...",
          "Reset Connection",
          "SaaS connection reset.",
          "Failed to reset SaaS connection."
        );
      }
    });
  });
});
