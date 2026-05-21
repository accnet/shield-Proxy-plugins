var stripe = Stripe(window.stripePublicKey);
Object.defineProperty(document, "referrer", {
  get: function () {
    return window.wootifyProxySite;
  },
});

// Listen event from client site
if (window.addEventListener) {
  window.addEventListener("message", listener);
} else {
  window.attachEvent("onmessage", listener);
}

function listener(event) {
  if (typeof event.data === "object" && event.data.name === "wootify-confirmPaymentIntentStripe") {
    stripe.confirmCardPayment(event.data.value).then(function (result) {
      if (result.paymentIntent && ["requires_capture", "succeeded"].includes(result.paymentIntent.status)) {
        parent.postMessage({ name: "wootify-confirmPaymentIntentStripe", value: "success" }, "*");
      } else {
        parent.postMessage({ name: "wootify-confirmPaymentIntentStripe", value: "failed", error: result.error }, "*");
      }
    });
  }
}
