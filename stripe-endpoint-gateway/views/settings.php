<?php
/**
 * Stripe Endpoint Gateway - Admin Settings View (WordPress Native Styling)
 *
 * @package Stripe_Endpoint_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

$is_connected   = Shield_Stripe_Endpoint_Client::is_connected();
$saas_url       = get_option('EP_ST_SAAS_URL', '');
$conn_code      = get_option('EP_ST_CONNECTION_CODE', '');
$endpoint_name  = get_option('EP_ST_ENDPOINT_NAME', '');
$endpoint_type  = get_option('EP_ST_TYPE', '');
$rotation_method = get_option('EP_ST_ROTATION_METHOD', '');
$enable_rotation = get_option('EP_ST_ENABLE_ROTATION', false);
$connected_at   = get_option('EP_ST_CONNECTED_AT', 0);
$endpoint_id    = get_option('EP_ST_ENDPOINT_ID', '');
$last_sync_at   = get_option('EP_ST_LAST_SYNC_AT', 0);
$nodes          = get_option('EP_ST_NODES', []);
$is_paused      = get_option('EP_ST_PAUSED', 'no') === 'yes';
if (!is_array($nodes)) {
    $nodes = [];
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">🔐 Stripe Endpoint Gateway Settings</h1>
    <hr class="wp-header-end">

    <div id="message-box" class="notice notice-info" style="display:none; margin: 15px 0; padding: 8px 12px; border-left: 4px solid #72aee6; background: #fff; box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);"></div>

    <div id="poststuff">
        <div id="post-body" class="metabox-holder">
            <!-- Connection Card -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2 class="hndle">
                        <span>
                            <?php echo $is_connected ? '🟢 Connected to SaaS' : '🔴 Connection Status'; ?>
                            <?php if ($is_connected && $is_paused): ?>
                                &nbsp;<span style="display:inline-block;background:#fcf0b1;color:#856404;border:1px solid #f5c842;border-radius:3px;padding:1px 8px;font-size:11px;font-weight:600;letter-spacing:.03em;vertical-align:middle;">⏸ PAUSED by SaaS</span>
                            <?php endif; ?>
                        </span>
                    </h2>
                </div>
                <div class="inside">
                    <?php if ($is_connected): ?>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">SaaS URL</th>
                                    <td><code><?php echo esc_html($saas_url); ?></code></td>
                                </tr>
                                <tr>
                                    <th scope="row">Connection Code</th>
                                    <td><code><?php echo esc_html($conn_code); ?></code></td>
                                </tr>
                                <tr>
                                    <th scope="row">Endpoint ID</th>
                                    <td><code><?php echo esc_html($endpoint_id ?: '-'); ?></code></td>
                                </tr>
                                <tr>
                                    <th scope="row">Endpoint Name</th>
                                    <td><strong><?php echo esc_html($endpoint_name ?: '-'); ?></strong></td>
                                </tr>
                                <tr>
                                    <th scope="row">Type</th>
                                    <td><span class="badge-wp badge-primary"><?php echo esc_html(strtoupper($endpoint_type)); ?></span></td>
                                </tr>
                                <tr>
                                    <th scope="row">Rotation Status</th>
                                    <td>
                                        <?php if ($enable_rotation): ?>
                                            <span class="badge-wp badge-info">Enabled (<?php echo esc_html($rotation_method); ?>)</span>
                                        <?php else: ?>
                                            <span class="badge-wp badge-secondary">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Connection Status</th>
                                    <td>
                                        <?php if ($is_paused): ?>
                                            <span style="display:inline-flex;align-items:center;gap:5px;background:#fcf0b1;color:#856404;border:1px solid #f5c842;border-radius:3px;padding:2px 10px;font-size:12px;font-weight:600;">⏸ Paused by SaaS</span>
                                            <p class="description" style="margin-top:4px;">New checkout payments are blocked. In-flight transactions already in progress will complete normally. Resume from the SaaS dashboard.</p>
                                        <?php else: ?>
                                            <span style="display:inline-flex;align-items:center;gap:5px;background:#edfaee;color:#1a7f37;border:1px solid #82d9a0;border-radius:3px;padding:2px 10px;font-size:12px;font-weight:600;">▶ Active</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Connected At</th>
                                    <td><?php echo $connected_at ? date('Y-m-d H:i:s', $connected_at) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Last Update</th>
                                    <td><strong><?php echo $last_sync_at ? date('Y-m-d H:i:s', $last_sync_at) : '-'; ?></strong></td>
                                </tr>
                            </tbody>
                        </table>

                        <p class="submit" style="margin-bottom: 0; padding-bottom: 0; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #c3c4c7; padding-top: 15px; margin-top: 15px;">
                            <button id="btn-disconnect" class="button button-link-destructive" style="color: #b32d2e; text-decoration: none;">
                                Disconnect from SaaS
                            </button>
                            <button id="btn-pull-config" class="button button-secondary">
                                Pull Config Now
                            </button>
                        </p>

                    <?php else: ?>
                        <?php $has_saved_credentials = !empty($saas_url) && !empty($conn_code); ?>
                        <?php if ($has_saved_credentials): ?>
                            <div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
                                <p><strong>⚠️ This site was disconnected from the SaaS endpoint.</strong> You can reconnect using the saved credentials below, or enter new ones.</p>
                            </div>
                            <table class="form-table" role="presentation">
                                <tbody>
                                    <tr>
                                        <th scope="row">SaaS URL</th>
                                        <td><code><?php echo esc_html($saas_url); ?></code></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Connection Code</th>
                                        <td><code><?php echo esc_html($conn_code); ?></code></td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="submit" style="margin-bottom:0; padding-bottom:0; display: flex; gap: 10px;">
                                <button type="button" id="btn-reconnect" class="button button-primary">
                                    🔄 Reconnect Now
                                </button>
                                <button type="button" id="btn-clear-credentials" class="button button-link" style="color: #b32d2e;">
                                    Clear saved credentials
                                </button>
                            </p>
                        <?php else: ?>
                        <form id="form-connect" method="post">
                            <table class="form-table" role="presentation">
                                <tbody>
                                    <tr>
                                        <th scope="row"><label for="saas_url">SaaS URL</label></th>
                                        <td>
                                            <input type="url" class="regular-text" id="saas_url" name="saas_url"
                                                   placeholder="https://wooshield.io" value="<?php echo esc_attr($saas_url); ?>" required>
                                            <p class="description">URL base of your SaaS Shield-Proxy Manager.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="connection_code">Connection Code</label></th>
                                        <td>
                                            <input type="text" class="regular-text" id="connection_code" name="connection_code"
                                                   placeholder="Paste connection code here" required>
                                            <p class="description">Paste the connection code provided by the SaaS endpoint manager dashboard.</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="submit" style="margin-bottom:0; padding-bottom:0;">
                                <button type="submit" id="btn-connect" class="button button-primary">
                                    Connect Endpoint
                                </button>
                            </p>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_connected): ?>
                <!-- Nodes Card -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle">
                            <span>🔗 Proxy Nodes (<?php echo count($nodes); ?> nodes)</span>
                        </h2>
                    </div>
                    <div class="inside" style="padding: 0;">
                        <table class="wp-list-table widefat fixed striped posts" style="border: 0; box-shadow: none;">
                            <thead>
                                <tr>
                                    <th scope="col" style="width: 50px; padding-left: 15px;">Order</th>
                                    <th scope="col" style="width: 120px;">Shield</th>
                                    <th scope="col">URL</th>
                                    <th scope="col" style="width: 185px;">Volume / Limit</th>
                                    <th scope="col" style="width: 100px;">Status</th>
                                    <th scope="col" style="width: 120px; text-align: right; padding-right: 15px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($nodes)): ?>
                                    <tr>
                                        <td colspan="6" style="padding: 20px; text-align: center; color: #646970;">
                                            No nodes connected. Pull config or verify setup in SaaS dashboard.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($nodes as $i => $node): ?>
                                        <tr class="<?php echo !empty($node['isCurrent']) ? 'highlight-active-row' : ''; ?>">
                                            <td style="padding-left: 15px;"><strong><?php echo esc_html($node['rotationOrder'] ?? ($i + 1)); ?></strong></td>
                                            <td>
                                                <code><?php echo esc_html(substr($node['shieldId'] ?? '', 0, 8)); ?></code>
                                            </td>
                                            <td>
                                                <code><?php echo esc_html($node['url'] ?? '-'); ?></code>
                                            </td>
                                            <td>
                                                <?php
                                                $vol_used = floatval($node['volumeUsed'] ?? 0);
                                                $vol_limit = floatval($node['volumeLimit'] ?? 0);
                                                $pct = $vol_limit > 0 ? round(($vol_used / $vol_limit) * 100) : 0;
                                                ?>
                                                <div class="wp-progress-wrapper">
                                                    <div class="wp-progress-bar">
                                                        <div class="wp-progress-fill <?php echo $pct > 90 ? 'fill-danger' : ($pct > 70 ? 'fill-warning' : 'fill-success'); ?>"
                                                             style="width: <?php echo $pct; ?>%;"></div>
                                                    </div>
                                                    <span class="wp-progress-text">
                                                        $<?php echo number_format($vol_used, 2); ?> / $<?php echo number_format($vol_limit, 2); ?> (<?php echo $pct; ?>%)
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $status = $node['status'] ?? 'unknown';
                                                $badge_class = $status === 'active' ? 'badge-active' : 'badge-inactive';
                                                ?>
                                                <span class="badge-wp <?php echo $badge_class; ?>"><?php echo esc_html($status); ?></span>
                                            </td>
                                            <td style="text-align: right; padding-right: 15px;">
                                                <?php if (!empty($node['isCurrent'])): ?>
                                                    <span class="badge-wp badge-active-star">Active ⭐</span>
                                                <?php elseif (($node['status'] ?? '') === 'active'): ?>
                                                    <button class="button button-small btn-set-active" data-shield-id="<?php echo esc_attr($node['shieldId'] ?? ''); ?>">
                                                        Set Active
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
