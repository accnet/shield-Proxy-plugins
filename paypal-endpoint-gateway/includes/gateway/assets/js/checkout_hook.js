jQuery(document).ready(function ($) {
    if ($('#cs_pay_for_order_page').length) {
        var WOOTIFY_checkout_form = $('#order_review');
    } else {
        var WOOTIFY_checkout_form = $('form.checkout');
    }
    var OPT_CS_PAYPAL_SETTING_CHECKOUT = 'checkout';

    WOOTIFY_checkout_form.on('checkout_place_order', function () {
        if ($('input[name="payment_method"]:checked').val() === 'endpoint_paypal') {
            var paypalPaymentOrderIdEl = WOOTIFY_checkout_form.find('[name="wootify-paypal-payment-order-id"]');
            if (validateFormCheckoutPaypal() && paypalPaymentOrderIdEl && paypalPaymentOrderIdEl.val().length == 0) {
                csPaypalClientLog({
                    'note': 'can not submit case [name="wootify-paypal-payment-order-id"] not have data',
                    'email': wootifyGetUserField('email')
                });
                if(confirm('An error occurred. Please try again!')) {
                    location.reload();
                }
                return false;
            }
            setTimeout(function () {
                if (!window.endpoint_paypal_checkout_error) {
                    $('.blockUI').hide();
                    $('#cs-pp-loader').show();
                    setTimeout((function () {
                        $('#cs-pp-loader').hide();
                    }), 30000);
                }
            }, 1000)
        }
    });

    $(document).on('checkout_error', function () {
        if ($('input[name="payment_method"]:checked').val() == 'endpoint_paypal') {
            $('#cs-pp-loader').hide();
            $('#cs-pp-loader-credit').hide();
            window.endpoint_paypal_checkout_error = true;
        }
    })

    setInterval(function () {
        handleShowHidePaypalButton();
    }, 200) // fix monlesacx.com

    $('body').on('updated_checkout', function () {
        handleShowHidePaypalButton();
    });

    $(document).on('payment_method_selected', function () {
        handleShowHidePaypalButton();
    })

    if (window.addEventListener) {
        window.addEventListener("message", listenerPaypal);
    } else {
        window.attachEvent("onmessage", listenerPaypal);
    }

    function handleShowHidePaypalButton() {
        if ($('input[name="payment_method"]:checked').val() == 'endpoint_paypal' && $('#wootify-paypal-button-setting').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
            $('#wootify-paypal-credit-form-container').show();
            $('#place_order').addClass('important-hide')
        } else {
            $('#wootify-paypal-credit-form-container').hide();
            $('#place_order').removeClass('important-hide')
        }
    }

    function listenerPaypal(event) {
        if (event.data === "wootify-paypalRequestFromBlacklist") {
            setInterval(function () {
                $('#payment-paypal-area').remove();
                $('.cs_pp_element').remove();
                $('.wc_payment_method.payment_method_endpoint_paypal').hide();
            }, 100)
        }
        if (event.data === "wootify-paypalOpenCreditForm") {
            csPaypalClientLog({
                'note': 'wootify-paypalOpenCreditForm',
                'email': wootifyGetUserField('email')
            });
            $('#payment-paypal-area').attr('height', 400);
        }
        if (event.data === "wootify-paypalOpenCreditFormReject") {
            csPaypalClientLog({
                'note': 'wootify-paypalOpenCreditFormReject',
                'email': wootifyGetUserField('email')
            });
            WOOTIFY_checkout_form.submit();
        }
        if (event.data === "wootify-paypalCloseCreditForm") {
            $('#payment-paypal-area').attr('height', 120);
        }
        if (event.data === "wootify-paypalMakeFullIframeCreditForm") {
            $('#payment-paypal-area').addClass('full_screen_iframe_paypal_checkout')
        }
        if (event.data === "wootify-paypalMakeIframeCreditFormNormal") {
            $('#payment-paypal-area').removeClass('full_screen_iframe_paypal_checkout')
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalBodyResizeCreditForm') {
            if (event.data.value >= 130) {
                $('#payment-paypal-area').attr('height', event.data.value + 10);
            }
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalOpenCreditFormFail') {
            csPaypalClientLog({
                'note': 'wootify-paypalOpenCreditFormFail',
                'email': wootifyGetUserField('email')
            });
            checkout_error_paypal(event.data.value)
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalOpenCreditFormError') {
            $.ajax({
                url: '/?wootify-paypal-button-create-order=1',
                method: 'POST',
                data: {
                    'cs_order': event.data.value,
                    'current_proxy_id': $('#WOOTIFY_express_paypal_current_proxy_id').data('value'),
                    'current_proxy_url': $('#WOOTIFY_express_paypal_current_proxy_url').data('value')
                }
            })
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalApprovedOrder') {
            var orderId = event.data.value.order_id;
            csPaypalClientLog({
                'note': 'wootify-paypalApprovedOrder',
                'pp_order_id': orderId,
                'email': wootifyGetUserField('email')
            });
            WOOTIFY_checkout_form.find('[name="wootify-paypal-payment-order-id"]').val(orderId);
            WOOTIFY_checkout_form.removeClass('processing').unblock();
            WOOTIFY_checkout_form.submit();
            if (validateFormCheckoutPaypal()) {
                setTimeout(function () {
                    if (!window.endpoint_paypal_checkout_error) {
                        $('.blockUI').hide();
                        $('#cs-pp-loader-credit').show();
                        setTimeout((function () {
                            $('#cs-pp-loader-credit').hide();
                        }), 30000);
                    }
                }, 1000)
            }
        }
    }

    if ($('#WOOTIFY_enable_paypal_card_payment').length) {
        setInterval(function () {
            if ($('input[name="payment_method"]:checked').val() == 'endpoint_paypal'
                && $('#wootify-paypal-button-setting').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT
                && $('#payment-paypal-area')[0]) {
                if (validateFormCheckoutPaypal()) {
                    var whitelistPostalCode = null;
                    var whitelistEmail = null;
                    var whitelistState = null;
                    var whitelistCity = null;
                    if (typeof $('#billing_postcode').val() === 'string' && $('#billing_postcode').val().trim().length > 0) {
                        whitelistPostalCode = Sha1.hash($('#billing_postcode').val())
                    }
                    if (typeof $('#billing_email').val() === 'string' && $('#billing_email').val().trim().length > 0) {
                        whitelistEmail = Sha1.hash($('#billing_email').val())
                    }
                    if (typeof $('#billing_state').val() === 'string' && $('#billing_state').val().trim().length > 0) {
                        whitelistState = Sha1.hash($('#billing_state').val().toLowerCase())
                    }
                    if (typeof $('#billing_city').val() === 'string' && $('#billing_city').val().trim().length > 0) {
                        whitelistCity = Sha1.hash($('#billing_city').val().toLowerCase())
                    }
                    $('#payment-paypal-area')[0].contentWindow.postMessage({
                        name: 'wootify-paypalSendOrderInfo',
                        value: {
                            whitelist_obj: {
                                merchant_site: Sha1.hash($('#WOOTIFY_merchant_site_url').data('value')),
                                postal_code: whitelistPostalCode,
                                email: whitelistEmail,
                                state: whitelistState,
                                city: whitelistCity,
                            },
                            isNotSendAddress: $('#cs_not_send_bill_address_to_paypal').length,
                            purchase_units: window.endpoint_paypal_checkout_purchase_units,
                            orderIntent: $('#wootify-paypal-order-intent').data('value'),
                            last_name: wootifyGetUserField('last_name'),
                            first_name: wootifyGetUserField('first_name'),
                            email: wootifyGetUserField('email'),
                            address: {
                                city: wootifyGetUserField('city'),
                                country: wootifyGetUserField('country'),
                                line1: wootifyGetUserField('address_1'),
                                line2: wootifyGetUserField('address_2'),
                                postal_code: wootifyGetUserField('postcode'),
                                state: wootifyGetUserField('state'),
                            },
                            phone: wootifyGetUserField('phone'),
                        }
                    }, '*')
                } else {
                    $('#payment-paypal-area')[0].contentWindow.postMessage({
                        name: 'wootify-paypalSendOrderInfo',
                        value: null
                    }, '*')
                }
            }
        }, 100);
    }

    function checkFieldValidatedPaypal(target) {
        var isNotInvalid = !target.closest('.form-row').hasClass('woocommerce-invalid');
        var isNotEmpty = true;
        if (target.closest('.form-row').hasClass('validate-required')) {
            isNotEmpty = (typeof target.val() == 'string') ? target.val().length : false;
        }
        return isNotInvalid && isNotEmpty;
    }

    function validateFormCheckoutPaypal() {
        var requiredFields = $('form.woocommerce-checkout .validate-required:visible :input');
        requiredFields.each((i, input) => {
            $(input).trigger('validate');
        });
        return (checkFieldValidatedPaypal($('#billing_first_name'))) &&
            (checkFieldValidatedPaypal($('#billing_last_name'))) &&
            (checkFieldValidatedPaypal($('#billing_email'))) &&
            ($('#shipping_city').val() && $('#shipping_city').val().toString().length || checkFieldValidatedPaypal($('#billing_city'))) &&
            (checkFieldValidatedPaypal($('#billing_country'))) &&
            ($('#shipping_postcode').val() && $('#shipping_postcode').val().toString().length || checkFieldValidatedPaypal($('#billing_postcode'))) &&
            (checkFieldValidatedPaypal($('#billing_state'))) &&
            (checkFieldValidatedPaypal($('#billing_address_1'))) &&
            (checkFieldValidatedPaypal($('#billing_address_2'))) &&
            (checkFieldValidatedPaypal($('#billing_phone')));
    }

    function checkout_error_paypal(error_message) {
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

    function wootifyGetUserField(fieldName) {
        if ($('#billing_' + fieldName).val() && $('#billing_' + fieldName).val().length > 0) {
            return $('#billing_' + fieldName).val();
        }
        return $('#shipping_' + fieldName).val()
    }
    
    function csPaypalClientLog(data) {
        $.ajax({
            url: '/?wootify-paypal-note-debug=1&' + $.param(data),
            method: 'GET',
        })
    }
});


