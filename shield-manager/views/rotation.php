<h3>Rotation settings</h3>

<style>
  .rotation-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 30px 0 16px;
    flex-wrap: wrap;
  }

  .rotation-tabs {
    display: inline-flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .rotation-tab {
    border: 1px solid #d0d7de;
    border-radius: 999px;
    background: #ffffff;
    color: #1f2937;
    padding: 8px 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
  }

  .rotation-tab.active {
    background: #0f766e;
    color: #ffffff;
    border-color: #0f766e;
  }

  .rotation-caption {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 12px;
  }

  .rotation-add-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }

  .rotation-add-modal.open {
    display: flex;
  }

  .rotation-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
  }

  .rotation-modal-content {
    position: relative;
    background: #fff;
    width: min(520px, calc(100% - 24px));
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
  }

  .rotation-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 14px;
  }

  /* Inline validation */
  input.is-invalid { border-color: #dc3545 !important; box-shadow: 0 0 0 .2rem rgba(220,53,69,.2); }
  input.is-invalid ~ .invalid-feedback { display: block; color: #dc3545; font-size: 12px; margin-top: 3px; }
  .invalid-feedback { display: none; }
  .shield-url-stack { display: flex; flex-direction: column; gap: 5px; align-items: flex-start; }
  .shield-url-badges { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .shield-hmac-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 700; line-height: 1.2; }
  .shield-hmac-connected { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
  .shield-hmac-missing { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
  .shield-hmac-not-required { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
</style>

<?php
$is_saas_connected = (Shield_Option_Manager::get('OPT_SHIELD_SAAS_CONNECTED', 'no') === 'yes');
?>
<div class="rotation-topbar">
  <div class="rotation-tabs">
    <button type="button" class="rotation-tab <?= $rotationMethod === $key['byAmount'] ? 'active' : '' ?>" data-method="<?= esc_attr($key['byAmount']) ?>">Volume</button>
    <button type="button" class="rotation-tab <?= $rotationMethod === $key['byTime'] ? 'active' : '' ?>" data-method="<?= esc_attr($key['byTime']) ?>">Time</button>
    <button type="button" class="rotation-tab <?= $rotationMethod === $key['byOrder'] ? 'active' : '' ?>" data-method="<?= esc_attr($key['byOrder']) ?>">Order</button>
  </div>
  <?php if (!$is_saas_connected) : ?>
    <button id="btn-open-add-modal" type="button" class="btn btn-primary">+ Add Shield Site</button>
  <?php endif; ?>
</div>

<input type="hidden" id="current-rotation-method" value="<?= esc_attr($rotationMethod) ?>">

<?php if ($is_saas_connected) : ?>
  <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size: 13px; color: #0e7490; background-color: #ecfeff; border: 1px solid #cffafe; border-radius: 6px; margin-top: 15px;">
    <span class="dashicons dashicons-lock" style="font-size: 18px; width: 18px; height: 18px; color: #0e7490; vertical-align: middle;"></span>
    <span>This proxy rotation is managed remotely by your SaaS account. Local modifications are disabled.</span>
  </div>
<?php endif; ?>

<div class="rotation-caption by-amount">
  *The gateway will not show if cannot affort order total amount.
</div>

<table id="proxy-list" class="rotation <?= $rotationMethod ?> <?= $is_saas_connected ? 'saas-connected-locked' : '' ?>">
  <thead>
    <tr>
      <th style=" text-align: left; ">PROXY URL</th>
      <th class="amount">AMOUNT</th>
      <th class="amount">REV.</th>
      <th class="time">TIME</th>
      <th class="order">ORDER COMPLETE</th>
      <th class="order">ORDER</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($proxyList as $proxy) :
      $class = (isset($proxy['active']) ? 'activated-proxy' : (isset($proxy['is_off']) ? 'off-proxy' : ''));
      $isOff = isset($proxy['is_off']) ? true : false;
      $actived = isset($proxy['active']) ? true : false;
    ?>
    <tr id="id-<?= $proxy['id'] ?>" class="proxy <?= $class ?>" data-id="<?= $proxy['id'] ?>">
      <td style="text-align: left; ">
        <?php
        $site_status = $proxy['site_status'] ?? 'unknown';
        $site_label  = $proxy['site_label']  ?? '';
        $badge_cls   = match($site_status) {
          'active'   => 'bg-success',
          'offline'  => 'bg-danger',
          'pending'  => 'bg-warning text-dark',
          default    => 'bg-secondary',
        };
        ?>
        <?php
        $hmac_status = $proxy['hmac_status'] ?? 'not_required';
        $hmac_label  = $proxy['hmac_label'] ?? 'No HMAC required';
        $hmac_cls    = match($hmac_status) {
          'connected' => 'shield-hmac-connected',
          'missing' => 'shield-hmac-missing',
          default => 'shield-hmac-not-required',
        };
        ?>
        <div class="shield-url-stack">
          <div class="shield-url-badges">
            <span class="shield-hmac-badge <?= esc_attr($hmac_cls) ?>" title="<?= esc_attr($hmac_status) ?>"><?= esc_html($hmac_label) ?></span>
          </div>
          <small class="text-muted"><?= esc_html($proxy['url']) ?></small>
        </div>
      </td>
      <td class="order">
        <?= $proxy['order_count'] ?? 0 ?>
      </td>
      <td class="order">
        <input type="number" name="order" min="1" class="form-control" value="<?= $proxy['order'] ?? 0 ?>" <?= $is_saas_connected ? 'disabled' : '' ?>>
        <div class="invalid-feedback">Must be &gt; 0.</div>
      </td>
      <td class="time"><input type="number" name="timestamp" min="1" class="form-control"
          value="<?= $proxy['timestamp'] ?? 0 ?>" <?= $is_saas_connected ? 'disabled' : '' ?>>
        <div class="invalid-feedback">Must be &gt; 0.</div>
      </td>
      <td class="amount">
        <?= $proxy['paid_amount'] ?? 0 ?>
      </td>
      <td class="amount">
        <input type="number" name="amount" min="1" class="form-control" value="<?= $proxy['amount'] ?? 0 ?>" <?= $is_saas_connected ? 'disabled' : '' ?>>
        <div class="invalid-feedback">Must be &gt; 0.</div>
      </td>
      <td class="proxy-actions">
        <?php if (!$is_saas_connected && !$actived) : ?>
        <button id="btn-force-active" class="btn btn-success" type="button"><span
            class="dashicons dashicons-shield-alt"></span></button>
        <button id="btn-delete" class="btn btn-danger" type="button"><span
            class="dashicons dashicons-trash"></span></button>
        <?php endif ?>
        <?php if (!$is_saas_connected) : ?>
        <span class="dashicons dashicons-menu-alt3 handle"></span>
        <?php endif; ?>
        <label class="switch">
          <input type="checkbox" <?= $isOff ? '' : 'checked' ?> <?= ($actived || $is_saas_connected) ? 'disabled' : '' ?> id="on_off"
            name="on_off">
          <span class="slider"></span>
        </label>
      </td>
    </tr>
    <?php endforeach ?>
  </tbody>
  <tfoot>
    <tr>
      <th></th>
      <th class="amount order"></th>
      <th>
        <?php if (!$is_saas_connected) : ?>
          <button id="btn-save" type="button" class="btn btn-primary">Save All</button>
        <?php else : ?>
          <span class="badge bg-success" style="padding: 8px 12px; font-size: 11px;">Managed Remotely</span>
        <?php endif; ?>
      </th>
      <th></th>
    </tr>
  </tfoot>
</table>

<div id="rotation-add-modal" class="rotation-add-modal" aria-hidden="true">
  <div class="rotation-modal-backdrop"></div>
  <div class="rotation-modal-content">
    <h4 style="margin-top: 0;">Add Shield Site</h4>
    <div class="form-group" style="margin-bottom: 10px;">
      <label for="modal-proxy-url" class="form-label">Site URL</label>
      <input type="text" id="modal-proxy-url" class="form-control" placeholder="https://site.example.com">
      <div class="invalid-feedback">Please enter a valid URL.</div>
    </div>
    <div class="form-group" style="margin-bottom: 0;">
      <label for="modal-rotation-value" class="form-label"><span id="rotation-value-label">Parameter</span> value</label>
      <input id="modal-rotation-value" type="number" class="form-control" min="1" value="">
      <div class="invalid-feedback">Value must be greater than 0.</div>
    </div>
    <div class="rotation-modal-actions">
      <button id="btn-close-add-modal" type="button" class="btn btn-outline-secondary">Cancel</button>
      <button id="btn-add-proxy" type="button" class="btn btn-primary">Add</button>
    </div>
  </div>
</div>
