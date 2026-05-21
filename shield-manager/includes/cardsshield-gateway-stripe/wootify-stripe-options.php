<?php

// update_option(OPT_WOOTIFY_Proxies, array(), true);

require_once(plugin_dir_path(__FILE__) . 'utils.php');

class WOOTIFY_Stripe_Paygate_Option {
    public function __construct() {
        // add_action('admin_menu', [$this, 'add_WOOTIFY_stripe_paygate_menu']);
        add_action('wp_ajax_WOOTIFY_gateway_stripe_action', [$this, 'WOOTIFY_gateway_stripe_action']);
    }


    function add_WOOTIFY_stripe_paygate_menu() {
        $mypage = add_menu_page('CardsShield Gateway Stripe Settings', 'CardsShield Stripe', 'manage_options', 'wootify-gateway-stripe', [$this, 'WOOTIFY_page_init']);
        add_action('load-' . $mypage, [$this, 'enqueue_scripts_front_end']);
    }


    function enqueue_scripts_front_end() {
        // css
        wp_register_style('WOOTIFY_bs_css', "https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css");
        wp_enqueue_style('WOOTIFY_bs_css');

        wp_register_style("WOOTIFY_settings_css", plugins_url('assets/css/settings.css', __FILE__), [], OPT_WOOTIFY_STRIPE_VERSION);
        wp_enqueue_style('WOOTIFY_settings_css');


        // js
        wp_register_script("WOOTIFY_swal2", "https://cdn.jsdelivr.net/npm/sweetalert2@8");
        wp_enqueue_script("WOOTIFY_swal2");

        wp_register_script("WOOTIFY_bs_js", "https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js");
        wp_enqueue_script("WOOTIFY_bs_js");

        wp_register_script("WOOTIFY_settings", plugins_url('assets/js/settings.js', __FILE__), [], OPT_WOOTIFY_STRIPE_VERSION);
        wp_enqueue_script("WOOTIFY_settings");


        // in JavaScript, object properties are accessed as ajax_object.ajax_url, ajax_object.we_value
        wp_localize_script('WOOTIFY_settings', 'ajax_object', ['ajax_url' => admin_url('admin-ajax.php'), 'we_value' => 1234]);
    }

    function WOOTIFY_gateway_stripe_action() {
        switch ($_POST['command']) {
            case 'changeRotationMethod':
                $this->changeRotationMethod();
                break;
            case 'addNewProxy':
                $this->addNewProxy();
                break;
            case 'deleteProxy':
                $this->deleteProxy();
                break;
            case 'activateProxy':
                $this->activateProxy();
                break;
            case 'moveToUnusedProxies':
                $this->moveToUnusedProxies();
                break;
            case 'saveProxies':
                $this->saveProxies();
                break;
            case 'moveBackProxies':
                $this->moveBackProxies();
                break;
            default:
                break;
        }

        wp_die(); // this is required to terminate immediately and return a proper response
    }

    function changeRotationMethod() {
        $isSuccess = update_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, $_POST['rotationMethod'], true);
        echo json_encode([
            'success' => $isSuccess
        ]);
    }

    function activateProxy() {
        $rotationMethod = $_POST["rotationMethod"];
        $proxyID = $_POST["proxyID"];

        $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
        foreach ($proxies as $proxy) {
            if ($proxy["id"] == $proxyID) {
                // Active
                update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $proxy, true);
                if ($rotationMethod === WOOTIFY_STRIPE_BY_TIME) {
                    update_option(OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE, time(), true);
                }
                logStripeRotation($rotationMethod, $proxy, "Force");
                echo json_encode([
                    'success' => true
                ]);
                return;
            }
        }
        echo json_encode([
            'success' => false
        ]);
    }

    function deleteProxy() {
        $deleteProxyIds = $_POST["deleteProxyIds"];
        $proxies = get_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, []);
        foreach ($proxies as $key => $proxy) {
            if (in_array($proxy['id'], $deleteProxyIds)) {
                unset($proxies[$key]);
            }
        }
        $isSuccess = update_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, array_values($proxies), true);
        echo json_encode([
            'success' => $isSuccess
        ]);
    }

    function addNewProxy() {
        $rotationMethod = $_POST["rotationMethod"];
        $proxyUrl = $_POST["proxyUrl"];
        $rotationValue = $_POST["rotationValue"];

        // Get current proxies
        $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
        if (empty($proxies)) {
            $proxies = [];
        }
        // Add the new one
        $proxy = [
            'id' => uniqid(),
            'url' => $proxyUrl,
            'paid_amount' => 0
        ];
        if ($rotationMethod === WOOTIFY_STRIPE_BY_TIME) {
            $proxy['timestamp'] = $rotationValue;
            $proxy['amount'] = 0;
        } else if ($rotationMethod === WOOTIFY_STRIPE_BY_AMOUNT) {
            $proxy['timestamp'] = 0;
            $proxy['amount'] = $rotationValue;
        }
        $proxies[] = $proxy;
        // Save
        $isSuccess = update_option(OPT_WOOTIFY_STRIPE_PROXIES, $proxies, true);

        $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
        if (empty($activatedProxy)) {
            update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $proxies[0], true);
            update_option(OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE, time(), true);
            update_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, $rotationMethod, true);
        }

        echo json_encode([
            'success' => $isSuccess,
            'addedProxy' => $proxy
        ]);
    }

    function moveToUnusedProxies() {
        $proxyIds = $_POST["proxyIds"];

        $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
        if (empty($proxies)) {
            $proxies = [];
        }
        $unusedProxies = get_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, []);
        if (empty($unusedProxies)) {
            $unusedProxies = [];
        }
        $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
        if (isset($activatedProxy) && in_array($activatedProxy['id'], $proxyIds)) {
            echo json_encode([
                "success" => false,
                "error" => "Can't move activated proxy to unused list!"
            ]);
            return;
        }
        foreach ($proxies as $key => $proxy) {
            if (in_array($proxy['id'], $proxyIds)) {
                $unusedProxies[] = $proxy;
                unset($proxies[$key]);
            }
        }
        $isSuccess1 = update_option(OPT_WOOTIFY_STRIPE_PROXIES, array_values($proxies), true);
        $isSuccess2 = update_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, $unusedProxies, true);
        echo json_encode([
            "success" => $isSuccess1 && $isSuccess2
        ]);
    }

    function saveProxies() {
        $rotationMethod = $_POST["rotationMethod"];
        $newProxies = $_POST["proxies"];

        $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
        $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
        foreach ($proxies as $key => $proxy) {
            if ($proxy['id'] !== $newProxies[$key]['id']) {
                continue;
            }
            $proxies[$key]['url'] = $newProxies[$key]['url'];
            $proxies[$key]['timestamp'] = $rotationMethod === WOOTIFY_STRIPE_BY_TIME ? $newProxies[$key]['rotationValue'] : $proxies[$key]['timestamp'];
            $proxies[$key]['amount'] = $rotationMethod === WOOTIFY_STRIPE_BY_AMOUNT ? $newProxies[$key]['rotationValue'] : $proxies[$key]['amount'];

            // Update activated proxy
            if (isset($activatedProxy) && $activatedProxy['id'] === $proxy['id']) {
                update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $proxies[$key], true);
            }
        }
        update_option(OPT_WOOTIFY_STRIPE_PROXIES, $proxies, true);
        echo json_encode([
            "success" => true
        ]);
    }

    function moveBackProxies() {
        $moveBackProxyIds = $_POST["moveBackProxyIds"];

        $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
        $needActiveFirstProxy = false;
        if (count($proxies) == 0) {
            $needActiveFirstProxy = true;
        }
        $unusedProxies = get_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, []);
        foreach ($unusedProxies as $key => $proxy) {
            if (in_array($proxy['id'], $moveBackProxyIds)) {
                $proxies[] = $proxy;
                unset($unusedProxies[$key]);
            }
        }
        $isSuccess1 = update_option(OPT_WOOTIFY_STRIPE_PROXIES, $proxies, true);
        $isSuccess2 = update_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, array_values($unusedProxies), true);
        if ($needActiveFirstProxy) {
            update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, isset($proxies[0]) ? $proxies[0] : null, true);
        }
        echo json_encode(["success" => $isSuccess1 && $isSuccess2]);
    }

    /**
    * WOOTIFY Stripe Gateway
     */

    function WOOTIFY_page_init() {
        $rotationMethod = get_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, WOOTIFY_STRIPE_BY_TIME);
        if (empty($rotationMethod)) {
            $rotationMethod = WOOTIFY_STRIPE_BY_TIME;
            update_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, WOOTIFY_STRIPE_BY_TIME, true);
        }

        $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
        $unusedProxies = get_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, []);
        $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);

        $currency = get_woocommerce_currency();
?>
        <style>
            .by-time {
                <?= $rotationMethod !== WOOTIFY_STRIPE_BY_TIME ? 'display: none' : '' ?>;
            }

            .by-amount {
                <?= $rotationMethod !== WOOTIFY_STRIPE_BY_AMOUNT ? 'display: none' : '' ?>;
            }
        </style>
        <br />
        <div class="container">
            <h3>CardsShield Stripe Settings</h3>
            <br />
            <hr style="border-top: 1px solid #333" />
            <h5 style="margin-top: 30px">Rotation settings</h5>
            <div class="row">
                <div class="col-sm">
                    <div class="form-group form-inline rotation-method-wrapper">
                        <label class="rotation-method-label" style="justify-content: left" for="rotation-type">Rotation
                            method: </label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="rotationMethod" id="rotationByTime" value="by_time" <?= $rotationMethod === WOOTIFY_STRIPE_BY_TIME ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rotationByTime">Time</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="rotationMethod" id="rotationByAmount" value="by_amount" <?= $rotationMethod === WOOTIFY_STRIPE_BY_AMOUNT ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rotationByAmount">Amount (per day)</label>
                        </div>
                        <div class="by-amount" style="font-size: 0.85rem; color: gray; width: 100%;">
                            *The gateway will not show if cannot affort order total amount.
                        </div>
                    </div>

                </div>
            </div>
            <div class="row">
                <div class="col-sm">
                    <table class="table table-proxy table-hover table-borderless">
                        <thead>
                            <tr>
                                <th scope="col" class="checkbox-col"></th>
                                <th scope="col" class="proxy-url-col">Shield URL</th>
                                <th scope="col" class="rotation-value-col">
                                    <span class="by-time">Time(min)</span>
                                    <span class="by-amount">Amount(<?= $currency ?>/day)</span>
                                </th>
                                <th scope="col" class="control-button-col"></th>
                            </tr>
                            <tr>
                                <th></th>
                                <th>
                                    <input type="text" class="form-control proxy-url" id="new-proxy-url">
                                </th>
                                <th>
                                    <input type="number" class="form-control proxy-rotation-value" id="new-rotation-value">
                                </th>
                                <th>
                                    <button id="btn-add-proxy" class="btn btn-info" type="button">Add</button>
                                </th>
                            </tr>
                            <tr style="border-top: 1px solid rgba(0,0,0,.1)">
                                <th></th>
                                <th>
                                    <b>Rotation List</b>
                                </th>
                                <th></th>
                                <th class="today-paid-amount"><span class="by-amount">Rev.</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($proxies as $proxy) {
                                $proxy["rotationValue"] = $rotationMethod === WOOTIFY_STRIPE_BY_TIME ? $proxy["timestamp"] : $proxy["amount"];
                                $className = isset($activatedProxy['id']) && $activatedProxy['id'] === $proxy['id'] ? 'activated-proxy' : '';
                                echo "
                                <tr class='proxy {$className}'>
                                    <td>
                                        <input type='checkbox' class='form-control proxy-id' value='{$proxy["id"]}'>
                                    </td>
                                    <td>
                                        <input type='text' class='form-control proxy-url' value='{$proxy["url"]}'>
                                    </td>
                                    <td>
                                        <input type='number' class='form-control proxy-rotation-value' value='{$proxy["rotationValue"]}'>
                                    </td>
                                    <td class='today-paid-amount'>
                                        <span class='by-amount'>{$proxy['paid_amount']}</span>
                                    </td>
                                </tr>
                                ";
                            }
                            ?>
                        </tbody>
                    </table>
                    <div class="control-button">
                        <button id="btn-save" class="btn btn-success mr-4" type="button">Save all</button>
                        <button id="btn-force-active" class="btn btn-primary mr-4" type="button">Force active</button>
                        <button id="btn-move-unused" class="btn btn-danger" type="button">Move to unused</button>
                    </div>

                    <table class="table table-unused table-hover table-borderless">
                        <thead>
                            <tr style="border-top: 1px solid rgba(0,0,0,.1)">
                                <th scope="col" class="checkbox-col"></th>
                                <th scope="col" class="proxy-url-col">
                                    <b>Unused List</b>
                                </th>
                                <th scope="col" class="rotation-value-col">
                                <th scope="col" class="control-button-col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($unusedProxies as $proxy) {
                                $proxy["rotationValue"] = $rotationMethod === WOOTIFY_STRIPE_BY_TIME ? $proxy["timestamp"] : $proxy["amount"];
                                echo "
                                <tr class='proxy'>
                                    <td>
                                        <input type='checkbox' class='form-control proxy-id' value='{$proxy["id"]}'>
                                    </td>
                                    <td>
                                        <input type='text' class='form-control proxy-url' value='{$proxy["url"]}'>
                                    </td>
                                    <td>
                                        <input type='number' class='form-control proxy-rotation-value' value='{$proxy["rotationValue"]}'>
                                    </td>
                                    <td></td>
                                </tr>
                            ";
                            }
                            ?>
                        </tbody>
                    </table>
                    <div class="control-button">
                        <button id="btn-move-back" class="btn btn-primary mr-4" type="button">Move back</button>
                        <button id="btn-delete" class="btn btn-danger" type="button">Delete</button>
                    </div>

                </div>
            </div>
        </div>
<?php
    }
}

new WOOTIFY_Stripe_Paygate_Option();


