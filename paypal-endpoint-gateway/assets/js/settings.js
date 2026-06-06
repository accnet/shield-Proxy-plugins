jQuery(function ($) {
    var $msgBox = $('#message-box');

    function showMsg(type, msg) {
        var alertClass = type === 'success' ? 'notice-success' : 'notice-error';
        $msgBox.removeClass('notice-success notice-error')
               .addClass('notice ' + alertClass)
               .html('<p>' + msg + '</p>')
               .show();
    }

    // Connect
    $('#form-connect').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#btn-connect').prop('disabled', true).text('Connecting...');
        $.post(EndpointPayPal.ajaxUrl, {
            action: 'endpoint_paypal_connect',
            nonce: EndpointPayPal.nonce,
            saas_url: $('#saas_url').val(),
            connection_code: $('#connection_code').val(),
        })
        .done(function (res) {
            if (res.success) {
                showMsg('success', res.data.message || 'Connected!');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showMsg('error', (res.data && res.data.message) || 'Connection failed');
                $btn.prop('disabled', false).text('Connect');
            }
        })
        .fail(function () {
            showMsg('error', 'Network error');
            $btn.prop('disabled', false).text('Connect');
        });
    });

    // Disconnect
    $('#btn-disconnect').on('click', function () {
        if (!confirm('Are you sure you want to disconnect?')) return;
        var $btn = $(this).prop('disabled', true).text('Disconnecting...');
        $.post(EndpointPayPal.ajaxUrl, {
            action: 'endpoint_paypal_disconnect',
            nonce: EndpointPayPal.nonce,
        })
        .done(function (res) {
            showMsg('success', 'Disconnected');
            setTimeout(function () { location.reload(); }, 800);
        })
        .fail(function () {
            showMsg('error', 'Network error');
            $btn.prop('disabled', false).text('Disconnect');
        });
    });

    // Pull Config
    $('#btn-pull-config').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var origText = $btn.html();
        $btn.text('Pulling...');
        $.post(EndpointPayPal.ajaxUrl, {
            action: 'endpoint_paypal_pull_config',
            nonce: EndpointPayPal.nonce,
        })
        .done(function (res) {
            showMsg('success', 'Config pulled successfully');
            setTimeout(function () { location.reload(); }, 800);
        })
        .fail(function () {
            showMsg('error', 'Pull config failed');
            $btn.prop('disabled', false).html(origText);
        });
    });

    // Set Active Node
    $('.btn-set-active').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var shieldId = $btn.data('shield-id');
        $.post(EndpointPayPal.ajaxUrl, {
            action: 'endpoint_paypal_set_active_node',
            nonce: EndpointPayPal.nonce,
            shield_id: shieldId,
        })
        .done(function (res) {
            if (res.success) {
                showMsg('success', 'Active node updated successfully');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showMsg('error', res.data.message || 'Failed to update active node');
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            showMsg('error', 'Network error');
            $btn.prop('disabled', false);
        });
    });
});
