var stripe = null;
var elements = null;
var expressCheckoutElement = null;
var pendingConfirmationToken = null;

function csStripeLinkTargetOrigin() {
  return window.parentOrigin ? window.parentOrigin.replace(/\/$/, "") : "*";
}

function csStripeLinkPost(message) {
  parent.postMessage(message, csStripeLinkTargetOrigin());
}

Object.defineProperty(document, "referrer", {
  get: function () {
    return window.wootifyProxySite;
  },
});

try {
  stripe = Stripe(window.stripePublicKey);
} catch (e) {
  csStripeLinkPost({ name: "wootify-stripeLinkUnavailable", value: "stripe_init_failed" });
}

if (stripe) {
  initializeStripeLink();
}

function initializeStripeLink() {
  try {
    elements = stripe.elements({
      mode: "payment",
      amount: parseInt(window.stripeLinkAmount, 10),
      currency: window.stripeLinkCurrency || "usd",
      paymentMethodTypes: ["link", "card"],
    });

    expressCheckoutElement = elements.create("expressCheckout", {
      paymentMethods: {
        link: "auto",
        applePay: "never",
        googlePay: "never",
        paypal: "never",
        amazonPay: "never",
        klarna: "never",
      },
    });

    expressCheckoutElement.on("ready", function (event) {
      var methods = event.availablePaymentMethods || {};
      if (methods.link) {
        csStripeLinkPost({ name: "wootify-stripeLinkReady" });
      } else {
        csStripeLinkPost({ name: "wootify-stripeLinkUnavailable", value: "link_unavailable" });
      }
      csStripeLinkResize();
    });

    expressCheckoutElement.on("confirm", function (event) {
      handleStripeLinkConfirm(event);
    });

    expressCheckoutElement.on("cancel", function () {
      csStripeLinkPost({ name: "wootify-stripeLinkCancel" });
    });

    expressCheckoutElement.on("loaderror", function (event) {
      csStripeLinkPost({
        name: "wootify-stripeLinkUnavailable",
        value: event && event.error ? event.error.message : "loaderror",
      });
    });

    expressCheckoutElement.mount("#stripe-link-express-element");
  } catch (e) {
    csStripeLinkPost({ name: "wootify-stripeLinkUnavailable", value: e.message || "init_failed" });
  }
}

function handleStripeLinkConfirm(event) {
  if (!elements) {
    csStripeLinkPost({ name: "wootify-stripeLinkError", value: "Payment form is not ready." });
    return;
  }

  csStripeLinkPost({ name: "wootify-stripeLinkStart" });
  elements.submit().then(function (submitResult) {
    if (submitResult && submitResult.error) {
      csStripeLinkPost({
        name: "wootify-stripeLinkError",
        value: submitResult.error.message || "Payment details are incomplete.",
      });
      return;
    }

    stripe.createConfirmationToken({
      elements: elements,
      params: {
        payment_method_data: {
          billing_details: event && event.billingDetails ? event.billingDetails : {},
        },
      },
    }).then(function (result) {
      if (result.error) {
        csStripeLinkPost({
          name: "wootify-stripeLinkError",
          value: result.error.message || "Unable to create confirmation token.",
        });
        return;
      }

      pendingConfirmationToken = result.confirmationToken ? result.confirmationToken.id : null;
      if (!pendingConfirmationToken) {
        csStripeLinkPost({ name: "wootify-stripeLinkError", value: "Missing confirmation token." });
        return;
      }

      csStripeLinkPost({
        name: "wootify-stripeLinkConfirmationToken",
        value: {
          confirmation_token: pendingConfirmationToken,
          billing_details: event && event.billingDetails ? event.billingDetails : {},
          shipping_address: event && event.shippingAddress ? event.shippingAddress : null,
        },
      });
    }).catch(function (err) {
      csStripeLinkPost({
        name: "wootify-stripeLinkError",
        value: err && err.message ? err.message : "Confirmation token failed.",
      });
    });
  }).catch(function (err) {
    csStripeLinkPost({
      name: "wootify-stripeLinkError",
      value: err && err.message ? err.message : "Payment validation failed.",
    });
  });
}

if (window.addEventListener) {
  window.addEventListener("message", csStripeLinkListener);
} else {
  window.attachEvent("onmessage", csStripeLinkListener);
}

function csStripeLinkListener(event) {
  if (window.parentOrigin) {
    var expected = window.parentOrigin.replace(/\/$/, "");
    var actual = event.origin.replace(/\/$/, "");
    if (actual !== expected) {
      return;
    }
  }
  if (typeof event.data !== "object" || event.data.name !== "wootify-stripeLinkConfirmIntent") {
    return;
  }

  var clientSecret = event.data.value && event.data.value.client_secret;
  var attemptToken = event.data.value && event.data.value.attempt_token;
  if (!clientSecret || !pendingConfirmationToken) {
    csStripeLinkPost({
      name: "wootify-stripeLinkConfirmResult",
      value: "failed",
      attempt_token: attemptToken,
      error: { message: "Missing client secret or confirmation token." },
    });
    return;
  }

  stripe.confirmPayment({
    clientSecret: clientSecret,
    confirmParams: {
      confirmation_token: pendingConfirmationToken,
      return_url: window.location.href.split("?")[0] + "?wootify-stripe-link-express-form=1&amount=" + encodeURIComponent(window.stripeLinkAmount) + "&currency=" + encodeURIComponent(window.stripeLinkCurrency) + "&parent_origin=" + encodeURIComponent(window.parentOrigin || ""),
    },
    redirect: "if_required",
  }).then(function (result) {
    if (result.paymentIntent && ["requires_capture", "succeeded"].includes(result.paymentIntent.status)) {
      csStripeLinkPost({
        name: "wootify-stripeLinkConfirmResult",
        value: "success",
        payment_intent_id: result.paymentIntent.id,
        attempt_token: attemptToken,
      });
      return;
    }

    csStripeLinkPost({
      name: "wootify-stripeLinkConfirmResult",
      value: "failed",
      attempt_token: attemptToken,
      error: result.error || { message: "Payment failed." },
    });
  }).catch(function (err) {
    csStripeLinkPost({
      name: "wootify-stripeLinkConfirmResult",
      value: "failed",
      attempt_token: attemptToken,
      error: { message: err && err.message ? err.message : "Payment confirmation failed." },
    });
  });
}

function csStripeLinkResize() {
  var form = document.getElementById("stripe-link-express-form");
  if (!form) {
    return;
  }
  csStripeLinkPost({
    name: "wootify-stripeLinkResize",
    value: form.offsetHeight,
  });
}

setInterval(csStripeLinkResize, 250);
