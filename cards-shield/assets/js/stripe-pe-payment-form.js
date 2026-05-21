var stripe = null;
var cardElement = null;

// Initialize Stripe with error handling
try {
  if (!window.stripePublicKey || window.stripePublicKey === '') {
    console.error('Stripe public key is missing!');
    parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Stripe configuration error - missing public key" }, "*");
  } else {
    stripe = Stripe(window.stripePublicKey);
  }
} catch (e) {
  console.error('Failed to initialize Stripe:', e);
  parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Failed to initialize Stripe: " + e.message }, "*");
}

Object.defineProperty(document, "referrer", {
  get: function () {
    return window.wootifyProxySite;
  },
});

var elements;

if (stripe) {
  initialize();
}

document.querySelector("#payment-form").addEventListener("submit", handleSubmit);

function initialize() {
  try {
    var amount = parseInt(window.stripeAmount);
    var currency = window.stripeCurrency || 'usd';
    
    // Validate amount - Stripe requires minimum amount
    if (isNaN(amount) || amount < 50) {
      console.error('Invalid Stripe amount:', window.stripeAmount, '-> parsed:', amount);
      // Use minimum amount for initialization, will be updated later
      amount = 50;
    }
    
    console.log('Stripe init - amount:', amount, 'currency:', currency);
    
    window.stripePaymentElements = stripe.elements({
      mode: "payment",
      amount: amount,
      currency: currency,
      paymentMethodCreation: "manual",
      paymentMethodTypes: ["card"],
    });
    
    cardElement = window.stripePaymentElements.create("payment", {
      fields: {
        billingDetails: {
          address: {
            country: "never",
          },
        },
      },
      wallets: {
        applePay: "never",
        googlePay: "never",
      },
    });
    
    cardElement.mount("#payment-element");
    
    cardElement.on("ready", function () {
      console.log('Stripe payment element ready');
      parent.postMessage("wootify-loadedPaymentFormStripe", "*");
    });
    
    cardElement.on("loaderror", function (event) {
      console.error('Stripe element load error:', event);
      parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Payment form failed to load" }, "*");
    });
    
    cardElement.on("change", function (event) {
      if (event.complete) {
        parent.postMessage("wootify-paymentFormCompletedStripe", "*");
      } else {
        parent.postMessage("wootify-paymentFormFailStripe", "*");
      }
      if (event.error) {
        console.error('Stripe element error:', event.error);
      }
    });
    
  } catch (e) {
    console.error('Failed to initialize Stripe elements:', e);
    parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Payment form initialization failed: " + e.message }, "*");
  }
}

function handleSubmit(formData) {
  console.log('handleSubmit called with:', formData);
  
  // Check if Stripe is properly initialized
  if (!stripe || !window.stripePaymentElements) {
    console.error('Stripe not initialized properly');
    parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Payment system not ready" }, "*");
    return;
  }
  
  parent.postMessage("wootify-startSubmitPaymentStripe", "*");
  
  window.stripePaymentElements.submit().then(function (e) {
    console.log('Stripe elements submit result:', e);
    if (e.error) {
      console.error('Stripe submit error:', e.error);
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
      return; // Don't continue if submit failed
    }
    
    // Only create payment method after successful submit
    stripe
      .createPaymentMethod({
        elements: window.stripePaymentElements,
        params: {
          billing_details: formData.billing_details,
        },
      })
      .then(function (result) {
        console.log('createPaymentMethod result:', result);
        if (result.paymentMethod && result.paymentMethod.id) {
          parent.postMessage(
            {
              name: "wootify-paymentMethodIdStripe",
              value: result.paymentMethod.id,
            },
            "*"
          );
        } else if (result.error) {
          console.error('createPaymentMethod error:', result.error);
          if (
            [
              "incomplete_number",
              "invalid_number",
              "incomplete_expiry",
              "invalid_expiry",
              "incomplete_cvc",
              "invalid_cvc",
            ].includes(result.error.code)
          ) {
            parent.postMessage("wootify-endSubmitPaymentStripe", "*");
          } else {
            parent.postMessage(
              {
                name: "wootify-errorSubmitPaymentStripe",
                value: result.error.message,
              },
              "*"
            );
          }
        } else {
          console.error('createPaymentMethod unknown result');
          parent.postMessage("wootify-endSubmitPaymentStripe", "*");
        }
      })
      .catch(function(err) {
        console.error('createPaymentMethod exception:', err);
        parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Payment method creation failed" }, "*");
      });
  }).catch(function(err) {
    console.error('Stripe submit exception:', err);
    parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Form validation failed" }, "*");
  });
}

// Listen event from client site
if (window.addEventListener) {
  window.addEventListener("message", listener);
} else {
  window.attachEvent("onmessage", listener);
}

function listener(event) {
  // Only log relevant messages (not resize ones)
  if (typeof event.data === "object" && event.data.name && event.data.name !== "wootify-stripeBodyResizeCreditForm") {
    console.log('Stripe iframe received message:', event.data);
  }
  if (typeof event.data === "object" && event.data.name === "wootify-submitFormStripe") {
    console.log('Processing wootify-submitFormStripe');
    handleSubmit(event.data.value);
  }
}

setInterval(function () {
  parent.postMessage(
    {
      name: "wootify-stripeBodyResizeCreditForm",
      value: document.getElementById("payment-form").offsetHeight,
    },
    "*"
  );
}, 50);
