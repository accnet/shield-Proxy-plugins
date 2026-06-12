var stripe = null;
var elements = null;
var expressCheckoutElement = null;
var pendingConfirmationToken = null;
var linkAvailabilityState = "unknown";
var linkUnavailableReason = "";

// Parent origin is resolved via postMessage handshake (event.origin is browser-enforced,
// cannot be spoofed) instead of a URL parameter — keeps site2 domain out of the iframe URL.
var resolvedParentOrigin = "";

function csStripeLinkNormalizeOrigin(origin) {
  if (!origin || !window.URL) {
    return origin ? origin.replace(/\/$/, "") : "";
  }
  try {
    return new URL(origin, window.location.href).origin;
  } catch (e) {
    return origin.replace(/\/$/, "");
  }
}

function csStripeLinkTargetOrigin() {
  return resolvedParentOrigin ? csStripeLinkNormalizeOrigin(resolvedParentOrigin) : "*";
}

// Handle handshake: parent sends wootify-parentHandshake → we capture event.origin
// and respond so parent knows we are ready.
function csStripeLinkHandleHandshake(event) {
  if (typeof event.data !== "object" || event.data.name !== "wootify-parentHandshake") {
    return;
  }
  if (!resolvedParentOrigin && event.origin && event.origin !== "null") {
    resolvedParentOrigin = event.origin;
  }
  // Respond so parent knows iframe accepted handshake
  try {
    event.source.postMessage({ name: "wootify-parentHandshakeAck" }, event.origin || "*");
  } catch (e) {}
  // Trigger availability broadcast now that we know the target
  csStripeLinkPostAvailability();
}

if (window.addEventListener) {
  window.addEventListener("message", csStripeLinkHandleHandshake);
} else {
  window.attachEvent("onmessage", csStripeLinkHandleHandshake);
}

function csStripeLinkPost(message) {
  parent.postMessage(message, csStripeLinkTargetOrigin());
}

// Restore parent origin from sessionStorage (3DS redirect case: iframe reloads
// without parent_origin in URL, but sessionStorage persists within the same tab).
if (!resolvedParentOrigin) {
  try {
    var stored = sessionStorage.getItem("wootify_link_parent_origin");
    if (stored) { resolvedParentOrigin = stored; }
  } catch (e) {}
}


function csStripeLinkPostAvailability() {
  if (linkAvailabilityState === "ready") {
    csStripeLinkPost({ name: "wootify-stripeLinkReady" });
    csStripeLinkResize();
    return;
  }
  if (linkAvailabilityState === "unavailable") {
    csStripeLinkPost({ name: "wootify-stripeLinkUnavailable", value: linkUnavailableReason || "link_unavailable" });
  }
}

Object.defineProperty(document, "referrer", {
  get: function () {
    return window.wootifyProxySite;
  },
});

try {
  stripe = Stripe(window.stripePublicKey);
} catch (e) {
  linkAvailabilityState = "unavailable";
  linkUnavailableReason = "stripe_init_failed";
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

    // Build the shipping rate from URL params passed by WooCommerce PHP.
    // PHP passes shipping_amount (in minor units, same scale as amount) and
    // shipping_label (localised label, defaults to "Shipping").
    // Stripe requires shippingRates to have at least one entry when
    // shippingAddressRequired is true; empty [] throws IntegrationError.
    var shippingAmount = parseInt(window.stripeLinkShippingAmount || "0", 10);
    var shippingLabel  = window.stripeLinkShippingLabel || "Shipping";
    var shippingRates  = [{
      id:          "wc-shipping",
      displayName: shippingLabel,
      amount:      shippingAmount,
    }];

    expressCheckoutElement = elements.create("expressCheckout", {
      paymentMethods: {
        link: "auto",
        applePay: "never",
        googlePay: "never",
        paypal: "never",
        amazonPay: "never",
        klarna: "never",
      },
      shippingAddressRequired: true,
    });

    // Must handle shippingaddresschange and resolve with the shipping rates.
    // We return the same WooCommerce-calculated rate regardless of the address
    // the customer picks (the actual charge amount is fixed in the PaymentIntent).
    expressCheckoutElement.on("shippingaddresschange", function (event) {
      event.resolve({ shippingRates: shippingRates });
    });

    expressCheckoutElement.on("shippingratechange", function (event) {
      event.resolve();
    });

    expressCheckoutElement.on("ready", function (event) {
      var methods = event.availablePaymentMethods || {};
      if (methods.link) {
        linkAvailabilityState = "ready";
        linkUnavailableReason = "";
      } else {
        linkAvailabilityState = "unavailable";
        linkUnavailableReason = "link_unavailable";
      }
      csStripeLinkPostAvailability();
      csStripeLinkResize();
    });

    expressCheckoutElement.on("confirm", function (event) {
      handleStripeLinkConfirm(event);
    });

    expressCheckoutElement.on("cancel", function () {
      csStripeLinkPost({ name: "wootify-stripeLinkCancel" });
    });

    expressCheckoutElement.on("loaderror", function (event) {
      linkAvailabilityState = "unavailable";
      linkUnavailableReason = event && event.error ? event.error.message : "loaderror";
      csStripeLinkPost({
        name: "wootify-stripeLinkUnavailable",
        value: linkUnavailableReason,
      });
    });

    expressCheckoutElement.mount("#stripe-link-express-element");
  } catch (e) {
    linkAvailabilityState = "unavailable";
    linkUnavailableReason = e.message || "init_failed";
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

// Fallback: also accept parentOrigin from URL if transitional (will be removed later)
if (!resolvedParentOrigin && window.parentOrigin) {
  resolvedParentOrigin = window.parentOrigin;
}

function csStripeLinkListener(event) {
  // Skip handshake messages — handled by csStripeLinkHandleHandshake
  if (typeof event.data === "object" && event.data && event.data.name === "wootify-parentHandshake") {
    return;
  }
  // Origin validation: once resolved, only accept messages from the trusted parent
  if (resolvedParentOrigin) {
    var expected = csStripeLinkNormalizeOrigin(resolvedParentOrigin);
    var actual = csStripeLinkNormalizeOrigin(event.origin);
    if (actual !== expected) {
      return;
    }
  }
  if (typeof event.data !== "object") {
    return;
  }

  if (event.data.name === "wootify-stripeLinkCheckAvailability") {
    csStripeLinkPostAvailability();
    return;
  }

  if (event.data.name !== "wootify-stripeLinkConfirmIntent") {
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

  // Persist resolved parent origin in sessionStorage so it survives the 3DS redirect
  // without needing to embed it in the return_url (which Stripe.js can read).
  try {
    if (resolvedParentOrigin) {
      sessionStorage.setItem("wootify_link_parent_origin", resolvedParentOrigin);
    }
  } catch (e) {}

  stripe.confirmPayment({
    clientSecret: clientSecret,
    confirmParams: {
      confirmation_token: pendingConfirmationToken,
      return_url: window.location.href.split("?")[0] + "?wootify-stripe-link-express-form=1&amount=" + encodeURIComponent(window.stripeLinkAmount) + "&currency=" + encodeURIComponent(window.stripeLinkCurrency),
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
