jQuery(function ($) {
    if ($('#WOOTIFY_stripe_pay_for_order_page').length) {
        var WOOTIFY_checkout_form = $('#order_review');
    } else {
        var WOOTIFY_checkout_form = $('form.checkout');
    }

    function loadPaymentProcess() {
        setTimeout(function () {
            if (!window.WOOTIFY_stripe_checkout_error) {
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
        if (window.WOOTIFY_stripe_frame_watch_src === iframeSrc && window.loadedPaymentFormStripe) {
            return;
        }

        window.WOOTIFY_stripe_frame_watch_src = iframeSrc;
        window.loadedPaymentFormStripe = false;
        if (window.WOOTIFY_stripe_frame_watch_timer) {
            clearTimeout(window.WOOTIFY_stripe_frame_watch_timer);
        }

        window.WOOTIFY_stripe_frame_watch_timer = setTimeout(function () {
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
        if ($('input[name="payment_method"]:checked').val() == 'WOOTIFY_stripe') {
            $('#cs-stripe-loader').hide();
            window.WOOTIFY_stripe_checkout_error = true;
            // Reset processing state when checkout has error
            if (window.WOOTIFY_stripe_timeout) {
                clearTimeout(window.WOOTIFY_stripe_timeout);
            }
            window.WOOTIFY_stripe_processing = false;
        }
    })
    $('body').on('click', '#place_order', function (e) {
        if ($('input[name="payment_method"]:checked').val() == 'WOOTIFY_stripe') {
            window.WOOTIFY_stripe_checkout_error = false;
            e.preventDefault();
            
            // Prevent double click
            if (window.WOOTIFY_stripe_processing) {
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
                window.WOOTIFY_stripe_processing = true;
                blockOnSubmit(WOOTIFY_checkout_form);
                WOOTIFY_checkout_form.addClass('processing');
                
                // Set timeout to unblock if no response from iframe after 15 seconds
                window.WOOTIFY_stripe_timeout = setTimeout(function() {
                    if (window.WOOTIFY_stripe_processing) {
                        console.error('Stripe iframe did not respond in time');
                        window.WOOTIFY_stripe_processing = false;
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
        return $('input[name="payment_method"]:checked').val() == 'WOOTIFY_stripe';
    }
    if(WOOTIFY_checkout_form.find('[name="wootify-stripe-payment-method-id"]').length) {
            $(document.body).on('updated_checkout', function (data) {
            if (!window.loadedPaymentFormStripe && $('input[name="payment_method"]:checked').val() == 'WOOTIFY_stripe') {
            $('.woocommerce-checkout-payment').block({
                    message: null,
                    overlayCSS: {
                        background: '#fff',
                        opacity: 0.6
                    }
                });
            }
            scheduleStripeFrameWatch();
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

    function listener(event) {
        if (event.data === "wootify-startSubmitPaymentStripe") {
            // Clear timeout since iframe responded
            if (window.WOOTIFY_stripe_timeout) {
                clearTimeout(window.WOOTIFY_stripe_timeout);
            }
            blockOnSubmit(WOOTIFY_checkout_form);
            WOOTIFY_checkout_form.addClass('processing')
        }
        if (event.data === "wootify-endSubmitPaymentStripe") {
            // Clear timeout and reset processing flag
            if (window.WOOTIFY_stripe_timeout) {
                clearTimeout(window.WOOTIFY_stripe_timeout);
            }
            window.WOOTIFY_stripe_processing = false;
            WOOTIFY_checkout_form.removeClass('processing').unblock();
        }
        if (event.data === 'wootify-loadedPaymentFormStripe') {
            window.loadedPaymentFormStripe = true;
            if (window.WOOTIFY_stripe_frame_watch_timer) {
                clearTimeout(window.WOOTIFY_stripe_frame_watch_timer);
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
            if (window.WOOTIFY_stripe_timeout) {
                clearTimeout(window.WOOTIFY_stripe_timeout);
            }
            window.WOOTIFY_stripe_processing = false;
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            checkout_error('We cannot process your payment right now [' + event.data.value + ']');
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeBodyResizeCreditForm') {
            $('#payment-stripe-area').attr('height', event.data.value + 30);
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paymentMethodIdStripe') {
            // Clear timeout and reset processing flag
            if (window.WOOTIFY_stripe_timeout) {
                clearTimeout(window.WOOTIFY_stripe_timeout);
            }
            window.WOOTIFY_stripe_processing = false;
            
            var paymentMethodId = event.data.value;
            WOOTIFY_checkout_form.find('[name="wootify-stripe-payment-method-id"]').val(paymentMethodId);
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            if ($('#WOOTIFY_stripe_pay_for_order_page').length) {
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
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkUnavailable') {
            $('#wootify-stripe-link-express-container').hide();
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkResize') {
            var linkHeight = parseInt(event.data.value, 10);
            if (linkHeight > 0) {
                $('#payment-stripe-link-area').attr('height', linkHeight + 8);
                $('#payment-stripe-link-area').css('height', (linkHeight + 8) + 'px');
            }
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkStart') {
            blockOnSubmit(WOOTIFY_checkout_form);
            WOOTIFY_checkout_form.addClass('processing');
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkCancel') {
            window.WOOTIFY_stripe_processing = false;
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            $('#cs-stripe-loader').hide();
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-stripeLinkError') {
            window.WOOTIFY_stripe_processing = false;
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
                var orderId = window.WOOTIFY_stripe_order_id;
                var attemptTok = window.cs_stripe_3ds_attempt_token || '';
                cleanupStripeConfirmFrame();
                loadPaymentProcess();
                window.location.href = '/?WOOTIFY_stripe_return_result=1&order_id=' + orderId + '&attempt_token=' + attemptTok;
            } else {
                var error = event.data.error || { code: 'unknown', message: 'Unknown error' };
                var capturedOrderId = window.WOOTIFY_stripe_order_id;
                var capturedAttempt = window.cs_stripe_3ds_attempt_token || 'none';
                cleanupStripeConfirmFrame();
                $.post('/?wc-ajax=cs_add_order_note', {
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

    function validateFormCheckoutForStripeLink() {
        var requiredFields = $('form.woocommerce-checkout .validate-required:visible :input');
        requiredFields.each((i, input) => {
            $(input).trigger('validate');
        });
        var fields = ['#billing_first_name', '#billing_last_name', '#billing_email'];
        for (var i = 0; i < fields.length; i++) {
            if (!checkFieldValidated($(fields[i]))) {
                return false;
            }
        }
        return true;
    }

    function handleStripeLinkConfirmationToken(payload) {
        if (window.WOOTIFY_stripe_processing) {
            return;
        }
        if (!payload.confirmation_token) {
            checkout_error('Payment confirmation token is missing. Please try again.');
            return;
        }
        if (!validateFormCheckoutForStripeLink()) {
            checkout_error('Please fill in the required checkout fields before using Link.');
            return;
        }

        window.WOOTIFY_stripe_processing = true;
        blockOnSubmit(WOOTIFY_checkout_form);
        WOOTIFY_checkout_form.addClass('processing');
        $('#cs-stripe-loader').show();

        var formData = WOOTIFY_checkout_form.serializeArray();
        formData.push({ name: 'wootify-stripe-link-create-woo-order', value: '1' });
        formData.push({ name: 'payment_method', value: 'WOOTIFY_stripe' });
        formData.push({ name: 'confirmation_token', value: payload.confirmation_token });

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
                    window.WOOTIFY_stripe_processing = false;
                    $('#cs-stripe-loader').hide();
                    WOOTIFY_checkout_form.removeClass('processing').unblock();
                    checkout_error((result && result.data && result.data.message) || 'We cannot process your payment right now, please try another payment method.');
                    return;
                }

                window.WOOTIFY_stripe_order_id = result.data.order_id;
                window.cs_stripe_link_attempt_token = result.data.attempt_token;
                $('#payment-stripe-link-area')[0].contentWindow.postMessage({
                    name: 'wootify-stripeLinkConfirmIntent',
                    value: {
                        client_secret: result.data.client_secret,
                        attempt_token: result.data.attempt_token,
                    }
                }, '*');
            },
            error: function () {
                window.WOOTIFY_stripe_processing = false;
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
            window.location.href = '/?WOOTIFY_stripe_return_result=1&order_id=' + window.WOOTIFY_stripe_order_id + '&attempt_token=' + (window.cs_stripe_link_attempt_token || '');
            return;
        }
        window.WOOTIFY_stripe_processing = false;
        $('#cs-stripe-loader').hide();
        WOOTIFY_checkout_form.removeClass('processing').unblock();
        var error = data.error || {};
        checkout_error(error.message || 'We cannot process your payment right now, please try another payment method.');
    }

    function checkout_error(error_message) {
        // Reset processing state
        if (window.WOOTIFY_stripe_timeout) {
            clearTimeout(window.WOOTIFY_stripe_timeout);
        }
        window.WOOTIFY_stripe_processing = false;
        
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
        if (!target.length) {
            return true;
        }
        var formRow = target.closest('.form-row');
        if (!formRow.length) {
            return true;
        }
        var isNotInvalid = !formRow.hasClass('woocommerce-invalid');
        var isNotEmpty = true;
        if (formRow.hasClass('validate-required')) {
            isNotEmpty = (typeof target.val() == 'string') ? target.val().length > 0 : false;
        }
        return isNotInvalid && isNotEmpty;
    }

    function validateFormCheckout() {
        // Basic validation - let WooCommerce do full validation after form submit
        var isStripeSelected = $('input[name="payment_method"]:checked').val() == 'WOOTIFY_stripe';
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
        if (window.WOOTIFY_stripe_3ds_timeout) {
            clearTimeout(window.WOOTIFY_stripe_3ds_timeout);
            window.WOOTIFY_stripe_3ds_timeout = null;
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
        window.WOOTIFY_stripe_order_id = partials[2];
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
        if (window.WOOTIFY_stripe_3ds_timeout) {
            clearTimeout(window.WOOTIFY_stripe_3ds_timeout);
        }
        window.WOOTIFY_stripe_3ds_timeout = setTimeout(function () {
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
