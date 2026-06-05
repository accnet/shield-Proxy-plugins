jQuery(document).ready(function ($) {
  window.wooCheckoutFormInfo = false;
  paypal
    .Buttons({
      createOrder: function (data, actions) {
        var payerData = {
          email_address: window.wooCheckoutFormInfo.email,
          name: {
            surname: window.wooCheckoutFormInfo.last_name,
            given_name: window.wooCheckoutFormInfo.first_name,
          },
        };
        var purchaseUnits = JSON.parse(JSON.stringify(window.wooCheckoutFormInfo.purchase_units));
        var applicationContext = {
          brand_name: "merchant",
          user_action: "CONTINUE",
        };
        // if (!navigator.userAgent.match(/firefox|fxios|edg|opr/i) && !mobileCheck()) {
        //     applicationContext.return_url = window.wootifyProxySite + '/checkout'
        //     applicationContext.cancel_url = window.wootifyProxySite + '/checkout'
        // }
        if (
          !window.wooCheckoutFormInfo.address.country ||
          window.wooCheckoutFormInfo.address.country.length === 0 ||
          !window.wooCheckoutFormInfo.address.city ||
          window.wooCheckoutFormInfo.address.city.length === 0
        ) {
          applicationContext.shipping_preference = "NO_SHIPPING";
        } else {
          payerData.address = {
            country_code: window.wooCheckoutFormInfo.address.country,
            address_line_1: window.wooCheckoutFormInfo.address.line1,
            address_line_2: window.wooCheckoutFormInfo.address.line2,
            admin_area_1: window.wooCheckoutFormInfo.address.state,
            admin_area_2: window.wooCheckoutFormInfo.address.city,
            postal_code: window.wooCheckoutFormInfo.address.postal_code,
          };
          purchaseUnits[0].shipping = {
            name: {
              full_name: window.wooCheckoutFormInfo.first_name + " " + window.wooCheckoutFormInfo.last_name,
            },
            address: {
              country_code: window.wooCheckoutFormInfo.address.country,
              address_line_1: window.wooCheckoutFormInfo.address.line1,
              address_line_2: window.wooCheckoutFormInfo.address.line2,
              admin_area_1: window.wooCheckoutFormInfo.address.state,
              admin_area_2: window.wooCheckoutFormInfo.address.city,
              postal_code: window.wooCheckoutFormInfo.address.postal_code,
            },
          };
        }
        if (window.wooCheckoutFormInfo.phone && window.wooCheckoutFormInfo.phone.length) {
          payerData.phone = {
            phone_type: "HOME",
            phone_number: {
              national_number: window.wooCheckoutFormInfo.phone.replace(/[^0-9]+/g, ""),
            },
          };
        }
        window.orderData = {
          intent: window.wooCheckoutFormInfo.orderIntent,
          purchase_units: purchaseUnits,
          payer: payerData,
          application_context: applicationContext,
        };
        var order = actions.order.create(window.orderData);
        return order;
      },
      onApprove: function (data, actions) {
        var wootify_checkout_form = $("form.checkout");
        var orderID = data.orderID;
        if (wootify_checkout_form.find('[name="wootify-paypal-payment-order-id"]')) {
          wootify_checkout_form.find('[name="wootify-paypal-payment-order-id"]').remove();
        }
        wootify_checkout_form.append(
          '<input style="display:none;" name="wootify-paypal-payment-order-id" value="' + orderID + '"/>'
        );
        wootify_checkout_form.submit();
      },
      onInit: function (data, actions) {
        console.log("onInit");
      },
      onClick: function (data, actions) {
        if (!window.wooCheckoutFormInfo) {
          var wootify_checkout_form = $("form.checkout");
          wootify_checkout_form.submit();
          return actions.reject();
        }
      },
      onCancel: function (data) {
        console.log("onCancel");
      },
      onError: function (err) {
        console.log("onError");
      },
    })
    .render("#paypal-button-container");
  $("body").on("updated_checkout", function () {
    handleShowHidePaypalButton();
  });

  $(document).on("payment_method_selected", function () {
    handleShowHidePaypalButton();
  });
  function handleShowHidePaypalButton() {
    if ($('input[name="payment_method"]:checked').val() == "cs_paypal") {
      $("#wootify-paypal-credit-form-container").show();
      $("#place_order").addClass("important-hide");
    } else {
      $("#wootify-paypal-credit-form-container").hide();
      $("#place_order").removeClass("important-hide");
    }
  }

  setInterval(function () {
    if ($('input[name="payment_method"]:checked').val() == "cs_paypal") {
      if (validateFormCheckoutPaypal()) {
        window.wooCheckoutFormInfo = {
          purchase_units: window.wootify_paypal_checkout_purchase_units,
          orderIntent: "CAPTURE",
          last_name: wootifyGetUserField("last_name"),
          first_name: wootifyGetUserField("first_name"),
          email: wootifyGetUserField("email"),
          address: {
            city: wootifyGetUserField("city"),
            country: wootifyGetUserField("country"),
            line1: wootifyGetUserField("address_1"),
            line2: wootifyGetUserField("address_2"),
            postal_code: wootifyGetUserField("postcode"),
            state: wootifyGetUserField("state"),
          },
          phone: wootifyGetUserField("phone"),
        };
      } else {
        window.wooCheckoutFormInfo = null;
      }
    }
  }, 100);
  function wootifyGetUserField(fieldName) {
    if ($("#billing_" + fieldName).val() && $("#billing_" + fieldName).val().length > 0) {
      return $("#billing_" + fieldName).val();
    }
    return $("#shipping_" + fieldName).val();
  }
  function checkFieldValidatedPaypal(target) {
    var isNotInvalid = !target.closest(".form-row").hasClass("woocommerce-invalid");
    var isNotEmpty = true;
    if (target.closest(".form-row").hasClass("validate-required")) {
      isNotEmpty = typeof target.val() == "string" ? target.val().length : false;
    }
    return isNotInvalid && isNotEmpty;
  }
  function validateFormCheckoutPaypal() {
    var requiredFields = $("form.woocommerce-checkout .validate-required:visible :input");
    requiredFields.each((i, input) => {
      $(input).trigger("validate");
    });
    return (
      checkFieldValidatedPaypal($("#billing_first_name")) &&
      checkFieldValidatedPaypal($("#billing_last_name")) &&
      checkFieldValidatedPaypal($("#billing_email")) &&
      (($("#shipping_city").val() && $("#shipping_city").val().toString().length) ||
        checkFieldValidatedPaypal($("#billing_city"))) &&
      checkFieldValidatedPaypal($("#billing_country")) &&
      (($("#shipping_postcode").val() && $("#shipping_postcode").val().toString().length) ||
        checkFieldValidatedPaypal($("#billing_postcode"))) &&
      checkFieldValidatedPaypal($("#billing_state")) &&
      checkFieldValidatedPaypal($("#billing_address_1")) &&
      checkFieldValidatedPaypal($("#billing_address_2")) &&
      checkFieldValidatedPaypal($("#billing_phone"))
    );
  }
});
