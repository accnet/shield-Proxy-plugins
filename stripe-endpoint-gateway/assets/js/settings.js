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
        $.post(EndpointStripe.ajaxUrl, {
            action: 'endpoint_stripe_connect',
            nonce: EndpointStripe.nonce,
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
        $.post(EndpointStripe.ajaxUrl, {
            action: 'endpoint_stripe_disconnect',
            nonce: EndpointStripe.nonce,
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
        $.post(EndpointStripe.ajaxUrl, {
            action: 'endpoint_stripe_pull_config',
            nonce: EndpointStripe.nonce,
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
        $.post(EndpointStripe.ajaxUrl, {
            action: 'endpoint_stripe_set_active_node',
            nonce: EndpointStripe.nonce,
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

    // Reconnect (after being kicked)
    $('#btn-reconnect').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Reconnecting...');
        $.post(EndpointStripe.ajaxUrl, {
            action: 'endpoint_stripe_reconnect',
            nonce: EndpointStripe.nonce,
        })
        .done(function (res) {
            if (res.success) {
                showMsg('success', res.data.message || 'Reconnected!');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showMsg('error', (res.data && res.data.message) || 'Reconnect failed');
                $btn.prop('disabled', false).text('🔄 Reconnect Now');
            }
        })
        .fail(function () {
            showMsg('error', 'Network error');
            $btn.prop('disabled', false).text('🔄 Reconnect Now');
        });
    });

    // Clear Credentials
    $('#btn-clear-credentials').on('click', function () {
        if (!confirm('Clear saved credentials? You will need to enter new ones to reconnect.')) return;
        $.post(EndpointStripe.ajaxUrl, {
            action: 'endpoint_stripe_clear_credentials',
            nonce: EndpointStripe.nonce,
        })
        .done(function () {
            location.reload();
        })
        .fail(function () {
            showMsg('error', 'Failed to clear credentials');
        });
    });
});
