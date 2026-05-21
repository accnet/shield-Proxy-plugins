<style>
.sp-wrap { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', sans-serif; }
.sp-page-header { display: flex; align-items: center; gap: 10px; padding: 4px 0 18px; }
.sp-page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #1e293b; }
/* Tab bar */
.sp-nav { border-bottom: 2px solid #e2e8f0; margin-bottom: 24px; }
.sp-nav .nav-link { font-size: 13px; font-weight: 600; color: #64748b; background: none; border: none;
  border-bottom: 3px solid transparent; border-radius: 0; padding: 10px 22px; margin-bottom: -2px;
  transition: color .15s, border-color .15s; }
.sp-nav .nav-link:hover:not(.active) { color: #334155; border-bottom-color: #cbd5e1; background: none; }
.sp-nav .nav-link.active { color: #0f766e; border-bottom-color: #0f766e; background: none; }
/* Section cards */
.sp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 14px;
  overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
.sp-card-header { padding: 10px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
  font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .6px; }
.sp-card-body { padding: 18px 24px; }
/* Save bar */
.sp-save-bar { padding: 14px 0 4px; display: flex; align-items: center; gap: 12px; }
.sp-save-bar .sp-btn-save { background: #0f766e; color: #fff; border: none; padding: 9px 28px;
  font-weight: 600; font-size: 14px; border-radius: 6px; cursor: pointer; line-height: 1; }
.sp-save-bar .sp-btn-save:hover { background: #0d6460; }
/* Sync row */
.sp-sync-row { display: flex; align-items: center; gap: 16px; }
.sp-btn-sync { background: #0f766e; color: #fff; border: none; padding: 8px 18px; font-weight: 600;
  border-radius: 6px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.sp-btn-sync:disabled { opacity: .55; cursor: not-allowed; }
.sp-unsynced { font-size: 13px; color: #64748b; }
/* Field rows */
.sp-card-body .row.mb-4 { margin-bottom: 16px !important; }
.sp-card-body label.col-sm-3 { font-size: 13px; font-weight: 600; color: #374151; padding-top: 8px; }
.sp-card-body .col-sm-9 .form-control,
.sp-card-body .col-sm-9 .form-select { font-size: 13px; }
.sp-card-body .col-sm-9 p.description { font-size: 12px; color: #6b7280; margin: 4px 0 0; }
</style>

<div class="sp-wrap">
  <div class="sp-page-header">
    <span class="dashicons dashicons-shield" style="font-size:26px;width:26px;height:26px;color:#0f766e;"></span>
    <h1>Shield Proxy Settings</h1>
  </div>

  <nav class="sp-nav">
    <div class="nav" id="nav-tab" role="tablist">
      <button class="nav-link active" id="nav-paypal-tab" data-bs-toggle="tab" data-bs-target="#nav-paypal"
        type="button" role="tab" aria-controls="nav-paypal" aria-selected="true">PayPal</button>
      <button class="nav-link" id="nav-stripe-tab" data-bs-toggle="tab" data-bs-target="#nav-stripe"
        type="button" role="tab" aria-controls="nav-stripe" aria-selected="false">Stripe</button>
      <button class="nav-link" id="nav-saas-tab" data-bs-toggle="tab" data-bs-target="#nav-saas"
        type="button" role="tab" aria-controls="nav-saas" aria-selected="false">SaaS Connect</button>
    </div>
  </nav>

  <div class="tab-content" id="nav-tabContent">
    <div class="tab-pane fade show active" id="nav-paypal" role="tabpanel" aria-labelledby="nav-paypal-tab">
      <?php include __DIR__ . '/paypal-settings.php'; ?>
    </div>
    <div class="tab-pane fade" id="nav-stripe" role="tabpanel" aria-labelledby="nav-stripe-tab">
      <?php include __DIR__ . '/stripe-settings.php'; ?>
    </div>
    <div class="tab-pane fade" id="nav-saas" role="tabpanel" aria-labelledby="nav-saas-tab">
      <?php include __DIR__ . '/saas-settings.php'; ?>
    </div>
  </div>
</div>
<div id="toast"></div>