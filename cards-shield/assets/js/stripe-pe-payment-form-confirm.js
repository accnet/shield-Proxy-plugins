var stripe = Stripe(window.stripePublicKey);
Object.defineProperty(document, "referrer", {
  get: function () {
    return window.wootifyProxySite;
  },
});

// Notify parent that iframe is ready to receive messages
if (window.parentOrigin && window.parentOrigin !== '') {
  var normalizedParentOrigin = window.parentOrigin.replace(/\/$/, "");
  parent.postMessage({ name: "wootify-confirmPaymentIntentStripeReady" }, normalizedParentOrigin);
} else {
  parent.postMessage({ name: "wootify-confirmPaymentIntentStripeReady" }, "*");
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
    var targetOrigin = window.parentOrigin ? window.parentOrigin.replace(/\/$/, "") : "*";
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

  // Verify origin if window.parentOrigin is set
  if (window.parentOrigin && window.parentOrigin !== '') {
    var normalizedParentOrigin = window.parentOrigin.replace(/\/$/, "");
    var normalizedEventOrigin = event.origin.replace(/\/$/, "");
    if (normalizedEventOrigin !== normalizedParentOrigin) {
      console.warn("Origin check failed. Expected: " + normalizedParentOrigin + ", Got: " + normalizedEventOrigin);
      return;
    }
  }

  if (
    event.data.name === "wootify-requestConfirmPaymentIntentStripe" &&
    !window.cs_stripe_payment_succeeded &&
    !window.cs_stripe_confirm_in_progress
  ) {
    window.cs_stripe_confirm_in_progress = true;
    
    // Build return URL pointing back to this iframe
    var returnUrl = window.location.href.split('?')[0] + 
      "?wootify-stripe-pe-get-payment-confirm-form=1&parent_origin=" + 
      encodeURIComponent(window.parentOrigin || "") +
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
        var targetOrigin = window.parentOrigin ? window.parentOrigin.replace(/\/$/, "") : "*";
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
