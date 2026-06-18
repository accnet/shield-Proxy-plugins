jQuery(document).ready( function($)
{
    $(document).on('click', '#ep-sync-tracking-info-btn', function () {
        var syncCount = $('#ep-sync-count').val();
        if (parseInt(syncCount) === 0) {
            alert("Don't have unsynced orders");
            return;
        }
        $(this).addClass('activeLoading');

        var data = {
            'action': 'ep_paypal_woo_action',
            'command': 'ep_paypal_sync_tracking_info',
        };
        jQuery.ajaxSetup({timeout: 300000});
        jQuery.post(ep_paypal_ajax_object.ajax_url, data, function (response) {
            var responseJson = JSON.parse(response);
            $(this).removeClass('activeLoading');
            if (responseJson.success) {
                alert('Sync tracking info successfully!');
                location.reload();
            } else {
                alert(responseJson.error);
            }
        }).fail(function () {
            alert('Error when sync tracking info. Please try again after![2]');
        }).always(function () {
            $('#ep-sync-tracking-info-btn').removeClass('activeLoading');
        });
    });
});

