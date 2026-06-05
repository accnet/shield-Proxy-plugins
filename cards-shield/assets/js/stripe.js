var stripe = Stripe(window.stripePublicKey);
var elements;
initialize();
var wootify_checkout_form = document.querySelector("form.checkout");
function initialize() {
  elements = stripe.elements();
  var cardElement = elements.create("card", {
    hidePostalCode: true,
    style: {
      base: {
        fontSize: "16px",
      },
    },
  });
  cardElement.mount("#payment-element");
  window.cardElement = cardElement;
  cardElement.on("ready", function () {
    parent.postMessage("wootify-loadedPaymentFormStripe", "*");
  });
  cardElement.on("change", function (event) {
    if (event.complete) {
      parent.postMessage("wootify-paymentFormCompletedStripe", "*");
    } else {
      parent.postMessage("wootify-paymentFormFailStripe", "*");
    }
  });
}
function handleSubmit(formData) {
  stripe
    .createPaymentMethod({
      type: "card",
      card: window.cardElement,
      billing_details: formData.billing_details,
    })
    .then(function (e) {
      if (e.paymentMethod && e.paymentMethod.id) {
        jQuery(function ($) {
          var wootify_checkout_form = $("form.checkout");
          var paymentMethodId = e.paymentMethod.id;
          if (wootify_checkout_form.find('[name="wootify-stripe-payment-method-id"]')) {
            wootify_checkout_form.find('[name="wootify-stripe-payment-method-id"]').remove();
          }
          wootify_checkout_form.append(
            '<input style="display:none;" name="wootify-stripe-payment-method-id" value="' + paymentMethodId + '"/>'
          );
          wootify_checkout_form.submit();
        });
      } else if (e.error) {
        if (
          [
            "incomplete_number",
            "invalid_number",
            "incomplete_expiry",
            "invalid_expiry",
            "incomplete_cvc",
            "invalid_cvc",
          ].includes(e.error.code)
        ) {
          parent.postMessage("wootify-endSubmitPaymentStripe", "*");
        } else {
          parent.postMessage(
            {
              name: "wootify-errorSubmitPaymentStripe",
              value: e.error.message,
            },
            "*"
          );
        }
      } else {
        parent.postMessage("wootify-endSubmitPaymentStripe", "*");
      }
    });
}

jQuery(function ($) {
  var wootify_checkout_form = $("form.checkout");

  $("body").on("click", "#place_order", function (e) {
    if ($('input[name="payment_method"]:checked').val() == "cs_stripe") {
      e.preventDefault();
      if (validateFormCheckout()) {
        let formData = {
          billing_details: {
            name: $("#billing_first_name").val() + " " + $("#billing_last_name").val(),
            email: $("#billing_email").val(),
            address: {
              city: $("#billing_city").val(),
              country: $("#billing_country").val(),
              line1: $("#billing_address_1").val(),
              line2: $("#billing_address_2").val(),
              postal_code: $("#billing_postcode").val(),
              state: $("#billing_state").val(),
            },
            phone: $("#billing_phone").val(),
          },
        };
        handleSubmit(formData);
      } else {
        wootify_checkout_form.submit();
      }
    }
  });
  function checkFieldValidated(target) {
    var isNotInvalid = !target.closest(".form-row").hasClass("woocommerce-invalid");
    var isNotEmpty = true;
    if (target.closest(".form-row").hasClass("validate-required")) {
      isNotEmpty = typeof target.val() == "string" ? target.val().length : false;
    }
    return isNotInvalid && isNotEmpty;
  }

  function validateFormCheckout() {
    return (
      checkFieldValidated($("#billing_first_name")) &&
      checkFieldValidated($("#billing_last_name")) &&
      checkFieldValidated($("#billing_email")) &&
      checkFieldValidated($("#billing_city")) &&
      checkFieldValidated($("#billing_country")) &&
      checkFieldValidated($("#billing_postcode")) &&
      checkFieldValidated($("#billing_address_1")) &&
      checkFieldValidated($("#billing_address_2")) &&
      checkFieldValidated($("#billing_phone")) &&
      $('input[name="payment_method"]:checked').val() == "cs_stripe"
    );
  }
});
window.addEventListener("hashchange", onHashChange);

function onHashChange() {
  var partials = window.location.hash.match(/^#?cs-confirm-pi-([^:]+):(.+):(.+)$/);
  var intentClientSecret = partials?.[1];
  if (!partials || 4 > partials.length) {
    return;
  }

  window.wootify_stripe_order_id = partials[2];

  // Cleanup the URL
  window.location.hash = "";
  stripe.confirmCardPayment(intentClientSecret).then(function (result) {
    if (result.paymentIntent && ["requires_capture", "succeeded"].includes(result.paymentIntent.status)) {
      window.location.href = "/?wootify_stripe_return_3d_success=1&order_id=" + window.wootify_stripe_order_id; //relative to domain
    } else {
      var error = result.error;
      $.post("/?wc-ajax=cs_add_order_note", {
        order_id: window.wootify_stripe_order_id,
        note: "Stripe checkout error! Error code: " + error.code + ", Message: " + error.message,
        security: ajax_object.cs_add_order_note_nonce,
      })
        .done(function (result) {
          // console.log('done: ' + result);
        })
        .fail(function () {
          console.log("Can't add order note");
        });

      checkout_error("We cannot process your payment right now, please try another payment method.[10]");
    }
  });
}
