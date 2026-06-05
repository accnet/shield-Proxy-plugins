jQuery(document).ready(function ($) {
    var OPT_CS_PAYPAL_SETTING_CHECKOUT = 'PAYPAL_CHECKOUT';

    if (window.addEventListener) {
        window.addEventListener("message", listenerPaypalCustom);
    } else {
        window.attachEvent("onmessage", listenerPaypalCustom);
    }

    function listenerPaypalCustom(event) {
        if (event.data === "wootify-paypalOpenCreditForm-custom") {
            $('#payment-paypal-area-custom').attr('height', 400);
            if ($('#wootify-paypal-button-setting-context').data('value') === 'product_page') {
                window.WOOTIFY_paypal_custom_checkout_purchase_units = undefined;
                if (isProductPageAndHasWootifyOptions()) {
                    if (isWootifyOptionsValidated()) {
                        handleAddtoCartAndGetPurchaseUnits();
                    }
                } else {
                    resetCartAndGetPurchaseUnits();
                }
            } else {
                window.WOOTIFY_paypal_custom_checkout_purchase_units = undefined;
                var order_id = null;
                if($('#cs_pay_for_order_page').length) {
                    order_id = $('#cs_pay_for_order_page').data('value')
                }
                $.ajax({
                    url: '/',
                    method: 'POST',
                    data: {
                        'wootify-paypal-button-calculate-to-get-purchase-units': 1,
                        'order_id': order_id 
                    },
                    success: function (res) {
                        window.WOOTIFY_paypal_custom_checkout_purchase_units = JSON.parse(res)
                    }
                })
            }
        }
        if (event.data === "wootify-paypalRequestFromBlacklist") {
            $('#payment-paypal-area-custom').remove();
            $('.cs_pp_element').remove();
        }
        if (event.data === "wootify-paypalOpenCreditFormReject-custom") {
            $('form.checkout').submit();
        }
        if (event.data === "wootify-paypalCloseCreditForm-custom") {
            $('#payment-paypal-area-custom').attr('height', 120);
        }
        if (event.data === "wootify-paypalMakeFullIframeCreditForm-custom") {
            $('#payment-paypal-area-custom').addClass('full_screen_iframe_paypal_checkout')
        }
        if (event.data === "wootify-paypalMakeIframeCreditFormNormal-custom") {
            $('#payment-paypal-area-custom').removeClass('full_screen_iframe_paypal_checkout')
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalBodyResizeCreditForm-custom') {
            if (event.data.value >= 20) {
                $('#payment-paypal-area-custom').attr('height',
                    event.data.value +
                    ($('#wootify-paypal-button-setting-context').data('value') === 'express_checkout_page' ? 20 : 20));
            }
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalOpenCreditFormError-custom') {
            $.ajax({
                url: '/?wootify-paypal-button-create-order=1',
                method: 'POST',
                data: {
                    'cs_order': event.data.value,
                    'current_proxy_id': $('#WOOTIFY_express_paypal_current_proxy_id').data('value'),
                    'current_proxy_url': $('#WOOTIFY_express_paypal_current_proxy_url').data('value'),
                }
            })
        }
        if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalApprovedOrder-custom') {
            $('.blockUI').hide();
            $('#cs-pp-loader-credit-custom').show();
            setTimeout((function () {
                $('#cs-pp-loader-credit-custom').hide();
            }), 30000);
            var order_id = null;
            if($('#cs_pay_for_order_page').length) {
                order_id = $('#cs_pay_for_order_page').data('value')
            }
            $.ajax({
                url: '/',
                method: 'POST',
                data: {
                    'pp_order_id': event.data.value.order_id,
                    'order_id': order_id,
                    'wootify-paypal-button-create-woo-order': 1,
                    'current_proxy_id': $('#WOOTIFY_express_paypal_current_proxy_id').data('value'),
                    'current_proxy_url': $('#WOOTIFY_express_paypal_current_proxy_url').data('value')
                },
                success: function (res) {
                    res = JSON.parse(res)
                    if (res && res.result === 'success') {
                        window.location.replace(res.redirect)
                    } else {
                        checkout_error_wc(res.message)
                        $('#cs-pp-loader-credit-custom').hide();
                    }
                },
                error: function () {
                    checkout_error_wc('We cannot process your PayPal payment now, please try again with another method.')
                    $('#cs-pp-loader-credit-custom').hide();
                }
            })
        }
    }

    if ($('#wootify-paypal-button-setting-custom').length || $('#payment-paypal-area-custom').length) {
        setInterval(function () {
            if ($('#wootify-paypal-button-setting-custom').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT && $('#payment-paypal-area-custom')[0]) {
                $('#payment-paypal-area-custom')[0].contentWindow.postMessage({
                    name: 'wootify-paypalSendOrderInfo-custom',
                    value: {
                        whitelist_obj: {
                            merchant_site: Sha1.hash($('#WOOTIFY_merchant_site_url').data('value')),
                        },
                        purchase_units: window.WOOTIFY_paypal_custom_checkout_purchase_units,
                        orderIntent: $('#wootify-paypal-order-intent-custom').data('value'),
                        shipping_preference: $('#WOOTIFY_express_paypal_shipping_preference').data('value'),
                    }
                }, '*')
            }
            if (isProductPageAndHasVariations()) {
                if (getVariations().length) {
                    $('#wootify-paypal-credit-form-container-custom').show();
                } else {
                    $('#wootify-paypal-credit-form-container-custom').hide();
                }
            }
			if (isProductPageAndHasWootifyOptions()) {
				if (isWootifyOptionsValidated()) {
					$('#wootify-paypal-credit-form-container-custom').show();
				} else {
					$('#wootify-paypal-credit-form-container-custom').hide();
				}
			}
        }, 100);
    }

    function getVariations() {
        var variations = []
        var dataSelections = $('.variations_form').find('[name^="attribute_"]');
        if (dataSelections) {
            dataSelections.each(function () {
                if ($(this).val() && $(this).val().toString().length) {
                    variations.push({
                        name: $(this).attr('name'),
                        value: $(this).val(),
                    })
                } else {
                    variations = [];
                    return false;
                }
            })
        }
        return variations;
    }
	
    function isProductPageAndHasVariations() {
        return $('#wootify-paypal-product-page-has-variations') &&
            $('#wootify-paypal-product-page-has-variations').data('value') === 'yes';
    }

    function getFormSelectionInputs() {
        var form = $('.cart');
        if (!form.length) {
            return $();
        }
        return form.find(':input').filter(function () {
            var inputEl = $(this);
            if (inputEl.attr('type') === 'hidden') {
                return false;
            }
            return inputEl.is('select') || inputEl.is(':radio') || inputEl.is(':checkbox') ||
                inputEl.is('input[type="text"], input[type="number"], input[type="email"], textarea');
        });
    }

    function isProductPageAndHasWootifyOptions() {
        return getFormSelectionInputs().length > 0;
    }
	
    function getWcpaInputs() {
        var form = $('.cart');
        var inputs = form.find('.wcpa_form_item :input');
        if (!inputs.length) {
            inputs = $('.wcpa_form_item :input');
        }
        if (!inputs.length) {
            inputs = $('[name^="wcpa"], [name*="wcpa_"] , [name*="wcpa["]');
        }
        return inputs;
    }


    function checkout_error_wc(msg) {
        var container = $('.woocommerce-notices-wrapper').first();
        if (container) {
            $('.woocommerce-error').remove();
            container.append('<ul class="woocommerce-error" role="alert">' +
                '<li>' +
                msg +
                '</li>' +
                '</ul>')
            $([document.documentElement, document.body]).animate({
                scrollTop: container.offset().top - 100
            }, 1000);
        }
    }
	
    function handleAddtoCartAndGetPurchaseUnits() {
		$.ajax({
			url: '/',
			method: 'POST',
			data: {
				'wootify-paypal-button-reset-carts': 1,
			},
			success: function (res) {
                var form = $('.cart');
                var submitButton = form.find('button[type="submit"]');
                var submitName = submitButton.attr('name');
                var submitValue = submitButton.attr('value');
                if (submitName && !form.find('input[type="hidden"][name="' + submitName + '"]').length) {
                    form.append(
                        $("<input type='hidden'>").attr({
                            name: submitName,
                            value: submitValue
                        })
                    );
                }
                var extraInputs = getWcpaInputs().filter(function () {
                    return form[0] ? !$.contains(form[0], this) : true;
                });
                var payload = form.serialize();
                var extraPayload = extraInputs.length ? extraInputs.serialize() : '';
                if (extraPayload && payload) {
                    payload += '&' + extraPayload;
                } else if (extraPayload) {
                    payload = extraPayload;
                }
				$.ajax({
					url : $('.cart').attr('action') || window.location.pathname,
					type: $('.cart').attr('method'),
                    data: payload,
					success: function (data) {
						$.ajax({
							url: '/',
							method: 'POST',
							data: {
								'wootify-paypal-button-calculate-to-get-purchase-units': 1,
							},
							success: function (res) {
								window.WOOTIFY_paypal_custom_checkout_purchase_units = JSON.parse(res)
							}
						})
					},
					error: function (jXHR, textStatus, errorThrown) {
						alert(errorThrown);
					}
				});
			}
		})
	}

    function isWootifyOptionsValidated() {
        var isValidated = true;
        var inputs = getFormSelectionInputs();
        if (!inputs.length) {
            return true;
        }
        inputs.each(function () {
            var inputEl = $(this);
            var isRequired = inputEl.is('[required]') || inputEl.data('required') == 1 || inputEl.hasClass('required');
            if (!isRequired) {
                return;
            }
            if (inputEl.attr('type') === 'radio') {
                var name = inputEl.attr('name');
                if (name && !$('[name="' + name + '"]:checked').length) {
                    isValidated = false;
                    return false;
                }
            }
            if (inputEl.attr('type') === 'checkbox') {
                if (!inputEl.is(':checked')) {
                    isValidated = false;
                    return false;
                }
            }
            if (inputEl.is('select')) {
                if (!inputEl.val() || !inputEl.val().toString().length) {
                    isValidated = false;
                    return false;
                }
            }
            if (inputEl.is('input[type="text"], textarea, input[type="number"], input[type="email"]')) {
                if (!inputEl.val() || !inputEl.val().toString().length) {
                    isValidated = false;
                    return false;
                }
            }
        });
        return isValidated;
    }
    
    function resetCartAndGetPurchaseUnits() {
        $.ajax({
            url: '/',
            method: 'POST',
            data: {
                'wootify-paypal-button-reset-carts-and-get-purchase-units': 1,
                'product_id': $('#wootify-paypal-product-page-current-id').data('value'),
                'quantity': $('input[name="quantity"]').val(),
                'variations': getVariations()
            },
            success: function (res) {
                window.WOOTIFY_paypal_custom_checkout_purchase_units = JSON.parse(res)
            }
        })	
    }
});



