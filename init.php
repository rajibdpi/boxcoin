<?php

/*
 * ==========================================================
 * INIT.PHP
 * ==========================================================
 *
 * This file loads and initilizes the payments
 *
 */

if (!file_exists(__DIR__ . '/config.php')) die();
require_once(__DIR__ . '/functions.php');
if (isset($_POST['data'])) $_POST = json_decode($_POST['data'], true);
if (BXC_CLOUD) {
    bxc_cloud_load();
    if (floatval(bxc_settings_db('credit_balance')) < 0) {
        bxc_cloud_credit_email(-1);
        die('no-credit-balance');
    }
}
if (isset($_POST['init'])) {
    bxc_checkout_init();
}
if (isset($_POST['checkout'])) {
    bxc_checkout($_POST['checkout']);
}

function bxc_checkout_init() {
    $qr_color = bxc_settings_get('color-2');
    if ($qr_color) {
        if (strpos('#', $qr_color) !== false) {
            $qr_color = substr($qr_color, 1);
        } else {
            $qr_color = str_replace(['rgb(', ')', ',', ' '], ['', '', '-', ''], $qr_color);
        }
    } else {
        $qr_color = '23413e';
    }
    $language = bxc_language();
    $translations = $language ? file_get_contents(__DIR__ . '/resources/languages/client/' . $language . '.json') : '{}';
    $settings = ['qr_code_color' => $qr_color, 'countdown' => bxc_settings_get('refresh-interval', 60), 'confirmations' => bxc_settings_get('confirmations', 3), 'webhook' => bxc_settings_get('webhook-url'), 'redirect' => bxc_settings_get('payment-redirect'), 'vat_validation' => bxc_settings_get('vat-validation'), 'names' => bxc_crypto_name()];
    echo 'var BXC_TRANSLATIONS = ' . ($translations ? $translations : '{}') . '; var BXC_URL = "' . BXC_URL . '"; var BXC_SETTINGS = ' . json_encode($settings, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE) . ';';
}

function bxc_select_countries() {
    $countries = json_decode(file_get_contents(__DIR__ . '/resources/countries.json'), true);
    $code = '';
    foreach ($countries as $key => $country_code) {
        $code .= '<option value="' . $key . '" data-country-code="' . $country_code . '">' . bxc_($key) . '</option>';
    }
    echo $code;
}

function bxc_checkout($settings) {
    $checkout_id = $settings['checkout_id'];
    $custom = strpos($checkout_id, 'custom') !== false;
    $cryptocurrencies = [['btc', 'Bitcoin'], ['eth', 'Ethereum'], ['xrp', 'XRP'], ['usdt', 'Tether'], ['usdt_tron', 'Tether'], ['usdt_bsc', 'Tether'], ['usdc', 'USD Coin'], ['busd', 'Binance USD'], ['bnb', 'BNB'], ['doge', 'Dogecoin'], ['shib', 'Shiba Inu'], ['ltc', 'Litecoin'], ['link', 'Chainlink'], ['bat', 'Basic Attention Token'], ['algo', 'Algorand'], ['bch', 'Bitcoin Cash']];
    $cryptocurrencies_code = '';
    $custom_tokens = bxc_get_custom_tokens();
    $collapse = bxc_settings_get('collapse');
    if (!$custom) {
        if (!is_numeric($checkout_id)) die();
        $settings = bxc_checkout_get($checkout_id);
    }
    if (!$settings) die();
    $title = ($custom && !empty($settings['title'])) || (!isset($settings['hide_title']) && !bxc_settings_get('hide-title')) || empty($settings['hide_title']);
    $currency_code = empty($settings['currency']) ? bxc_settings_get('currency', 'USD') : $settings['currency'];
    if (bxc_settings_get('stripe-active') || bxc_settings_get('verifone-active')) {
        $cryptocurrencies_code .= '<div data-cryptocurrency="' . (bxc_settings_get('stripe-active') ? 'stripe' : 'verifone') . '" class="bxc-flex"><img src="' . BXC_URL . 'media/icon-cc.svg" alt="' . bxc_('Credit or debit card') . '" /><span>' . bxc_('Credit or debit card') . '</span></div>';
    }
    foreach ($custom_tokens as $key => $value) {
        $cryptocurrencies_code .= '<div data-custom-coin="' . $value['type'] . '" data-cryptocurrency="' . $key . '" class="bxc-flex"><img src="' . $value['img'] . '" alt="' . $value['name'] . '" /><span>' . $value['name'] . '</span><span>' . strtoupper($key) . '</span></div>';
    }
    if ($title) {
        $title = bxc_isset($settings, 'title', bxc_settings_get('form-title'));
        if ($title) $title = '<div class="bxc-top"><div><div class="bxc-title">' . bxc_($title) . '</div><div class="bxc-text">' . trim(bxc_(empty($settings['description']) ? bxc_settings_get('form-description', '') : $settings['description'])) . '</div></div></div>';
    }
    for ($i = 0; $i < count($cryptocurrencies); $i++) {
        $cryptocurrency_code = $cryptocurrencies[$i][0];
        if (bxc_settings_get('address-' . $cryptocurrency_code)) {
            $cryptocurrencies_code .= '<div data-cryptocurrency="' . $cryptocurrency_code . '" class="bxc-flex"><img src="' . BXC_URL . 'media/icon-' . $cryptocurrency_code . '.svg" alt="' . strtoupper($cryptocurrency_code) . '" /><span>' . $cryptocurrencies[$i][1] . bxc_crypto_get_network($cryptocurrency_code, true) . '</span><span>' . strtoupper(bxc_crypto_get_base_code($cryptocurrency_code)) . '</span></div>';
        }
    }
    $checkout_price = floatval($settings['price']);
    if ($checkout_price == -1) $checkout_price = '';
    $checkout_start_price = $checkout_price;
    $checkout_type = empty($_POST['payment_page']) ? bxc_isset($settings, 'type', 'I') : 'I';
    $checkout_type = bxc_isset(['I' => 'inline', 'L' => 'link', 'P' => 'popup', 'H' => 'hidden'], $checkout_type, $checkout_type);
    echo '<!-- Boxcoin - https://boxcoin.dev -->';
    if ($checkout_type == 'popup') echo '<div class="bxc-btn bxc-btn-popup"><img src="' . BXC_URL . '/media/icon-cryptos.svg" alt="" />' . bxc_(bxc_settings_get('button-text', 'Pay now')) . '</div><div class="bxc-popup-overlay"></div>';
    $css = false;
    $color_1 = bxc_settings_get('color-1');
    $color_2 = bxc_settings_get('color-2');
    $color_3 = bxc_settings_get('color-3');
    $vat = bxc_settings_get('vat');
    if ($vat && $checkout_price) {
        $vat_details = bxc_vat($checkout_price, false, $currency_code);
        $checkout_price = $vat_details[0];
        $vat = '<span class="bxc-vat" data-country="' . $vat_details[3] . '" data-country-code="' . $vat_details[2] . '" data-amount="' . $vat_details[1] . '" data-percentage="' . $vat_details[5] . '">' . $vat_details[4] . '</span>';
    }
    if ($color_1) {
        $css = '.bxc-payment-methods>div:hover .bxc-label,.bxc-payment-methods>div:hover,.bxc-btn.bxc-btn-border:hover, .bxc-btn.bxc-btn-border:active { border-color: ' . $color_1 . '; color: ' . $color_1 . '; }';
        $css .= '.bxc-complete-cnt>i, .bxc-failed-cnt>i,.bxc-payment-methods>div:hover span+span,.bxc-clipboard:hover,.bxc-tx-cnt .bxc-loading:before,.bxc-loading:before,.bxc-btn-text:hover { color: ' . $color_1 . '; }';
        $css .= '.bxc-tx-status,.bxc-select ul li:hover { background-color: ' . $color_1 . '; }';
    }
    if ($color_2) {
        $css .= '.bxc-box { color: ' . $color_2 . '; }';
    }
    if ($color_3) {
        $css .= '.bxc-text,.bxc-payment-methods>div span+span { color: ' . $color_3 . '; }';
        $css .= '.bxc-btn.bxc-btn-border { border-color: ' . $color_3 . '; color: ' . $color_3 . '; }';
    }
    if ($css) echo '<style>' . $css . '</style>';
?>
<div class="bxc-main bxc-start bxc-<?php echo $checkout_type; if (bxc_is_rtl(bxc_language())) echo ' bxc-rtl'; ?>" data-currency="<?php echo $currency_code ?>" data-price="<?php echo $checkout_price ?>" data-external-reference="<?php echo bxc_isset($settings, 'external_reference', bxc_isset($settings, 'external-reference', '')) ?>" data-title="<?php echo str_replace('"', '', bxc_isset($settings, 'title', '')) ?>" data-note="<?php echo str_replace('"', '', bxc_isset($settings, 'note', '')) ?>" data-redirect="<?php echo bxc_isset($settings, 'redirect', '') ?>" data-start-price="<?php echo $checkout_start_price ?>">
    <?php if ($checkout_type == 'popup') echo '<i class="bxc-popup-close bxc-icon-close"></i>' ?>
    <div class="bxc-cnt bxc-box">
        <?php echo $title ?>
        <div class="bxc-body">
            <div class="bxc-flex bxc-amount-fiat<?php if (!$checkout_price) echo ' bxc-donation' ?>">
                <div class="bxc-title">
                    <?php bxc_e('Total') ?>
                    <?php if (!$checkout_price) echo '<div class="bxc-text">' . bxc_(bxc_settings_get('user-amount-text', 'Pay what you want')) . '</div>'; ?>
                </div>
                <div class="bxc-title">
                    <?php echo $checkout_price ? strtoupper($currency_code) . ' <span class="bxc-amount-fiat-total">' . bxc_decimal_number($checkout_price) . '</span>' . $vat : '<div class="bxc-input" id="user-amount"><span>' . strtoupper($settings['currency']) . '</span><input type="number" min="0" /></div>' ?>
                </div>
            </div>
            <?php if (bxc_settings_get('invoice-active')) { ?>
            <div class="bxc-billing-cnt">
                <div id="bxc-btn-invoice" class="bxc-link bxc-underline">
                    <?php bxc_e('Generate invoice?') ?>
                </div>
                <div id="bxc-billing" class="bxc-billing bxc-hidden">
                    <i id="bxc-btn-invoice-close" class="bxc-icon-close bxc-btn-red bxc-btn-icon"></i>
                    <div class="bxc-title bxc-title-1"><?php bxc_e('Billing information') ?></div>
                    <div class="bxc-input">
                        <input name="name" type="text" placeholder="<?php bxc_e('Full name') ?>" />
                    </div>
                    <div class="bxc-input">
                        <input name="vat" type="text" placeholder="<?php bxc_e('VAT') ?>" />
                    </div>
                    <div class="bxc-input">
                        <input name="address" type="text" placeholder="<?php bxc_e('Address') ?>" />
                    </div>
                    <div class="bxc-input bxc-input-3x">
                        <input name="city" type="text" placeholder="<?php bxc_e('City') ?>" />
                        <input name="state" type="text" placeholder="<?php bxc_e('State') ?>" />
                        <input name="zip" type="text" placeholder="<?php bxc_e('ZIP code') ?>" />
                    </div>
                    <div class="bxc-input">
                        <select name="country">
                            <option></option>
                            <?php bxc_select_countries() ?>
                        </select>
                    </div>
                    <div class="bxc-title bxc-title-2" /><?php bxc_e('Payment') ?></div>
                </div>
            </div>
            <?php } ?>
            <div class="bxc-payment-methods-cnt">
                <div <?php if ($collapse) echo 'class="bxc-collapse"' ?>>
                    <div class="bxc-payment-methods">
                        <?php echo $cryptocurrencies_code ?>
                    </div>
                    <?php if ($collapse) echo '<div class="bxc-btn-text bxc-collapse-btn"><i class="bxc-icon-arrow-down"></i>' . bxc_('All cryptocurrencies') . '</div>' ?>
                </div>
            </div>
        </div>
    </div>
    <div class="bxc-pay-cnt bxc-box">
        <div class="bxc-top">
            <div class="bxc-pay-top-main">
                <div class="bxc-title">
                    <?php bxc_e(bxc_settings_get('form-payment-title', 'Send payment')) ?>
                    <div class="bxc-flex">
                        <div class="bxc-countdown bxc-toolip-cnt">
                            <div data-countdown="<?php bxc_settings_get('refresh-interval', 60) ?>"></div>
                            <span class="bxc-toolip">
                                <?php bxc_e('Checkout timeout') ?>
                            </span>
                        </div>
                        <div class="bxc-btn bxc-btn-border bxc-back">
                            <i class="bxc-icon-back"></i><?php bxc_e('Back') ?>
                        </div>
                    </div>
                </div>
                <?php echo '<div class="bxc-text">' . trim(bxc_(bxc_settings_get('form-payment-description', ''))) . '</div>' ?>
            </div>
            <div class="bxc-pay-top-back">
                <div class="bxc-title">
                    <?php bxc_e('Are you sure?') ?>
                </div>
                <div class="bxc-text">
                    <?php bxc_e('This transaction will be cancelled. If you already sent the payment please wait.') ?>
                </div>
                <div id="bxc-confirm-cancel" class="bxc-btn bxc-btn-border bxc-btn-red">
                    <?php bxc_e('Yes, I\'m sure') ?>
                </div>
                <div id="bxc-abort-cancel" class="bxc-btn bxc-btn-border bxc-back">
                    <?php bxc_e('Cancel') ?>
                </div>
            </div>
        </div>
        <div class="bxc-body">
            <div class="bxc-flex">
                <?php if (!bxc_settings_get('disable-qrcode')) echo '<img class="bxc-qrcode" src="" alt="QR code" />' ?>
                <div class="bxc-flex bxc-qrcode-text">
                    <img src="" alt="" />
                    <div class="bxc-text"></div>
                </div>
            </div>
            <div class="bxc-flex bxc-pay-address">
                <div>
                    <div class="bxc-text"></div>
                    <div class="bxc-title"></div>
                </div>
                <i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt">
                    <span class="bxc-toolip">
                        <?php bxc_e('Copy to clipboard') ?>
                    </span>
                </i>
            </div>
            <div class="bxc-flex bxc-pay-amount">
                <div>
                    <div class="bxc-text">
                        <?php bxc_e('Total amount') ?>
                    </div>
                    <div class="bxc-title"></div>
                </div>
                <div class="bxc-flex">
                    <?php if (bxc_settings_get('metamask')) echo '<div id="metamask" class="bxc-btn bxc-btn-img bxc-hidden"><img src="' . BXC_URL . 'media/metamask.svg" alt="MetaMask" />MetaMask</div>' ?>
                    <i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt">
                        <span class="bxc-toolip">
                            <?php bxc_e('Copy to clipboard') ?>
                        </span>
                    </i>
                </div>
            </div>
        </div>
    </div>
    <div class="bxc-tx-cnt bxc-box">
        <div class="bxc-loading"></div>
        <div class="bxc-title">
            <?php bxc_e('Payment received') ?>
        </div>
        <div class="bxc-flex">
            <div class="bxc-tx-status"></div>
            <div class="bxc-tx-confirmations">
                <span></span> /
            </div>
            <div>
                <?php bxc_e('confirmations') ?>
            </div>
        </div>
    </div>
    <div class="bxc-complete-cnt bxc-box">
        <i class="bxc-icon-check"></i>
        <div class="bxc-title">
            <?php bxc_e(bxc_settings_get('success-title', 'Payment completed')) ?>
        </div>
        <div class="bxc-text">
            <span>
                <?php bxc_e(bxc_settings_get('success-description', 'Thank you for your payment')) ?>
            </span>
            <span>
                <?php bxc_e(bxc_settings_get('order-processing-text', 'We are processing the order, please wait...')) ?>
            </span>
        </div>
    </div>
    <div class="bxc-failed-cnt bxc-box">
        <i class="bxc-icon-close"></i>
        <div class="bxc-title">
            <?php bxc_e(bxc_settings_get('failed-title', 'No payment')) ?>
        </div>
        <div class="bxc-text">
            <?php bxc_e(bxc_settings_get('failed-text', 'We didn\'t detect a payment. If you have already paid, please contact us.')) ?>
        </div>
        <div class="bxc-text">
            <?php bxc_e('Your transaction ID is:') ?>
            <span id="bxc-expired-tx-id"></span>
        </div>
        <div class="bxc-btn bxc-btn-border ">
            <?php bxc_e('Retry') ?>
        </div>
    </div>
    <div class="bxc-underpayment-cnt bxc-box">
        <i class="bxc-icon-close"></i>
        <div class="bxc-title">
            <?php bxc_e(bxc_settings_get('underpayment-title', 'Underpayment')) ?>
        </div>
        <div class="bxc-text">
            <?php bxc_e(bxc_settings_get('underpayment-description', 'We have detected your payment but the amount is less than requested and the transaction cannot be completed, please contact us.')) ?>
            <?php bxc_e('Your transaction ID is:') ?><span id="bxc-underpaid-tx-id"></span>
        </div>
    </div>
    <?php if (BXC_CLOUD) echo '<a href="' . CLOUD_POWERED_BY[0] . '" target="_blank" class="bxc-cloud-branding" style="display:flex !important;"><span style="display:block !important;">Powered by</span><img style="display:block !important;" src="' . CLOUD_POWERED_BY[1] . '" alt="" /></a>' ?>
</div>
<?php } ?>