<?php
defined('ABSPATH') || exit;
$sites = Shield_Site_Registry::all();
$total_sites = count($sites);
$active_sites = count(array_filter($sites, static function ($s) {
    return ($s['status'] ?? '') === 'active';
}));
$synced_sites = count(array_filter($sites, static function ($s) {
    return ($s['sync_status'] ?? '') === 'synced';
}));
?>
<div class="cs-wrap cs-layout">
    <section class="cs-hero">
        <div>
            <h1>Connected Sites</h1>
            <p>Quan ly ket noi den cac proxy site, luan chuyen key va dong bo cau hinh thanh toan theo manager.</p>
        </div>
        <div class="cs-kpis" aria-label="Connection stats">
            <div class="cs-kpi">
                <span class="cs-kpi-label">Total</span>
                <strong><?= (int) $total_sites ?></strong>
            </div>
            <div class="cs-kpi">
                <span class="cs-kpi-label">Active</span>
                <strong><?= (int) $active_sites ?></strong>
            </div>
            <div class="cs-kpi">
                <span class="cs-kpi-label">Synced</span>
                <strong><?= (int) $synced_sites ?></strong>
            </div>
        </div>
    </section>

    <section class="cs-create card mb-4">
        <div class="card-body">
            <h2 class="card-title">Add Proxy Site</h2>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label">Label</label>
                    <input type="text" id="cs-site-label" class="form-control" placeholder="Site 1">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">URL</label>
                    <input type="url" id="cs-site-url" class="form-control" placeholder="https://site1.example.com">
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label">Bootstrap Token (site1)</label>
                    <input type="text" id="cs-site-bootstrap-token" class="form-control" placeholder="shield_proxy_key or bootstrap token">
                    <small class="text-muted">Khuyen nghi su dung token de bootstrap HMAC v2 tu dong.</small>
                </div>
                <div class="col-12 col-md-9">
                    <label class="form-label">Legacy License Key (optional)</label>
                    <input type="text" id="cs-site-license" class="form-control" placeholder="chi dung khi can tuong thich v1">
                </div>
                <div class="col-12 col-md-3 d-flex align-items-end">
                    <button id="cs-btn-add-site" class="btn btn-primary w-100">
                        <span id="cs-btn-add-spinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        Add Connection
                    </button>
                </div>
            </div>
        </div>
    </section>

    <table class="widefat striped" id="cs-sites-table">
        <thead>
            <tr>
                <th>Site</th>
                <th>Health</th>
                <th>Gateways</th>
                <th>Manager Keys</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($sites)) : ?>
            <tr id="cs-sites-empty"><td colspan="5" class="text-center text-muted py-4">No proxy sites registered yet.</td></tr>
        <?php else : ?>
            <?php foreach ($sites as $site) :
                $status_map = ['active' => ['bg-success','Active'], 'offline' => ['bg-danger','Offline'],
                               'pending' => ['bg-warning text-dark','Pending'], 'disabled' => ['bg-secondary','Disabled']];
                $sync_map   = ['synced' => ['bg-success','Synced'], 'pending' => ['bg-warning text-dark','Pending'],
                               'failed' => ['bg-danger','Failed']];
                [$st_class, $st_label] = $status_map[$site['status']]      ?? ['bg-secondary', $site['status']];
                [$sy_class, $sy_label] = $sync_map[$site['sync_status']]   ?? ['bg-secondary', $site['sync_status']];
                $gateways  = implode(', ', $site['gateways'] ?? []) ?: '—';
                $last_ping = $site['last_ping'] ? human_time_diff($site['last_ping']) . ' ago' : '—';
                $manager_id = $site['manager_id'] ?? '—';
                $key_id = $site['key_id'] ?? '—';
                $is_primary = ($site['is_primary_manager'] ?? '0') === '1';
            ?>
            <tr id="cs-site-row-<?= esc_attr($site['id']) ?>" class="cs-site-row">
                <td class="cs-col-site">
                    <div class="cs-site-label"><?= esc_html($site['label']) ?></div>
                    <a class="cs-site-url" href="<?= esc_url($site['url']) ?>" target="_blank"><?= esc_html($site['url']) ?></a>
                </td>
                <td class="cs-col-health">
                    <div class="cs-health-badges">
                        <span class="badge <?= $st_class ?> cs-status-badge"><?= $st_label ?></span>
                        <span class="badge <?= $sy_class ?> cs-sync-badge"><?= $sy_label ?></span>
                    </div>
                    <div class="cs-last-ping">Last ping: <span class="cs-last-ping-value"><?= esc_html($last_ping) ?></span></div>
                </td>
                <td class="cs-col-gateways"><span class="cs-gateway-value"><?= esc_html($gateways) ?></span></td>
                <td class="cs-col-keys">
                    <div><span class="cs-key-label">Manager</span> <span class="cs-manager-id"><?= esc_html($manager_id) ?></span></div>
                    <div><span class="cs-key-label">Key</span> <span class="cs-key-id"><?= esc_html($key_id) ?></span></div>
                    <div class="cs-primary-state <?= $is_primary ? 'is-primary' : '' ?>"><?= $is_primary ? 'Primary writer' : 'Secondary writer' ?></div>
                </td>
                <td class="cs-col-actions">
                    <div class="cs-actions-grid">
                        <button class="btn btn-sm btn-outline-primary cs-btn-ping" data-id="<?= esc_attr($site['id']) ?>">Ping</button>
                        <button class="btn btn-sm btn-outline-success cs-btn-sync" data-id="<?= esc_attr($site['id']) ?>">Sync</button>
                        <button class="btn btn-sm btn-outline-info cs-btn-rotate" data-id="<?= esc_attr($site['id']) ?>">Rotate</button>
                        <button class="btn btn-sm btn-outline-warning cs-btn-primary" data-id="<?= esc_attr($site['id']) ?>">Primary</button>
                        <button class="btn btn-sm btn-outline-secondary cs-btn-revoke" data-id="<?= esc_attr($site['id']) ?>">Revoke</button>
                        <button class="btn btn-sm btn-outline-danger cs-btn-delete" data-id="<?= esc_attr($site['id']) ?>" data-label="<?= esc_attr($site['label']) ?>">Delete</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<div id="toast"></div>
<script>
const ShieldSites = { nonce: '<?= wp_create_nonce("shield_sites_nonce") ?>', ajax_url: '<?= admin_url("admin-ajax.php") ?>' };
</script>
<script src="<?= esc_url(plugins_url('assets/js/sites.js', SHIELD_MANAGER_PLUGIN_FILE)) ?>?v=<?= SHILED_PROXY_VERSION ?>"></script>
