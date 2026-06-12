var stripe = Stripe(window.stripePublicKey);
Object.defineProperty(document, "referrer", {
  get: function () {
    return window.wootifyProxySite;
  },
});

// Resolve parent origin via handshake (event.origin is browser-enforced)
// or fallback from sessionStorage (post-3DS redirect) or URL param (transitional).
var resolvedParentOrigin = "";
try {
  var _stored = sessionStorage.getItem("wootify_confirm_parent_origin");
  if (_stored) { resolvedParentOrigin = _stored; }
} catch (e) {}
if (!resolvedParentOrigin && window.parentOrigin) { resolvedParentOrigin = window.parentOrigin; }

function csConfirmNormalizedOrigin(o) { return o ? o.replace(/\/$/, "") : ""; }

function csConfirmHandleHandshake(event) {
  if (typeof event.data !== "object" || event.data.name !== "wootify-parentHandshake") { return; }
  if (!resolvedParentOrigin && event.origin && event.origin !== "null") {
    resolvedParentOrigin = event.origin;
    try { sessionStorage.setItem("wootify_confirm_parent_origin", resolvedParentOrigin); } catch (e) {}
  }
  try { event.source.postMessage({ name: "wootify-parentHandshakeAck" }, event.origin || "*"); } catch (e) {}
}
if (window.addEventListener) {
  window.addEventListener("message", csConfirmHandleHandshake);
} else {
  window.attachEvent("onmessage", csConfirmHandleHandshake);
}

// Notify parent that iframe is ready to receive messages
var _targetOrigin = resolvedParentOrigin ? csConfirmNormalizedOrigin(resolvedParentOrigin) : "*";
if (_targetOrigin) {
  parent.postMessage({ name: "wootify-confirmPaymentIntentStripeReady" }, _targetOrigin);
}

// Listen event from client site
if (window.addEventListener) {
  window.addEventListener("message", listener);
} else {
  window.attachEvent("onmessage", listener);
}

window.cs_stripe_payment_succeeded = false;
window.cs_stripe_confirm_in_progress = false;

// Handle redirect return on load
var urlParams = new URLSearchParams(window.location.search);
var clientSecretFromUrl = urlParams.get('payment_intent_client_secret');
var attemptTokenFromUrl = urlParams.get('attempt_token');

if (clientSecretFromUrl && !window.cs_stripe_payment_succeeded) {
  window.cs_stripe_confirm_in_progress = true;
  stripe.retrievePaymentIntent(clientSecretFromUrl).then(function (result) {
    window.cs_stripe_confirm_in_progress = false;
    var targetOrigin = resolvedParentOrigin ? csConfirmNormalizedOrigin(resolvedParentOrigin) : "*";
    if (result.paymentIntent && ["requires_capture", "succeeded"].includes(result.paymentIntent.status)) {
      window.cs_stripe_payment_succeeded = true;
      parent.postMessage({
        name: "wootify-resultConfirmPaymentIntentStripe",
        value: "success",
        attempt_token: attemptTokenFromUrl
      }, targetOrigin);
    } else {
      parent.postMessage(
        {
          name: "wootify-resultConfirmPaymentIntentStripe",
          value: "failed",
          error: result.error || { message: "Payment status: " + (result.paymentIntent ? result.paymentIntent.status : "unknown") },
          attempt_token: attemptTokenFromUrl
        },
        targetOrigin
      );
    }
  });
}

function listener(event) {
  if (typeof event.data !== "object") {
    return;
  }
  // Skip handshake — handled by csConfirmHandleHandshake
  if (event.data.name === "wootify-parentHandshake") { return; }

  // Verify origin if resolvedParentOrigin is known
  if (resolvedParentOrigin) {
    var normalizedResolved = csConfirmNormalizedOrigin(resolvedParentOrigin);
    var normalizedEventOrigin = event.origin.replace(/\/$/, "");
    if (normalizedEventOrigin !== normalizedResolved) {
      console.warn("Origin check failed. Expected: " + normalizedResolved + ", Got: " + normalizedEventOrigin);
      return;
    }
  }

  if (
    event.data.name === "wootify-requestConfirmPaymentIntentStripe" &&
    !window.cs_stripe_payment_succeeded &&
    !window.cs_stripe_confirm_in_progress
  ) {
    window.cs_stripe_confirm_in_progress = true;

    // Persist for 3DS redirect return (no parent_origin in return_url anymore)
    if (resolvedParentOrigin) {
      try { sessionStorage.setItem("wootify_confirm_parent_origin", resolvedParentOrigin); } catch (e) {}
    }

    // Build return URL — no parent_origin in URL (kept in sessionStorage instead)
    var returnUrl = window.location.href.split('?')[0] +
      "?wootify-stripe-pe-get-payment-confirm-form=1" +
      "&attempt_token=" + encodeURIComponent(event.data.attempt_token || "");

    stripe
      .confirmPayment({
        clientSecret: event.data.value,
        confirmParams: {
          return_url: returnUrl,
        },
        redirect: "if_required",
      })
      .then(function (result) {
        window.cs_stripe_confirm_in_progress = false;
        var targetOrigin = resolvedParentOrigin ? csConfirmNormalizedOrigin(resolvedParentOrigin) : "*";
        if (result.paymentIntent && ["requires_capture", "succeeded"].includes(result.paymentIntent.status)) {
          window.cs_stripe_payment_succeeded = true;
          parent.postMessage({
            name: "wootify-resultConfirmPaymentIntentStripe",
            value: "success",
            attempt_token: event.data.attempt_token
          }, targetOrigin);
        } else {
          parent.postMessage(
            {
              name: "wootify-resultConfirmPaymentIntentStripe",
              value: "failed",
              error: result.error || { message: "Payment failed or status: " + (result.paymentIntent ? result.paymentIntent.status : "unknown") },
              attempt_token: event.data.attempt_token
            },
            targetOrigin
          );
        }
      });
  }
}
