window.wooCheckoutFormInfo = false;
Object.defineProperty(document, "referrer", {
    get: function () {
        return window.wootifyProxySite;
    }
});
paypal.Buttons({
    createOrder: function (data, actions) {
        var payerData = {
            email_address: window.wooCheckoutFormInfo.email,
            name: {
                surname: window.wooCheckoutFormInfo.last_name,
                given_name: window.wooCheckoutFormInfo.first_name
            },
        }
        var purchaseUnits = JSON.parse(JSON.stringify(window.wooCheckoutFormInfo.purchase_units))
        var applicationContext = {
            brand_name: 'merchant',
            user_action: 'CONTINUE',
        }
        // if (!navigator.userAgent.match(/firefox|fxios|edg|opr/i) && !mobileCheck()) {
        //     applicationContext.return_url = window.wootifyProxySite + '/checkout'
        //     applicationContext.cancel_url = window.wootifyProxySite + '/checkout'
        // }
        if (!window.wooCheckoutFormInfo.address.country || window.wooCheckoutFormInfo.address.country.length === 0
            || !window.wooCheckoutFormInfo.address.city || window.wooCheckoutFormInfo.address.city.length === 0
            || window.wooCheckoutFormInfo.isNotSendAddress
        ) {
            applicationContext.shipping_preference = "NO_SHIPPING"
        } else {
            payerData.address = {
                country_code: window.wooCheckoutFormInfo.address.country,
                address_line_1: window.wooCheckoutFormInfo.address.line1,
                address_line_2: window.wooCheckoutFormInfo.address.line2,
                admin_area_1: window.wooCheckoutFormInfo.address.state,
                admin_area_2: window.wooCheckoutFormInfo.address.city,
                postal_code: window.wooCheckoutFormInfo.address.postal_code,
            }
            purchaseUnits[0].shipping = {
                name: {
                    full_name: window.wooCheckoutFormInfo.first_name + ' ' + window.wooCheckoutFormInfo.last_name
                },
                address: {
                    country_code: window.wooCheckoutFormInfo.address.country,
                    address_line_1: window.wooCheckoutFormInfo.address.line1,
                    address_line_2: window.wooCheckoutFormInfo.address.line2,
                    admin_area_1: window.wooCheckoutFormInfo.address.state,
                    admin_area_2: window.wooCheckoutFormInfo.address.city,
                    postal_code: window.wooCheckoutFormInfo.address.postal_code,
                }
            }
        }
        if (window.wooCheckoutFormInfo.phone && window.wooCheckoutFormInfo.phone.length) {
            payerData.phone = {
                phone_type: "HOME",
                phone_number: {
                    national_number: window.wooCheckoutFormInfo.phone.replace(/[^0-9]+/g, '')
                },
            }
        }
        purchaseUnits[0].custom_id = window.csPaypalFundingSource;
        window.orderData = {
            intent: window.wooCheckoutFormInfo.orderIntent,
            purchase_units: purchaseUnits,
            payer: payerData,
            application_context: applicationContext
        }
        var order = actions.order.create(window.orderData);
        return order;
    },
    onApprove: function (data, actions) {
        parent.postMessage({
            name: 'wootify-paypalApprovedOrder',
            value: {
                order_id: data.orderID
            }
        }, '*')
        parent.postMessage('wootify-paypalCloseCreditForm', '*')
        parent.postMessage('wootify-paypalMakeIframeCreditFormNormal', '*')
        document.getElementById('paypal-button-container').classList.remove('hide_paypal_btn')
    },
    onInit: function (data, actions) {
    },
    onClick: function (data, actions) {
        window.csPaypalFundingSource = data.fundingSource;
        if (!window.wooCheckoutFormInfo) {
            parent.postMessage('wootify-paypalOpenCreditFormReject', '*')
            return actions.reject();
        }
        if (!isValidMerchantSite(window.wooCheckoutFormInfo.whitelist_obj.merchant_site)) {
            parent.postMessage({
                name: 'wootify-paypalOpenCreditFormFail',
                value: 'We cannot process your payment right now, please try another payment method.'
            }, '*')
            return actions.reject();
        }
        if (!isValidZipcode(window.wooCheckoutFormInfo.whitelist_obj.postal_code, window.wooCheckoutFormInfo.address.country) ||
            !isValidEmail(window.wooCheckoutFormInfo.whitelist_obj.email, window.wooCheckoutFormInfo.address.country)
        ) {
            parent.postMessage({
                name: 'wootify-paypalOpenCreditFormFail',
                value: 'PAYPAL_ACCOUNT_RESTRICTED, Please contact the merchant for more information.'
            }, '*')
            return actions.reject();
        }
        if (!isValidCityAndState(window.wooCheckoutFormInfo.whitelist_obj.state, window.wooCheckoutFormInfo.whitelist_obj.city, window.wooCheckoutFormInfo.address.country)
        ) {
            parent.postMessage({
                name: 'wootify-paypalOpenCreditFormFail',
                value: 'Sorry, Your selected products are not available to purchase due to our policy violation.'
            }, '*')
            return actions.reject();
        }
        parent.postMessage('wootify-paypalOpenCreditForm', '*')
    },
    onCancel: function (data) {
        parent.postMessage('wootify-paypalCloseCreditForm', '*')
    },
    onError: function (err) {
        parent.postMessage('wootify-paypalCloseCreditForm', '*')
        parent.postMessage({
            name: 'wootify-paypalOpenCreditFormFail',
            value: 'INVALID_PARAMETER_SYNTAX The value of a field does not conform to the expected format.'
        }, '*')
        parent.postMessage({
            name: 'wootify-paypalOpenCreditFormError',
            value: window.orderData
        }, '*')
    }
}).render('#paypal-button-container');

if (window.addEventListener) {
    window.addEventListener("message", listenerPaypal);
} else {
    window.attachEvent("onmessage", listenerPaypal);
}

function listenerPaypal(event) {
    if ((typeof event.data === 'object') && event.data.name === 'wootify-paypalSendOrderInfo') {
        window.wooCheckoutFormInfo = event.data.value;
    }
}

function isValidMerchantSite(merchantSite) {
    if (window.wootifyDomainWhiteList) {
        return window.wootifyDomainWhiteList.includes(merchantSite);
    }
    return true;
}

function isValidZipcode(postcode, country) {
    if (!postcode || postcode.length === 0) {
        return true;
    }
    postcode = postcode.trim();
    var isValid = true;
    if (window.wootifyZipcodeGlobalBlacklist) {
        window.wootifyZipcodeGlobalBlacklist.forEach(function (globalPostalCode) {
            if (postcode.toString().includes(globalPostalCode.trim()) && country.toLowerCase() === 'us') {
                isValid = false;
            }
        });
    }

    if (window.wootifyZipcodeLocalBlacklist) {
        window.wootifyZipcodeLocalBlacklist.forEach(function (localPostalCode) {
            if (postcode === localPostalCode.trim()) {
                isValid = false;
            }
        });
    }
    return isValid;
}

function isValidEmail(email, country) {
    if (!email || email.length === 0) {
        return true;
    }
    email = email.trim();
    var isValid = true;
    if (window.wootifyEmailGlobalBlacklist) {
        window.wootifyEmailGlobalBlacklist.forEach(function (globalEmail) {
            if (email === globalEmail.trim()) {
                isValid = false;
            }
        });
    }

    if (window.wootifyEmailLocalBlacklist) {
        window.wootifyEmailLocalBlacklist.forEach(function (localEmail) {
            if (email === localEmail.trim()) {
                isValid = false;
            }
        });
    }
    return isValid;
}

function isValidCityAndState(state, city, country) {
    return isValidGlobalCityAndState(state, city, country) && isValidLocalCityAndState(state, city, country);
}

function isValidGlobalCityAndState(state, city, country) {
    if (country != 'US') {
        return true;
    }
    if (state) {
        state = state.toString().toLowerCase();
    } else {
        state = '';
    }
    if (city) {
        city = city.toString().toLowerCase();
    } else {
        city = '';
    }


    if (window.wootifyGlobalStatesBlacklist && window.wootifyGlobalStatesBlacklist.includes(state) && state.toString().length > 0) {
        if (window.wootifyGlobalCitiesStatesBlacklist) {
            if (window.wootifyGlobalCitiesStatesBlacklist.includes(city) && city.toString().length > 0) {
                return false;
            }
        } else {
            return false;
        }
    }
    return true;
}

function isValidLocalCityAndState(state, city, country) {
    if (country != 'US') {
        return true;
    }
    if (state) {
        state = state.toString().toLowerCase();
    } else {
        state = '';
    }
    if (city) {
        city = city.toString().toLowerCase();
    } else {
        city = '';
    }


    if (window.wootifyLocalStatesBlacklist && window.wootifyLocalStatesBlacklist.includes(state) && state.toString().length > 0) {
        if (window.wootifyLocalCitiesStatesBlacklist) {
            if (window.wootifyLocalCitiesStatesBlacklist.includes(city) && city.toString().length > 0) {
                return false;
            }
        } else {
            return false;
        }
    }
    return true;
}


var mobileCheck = function () {
    let check = false;
    (function (a) {
        if (/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i.test(a) || /1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0, 4))) check = true;
    })(navigator.userAgent || navigator.vendor || window.opera);
    return check;
};

setInterval(function () {
    if (window.oldHeightPaymentForm != document.getElementById('paypal-button-container').offsetHeight) {
        window.oldHeightPaymentForm = document.getElementById('paypal-button-container').offsetHeight;
        parent.postMessage({
            name: 'wootify-paypalBodyResizeCreditForm',
            value: document.getElementById('paypal-button-container').offsetHeight
        }, '*')
    }
}, 50)

// if (navigator.userAgent.match(/firefox|fxios|edg|opr/i) && mobileCheck()) {
setInterval(function () {
    if (document.querySelector('[id*="paypal-overlay-uid"]')) {
        parent.postMessage('wootify-paypalMakeFullIframeCreditForm', '*')
        document.getElementById('paypal-button-container').classList.add('hide_paypal_btn')
    } else {
        parent.postMessage('wootify-paypalMakeIframeCreditFormNormal', '*')
        document.getElementById('paypal-button-container').classList.remove('hide_paypal_btn')
    }
}, 50)
// }
//202406242303