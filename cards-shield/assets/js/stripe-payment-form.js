var stripe = Stripe(window.stripePublicKey);
Object.defineProperty(document, "referrer", {
  get: function () {
    return window.wootifyProxySite;
  },
});

var elements;
initialize();

document.querySelector("#payment-form").addEventListener("submit", handleSubmit);

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
  parent.postMessage("wootify-startSubmitPaymentStripe", "*");
  stripe
    .createPaymentMethod({
      type: "card",
      card: window.cardElement,
      billing_details: formData.billing_details,
    })
    .then(function (e) {
      if (e.paymentMethod && e.paymentMethod.id) {
        parent.postMessage(
          {
            name: "wootify-paymentMethodIdStripe",
            value: e.paymentMethod.id,
          },
          "*"
        );
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

// Listen event from client site
if (window.addEventListener) {
  window.addEventListener("message", listener);
} else {
  window.attachEvent("onmessage", listener);
}

function listener(event) {
  if (typeof event.data === "object" && event.data.name === "wootify-submitFormStripe") {
    handleSubmit(event.data.value);
  }
}
//202307051117
