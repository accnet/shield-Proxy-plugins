/* global ShieldSites, Swal */
jQuery(function ($) {
    const { nonce, ajax_url } = ShieldSites;
    function ajaxPost(data, done) { $.post(ajax_url, Object.assign({ nonce }, data), done); }
    function showConfirm(message) {
        return Swal.fire({ title: 'Are you sure?', html: message, type: 'warning',
                           showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#3085d6', confirmButtonText: 'Yes!' });
    }
    function showSuccess(msg) { Swal.fire('Done!', msg, 'success'); }
    function showError(msg)   { Swal.fire('Error', msg, 'error'); }
    const STATUS_CLASSES = { active:'bg-success', offline:'bg-danger', pending:'bg-warning text-dark', disabled:'bg-secondary' };
    const SYNC_CLASSES   = { synced:'bg-success', pending:'bg-warning text-dark', failed:'bg-danger' };
    function statusBadge(status, map) {
        const cls = map[status] || 'bg-secondary';
        const lbl = status.charAt(0).toUpperCase() + status.slice(1);
        return '<span class="badge ' + cls + '">' + lbl + '</span>';
    }
    function setRowStatus($tr, status) {
        $tr.find('.cs-status-badge').replaceWith(statusBadge(status, STATUS_CLASSES).replace('badge ', 'badge cs-status-badge '));
    }
    function setRowSync($tr, status) {
        $tr.find('.cs-sync-badge').replaceWith(statusBadge(status, SYNC_CLASSES).replace('badge ', 'badge cs-sync-badge '));
    }
    $('#cs-btn-add-site').on('click', function () {
        const label = $('#cs-site-label').val().trim();
        const url = $('#cs-site-url').val().trim();
        const license = $('#cs-site-license').val().trim();
        const bootstrap_token = $('#cs-site-bootstrap-token').val().trim();
        if (!label || !url) { showError('Label and URL are required.'); return; }
        const $btn = $(this), $sp = $('#cs-btn-add-spinner');
        $btn.prop('disabled', true); $sp.removeClass('d-none');
        ajaxPost({ action: 'shield_sites_action', command: 'add_site', label, url, license, bootstrap_token }, function (raw) {
            $btn.prop('disabled', false); $sp.addClass('d-none');
            let res; try { res = JSON.parse(raw); } catch (e) { showError('Invalid response.'); return; }
            if (!res.success) { showError(res.error || 'Failed to add site.'); return; }
            $('#cs-site-label, #cs-site-url, #cs-site-license, #cs-site-bootstrap-token').val('');
            $('#cs-sites-empty').remove();
            $('#cs-sites-table tbody').append(buildRow(res.site));
            const boot = res.bootstrap || {};
            if (boot.success) {
                showSuccess('"' + res.site.label + '" added. HMAC v2 bootstrap success.');
            } else {
                showSuccess('"' + res.site.label + '" added. Running initial health check...');
            }
        });
    });
    $(document).on('click', '.cs-btn-ping', function () {
        const id = $(this).data('id'), $tr = $('#cs-site-row-' + id);
        $(this).prop('disabled', true).text('...');
        ajaxPost({ action: 'shield_sites_action', command: 'ping_site', site_id: id }, function (raw) {
            let res; try { res = JSON.parse(raw); } catch (e) { showError('Invalid response.'); return; }
            $tr.find('.cs-btn-ping').prop('disabled', false).text('Ping');
            if (res.success) {
                setRowStatus($tr, 'active');
                $tr.find('.cs-last-ping-value').text('just now');
                $tr.find('.cs-gateway-value').text((res.gateways || []).join(', ') || '—');
            } else {
                setRowStatus($tr, 'offline');
                showError(res.error || 'Site did not respond.');
            }
        });
    });
    $(document).on('click', '.cs-btn-sync', function () {
        const id = $(this).data('id'), $tr = $('#cs-site-row-' + id);
        $(this).prop('disabled', true).text('...');
        ajaxPost({ action: 'shield_sites_action', command: 'sync_site', site_id: id }, function (raw) {
            let res; try { res = JSON.parse(raw); } catch (e) { showError('Invalid response.'); return; }
            $tr.find('.cs-btn-sync').prop('disabled', false).text('Sync');
            if (res.success) { setRowSync($tr, 'synced'); showSuccess('Settings pushed to proxy site.'); }
            else { setRowSync($tr, 'pending'); showError(res.error || 'Sync queued for retry.'); }
        });
    });
    $(document).on('click', '.cs-btn-rotate', function () {
        const id = $(this).data('id');
        const $btn = $(this);
        $btn.prop('disabled', true).text('...');
        ajaxPost({ action: 'shield_sites_action', command: 'rotate_key', site_id: id }, function (raw) {
            let res; try { res = JSON.parse(raw); } catch (e) { showError('Invalid response.'); return; }
            $btn.prop('disabled', false).text('Rotate Key');
            if (!res.success) { showError(res.error || 'Rotate failed.'); return; }
            showSuccess('Key rotated successfully.');
        });
    });
    $(document).on('click', '.cs-btn-primary', function () {
        const id = $(this).data('id');
        const $btn = $(this);
        $btn.prop('disabled', true).text('...');
        ajaxPost({ action: 'shield_sites_action', command: 'set_primary', site_id: id }, function (raw) {
            let res; try { res = JSON.parse(raw); } catch (e) { showError('Invalid response.'); return; }
            $btn.prop('disabled', false).text('Set Primary');
            if (!res.success) { showError(res.error || 'Set primary failed.'); return; }
            $('.cs-primary-state').removeClass('is-primary').text('Secondary writer');
            $('#cs-site-row-' + id).find('.cs-primary-state').addClass('is-primary').text('Primary writer');
            showSuccess('Primary manager set successfully.');
        });
    });
    $(document).on('click', '.cs-btn-revoke', function () {
        const id = $(this).data('id');
        const $btn = $(this);
        showConfirm('Revoke this manager connection on target site?').then(function (r) {
            if (!r.value) return;
            $btn.prop('disabled', true).text('...');
            ajaxPost({ action: 'shield_sites_action', command: 'revoke_site', site_id: id }, function (raw) {
                let res; try { res = JSON.parse(raw); } catch (e) { showError('Invalid response.'); return; }
                $btn.prop('disabled', false).text('Revoke');
                if (!res.success) { showError(res.error || 'Revoke failed.'); return; }
                const $tr = $('#cs-site-row-' + id);
                setRowStatus($tr, 'disabled');
                setRowSync($tr, 'failed');
                showSuccess('Connection revoked.');
            });
        });
    });
    $(document).on('click', '.cs-btn-delete', function () {
        const id = $(this).data('id'), label = $(this).data('label');
        showConfirm('Remove <strong>' + label + '</strong> from the registry?').then(function (r) {
            if (!r.value) return;
            ajaxPost({ action: 'shield_sites_action', command: 'delete_site', site_id: id }, function (raw) {
                let res; try { res = JSON.parse(raw); } catch (e) { showError('Invalid response.'); return; }
                if (res.success) { $('#cs-site-row-' + id).fadeOut(300, function () { $(this).remove(); }); }
                else showError(res.error || 'Could not delete site.');
            });
        });
    });
    function buildRow(site) {
         const managerId = esc(site.manager_id || '—');
         const keyId = esc(site.key_id || '—');
         const isPrimary = String(site.is_primary_manager || '0') === '1';
         return '<tr id="cs-site-row-' + site.id + '" class="cs-site-row">' +
             '<td class="cs-col-site"><div class="cs-site-label">' + esc(site.label) + '</div><a class="cs-site-url" href="' + esc(site.url) + '" target="_blank">' + esc(site.url) + '</a></td>' +
             '<td class="cs-col-health"><div class="cs-health-badges">' +
             statusBadge(site.status || 'pending', STATUS_CLASSES).replace('badge ', 'badge cs-status-badge ') +
             statusBadge(site.sync_status || 'pending', SYNC_CLASSES).replace('badge ', 'badge cs-sync-badge ') +
             '</div><div class="cs-last-ping">Last ping: <span class="cs-last-ping-value">—</span></div></td>' +
             '<td class="cs-col-gateways"><span class="cs-gateway-value">—</span></td>' +
             '<td class="cs-col-keys"><div><span class="cs-key-label">Manager</span> <span class="cs-manager-id">' + managerId + '</span></div>' +
             '<div><span class="cs-key-label">Key</span> <span class="cs-key-id">' + keyId + '</span></div>' +
             '<div class="cs-primary-state ' + (isPrimary ? 'is-primary' : '') + '">' + (isPrimary ? 'Primary writer' : 'Secondary writer') + '</div></td>' +
             '<td class="cs-col-actions"><div class="cs-actions-grid">' +
             '<button class="btn btn-sm btn-outline-primary cs-btn-ping" data-id="' + site.id + '">Ping</button>' +
             '<button class="btn btn-sm btn-outline-success cs-btn-sync" data-id="' + site.id + '">Sync</button>' +
             '<button class="btn btn-sm btn-outline-info cs-btn-rotate" data-id="' + site.id + '">Rotate</button>' +
             '<button class="btn btn-sm btn-outline-warning cs-btn-primary" data-id="' + site.id + '">Primary</button>' +
             '<button class="btn btn-sm btn-outline-secondary cs-btn-revoke" data-id="' + site.id + '">Revoke</button>' +
             '<button class="btn btn-sm btn-outline-danger cs-btn-delete" data-id="' + site.id + '" data-label="' + esc(site.label) + '">Delete</button>' +
             '</div></td></tr>';
    }
    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
});
