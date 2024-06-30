<?php

/*
 * ==========================================================
 * PAY.PHP
 * ==========================================================
 *
 * Payment page
 *
 */

if (!file_exists(__DIR__ . '/config.php')) die();
require(__DIR__ . '/functions.php');
if (BXC_CLOUD) bxc_cloud_load();
$logo = bxc_settings_get('logo-pay');
$minify = isset($_GET['debug']) ? false : (BXC_CLOUD || bxc_settings_get('minify'));
if (isset($_GET['invoice'])) {
    $invoice = bxc_transactions_invoice($_GET['invoice']);
    die($invoice ? '<script>document.location = "' . $invoice . '"</script>' : 'Transaction not found or not completed.');
}
$code_transaction = '';
if (isset($_GET['id']) && !isset($_GET['demo'])) {
    $transaction = bxc_transactions_get(bxc_encryption($_GET['id'], false));
    if (!$transaction) die('Transaction not found.');
    if ($transaction['status'] != 'P') die('Transaction not in pending status.');
    $_GET['checkout_id'] = 'custom-pay-page';
    $code_transaction = '<script>BOXCoin.checkout.storageTransaction("custom-pay-page", "delete"); BOXCoin.checkout.storageTransaction("custom-pay-page", { id: ' . $transaction['id'] . ', amount: "' . $transaction['amount'] . '", address_payment: "' . $transaction['to'] . '", cryptocurrency: "' . $transaction['cryptocurrency'] . '", external_reference: "' . $transaction['external_reference'] . '", vat: "' . $transaction['vat'] . '" })</script>';
}
if (bxc_settings_get('css-pay')) $code_transaction .= PHP_EOL . '<link rel="stylesheet" href="' . bxc_settings_get('css-pay') . '" media="all" />';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
    <title>
        <?php bxc_e(bxc_settings_get('form-title', 'Payment method')) ?>
    </title>
    <?php if (isset($_GET['lang'])) echo '<script>var BXC_LANGUAGE = "' . substr($_GET['lang'], 0, 2) . '";</script>' ?>
    <link rel="shortcut icon" type="image/svg" href="<?php echo BXC_CLOUD ? CLOUD_ICON : ($logo ? bxc_settings_get('logo-icon-url', BXC_URL . 'media/icon.svg') : BXC_URL . 'media/icon.svg') ?>" />
    <script id="boxcoin" src="<?php echo BXC_URL . 'js/client' . ($minify ? '.min' : '') ?>.js"></script>
    <?php if (BXC_CLOUD) bxc_cloud_front() ?>
    <?php echo $code_transaction ?>
    <style>
        body {
            text-align: center;
            padding: 100px 0;
        }

        .bxc-main {
            text-align: left;
            margin: auto;
        }

        .bxc-pay-logo {
            text-align: center;
        }

        .bxc-pay-logo img {
            margin: 0 auto 30px auto;
            max-width: 200px;
        }
    </style>
</head>
<body style="display: none">
    <script>(function () { setTimeout(() => { document.body.style.removeProperty('display') }, 500) }())</script>
    <?php
    if ($logo) echo '<div class="bxc-pay-logo"><img src="' . bxc_settings_get('logo-url') . '" alt="" /></div>';
    bxc_checkout_direct();
    echo bxc_settings_get('pay-text');
    ?>
</body>
</html>