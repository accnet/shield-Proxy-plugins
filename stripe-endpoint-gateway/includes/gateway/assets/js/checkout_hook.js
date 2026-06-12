jQuery(function ($) {
    if ($('#endpoint_stripe_pay_for_order_page').length) {
        var WOOTIFY_checkout_form = $('#order_review');
    } else {
        var WOOTIFY_checkout_form = $('form.checkout');
    }

    var STRIPE_LINK_REQUIRED_FIELDS_MESSAGE = 'Please fill in the required checkout fields before using Link.';

    function loadPaymentProcess() {
        setTimeout(function () {
            if (!window.endpoint_stripe_checkout_error) {
                WOOTIFY_checkout_form.removeClass('processing').unblock();
                $('#cs-stripe-loader').show();
                setTimeout((function () {
                    $('#cs-stripe-loader').hide();
                }), 30000);
            }
        }, 1000)
    }

    function scheduleStripeFrameWatch() {
        // Do not interfere with 3DS confirm flow
        if (window.pending_confirm_secret) {
            return;
        }
        var stripeIframe = $('#payment-stripe-area');
        if (!stripeIframe.length || !isStripePaymentSelected()) {
            return;
        }

        var iframeSrc = stripeIframe.attr('src') || '';
        if (window.endpoint_stripe_frame_watch_src === iframeSrc && window.loadedPaymentFormStripe) {
            return;
        }

        window.endpoint_stripe_frame_watch_src = iframeSrc;
        window.loadedPaymentFormStripe = false;
        if (window.endpoint_stripe_frame_watch_timer) {
            clearTimeout(window.endpoint_stripe_frame_watch_timer);
        }

        window.endpoint_stripe_frame_watch_timer = setTimeout(function () {
            if (window.loadedPaymentFormStripe || !isStripePaymentSelected()) {
                return;
            }
            reportStripeFrameFailure('frame_timeout');
        }, 8000);
    }

    function reportStripeFrameFailure(reason) {
        var ajaxConfig = window.ajax_object || {};
        if (!ajaxConfig.ajax_url || !ajaxConfig.shield_proxy_frame_status_nonce) {
            checkout_error('Payment form is not ready. Please refresh the page and try again.');
            return;
        }

        $('.woocommerce-checkout-payment').block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });

        $.post(ajaxConfig.ajax_url, {
            action: 'shield_proxy_frame_status',
            nonce: ajaxConfig.shield_proxy_frame_status_nonce,
            gateway: 'stripe',
            reason: reason || 'frame_timeout'
        }).done(function (response) {
            if (response && response.success && response.data && response.data.reload_checkout) {
                $(document.body).trigger('update_checkout');
                setTimeout(scheduleStripeFrameWatch, 1500);
                return;
            }
            checkout_error('Payment form is not ready. Please try another payment method.');
        }).fail(function () {
            checkout_error('Payment form is not ready. Please try another payment method.');
        }).always(function () {
            $('.woocommerce-checkout-payment').unblock();
        });
    }

    $(document).on('checkout_error', function () {
        if ($('input[name="payment_method"]:checked').val() == 'endpoint_stripe') {
            $('#cs-stripe-loader').hide();
            window.endpoint_stripe_checkout_error = true;
            // Reset processing state when checkout has error
            if (window.endpoint_stripe_timeout) {
                clearTimeout(window.endpoint_stripe_timeout);
            }
            window.endpoint_stripe_processing = false;
        }
    })
    $('body').on('click', '#place_order', function (e) {
        if ($('input[name="payment_method"]:checked').val() == 'endpoint_stripe') {
            window.endpoint_stripe_checkout_error = false;
            e.preventDefault();
            
            // Prevent double click
            if (window.endpoint_stripe_processing) {
                console.log('Stripe payment already processing...');
                return;
            }
            
            // Check if iframe exists and is loaded
            var stripeIframe = $('#payment-stripe-area');
            if (!stripeIframe.length || !stripeIframe[0].contentWindow) {
                console.error('Stripe payment iframe not found or not loaded');
                checkout_error('Payment form is not ready. Please refresh the page and try again.');
                return;
            }
            
            // Check if payment form is loaded
            if (!window.loadedPaymentFormStripe) {
                console.error('Stripe payment form not loaded yet');
                checkout_error('Payment form is still loading. Please wait a moment and try again.');
                return;
            }
            
            // Always send message to iframe first if basic validation passes
            // Let WooCommerce handle full form validation after we get payment method id
            if (isStripePaymentSelected()) {
                // Block form IMMEDIATELY when clicking Place Order
                window.endpoint_stripe_processing = true;
                // Disable button physically to prevent double-click sending duplicate request to site1
                $('#place_order').prop('disabled', true);
                blockOnSubmit(WOOTIFY_checkout_form);
                WOOTIFY_checkout_form.addClass('processing');
                
                // Set timeout to unblock if no response from iframe after 15 seconds
                window.endpoint_stripe_timeout = setTimeout(function() {
                    if (window.endpoint_stripe_processing) {
                        console.error('Stripe iframe did not respond in time');
                        window.endpoint_stripe_processing = false;
                        WOOTIFY_checkout_form.removeClass('processing').unblock();
                        checkout_error('Payment processing timed out. Please try again.');
                    }
                }, 15000);
                
                var messageData = {
                    name: 'wootify-submitFormStripe',
                    value: {
                        billing_details: {
                            name: (stripeGetFormCheckoutVal('#billing_first_name') || '') + ' ' + (stripeGetFormCheckoutVal('#billing_last_name') || ''),
                            phone: stripeGetFormCheckoutVal('#billing_phone') || '',
                            email: stripeGetFormCheckoutVal('#billing_email') || '',
                            address: {
                                city: stripeGetFormCheckoutVal('#billing_city') || '',
                                country: stripeGetFormCheckoutVal('#billing_country') || '',
                                line1: stripeGetFormCheckoutVal('#billing_address_1') || '',
                                line2: stripeGetFormCheckoutVal('#billing_address_2') || '',
                                postal_code: stripeGetFormCheckoutVal('#billing_postcode') || '',
                                state: stripeGetFormCheckoutVal('#billing_state') || '',
                            },
                        }
                    }
                };
                
                console.log('Sending message to Stripe iframe:', messageData);
                stripeIframe[0].contentWindow.postMessage(messageData, '*');
            } else {
                WOOTIFY_checkout_form.submit()
            }
        }
    })
    
    function isStripePaymentSelected() {
        return $('input[name="payment_method"]:checked').val() == 'endpoint_stripe';
    }
    if(WOOTIFY_checkout_form.find('[name="wootify-stripe-payment-method-id"]').length) {
            $(document.body).on('updated_checkout', function (data) {
            if (!window.loadedPaymentFormStripe && $('input[name="payment_method"]:checked').val() == 'endpoint_stripe') {
            $('.woocommerce-checkout-payment').block({
                    message: null,
                    overlayCSS: {
                        background: '#fff',
                        opacity: 0.6
                    }
                });
            }
            scheduleStripeFrameWatch();
            setTimeout(function () {
                scheduleStripeLinkAvailabilityCheck(0);
            }, 500);
        });
    }
    /*
    event from proxy iframe
     */
    if (window.addEventListener) {
        window.addEventListener("message", listener);
    } else {
        window.attachEvent("onmessage", listener);
    }
    setTimeout(function () {
        scheduleStripeLinkAvailabilityCheck(0);
    }, 500);

    function blockOnSubmit(form) {
        var isBlocked = form.data('blockUI.isBlocked');

        if (1 !== isBlocked) {
            form.block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        }
    }

    function getStripeLinkExpectedOrigin() {
        var proxyUrl = $('#endpoint_stripe_link_current_proxy_url').data('value')
            || $('#WOOTIFY_stripe_link_current_proxy_url').data('value');
        if (!proxyUrl || !window.URL) {
            return '';
        }
        try {
            return new URL(proxyUrl, window.location.href).origin;
        } catch (e) {
            return '';
        }
    }

    function isStripeLinkMessage(data) {
        return typeof data === 'object'
            && data
            && typeof data.name === 'string'
            && data.name.indexOf('wootify-stripeLink') === 0;
    }

    function isTrustedStripeLinkMessage(event) {
        var iframe = document.getElementById('payment-stripe-link-area');
        var expectedOrigin = getStripeLinkExpectedOrigin();
        if (!iframe || !iframe.contentWindow || !expectedOrigin) {
            return false;
        }
        return event.source === iframe.contentWindow && event.origin === expectedOrigin;
    }

    function postStripeLinkMessage(payload) {
        var iframe = document.getElementById('payment-stripe-link-area');
        var expectedOrigin = getStripeLinkExpectedOrigin();
        if (!iframe || !iframe.contentWindow || !expectedOrigin) {
            checkout_error('Express checkout is not ready. Please try another payment method.');
            return false;
        }
        iframe.contentWindow.postMessage(payload, expectedOrigin);
        return true;
    }

    function scheduleStripeLinkAvailabilityCheck(attempt) {
        attempt = attempt || 0;
        var iframe = document.getElementById('payment-stripe-link-area');
        var expectedOrigin = getStripeLinkExpectedOrigin();
        if (iframe && iframe.contentWindow && expectedOrigin) {
            iframe.contentWindow.postMessage({ name: 'wootify-stripeLinkCheckAvailability' }, expectedOrigin);
        }
        if (attempt < 6 && (!iframe || !iframe.contentWindow || !expectedOrigin || !$('#wootify-stripe-link-express-container').is(':visible'))) {
            setTimeout(function () {
                scheduleStripeLinkAvailabilityCheck(attempt + 1);
            }, 500);
        }
    }

    function listener(event) {
        if (isStripeLinkMessage(event.data) && !isTrustedStripeLinkMessage(event)) {
            return;
        }
        if (event.data === "wootify-startSubmitPaymentStripe") {
            // Clear timeout since iframe responded
            if (window.endpoint_stripe_timeout) {
                clearTimeout(window.endpoint_stripe_timeout);
            }
            blockOnSubmit(WOOTIFY_checkout_form);
            WOOTIFY_checkout_form.addClass('processing')
        }
        if (event.data === "wootify-endSubmitPaymentStripe") {
            // Clear timeout and reset processing flag
            if (window.endpoint_stripe_timeout) {
                clearTimeout(window.endpoint_stripe_timeout);
            }
            window.endpoint_stripe_processing = false;
            $('#place_order').prop('disabled', false);
            WOOTIFY_checkout_form.removeClass('processing').unblock();
        }
        if (event.data === 'wootify-loadedPaymentFormStripe') {
            window.loadedPaymentFormStripe = true;
            if (window.endpoint_stripe_frame_watch_timer) {
                clearTimeout(window.endpoint_stripe_frame_watch_timer);
            }
            $('.woocommerce-checkout-payment').unblock();
        }
        if (event.data === 'wootify-paymentFormCompletedStripe') {
            window.paymentFormCompletedStripe = true;
        }

        if (event.data === 'wootify-paymentFormFailStripe') {
            window.paymentFormCompletedStripe = false;
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-errorSubmitPaymentStripe') {
            // Clear timeout and reset processing flag
            if (window.endpoint_stripe_timeout) {
                clearTimeout(window.endpoint_stripe_timeout);
            }
            window.endpoint_stripe_processing = false;
            $('#place_order').prop('disabled', false);
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            checkout_error('We cannot process your payment right now [' + event.data.value + ']');
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeBodyResizeCreditForm') {
            $('#payment-stripe-area').attr('height', event.data.value + 30);
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paymentMethodIdStripe') {
            // Clear timeout and reset processing flag
            if (window.endpoint_stripe_timeout) {
                clearTimeout(window.endpoint_stripe_timeout);
            }
            window.endpoint_stripe_processing = false;
            $('#place_order').prop('disabled', false);
            
            var paymentMethodId = event.data.value;
            WOOTIFY_checkout_form.find('[name="wootify-stripe-payment-method-id"]').val(paymentMethodId);
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            if ($('#endpoint_stripe_pay_for_order_page').length) {
                $.ajax({
                    url: WOOTIFY_checkout_form.attr('action') || window.location.href,
                    type: WOOTIFY_checkout_form.attr('method'),
                    data: WOOTIFY_checkout_form.serialize(),
                    success: function (data) {
                        var res = JSON.parse(data);
                        window.location.hash = res.redirect;
                    },
                    error: function (jXHR, textStatus, errorThrown) {
                        alert(errorThrown);
                    }
                });
            } else {
                WOOTIFY_checkout_form.submit();
            }

            if (validateFormCheckout()) {
                loadPaymentProcess();
            }
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkReady') {
            $('#wootify-stripe-link-express-container').show();
            updateStripeLinkValidationOverlay();
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkUnavailable') {
            $('#wootify-stripe-link-express-container').hide();
            $('#wootify-stripe-link-validation-overlay').hide();
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkResize') {
            var linkHeight = parseInt(event.data.value, 10);
            if (linkHeight > 0) {
                $('#payment-stripe-link-area').attr('height', linkHeight + 8);
                $('#payment-stripe-link-area').css('height', (linkHeight + 8) + 'px');
                updateStripeLinkValidationOverlay();
            }
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkStart') {
            blockOnSubmit(WOOTIFY_checkout_form);
            WOOTIFY_checkout_form.addClass('processing');
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkCancel') {
            window.endpoint_stripe_processing = false;
            $('#place_order').prop('disabled', false);
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            $('#cs-stripe-loader').hide();
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkError') {
            window.endpoint_stripe_processing = false;
            $('#place_order').prop('disabled', false);
            $('#cs-stripe-loader').hide();
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            checkout_error(event.data.value || 'We cannot process your payment right now, please try another payment method.');
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkConfirmationToken') {
            handleStripeLinkConfirmationToken(event.data.value || {});
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkConfirmResult') {
            handleStripeLinkConfirmResult(event.data);
        }
        if (typeof event.data === 'object' && event.data.name === 'wootify-confirmPaymentIntentStripeReady') {
            console.log("Stripe iframe confirm is ready.");
            // Clear retry timer to prevent double-send
            if (window.cs_confirm_retry_timer) {
                clearTimeout(window.cs_confirm_retry_timer);
                window.cs_confirm_retry_timer = null;
            }
            window.cs_confirm_iframe_ready = true;
            trySendConfirm();
        }

        if (typeof event.data === 'object' && event.data.name === 'wootify-resultConfirmPaymentIntentStripe') {
            // Validate attempt token
            if (event.data.attempt_token && event.data.attempt_token !== window.cs_stripe_3ds_attempt_token) {
                console.warn("Stripe 3DS result attempt token mismatch. Expected: " + window.cs_stripe_3ds_attempt_token + ", Got: " + event.data.attempt_token);
                return;
            }

            if (event.data.value === 'success') {
                var orderId = window.endpoint_stripe_order_id;
                var attemptTok = window.cs_stripe_3ds_attempt_token || '';
                cleanupStripeConfirmFrame();
                loadPaymentProcess();
                window.location.href = '/?endpoint_stripe_return_result=1&order_id=' + orderId + '&attempt_token=' + attemptTok;
            } else {
                var error = event.data.error || { code: 'unknown', message: 'Unknown error' };
                var capturedOrderId = window.endpoint_stripe_order_id;
                var capturedAttempt = window.cs_stripe_3ds_attempt_token || 'none';
                cleanupStripeConfirmFrame();
                $.post('/?wc-ajax=ep_stripe_add_order_note', {
                    order_id: capturedOrderId,
                    note: 'Stripe checkout error! Error code: ' + error.code + ', Message: ' + error.message + ' (attempt: ' + capturedAttempt + ')',
                    security: ajax_object.cs_add_order_note_nonce
                }).done(function (result) {
                    console.log('done: ' + result);
                }).fail(function () {
                    console.log("Can't add order note");
                });
                $('#cs-stripe-loader').hide();
                checkout_error('We cannot process your payment right now, please try another payment method.[10]');
            }
        }
    }

    function getStripeLinkRequiredCheckoutFields() {
        return WOOTIFY_checkout_form.find('.validate-required:visible :input').filter(function () {
            var input = $(this);
            return input.is(':visible') && !input.is(':disabled') && input.attr('type') !== 'hidden';
        });
    }

    function validateFormCheckoutForStripeLink(showErrors) {
        var valid = true;
        var requiredFields = getStripeLinkRequiredCheckoutFields();
        requiredFields.each(function (i, input) {
            var field = $(input);
            if (showErrors) {
                field.trigger('validate').trigger('blur');
            } else {
                field.trigger('validate');
            }
            if (!checkFieldValidated(field)) {
                valid = false;
            }
        });
        return valid;
    }

    function updateStripeLinkValidationOverlay() {
        $('#wootify-stripe-link-validation-overlay').hide();
    }

    $(document).on('click', '#wootify-stripe-link-validation-overlay', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!validateFormCheckoutForStripeLink(true)) {
            checkout_error(STRIPE_LINK_REQUIRED_FIELDS_MESSAGE);
        }
        updateStripeLinkValidationOverlay();
    });

    WOOTIFY_checkout_form.on('input change blur', '.validate-required :input', function () {
        window.setTimeout(updateStripeLinkValidationOverlay, 0);
    });

    $(window).on('resize', function () {
        updateStripeLinkValidationOverlay();
    });

    function handleStripeLinkConfirmationToken(payload) {
        if (window.endpoint_stripe_processing) {
            return;
        }
        if (!payload.confirmation_token) {
            checkout_error('Payment confirmation token is missing. Please try again.');
            return;
        }

        // ── Autofill billing & shipping form fields from Stripe Link ─────────────
        // IMPORTANT: We must NOT call .trigger('change') on country or state fields.
        // Doing so causes WooCommerce to fire update_checkout → reloads checkout
        // fragments → the Stripe Link iframe is re-rendered with a new src → the
        // new iframe has no pendingConfirmationToken → confirmPayment never resolves
        // → the spinner loops forever.
        //
        // Strategy: set all text/phone/address fields silently via .val() only.
        // Country and state are injected directly into the POST payload via
        // csStripeLinkOverrideField() so the PHP create-order handler receives the
        // correct values even when the select dropdown hasn't reloaded yet.
        // ─────────────────────────────────────────────────────────────────────────
        var b = payload.billing_details || {};
        var s = payload.shipping_address || {};

        var bAddress = b.address || s.address || {};
        var sAddress = s.address || b.address || {};
        var bName = b.name || s.name || '';
        var sName = s.name || b.name || '';
        var bPhone = b.phone || s.phone || '';
        var sPhone = s.phone || b.phone || '';
        var bEmail = b.email || '';

        /**
         * Resolve a state value for the WooCommerce state <select> (UI only).
         * Stripe Link may return full names ("California") or codes ("CA").
         * We try option-text matching first, then fall back to the raw value.
         * No trigger('change') is fired to avoid unwanted WC reactions.
         *
         * Note: this function is for visual UI sync only. The authoritative
         * state value is injected directly into formData below.
         */
        function csStripeLinkSetStateSilent(selector, stateVal) {
            if (!stateVal) { return; }
            var $field = $(selector);
            if (!$field.length) { return; }
            if ($field.is('select')) {
                var norm = stateVal.trim().toLowerCase();
                var matched = false;
                $field.find('option').each(function () {
                    if ($(this).text().trim().toLowerCase() === norm) {
                        $field.val($(this).val()); // silent — no trigger('change')
                        matched = true;
                        return false;
                    }
                });
                if (!matched) {
                    $field.val(stateVal); // try as-is (may already be a code)
                }
            } else {
                $field.val(stateVal);
            }
        }

        /**
         * Override or append a field value in a serializeArray() result.
         * Used to inject country/state into the POST body without touching the
         * DOM in a way that would trigger WC checkout reloads.
         */
        function csStripeLinkOverrideField(data, name, value) {
            for (var i = 0; i < data.length; i++) {
                if (data[i].name === name) {
                    data[i].value = value;
                    return;
                }
            }
            data.push({ name: name, value: value });
        }

        // Billing — text fields (safe, no WC side-effects)
        if (bName) {
            var bNames = bName.trim().split(/\s+/).filter(Boolean);
            $('#billing_first_name').val(bNames[0] || '');
            // Single-word name: use same token as last_name (WC requires both fields).
            $('#billing_last_name').val(bNames.slice(1).join(' ') || bNames[0] || '');
        }
        if (bEmail) $('#billing_email').val(bEmail);
        if (bPhone) $('#billing_phone').val(bPhone);
        if (bAddress.line1) $('#billing_address_1').val(bAddress.line1);
        if (bAddress.line2) $('#billing_address_2').val(bAddress.line2);
        if (bAddress.city) $('#billing_city').val(bAddress.city);
        if (bAddress.postal_code) $('#billing_postcode').val(bAddress.postal_code);
        // Country/state: silent UI sync only — authoritative values go into formData
        if (bAddress.country) $('#billing_country').val(bAddress.country);
        if (bAddress.state) csStripeLinkSetStateSilent('#billing_state', bAddress.state);

        // Shipping — text fields
        if (sName) {
            var sNames = sName.trim().split(/\s+/).filter(Boolean);
            $('#shipping_first_name').val(sNames[0] || '');
            // Single-word name: fallback to same token.
            $('#shipping_last_name').val(sNames.slice(1).join(' ') || sNames[0] || '');
        }
        if (sPhone) $('#shipping_phone').val(sPhone);
        if (sAddress.line1) $('#shipping_address_1').val(sAddress.line1);
        if (sAddress.line2) $('#shipping_address_2').val(sAddress.line2);
        if (sAddress.city) $('#shipping_city').val(sAddress.city);
        if (sAddress.postal_code) $('#shipping_postcode').val(sAddress.postal_code);
        // Country/state: silent UI sync only
        if (sAddress.country) $('#shipping_country').val(sAddress.country);
        if (sAddress.state) csStripeLinkSetStateSilent('#shipping_state', sAddress.state);

        // Ship-to-different-address: inject into POST formData instead of
        // triggering DOM change (which would fire update_checkout and reload
        // the Stripe Link iframe, losing the pendingConfirmationToken).
        if (payload.shipping_address) {
            $('#ship-to-different-address-checkbox, #ship-to-different-address input').prop('checked', true);
        }

        window.endpoint_stripe_processing = true;
        blockOnSubmit(WOOTIFY_checkout_form);
        WOOTIFY_checkout_form.addClass('processing');
        $('#cs-stripe-loader').show();

        // Serialize the form then inject Link address values directly into the
        // POST payload.  This guarantees the PHP handler receives the correct
        // billing, shipping, and country/state codes even when the WC select
        // dropdown hasn't been reloaded yet (avoiding the update_checkout race).
        var formData = WOOTIFY_checkout_form.serializeArray();

        // Inject all billing address fields from Stripe Link data
        if (bName) {
            var bNames = bName.trim().split(/\s+/);
            csStripeLinkOverrideField(formData, 'billing_first_name', bNames[0] || '');
            csStripeLinkOverrideField(formData, 'billing_last_name', bNames.slice(1).join(' ') || bNames[0] || '');
        }
        if (bEmail) csStripeLinkOverrideField(formData, 'billing_email', bEmail);
        if (bPhone) csStripeLinkOverrideField(formData, 'billing_phone', bPhone);
        if (bAddress.line1) csStripeLinkOverrideField(formData, 'billing_address_1', bAddress.line1);
        if (bAddress.line2) csStripeLinkOverrideField(formData, 'billing_address_2', bAddress.line2);
        if (bAddress.city)  csStripeLinkOverrideField(formData, 'billing_city', bAddress.city);
        if (bAddress.postal_code) csStripeLinkOverrideField(formData, 'billing_postcode', bAddress.postal_code);
        if (bAddress.country) {
            csStripeLinkOverrideField(formData, 'billing_country', bAddress.country);
            // Always inject state when country is provided — clears any stale DOM state
            // from a previously selected country. WC validates state per-country locale,
            // so an empty state is accepted for countries that don't require it (HK, SG, IE…).
            csStripeLinkOverrideField(formData, 'billing_state', bAddress.state || '');
        } else if (bAddress.state) {
            csStripeLinkOverrideField(formData, 'billing_state', bAddress.state);
        }

        // Inject all shipping address fields from Stripe Link data
        // (fallback to billing if shipping not provided by Stripe Link)
        if (payload.shipping_address) {
            csStripeLinkOverrideField(formData, 'ship_to_different_address', '1');
            if (sName) {
                var sNames2 = sName.trim().split(/\s+/);
                csStripeLinkOverrideField(formData, 'shipping_first_name', sNames2[0] || '');
                csStripeLinkOverrideField(formData, 'shipping_last_name', sNames2.slice(1).join(' ') || sNames2[0] || '');
            }
            if (sPhone) csStripeLinkOverrideField(formData, 'shipping_phone', sPhone);
            csStripeLinkOverrideField(formData, 'shipping_address_1', sAddress.line1 || bAddress.line1 || '');
            csStripeLinkOverrideField(formData, 'shipping_address_2', sAddress.line2 || bAddress.line2 || '');
            csStripeLinkOverrideField(formData, 'shipping_city',      sAddress.city  || bAddress.city  || '');
            csStripeLinkOverrideField(formData, 'shipping_postcode',  sAddress.postal_code || bAddress.postal_code || '');
            var shippingCountry = sAddress.country || bAddress.country || '';
            if (shippingCountry) {
                csStripeLinkOverrideField(formData, 'shipping_country', shippingCountry);
                // Always inject state alongside country to clear any stale DOM state
                // value. Countries without state (HK, SG, IE…) pass WC locale validation
                // with an empty state field.
                var shippingState = sAddress.state || bAddress.state || '';
                csStripeLinkOverrideField(formData, 'shipping_state', shippingState);
            } else if (sAddress.state || bAddress.state) {
                csStripeLinkOverrideField(formData, 'shipping_state', sAddress.state || bAddress.state || '');
            }
        }

        formData.push({ name: 'wootify-stripe-link-create-woo-order', value: '1' });
        formData.push({ name: 'payment_method', value: 'endpoint_stripe' });
        formData.push({ name: 'confirmation_token', value: payload.confirmation_token });
        // Accept terms on behalf of user — they accepted by clicking Stripe Link.
        csStripeLinkOverrideField(formData, 'terms', '1');
        if (!formData.some(function (f) { return f.name === 'terms'; })) {
            formData.push({ name: 'terms', value: '1' });
        }

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $.param(formData),
            success: function (response) {
                var result = response;
                if (typeof response === 'string') {
                    try {
                        result = JSON.parse(response);
                    } catch (e) {
                        result = { success: false, data: { message: 'Invalid payment response.' } };
                    }
                }
                if (!result || !result.success || !result.data) {
                    window.endpoint_stripe_processing = false;
                    $('#cs-stripe-loader').hide();
                    WOOTIFY_checkout_form.removeClass('processing').unblock();
                    checkout_error((result && result.data && result.data.message) || 'We cannot process your payment right now, please try another payment method.');
                    return;
                }

                window.endpoint_stripe_order_id = result.data.order_id;
                window.cs_stripe_link_attempt_token = result.data.attempt_token;
                postStripeLinkMessage({
                    name: 'wootify-stripeLinkConfirmIntent',
                    value: {
                        client_secret: result.data.client_secret,
                        attempt_token: result.data.attempt_token,
                    }
                });
            },
            error: function () {
                window.endpoint_stripe_processing = false;
                $('#cs-stripe-loader').hide();
                WOOTIFY_checkout_form.removeClass('processing').unblock();
                checkout_error('We cannot process your payment right now, please try another payment method.');
            }
        });
    }

    function handleStripeLinkConfirmResult(data) {
        if (data.attempt_token && window.cs_stripe_link_attempt_token && data.attempt_token !== window.cs_stripe_link_attempt_token) {
            return;
        }
        if (data.value === 'success') {
            window.location.href = '/?endpoint_stripe_return_result=1&order_id=' + window.endpoint_stripe_order_id + '&attempt_token=' + (window.cs_stripe_link_attempt_token || '');
            return;
        }
        window.endpoint_stripe_processing = false;
        $('#cs-stripe-loader').hide();
        WOOTIFY_checkout_form.removeClass('processing').unblock();
        var error = data.error || {};
        checkout_error(error.message || 'We cannot process your payment right now, please try another payment method.');
    }

    function checkout_error(error_message) {
        // Reset processing state
        if (window.endpoint_stripe_timeout) {
            clearTimeout(window.endpoint_stripe_timeout);
        }
        window.endpoint_stripe_processing = false;
        // Re-enable Place Order button
        $('#place_order').prop('disabled', false);
        
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        WOOTIFY_checkout_form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
            '<ul class="woocommerce-error">' +
            '<li data-id="billing_last_name">' + error_message + '' +
            '</li>' +
            '</ul>' +
            '</div>'); // eslint-disable-line max-len
        WOOTIFY_checkout_form.removeClass('processing').unblock();
        WOOTIFY_checkout_form.find('.input-text, select, input:checkbox').trigger('validate').trigger('blur');
        var scrollElement = $('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');
        if (!scrollElement.length) {
            scrollElement = WOOTIFY_checkout_form;
        }
        $.scroll_to_notices(scrollElement);
        $(document.body).trigger('checkout_error', [error_message]);
    }

    function checkFieldValidated(target) {
        // If field doesn't exist, consider it valid (let WooCommerce handle validation)
        if (!target.length || target.is(':disabled') || !target.is(':visible')) {
            return true;
        }
        var formRow = target.closest('.form-row');
        if (!formRow.length) {
            return true;
        }
        var isNotInvalid = !formRow.hasClass('woocommerce-invalid');
        var isNotEmpty = true;
        if (formRow.hasClass('validate-required')) {
            if (target.is(':checkbox')) {
                isNotEmpty = target.is(':checked');
            } else if (target.is(':radio')) {
                isNotEmpty = !!target.closest('.form-row').find('input[type="radio"]:checked').length;
            } else {
                isNotEmpty = (typeof target.val() == 'string') ? $.trim(target.val()).length > 0 : target.val() !== null && typeof target.val() !== 'undefined';
            }
        }
        return isNotInvalid && isNotEmpty;
    }

    function validateFormCheckout() {
        // Basic validation - let WooCommerce do full validation after form submit
        var isStripeSelected = $('input[name="payment_method"]:checked').val() == 'endpoint_stripe';
        if (!isStripeSelected) {
            return false;
        }
        
        // Check required fields exist and are not empty
        var requiredFields = ['#billing_first_name', '#billing_last_name', '#billing_email'];
        for (var i = 0; i < requiredFields.length; i++) {
            if (!checkFieldValidated($(requiredFields[i]))) {
                return false;
            }
        }
        
        // Optional fields - just check they're not invalid if they exist
        var optionalFields = ['#billing_city', '#billing_country', '#billing_postcode', 
                             '#billing_address_1', '#billing_address_2', '#billing_phone'];
        for (var j = 0; j < optionalFields.length; j++) {
            var field = $(optionalFields[j]);
            if (field.length && field.closest('.form-row').hasClass('woocommerce-invalid')) {
                return false;
            }
        }
        
        return true;
    }

    window.addEventListener('hashchange', onHashChange);
    window.cs_confirm_iframe_ready = false;
    window.pending_confirm_secret = null;
    window.cs_stripe_3ds_attempt_token = null;
    window.cs_confirm_retry_count = 0;
    window.cs_confirm_retry_timer = null;
    scheduleStripeFrameWatch();
    $(document.body).on('updated_checkout', function () {
        setTimeout(scheduleStripeFrameWatch, 500);
    });

    // ── Dynamic confirm iframe helpers ────────────────────────────────────────

    function getStripeConfirmUrl() {
        var config = document.getElementById('endpoint-stripe-confirm-config');
        if (config && config.getAttribute('data-confirm-url')) {
            return config.getAttribute('data-confirm-url');
        }
        return window.endpoint_stripe_confirm_url || '';
    }

    function ensureStripeConfirmIframe() {
        var iframe = document.getElementById('payment-area-stripe-to-confirm');
        if (iframe) {
            return iframe;
        }

        var confirmUrl = getStripeConfirmUrl();
        if (!confirmUrl) {
            return null;
        }

        // Reset ready state for the new iframe
        window.cs_confirm_iframe_ready = false;

        iframe = document.createElement('iframe');
        iframe.id = 'payment-area-stripe-to-confirm';
        iframe.referrerPolicy = 'no-referrer';
        iframe.frameBorder = '0';
        iframe.style.cssText = 'width:100%;display:none;position:fixed;top:0;left:0;z-index:99999;height:100vh';
        // Append first, set src after — ensures contentWindow is available reliably
        document.body.appendChild(iframe);
        iframe.src = confirmUrl;

        return iframe;
    }

    function cleanupStripeConfirmFrame() {
        if (window.cs_confirm_retry_timer) {
            clearTimeout(window.cs_confirm_retry_timer);
            window.cs_confirm_retry_timer = null;
        }
        if (window.endpoint_stripe_3ds_timeout) {
            clearTimeout(window.endpoint_stripe_3ds_timeout);
            window.endpoint_stripe_3ds_timeout = null;
        }

        window.pending_confirm_secret = null;
        window.cs_confirm_retry_count = 0;
        window.cs_confirm_iframe_ready = false;

        var iframe = document.getElementById('payment-area-stripe-to-confirm');
        if (iframe && iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);
        }
    }

    function trySendConfirm() {
        if (!window.pending_confirm_secret) {
            return;
        }

        var iframe = document.getElementById('payment-area-stripe-to-confirm');
        if (!iframe || !iframe.contentWindow) {
            cleanupStripeConfirmFrame();
            checkout_error('Payment confirmation frame is not available. Please try again.');
            return;
        }

        if (window.cs_confirm_iframe_ready) {
            console.log('Sending wootify-requestConfirmPaymentIntentStripe to iframe, attempt: ' + window.cs_stripe_3ds_attempt_token);
            iframe.contentWindow.postMessage({
                name: 'wootify-requestConfirmPaymentIntentStripe',
                value: window.pending_confirm_secret,
                attempt_token: window.cs_stripe_3ds_attempt_token
            }, '*');
            return;
        }

        window.cs_confirm_retry_count = (window.cs_confirm_retry_count || 0) + 1;
        if (window.cs_confirm_retry_count > 20) {
            cleanupStripeConfirmFrame();
            checkout_error('Payment confirmation frame did not load. Please try again.');
            return;
        }

        console.log('Stripe confirm iframe not ready yet, retry ' + window.cs_confirm_retry_count + '/20...');
        window.cs_confirm_retry_timer = setTimeout(trySendConfirm, 500);
    }

    // ── Hash change handler ───────────────────────────────────────────────────

    function onHashChange() {
        var partials = window.location.hash.match(/^#?cs-confirm-pi-([^:]+):(.+):(.+)$/);
        if (!partials || 4 > partials.length) {
            return;
        }

        var intentClientSecret = partials[1];
        window.endpoint_stripe_order_id = partials[2];
        var attemptToken = partials[3];
        window.cs_stripe_3ds_attempt_token = attemptToken;
        window.cs_confirm_retry_count = 0;

        // Cleanup the URL
        window.location.hash = '';

        window.pending_confirm_secret = intentClientSecret;

        var iframe = ensureStripeConfirmIframe();
        if (!iframe) {
            cleanupStripeConfirmFrame();
            checkout_error('Payment confirmation frame could not be created. Please try again.');
            return;
        }

        $('#payment-area-stripe-to-confirm').show();

        // Clear existing 3DS timeout if any, then set fresh 5-minute timeout
        if (window.endpoint_stripe_3ds_timeout) {
            clearTimeout(window.endpoint_stripe_3ds_timeout);
        }
        window.endpoint_stripe_3ds_timeout = setTimeout(function () {
            if (window.pending_confirm_secret) {
                console.error('Stripe 3DS confirmation timed out');
                cleanupStripeConfirmFrame();
                checkout_error('Payment confirmation timed out. Please try again.');
            }
        }, 300000);

        trySendConfirm();
    }
    
    function stripeGetFormCheckoutVal(selector) {
        if ($(selector).val()) {
            return $(selector).val();
        }
        return null;
    }
});
