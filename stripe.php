<?php

/*
 * ==========================================================
 * STRIPE.PHP
 * ==========================================================
 *
 * Process Stripe payments
 *
 */

header('Content-Type: application/json');
$raw = file_get_contents('php://input');
$response = json_decode($raw, true);

if ($response && empty($response['error']) && $response['data']) {
    $data = $response['data']['object'];
    if (isset($data['metadata']) && isset($data['metadata']['source']) && $data['metadata']['source'] === 'boxcoin') {
        require('functions.php');
        if (BXC_CLOUD) {
            if (isset($data['metadata']['cloud'])) {
                $_POST['cloud'] = $data['metadata']['cloud'];
                bxc_cloud_load();
                bxc_cloud_spend_credit($data['amount_total'] / 100, $transaction['currency']);
            } else die();
        }
        switch ($response['type']) {
            case 'checkout.session.completed':
                $response = bxc_stripe_curl('events/' . $response['id'], 'GET');
                bxc_db_query('UPDATE bxc_transactions SET `from` = "' . bxc_db_escape($data['customer']) . '", status = "C" WHERE id = ' . bxc_db_escape($data['client_reference_id']));
                break;
        }
    }
}

?>