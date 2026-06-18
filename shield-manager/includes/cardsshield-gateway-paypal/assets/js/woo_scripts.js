jQuery(document).ready( function($)
{
    $(document).on('click', '#cs-sync-tracking-info-btn', function () {
        var syncCount = $('#cs-sync-count').val();
        if (parseInt(syncCount) === 0) {
            alert("Don't have unsynced orders");
            return;
        }
        $(this).addClass('activeLoading');

        var data = {
            'action': 'WOOTIFY_gateway_paypal_action',
            'command': 'syncTrackingInfo',
        };
        jQuery.ajaxSetup({timeout: 300000});
        jQuery.post(cs_paypal_ajax_object.ajax_url, data, function (response) {
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
            $('#cs-sync-tracking-info-btn').removeClass('activeLoading');
        });
    });
});

