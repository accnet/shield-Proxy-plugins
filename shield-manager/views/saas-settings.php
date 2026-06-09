<?php
/**
 * SaaS Connection Tab View
 * 
 * @package Shield_Manager
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

$connected = Shield_Option_Manager::get('OPT_SHIELD_SAAS_CONNECTED', 'no');
$saas_url = Shield_Option_Manager::get('OPT_SHIELD_SAAS_URL', 'https://wooshield.io');
$connect_key = Shield_Option_Manager::get('OPT_SHIELD_SAAS_KEY', '');
$connected_at = Shield_Option_Manager::get('OPT_SHIELD_SAAS_CONNECTED_AT', null);
$has_saved_connection = !empty($saas_url) && !empty($connect_key) && !empty(Shield_Option_Manager::get('OPT_SHIELD_SAAS_HMAC_SECRET', ''));
?>

<div class="sp-card">
  <div class="sp-card-header">SaaS Connection Settings</div>
  <div class="sp-card-body">
    <?php if ($connected === 'yes') : ?>
      <div class="alert alert-success d-flex align-items-center gap-3 py-3" role="alert">
        <span class="dashicons dashicons-yes-alt" style="font-size: 28px; width: 28px; height: 28px; color: #15803d;"></span>
        <div>
          <h5 class="alert-heading mb-1 font-weight-bold" style="color: #166534; font-size: 15px;">Site is Connected to SaaS Manager</h5>
          <p class="mb-0 text-xs" style="color: #15803d; font-size: 13px;">
            This site is successfully linked to your SaaS account. Local editing and manual reordering of PayPal/Stripe proxy gateways are locked. All rotation settings are managed dynamically from the SaaS.
          </p>
        </div>
      </div>

      <div class="row mb-3 mt-4">
        <label class="col-sm-3 font-weight-bold text-slate-700">SaaS Server URL</label>
        <div class="col-sm-9 pt-1 font-mono text-sm">
          <span class="badge bg-light text-dark border px-2 py-1"><?= esc_url($saas_url) ?></span>
        </div>
      </div>

      <div class="row mb-3">
        <label class="col-sm-3 font-weight-bold text-slate-700">Connected At</label>
        <div class="col-sm-9 pt-1 text-sm text-muted">
          <?= esc_html($connected_at ? date('Y-m-d H:i:s', $connected_at) : 'N/A') ?>
        </div>
      </div>

      <div class="row mb-3">
        <label class="col-sm-3 font-weight-bold text-slate-700">Webhook Sync Endpoint</label>
        <div class="col-sm-9 pt-1 font-mono text-xs text-muted">
          <?= esc_url(get_site_url() . '/wp-json/shield-manager/v1/receive-sync') ?>
        </div>
      </div>

      <div class="sp-save-bar border-top pt-3 mt-4">
        <div class="form-check form-switch d-inline-flex align-items-center me-4" style="gap: 10px;">
          <input class="form-check-input" type="checkbox" role="switch" id="saas-sync-toggle" checked style="width: 3rem; height: 1.5rem; cursor: pointer;">
          <label class="form-check-label fw-bold" for="saas-sync-toggle" style="font-size: 13px; cursor: pointer;">
            SaaS Sync Active
          </label>
        </div>
        <button type="button" id="saas-reset-btn" class="btn btn-outline-danger px-4 ms-2" style="font-size: 13px; font-weight: 600; padding: 10px 20px; border-radius: 6px;">
          Reset Connection
        </button>
      </div>

    <?php else : ?>
      <div class="alert <?= $has_saved_connection ? 'alert-info' : 'alert-warning' ?> d-flex align-items-center gap-3 py-3" role="alert">
        <span class="dashicons <?= $has_saved_connection ? 'dashicons-controls-pause' : 'dashicons-warning' ?>" style="font-size: 28px; width: 28px; height: 28px; color: <?= $has_saved_connection ? '#0369a1' : '#b45309' ?>;"></span>
        <div>
          <h5 class="alert-heading mb-1 font-weight-bold" style="color: <?= $has_saved_connection ? '#075985' : '#92400e' ?>; font-size: 15px;">
            <?= $has_saved_connection ? 'SaaS Sync Temporarily Disabled' : 'Local-Only Mode' ?>
          </h5>
          <p class="mb-0 text-xs" style="color: <?= $has_saved_connection ? '#0369a1' : '#b45309' ?>; font-size: 13px;">
            <?= $has_saved_connection
              ? 'Saved SaaS URL, connection key, HMAC secret, and synced rotation settings are preserved. Activate sync again when you are ready.'
              : 'Connecting this plugin to the SaaS panel unlocks centralized remote rotation controls, allowing you to edit credentials and rotate multiple gateway configurations without manually logging into WooCommerce.' ?>
          </p>
        </div>
      </div>

      <?php if ($has_saved_connection) : ?>
        <div class="sp-save-bar border-top pt-3 mt-4 mb-4">
          <div class="form-check form-switch d-inline-flex align-items-center me-4" style="gap: 10px;">
            <input class="form-check-input" type="checkbox" role="switch" id="saas-sync-toggle" style="width: 3rem; height: 1.5rem; cursor: pointer;">
            <label class="form-check-label fw-bold" for="saas-sync-toggle" style="font-size: 13px; cursor: pointer;">
              SaaS Sync Disabled
            </label>
          </div>
          <button type="button" id="saas-reset-btn" class="btn btn-outline-danger px-4 ms-2" style="font-size: 13px; font-weight: 600; padding: 10px 20px; border-radius: 6px;">
            Reset Connection
          </button>
        </div>
      <?php endif; ?>

      <form id="saas-connect-form" class="mt-4">
        <div class="row mb-4">
          <label for="saas_url" class="col-sm-3 col-form-label">SaaS Server URL</label>
          <div class="col-sm-9">
            <input type="url" class="form-control" id="saas_url" name="saas_url" value="<?= esc_url($saas_url) ?>" placeholder="e.g. https://wooshield.io" required>
            <p class="description">Enter the endpoint of your SaaS Proxy Controller. For local Docker setups, use http://172.19.0.1:3000</p>
          </div>
        </div>

        <div class="row mb-4">
          <label for="connect_key" class="col-sm-3 col-form-label">Connection Key</label>
          <div class="col-sm-9">
            <textarea class="form-control font-mono" id="connect_key" name="connect_key" rows="3" placeholder="Paste the Connection Key generated from your SaaS Profile settings..." required><?= esc_textarea($connect_key) ?></textarea>
            <p class="description">Copy this key from your Profile settings in the SaaS platform under the "SaaS Remote Rotation Manager" card.</p>
          </div>
        </div>

        <div class="sp-save-bar border-top pt-3 mt-4">
          <button type="submit" id="saas-connect-btn" class="btn btn-teal px-4" style="background-color: #0f766e; color: #fff; border: none; font-size: 13px; font-weight: 600; padding: 10px 24px; border-radius: 6px;">
            <?= $has_saved_connection ? 'Connect With New URL / Key' : 'Establish SaaS Connection' ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
