jQuery(document).ready(function ($) {
  const PG = CS["PG"];
  const methodMeta = {
    by_amount: { label: "Volume", field: "amount" },
    by_time: { label: "Time", field: "timestamp" },
    by_order: { label: "Order", field: "order" },
  };

  function getCurrentRotationMethod() {
    return $(".rotation-tab.active").data("method") || $("#current-rotation-method").val() || "by_time";
  }

  function updateDynamicLabels(method) {
    const label = methodMeta[method]?.label || "Parameter";
    $("#rotation-value-label").text(label);
  }

  function syncMethodState(method) {
    $("#current-rotation-method").val(method);
    $(".rotation-tab").removeClass("active");
    $(`.rotation-tab[data-method='${method}']`).addClass("active");
    $("#proxy-list").removeClass("by_time by_amount by_order").addClass(method);
    updateDynamicLabels(method);
  }
  if (!$("#proxy-list").hasClass("saas-connected-locked")) {
    $("#proxy-list").sortable({
      helper: function (e, tr) {
        const $originals = tr.children();
        const $helper = tr.clone();
        $helper.children().each(function (index) {
          $(this).width($originals.eq(index).width());
        });
        return $helper;
      },
      items: "tbody tr",
      forceHelperSize: true,
      handle: ".handle",
      cursor: "move",
      axis: "y",
      dropOnEmpty: false,
      update: function (e, ui) {
        const positionList = {};
        $("#proxy-list tbody tr").each(function (index, tr) {
          positionList[$(tr).data("id")] = index + 1;
        });
        changePosition(positionList);
      },
    });
  }
  function changePosition(positionList) {
    const rotationMethod = getCurrentRotationMethod();
    const data = {
      action: "rotation_action",
      command: "change_position",
      nonce: CS.nonce,
      positionList,
      rotationMethod,
      PG,
    };
    $.post(cs_ajax_object.ajax_url, data, function (response) {
      const dataJson = JSON.parse(response);
      if (dataJson.success) {
        toastShowSuccess();
      } else {
        toastShowError();
      }
    });
  }
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

  function addNewProxy() {
    const rotationMethod = getCurrentRotationMethod();
    const $url = $("#modal-proxy-url");
    const $val = $("#modal-rotation-value");
    let valid = true;

    $url.removeClass("is-invalid");
    $val.removeClass("is-invalid");

    const newProxyUrl = $url.val().trim().replace(/\/+$/, "");
    const newRotationValue = $val.val().trim();

    if (!newProxyUrl) {
      $url.addClass("is-invalid");
      valid = false;
    }
    if (!newRotationValue || Number(newRotationValue) <= 0) {
      $val.addClass("is-invalid");
      const msg = !newRotationValue ? "Please fill in this field." : (methodMeta[rotationMethod]?.label || "Value") + " must be greater than 0.";
      $val.siblings(".invalid-feedback").text(msg);
      valid = false;
    }
    if (!valid) return;
    const data = {
      action: "rotation_action",
      command: "add_new_proxy",
      nonce: CS.nonce,
      rotationMethod: rotationMethod,
      proxyUrl: newProxyUrl,
      rotationValue: newRotationValue,
      PG,
    };
    // We can also pass the url value separately from ajax url for front end AJAX implementations
    $.post(cs_ajax_object.ajax_url, data, function (response) {
      const dataJson = JSON.parse(response);
      if (!dataJson.success) {
        const msg = dataJson.error === "duplicate_url"
          ? "This site URL already exists in the current rotation tab."
          : "Failed to add proxy. Please try again!";
        showError(msg);
        return;
      }

      $("#modal-proxy-url").val("");
      $("#modal-rotation-value").val("");
      $("#rotation-add-modal").removeClass("open");

      const conn = dataJson.connection || {};
      let msg = "Add proxy successfully!";
      if (conn.created && conn.bootstrapped) {
        msg += " Site connection was created and bootstrapped automatically.";
      } else if (conn.created) {
        msg += " Site connection was created automatically.";
      }
      if (conn.warning) {
        msg += " " + conn.warning;
      }

      showSuccess(msg).then(function () {
        location.reload();
      });
    });
  }
  $(document).on("click", "#btn-add-proxy", function () {
    addNewProxy();
  });

  $(document).on("click", "#btn-open-add-modal", function () {
    $("#modal-proxy-url, #modal-rotation-value").removeClass("is-invalid");
    $("#rotation-add-modal").addClass("open");
    $("#modal-proxy-url").trigger("focus");
  });

  $(document).on("input", "#modal-proxy-url, #modal-rotation-value", function () {
    $(this).removeClass("is-invalid");
  });

  $(document).on("click", "#btn-close-add-modal, #rotation-add-modal .rotation-modal-backdrop", function () {
    $("#rotation-add-modal").removeClass("open");
  });

  function saveProxies() {
    const rotationMethod = getCurrentRotationMethod();
    const proxies = [];
    let hasError = false;
    let name = "";
    name = methodMeta[rotationMethod]?.field || "timestamp";
    // Clear previous errors
    $("#proxy-list tbody tr input[type=number]").removeClass("is-invalid");
    $("#proxy-list tbody tr").each(function () {
      const proxy = {
        id: String($(this).data("id") ?? ""),
        rotationValue: String($(this).find(`[name=${name}]`).val() ?? ""),
      };
      if (!proxy.id.trim() || !proxy.rotationValue.trim()) {
        $(this).find(`[name=${name}]`).addClass("is-invalid").siblings(".invalid-feedback").text("Required.");
        hasError = true;
        return;
      }
      if (Number(proxy.rotationValue) <= 0) {
        $(this).find(`[name=${name}]`).addClass("is-invalid").siblings(".invalid-feedback").text("Must be greater than 0.");
        hasError = true;
        return;
      }
      proxies.push(proxy);
    });
    if (hasError) {
      return;
    }

    const data = {
      action: "rotation_action",
      command: "save_proxies",
      nonce: CS.nonce,
      rotationMethod,
      name,
      proxies,
      PG,
    };
    jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
      const responseJson = JSON.parse(response);
      if (responseJson.success === true) {
        toastShowSuccess();
      } else {
        showError("Failed. Please try again!").then(function () {
          location.reload();
        });
      }
    });
  }

  // Collect current table proxies for the active tab, returns {valid, proxies, name}.
  function collectCurrentProxies() {
    const rotationMethod = getCurrentRotationMethod();
    const name = methodMeta[rotationMethod]?.field || "timestamp";
    const proxies = [];
    let valid = true;
    $("#proxy-list tbody tr input[type=number]").removeClass("is-invalid");
    $("#proxy-list tbody tr").each(function () {
      const id = String($(this).data("id") ?? "");
      const rotationValue = String($(this).find(`[name=${name}]`).val() ?? "");
      if (!id.trim()) return;
      if (!rotationValue.trim() || Number(rotationValue) <= 0) {
        $(this).find(`[name=${name}]`).addClass("is-invalid")
          .siblings(".invalid-feedback").text("Fix value before switching tab.");
        valid = false;
        return false; // break loop
      }
      proxies.push({ id, rotationValue });
    });
    return { valid, proxies, name, rotationMethod };
  }

  $(document).on("click", "#btn-save", function () {
    saveProxies();
  });

  $(document).on("click", ".rotation-tab", function () {
    const currentRotationMethod = getCurrentRotationMethod();
    const rotationMethod = $(this).data("method");
    if (!rotationMethod || rotationMethod === currentRotationMethod) {
      return;
    }

    const methodName = methodMeta[rotationMethod]?.label || "Selected";
    showConfirm(`Proxy will be rotated by <b>${methodName}</b>.`).then((result) => {
      if (!result.value) return;

      // Auto-save current tab data before switching to preserve edits.
      const { valid, proxies, name, rotationMethod: currentMethod } = collectCurrentProxies();
      if (!valid) return; // red borders already shown, block switch

      function doSwitch() {
        const data = {
          action: "rotation_action",
          command: "change_rotation_method",
          nonce: CS.nonce,
          rotationMethod,
          PG,
        };
        jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
          const responseJson = JSON.parse(response);
          if (responseJson.success === true) {
            syncMethodState(rotationMethod);
            location.reload();
          } else {
            showError("Failed to change rotation method. Please try again!");
          }
        });
      }

      if (proxies.length === 0) {
        // No proxies in table, switch directly.
        doSwitch();
        return;
      }

      // Save current tab first, then switch.
      jQuery.post(cs_ajax_object.ajax_url, {
        action: "rotation_action",
        command: "save_proxies",
        nonce: CS.nonce,
        rotationMethod: currentMethod,
        name,
        proxies,
        PG,
      }, function (res) {
        const r = JSON.parse(res);
        if (r.success) {
          doSwitch();
        } else {
          showError("Failed to save current data. Please try again!");
        }
      });
    });
  });

  function moveBackProxy(proxyId) {
    const rotationMethod = getCurrentRotationMethod();
    const data = {
      action: "rotation_action",
      command: "move_back_proxy",
      nonce: CS.nonce,
      proxyId,
      rotationMethod,
      PG,
    };
    jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
      const responseJson = JSON.parse(response);
      if (responseJson.success === true) {
        $(`#id-${proxyId}`).removeClass("off-proxy");
        toastShowSuccess();
      } else {
        $(`#id-${proxyId} #on_off`).prop("checked", false);
        toastShowError();
      }
    });
  }

  function moveToUnused(proxyId) {
    showConfirm("Selected proxy will be moved to Unused list!").then(function (result) {
      if (!result.value) {
        $(`#id-${proxyId} #on_off`).prop("checked", true);
        return;
      }
      const data = {
        action: "rotation_action",
        command: "move_to_unused_proxies",
        nonce: CS.nonce,
        proxyId,
        rotationMethod: getCurrentRotationMethod(),
        PG,
      };
      jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
        const responseJson = JSON.parse(response);
        if (responseJson.success === true) {
          $(`#id-${proxyId}`).addClass("off-proxy");
          toastShowSuccess();
        } else {
          $(`#id-${proxyId} #on_off`).prop("checked", true);
          toastShowError();
        }
      });
    });
  }
  $(document).on("change", "#on_off", function () {
    const proxyId = this.closest("tr").dataset.id;
    if (this.checked) {
      moveBackProxy(proxyId);
    } else {
      moveToUnused(proxyId);
    }
  });
  function deleteProxy(proxyId) {
    showConfirm("Selected proxy will be deleted!").then(function (result) {
      if (!result.value) {
        return;
      }
      const data = {
        action: "rotation_action",
        command: "delete_proxy",
        nonce: CS.nonce,
        proxyId,
        rotationMethod: getCurrentRotationMethod(),
        PG,
      };
      jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
        const responseJson = JSON.parse(response);
        if (responseJson.success === true) {
          $("#proxy-list #id-" + proxyId).remove();
          showSuccess("Selected proxies has been deleted successfully!");
        } else {
          showError("Failed to delete selected proxies!");
        }
      });
    });
  }
  $(document).on("click", "#btn-delete", function () {
    const proxyId = this.closest("tr").dataset.id;
    deleteProxy(proxyId);
  });

  function forceActive(proxyId) {
    const rotationMethod = getCurrentRotationMethod();
    showConfirm("The new proxy will be activated and use as main Payment method!").then(function (result) {
      if (!result.value) {
        return;
      }
      const data = {
        action: "rotation_action",
        command: "activate_proxy",
        nonce: CS.nonce,
        rotationMethod,
        proxyId,
        PG,
      };
      jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
        const responseJson = JSON.parse(response);
        if (responseJson.success === true) {
          location.reload();
        } else {
          toastShowError();
        }
      });
    });
  }
  $(document).on("click", "#btn-force-active", function () {
    const proxyId = this.closest("tr").dataset.id;
    forceActive(proxyId);
  });

  syncMethodState(getCurrentRotationMethod());
});
