<?php
use GuzzleHttp\Promise\Is;

/*
 * ==========================================================
 * FUNCTIONS.PHP
 * ==========================================================
 *
 * Admin and client side functions. © 2022-2023 boxcoin.dev. All rights reserved.
 * You may not use Boxcoin to create a SAAS or Boxcoin-like business. For more details, visit https://boxcoin.dev/terms-of-service (see 5. Intellectual Property and Content Ownership).
 *
 */

define('BXC_VERSION', '1.1.4');
if (!defined('BXC_CLOUD')) define('BXC_CLOUD', file_exists(__DIR__ . '/cloud'));
require(__DIR__ . '/config.php');
global $BXC_LOGIN;
global $BXC_LANGUAGE;
global $BXC_TRANSLATIONS;
global $BXC_TRANSLATIONS_2;
global $BXC_APPS;
$BXC_APPS = ['wordpress'];
for ($i = 0; $i < count($BXC_APPS); $i++) {
    $file = __DIR__ . '/apps/' . $BXC_APPS[$i] . '/functions.php';
    if (file_exists($file)) {
        require_once($file);
    }
}

/*
 * -----------------------------------------------------------
 * TRANSACTIONS
 * -----------------------------------------------------------
 *
 * 1. Get transactions
 * 2. Get a single transaction
 * 3. Create a transaction
 * 4. Generate a random cryptcurrency amount
 * 5. Delete pending transactions older than 48h
 * 6. Check the transactions of an address
 * 7. Check a single transaction
 * 8. Check pending transactions
 * 9. Finalize a confirmed transaction
 * 10 Update a transaction
 * 11. Send the webhook for a specific transaction
 * 12. Download transactions in CSV format
 * 13. Generate an invoice
 * 14. Update a transaction
 * 15. Decrypt a transaction securely
 * 16. Generate a payment link for a transaction
 * 17. Get the transaction description array
 *
 */

function bxc_transactions_get_all($pagination = 0, $search = false, $status = false, $cryptocurrency = false, $date_range = false) {
    $where = '';
    if ($search) {
        $search = bxc_db_escape(trim($search));
        $where = is_numeric($search) ? '(amount LIKE "%' . $search . '%" OR amount_fiat LIKE "%' . $search . '%")' : '(title LIKE "%' . $search . '%" OR description LIKE "%' . $search . '%" OR cryptocurrency LIKE "%' . $search . '%" OR currency LIKE "%' . $search . '%" OR `from` LIKE "%' . $search . '%" OR `to` LIKE "%' . $search . '%" OR hash LIKE "%' . $search . '%" OR external_reference LIKE "%' . $search . '%")';
    }
    if ($status) {
        $where .= ($where ? ' AND ' : '') . ' status = "' . bxc_db_escape($status) . '"';
    }
    if ($cryptocurrency) {
        $where .= ($where ? ' AND ' : '') . ' cryptocurrency = "' . bxc_db_escape($cryptocurrency) . '"';
    }
    if ($date_range && $date_range[0]) {
        $where .= ($where ? ' AND ' : '') . ' creation_time >= "' . bxc_db_escape($date_range[0]) . '" AND creation_time <= "' . bxc_db_escape($date_range[1])  . '"';
    }
    $transactions = bxc_db_get('SELECT * FROM bxc_transactions' . ($where ? ' WHERE ' . $where : '') . ' ORDER BY id DESC' . ($pagination != -1 ? ' LIMIT ' . intval(bxc_db_escape($pagination, true)) * 100 . ',100' : ''), false);
    return $transactions;
}

function bxc_transactions_get($transaction_id) {
    return bxc_db_get('SELECT * FROM bxc_transactions WHERE id = ' . bxc_db_escape($transaction_id, true));
}

function bxc_transactions_create($amount, $cryptocurrency_code, $currency_code = false, $external_reference = '', $title = '', $description = '', $url = false, $billing = '', $vat = false) {
    $query_parts = ['INSERT INTO bxc_transactions(title, description, `from`, `to`, amount, amount_fiat, cryptocurrency, currency, external_reference, creation_time, status, webhook, billing, vat, vat_details) VALUES ("' . bxc_db_escape($title) . '", "' . ($description ? bxc_db_json_escape([base64_decode($description)]) : '') . '", "",', ', "' . bxc_db_escape($currency_code) . '", "' . bxc_db_escape($external_reference) . '", "' . gmdate('Y-m-d H:i:s') . '", "P", 0, "' . bxc_db_escape($billing) . '", "' . bxc_isset($vat, 'amount', 0) . '", "' . ($vat && !empty($vat['amount']) ? bxc_db_json_escape($vat) : '') . '")'];
    if (!$currency_code) $currency_code = bxc_settings_get('currency', 'USD');
    if (in_array($cryptocurrency_code, ['stripe', 'verifone'])) {
        $transaction_id = bxc_db_query($query_parts[0] . '"", "", "' . bxc_db_escape($amount, true) . '", "' . bxc_db_escape($cryptocurrency_code) . '"' . $query_parts[1], true);
        return [$transaction_id, $cryptocurrency_code, $cryptocurrency_code == 'verifone' ? bxc_verifone_create_checkout($amount, $url, $transaction_id, $title, $currency_code) : bxc_stripe_payment(floatval($amount) * 100, $url, $transaction_id, $currency_code)];
    }
    $decimals = bxc_crypto_get_decimals($cryptocurrency_code);
    $custom_token = bxc_isset(bxc_get_custom_tokens(), $cryptocurrency_code);
    $address = $custom_token ? $custom_token['address'] : bxc_crypto_get_address($cryptocurrency_code);
    $amount_cryptocurrency = $currency_code == 'crypto' ? [$amount, ''] : ($custom_token ? false : explode('.', strval(bxc_crypto_get_cryptocurrency_value($amount, $cryptocurrency_code, $currency_code, false))));
    if (bxc_crypto_whitelist_invalid($address, true, $cryptocurrency_code)) return 'whitelist-invalid';
    if ($amount_cryptocurrency && !isset($amount_cryptocurrency[1])) array_push($amount_cryptocurrency, '');
    if ($custom_token)  {
        $amount_cryptocurrency = explode('.', $amount * (1 / (floatval($custom_token['rate']) * ($currency_code == 'USD' ? 1 : bxc_usd_rates($currency_code)))));
        if (!isset($amount_cryptocurrency[1])) array_push($amount_cryptocurrency, '');
        $decimals = $custom_token['decimals'];
    };
    if (strlen($amount_cryptocurrency[1]) > $decimals) $amount_cryptocurrency[1] = substr($amount_cryptocurrency[1], 0, $decimals);
    $amount_cryptocurrency_string = $amount_cryptocurrency[0] . ($amount_cryptocurrency[1] ? '.' . $amount_cryptocurrency[1] : '');
    if ($address == bxc_settings_get_address($cryptocurrency_code)) {
        $temp = bxc_db_get('SELECT amount FROM bxc_transactions WHERE cryptocurrency = "' . bxc_db_escape($cryptocurrency_code) . '"', false);
        $existing_amounts = [];
        $i = 0;
        for ($i = 0; $i < count($temp); $i++) {
            array_push($existing_amounts, $temp[$i]['amount']);
        }
        while (in_array($amount_cryptocurrency_string, $existing_amounts) && $i < 1000) {
            $amount_cryptocurrency_string = bxc_transactions_random_amount($amount_cryptocurrency, $decimals);
            $i++;
        }
    }
    $transaction_id = bxc_db_query($query_parts[0] . '"' . $address . '", "' . $amount_cryptocurrency_string . '", "' . bxc_db_escape($amount, true) . '", "' . bxc_db_escape($cryptocurrency_code) . '"' . $query_parts[1], true);
    $url = bxc_is_demo(true);
    if ($url) {
        $amount_cryptocurrency_string = $url['amount'];
        $transaction_id = $url['id'];
    }
    return [$transaction_id, $amount_cryptocurrency_string, $address];
}

function bxc_transactions_random_amount($amount, $decimals) {
    $zeros = '';
    $check = $decimals > 2;
    if ($check) {
        if ($amount[$check]) {
            $i = 0;
            while (substr($amount[$check], $i, 1) == '0') {
                $zeros .= '0';
                $i++;
            }
        } else {
            for ($i = 0; $i < $decimals; $i++) {
                $amount[$check] .= $i;
            }
        }
    }
    $amount[$check] = $zeros . intval(intval($amount[$check]) * floatval('1.00' . rand(99, 9999)));
    if (strlen($amount[1]) > $decimals) $amount[1] = substr($amount[1], 0, $decimals);
    return $amount[0] . ($amount[1] && $amount[1] != '0' ? '.' . $amount[1] : '');
}

function bxc_transactions_delete_pending() {
    return bxc_db_query('DELETE FROM bxc_transactions WHERE status = "P" AND creation_time < "' . gmdate('Y-m-d H:i:s', time() - 172800) . '"');
}

function bxc_transactions_check($transaction_id) {
    $boxcoin_transaction = bxc_transactions_get($transaction_id);
    $refresh_interval = intval(bxc_settings_get('refresh-interval', 60)) * 60;
    $time = time();
    $transaction_creation_time = strtotime($boxcoin_transaction['creation_time'] . ' UTC');
    if ((($transaction_creation_time + $refresh_interval) <= $time) && !bxc_is_demo()) {
        return 'expired';
    }
    if ($boxcoin_transaction) {
        $cryptocurrency_code = $boxcoin_transaction['cryptocurrency'];
        $to = $boxcoin_transaction['to'];
        $address_generation = $to != bxc_settings_get_address($cryptocurrency_code) && !bxc_is_demo();
        if (bxc_crypto_whitelist_invalid($to, true, $cryptocurrency_code)) return false;
        $transactions = bxc_blockchain($cryptocurrency_code, 'transactions', false, $to);
        if (is_array($transactions)) {
            for ($i = 0; $i < count($transactions); $i++) {
                if ((empty($transactions[$i]['time']) || $transactions[$i]['time'] > $transaction_creation_time) && ($address_generation || $boxcoin_transaction['amount'] == $transactions[$i]['value'] || strpos($transactions[$i]['value'], $boxcoin_transaction['amount']) === 0)) {
                    return bxc_encryption(array_merge($boxcoin_transaction, ['hash' => $transactions[$i]['hash'], 'id' => $transaction_id, 'cryptocurrency' => $cryptocurrency_code, 'to' => $to]));
                }
            }
        } else {
            return ['error', $transactions];
        }
    }
    return false;
}

function bxc_transactions_check_single($transaction) {
    $minimum_confirmations = bxc_settings_get('confirmations', 3);
    $transaction = bxc_transactions_decrypt($transaction);
    $transaction_blockchain = bxc_blockchain($transaction['cryptocurrency'], 'transaction', $transaction['hash'], $transaction['to']);
    if (!$transaction_blockchain) return 'transaction-not-found';
    $confirmations = bxc_isset($transaction_blockchain, 'confirmations');
    if (!$confirmations && $transaction_blockchain['block_height']) $confirmations = bxc_blockchain($transaction['cryptocurrency'], 'blocks_count') - $transaction_blockchain['block_height'] + 1;
    $confirmed = $confirmations >= $minimum_confirmations;
    $response = $confirmed ? bxc_transactions_complete($transaction, $transaction_blockchain['value'], $transaction_blockchain['address']) : [];
    return ['confirmed' => $confirmed, 'confirmations' => $confirmations ? $confirmations : 0, 'minimum_confirmations' => $minimum_confirmations, 'hash' => $transaction['hash'], 'invoice' => bxc_isset($transaction, 'billing') && bxc_settings_get('invoice-active') ? bxc_transactions_invoice($transaction['id']) : false, 'underpayment' => bxc_isset($response, 'underpayment') ? $transaction_blockchain['value'] : false, 'redirect' => bxc_isset($response, 'redirect'), 'source' => bxc_isset($response, 'source')];
}

function bxc_transactions_check_pending() {
    $transactions = bxc_db_get('SELECT * FROM bxc_transactions WHERE status = "P" AND creation_time > "' . gmdate('Y-m-d H:i:s', time() - 172800) . '"', false);
    $transactions_blockchains = [];
    for ($i = 0; $i < count($transactions); $i++) {
        $transaction = $transactions[$i];
        $to = $transaction['to'];
        $cryptocurrency_code = strtolower($transaction['cryptocurrency']);
        if (bxc_crypto_whitelist_invalid($to, true, $cryptocurrency_code)) continue;
        if (!isset($transactions_blockchains[$to])) {
            $transactions_blockchains[$to] = bxc_blockchain($cryptocurrency_code, 'transactions', ['limit' => 99], $to);
        }
        $transactions_blockchain = $transactions_blockchains[$to];
        $address_generation = $to != bxc_settings_get_address($cryptocurrency_code);
        if (is_array($transactions_blockchain)) {
            for ($y = 0; $y < count($transactions_blockchain); $y++) {
                $transaction_blockchain = $transactions_blockchain[$y];
                if ((empty($transaction_blockchain['time']) || $transaction_blockchain['time'] > strtotime($transaction['creation_time'] . ' UTC')) && ($address_generation || $transaction['amount'] == $transaction_blockchain['value'] || strpos($transaction_blockchain['value'], $transaction['amount']) === 0)) {
                    $transaction['hash'] = $transaction_blockchain['hash'];
                    $response = bxc_transactions_check_single($transaction);
                    if ($response && !empty($response['confirmed'])) {
                        bxc_transactions_update($transaction['id'], ['status' => 'C']);
                    }
                }
            }
        }
    }
}

function bxc_transactions_complete($transaction, $amount_blockchain, $address_from) {
    $redirect = false;
    $source = false;
    $amount = $transaction['amount'];
    $cryptocurrency_code = $transaction['cryptocurrency'];
    $external_reference = $transaction['external_reference'];
    $underpayment = floatval($amount_blockchain) < floatval($amount);
    $node_transfer = false;
    if ($underpayment) {
        $note = $amount_blockchain . '/' . $amount . ' ' . strtoupper($cryptocurrency_code) . ' ' . bxc_('received') . '. ' . bxc_decimal_number(floatval($amount) - floatval($amount_blockchain)) . ' ' . strtoupper($cryptocurrency_code) . ' ' . bxc_('are missing.');
        $description = bxc_transactions_get_description($transaction['id']);
        if (!in_array($note, $description)) array_push($description, $note);
        $underpayment = ', description = "' . bxc_db_json_escape($description) . '"';
    }
    bxc_db_query('UPDATE bxc_transactions SET `from` = "' . bxc_db_escape($address_from) . '", hash = "' . bxc_db_escape($transaction['hash']) . '", status = "' . ($underpayment ? 'X' : 'C') . '"' . $underpayment . ' WHERE id = ' . bxc_db_escape($transaction['id'], true));
    if (($cryptocurrency_code === 'btc' && bxc_settings_get('btc-node-address-generation')) || bxc_is_eth_address_generation($cryptocurrency_code)) {
        $ethereum = $cryptocurrency_code !== 'btc';
        $prefix = $ethereum ? 'eth' : 'btc';
        $addresses = json_decode(bxc_encryption(bxc_settings_db($prefix . '-addresses'), false), true);
        for ($i = 0; $i < count($addresses); $i++) {
            if ($addresses[$i][0]['address'] == $transaction['to']) {
                require_once(__DIR__ . ($ethereum ? '/web3.php' : '/bitcoin.php'));
                $node_transfer = bxc_settings_get($prefix . '-node-transfer');
                $to = $node_transfer ? bxc_settings_get($prefix . '-node-transfer-address') : bxc_settings_get($prefix. '-address');
                if ($ethereum) {
                    bxc_eth_transfer($amount, $cryptocurrency_code, $to, $transaction['to'], $addresses[$i][0]['private_key']);
                } else {
                    bxc_btc_transfer($amount, $to, $transaction['to'], $addresses[$i][0]['private_key']);
                }
                break;
            }
        }
    }
    bxc_crypto_convert($transaction['id'], $cryptocurrency_code, $amount_blockchain);
    if (!$node_transfer) bxc_crypto_transfer($transaction['id'], $cryptocurrency_code, $amount_blockchain);
    if (bxc_settings_get('notifications-sale')) {
        $language = bxc_settings_get('language-admin');
        bxc_email_notification(($underpayment ? '[' . bxc_m('Underpayment', $language) . '] ' : '') . bxc_m('New payment of', $language) . ' ' . $transaction['amount_fiat'] . ' ' . strtoupper($transaction['currency']), str_replace('{T}', $transaction['amount_fiat'] . ' ' . $transaction['currency'] . ' (' . $amount_blockchain . ' ' . strtoupper($cryptocurrency_code) . ')' . ($underpayment ? ' (<b>' . bxc_m('Underpayment', $language) . '</b>)' : ''), bxc_m('A new payment of {T} has been sent to your', $language)) . ' ' . ucfirst(bxc_crypto_name($transaction['cryptocurrency'])) . ' ' . bxc_m('address', $language) . ' <b>' . $transaction['to'] . '</b>. ' . bxc_m('Transaction ID' . ': ' . $transaction['id'], $language));
    }
    if (strpos($external_reference, 'shopify_') === 0) {
        bxc_curl(bxc_settings_get('shopify-url') . '/admin/api/2023-01/orders/' . str_replace('shopify_', '', $external_reference) . '/transactions.json', json_encode(['transaction' => ['currency' => $transaction['currency'], 'amount' => $transaction['amount_fiat'], 'kind' => 'capture']]), ['X-Shopify-Access-Token: ' . trim(bxc_settings_get('shopify-token'))], 'POST');
    }
    if (!$underpayment) {
        $external_reference = explode('|', bxc_encryption($external_reference, false));
        $source = in_array('woo', $external_reference) ? 'woo' : (in_array('edd', $external_reference) ? 'edd' : false);
        $redirect = $source == 'woo' ? $external_reference[1] : ($source ? bxc_settings_db('wp_edd_url') : false);
        bxc_transactions_webhook($transaction, $source ? bxc_settings_db('wp_api_url') : false);
    }
    if (BXC_CLOUD) bxc_cloud_spend_credit($transaction['amount_fiat'], $transaction['currency']);
    return ['underpayment' => $underpayment, 'redirect' => $redirect, 'source' => $source];
}

function bxc_transactions_update($transaction_id, $values) {
    $query = 'UPDATE bxc_transactions';
    if (is_string($values)) $values = json_decode($values, true);
    foreach ($values as $key => $value) {
    	$query .= ' SET ' . bxc_db_escape($key) . ' = "' . bxc_db_escape($value) . '",';
    }
    if (bxc_isset($values, 'status') == 'C') {
        $transaction = bxc_transactions_get($transaction_id);
        bxc_transactions_complete($transaction, $transaction['amount'], $transaction['from']);
    }
    return bxc_db_query(substr($query, 0, -1) . ' WHERE id = '. bxc_db_escape($transaction_id, true));
}

function bxc_transactions_webhook($transaction, $webhook_url = false) {
    if (!$webhook_url) $webhook_url = bxc_settings_get('webhook-url');
    if (!$webhook_url) return false;
    if (is_string($transaction)) $transaction = ['id' => bxc_transactions_decrypt($transaction)['id']];
    $webhook_secret_key = bxc_settings_get('webhook-secret');
    $transaction = bxc_transactions_get($transaction['id']);
    if ($transaction['status'] != 'C') return false;
    if ($transaction['webhook']) {
        $url = bxc_is_demo(true);
        if (!$url || bxc_isset($url, 'webhook_key') != $webhook_secret_key) return false;
    }
    $body = json_encode(['key' => $webhook_secret_key ? $webhook_secret_key : BXC_PASSWORD, 'transaction' => $transaction]);
    bxc_db_query('UPDATE bxc_transactions SET webhook = 1 WHERE id = ' . $transaction['id']);
    return bxc_curl($webhook_url, $body, ['Content-Type: application/json', 'Content-Length: ' . strlen($body)], 'POST');
}

function bxc_transactions_download($search = false, $status = false, $cryptocurrency = false, $date_range = false) {
    return bxc_csv(bxc_transactions_get_all(-1, $search, $status, $cryptocurrency, $date_range), ['ID', 'Title', 'Description', 'From', 'To', 'Hash', 'Amount', 'Amount FIAT', 'Cryptocurrency', 'Currency', 'External Reference', 'Creation Time', 'Status', 'Webhook', 'Billing', 'VAT', 'VAT details'], 'transactions');
}

function bxc_transactions_invoice($transaction_id) {
    require_once __DIR__ . '/vendor/fpdf/fpdf.php';
    require_once __DIR__ . '/vendor/fpdf/autoload.php';
    require_once __DIR__ . '/vendor/fpdf/Fpdi.php';

    $file_name = 'inv-' . $transaction_id . '.pdf';
    $invoice_url = BXC_URL . 'uploads/' . $file_name;
    if (file_exists(__DIR__ . '/uploads/' . $file_name)) return $invoice_url;
    $transaction = bxc_transactions_get($transaction_id);
    if (!$transaction || $transaction['status'] != 'C') return false;
    $billing = json_decode($transaction['billing'], true);
    $billing_text = $billing ? bxc_isset($billing, 'name', '') . PHP_EOL . bxc_isset($billing, 'address', '') . PHP_EOL . bxc_isset($billing, 'city', '') . ', ' . bxc_isset($billing, 'state', '') . ', ' . bxc_isset($billing, 'zip', '') . PHP_EOL . bxc_isset($billing, 'country', ''). PHP_EOL . PHP_EOL . bxc_isset($billing, 'vat', '') : '';

    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->AddPage();
    $pdf->setSourceFile(__DIR__ . '/resources/invoice.pdf');
    $tpl = $pdf->importPage(1);
    $pdf->useTemplate($tpl, 0, 0, null, null);
    $pdf->SetTextColor(90, 90, 90);

    $pdf->SetXY(20, 29);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->Cell(1000, 1,  bxc_('Tax Invoice'));

    $pdf->SetXY(130, 27);
    $pdf->SetFont('Arial', '', 13);
    $pdf->Multicell(500, 7, bxc_('Invoice date: ') . date('d-m-Y') . PHP_EOL . bxc_('Invoice number: ') . 'INV-' . $transaction['id']);

    $pdf->SetXY(20, 60);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(50, 1, bxc_('To'));
    $pdf->SetFont('Arial', '', 13);
    $pdf->SetXY(20, 70);
    $pdf->Multicell(168, 7, strip_tags(trim(iconv('UTF-8', 'windows-1252', $billing_text))));

    $pdf->SetXY(130, 60);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(168, 1, bxc_('Supplier'));
    $pdf->SetFont('Arial', '', 13);
    $pdf->SetXY(130, 70);
    $pdf->Multicell(168, 7, strip_tags(trim(iconv('UTF-8', 'windows-1252', bxc_settings_get('invoice-details')))));

    $pdf->SetXY(20, 150);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(168, 1, bxc_('Purchase details'));
    $pdf->SetFont('Arial', '', 13);
    $pdf->SetXY(20, 160);
    $pdf->Cell(168, 1, $transaction['title']);

    $pdf->SetXY(20, 180);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(168, 1, bxc_('Transaction amount'));
    $pdf->SetFont('Arial', '', 13);
    $pdf->SetXY(20, 190);
    $pdf->Cell(168, 1, strtoupper($transaction['currency']) . ' ' . $transaction['amount_fiat'] . ' (' . strtoupper($transaction['cryptocurrency']) . ' ' . $transaction['amount'] . ')');
    if ($transaction['vat']) {
        $pdf->SetXY(20, 200);
        $pdf->Cell(100, 1, 'VAT ' . strtoupper($transaction['currency']) . ' ' . $transaction['vat']);
    }
    $pdf->Output(__DIR__ . '/uploads/' . $file_name, 'F');
    return $invoice_url;
}

function bxc_transactions_decrypt($transaction) {
    if (is_string($transaction)) return json_decode(bxc_encryption($transaction, false), true);
    if (!bxc_verify_admin()) {
        bxc_error('security-error', 'bxc_transactions_decrypt');
        return 'security-error';
    }
    return $transaction;
}

function bxc_payment_link($transaction_id) {
    return BXC_URL . 'pay.php?id=' . bxc_encryption($transaction_id);
}

function bxc_transactions_get_description($transaction_id) {
    $description = json_decode(bxc_isset(bxc_db_get('SELECT description FROM bxc_transactions WHERE id = ' .  bxc_db_escape($transaction_id, true)), 'description', '[]'), true);
    return $description ? $description : [];
}

/*
 * -----------------------------------------------------------
 * CHECKOUT
 * -----------------------------------------------------------
 *
 * 1. Return all checkouts or the specified one
 * 2. Save a checkout
 * 3. Delete a checkout
 * 4. Direct payment checkout
 *
 */

function bxc_checkout_get($checkout_id = false) {
    return bxc_db_get('SELECT * FROM bxc_checkouts' . ($checkout_id ? ' WHERE id = ' . bxc_db_escape($checkout_id, true) : ''), $checkout_id);
}

function bxc_checkout_save($checkout) {
    $checkout = json_decode($checkout, true);
    if (empty($checkout['currency'])) $checkout['currency'] = bxc_settings_get('currency', 'USD');
    if (empty($checkout['id'])) {
        return bxc_db_query('INSERT INTO bxc_checkouts(title, description, price, currency, type, redirect, hide_title, external_reference, creation_time) VALUES ("' . bxc_db_escape($checkout['title']) . '", "' . bxc_db_escape(bxc_isset($checkout, 'description', '')) . '", "' . bxc_db_escape($checkout['price'], true) . '", "' . bxc_db_escape(bxc_isset($checkout, 'currency', '')) . '", "' . bxc_db_escape($checkout['type']) . '", "' . bxc_db_escape(bxc_isset($checkout, 'redirect', '')) . '", ' . (empty($checkout['hide_title']) ? 0 : 1) . ', "' . bxc_db_escape(bxc_isset($checkout, 'external_reference', '')) . '", "' . gmdate('Y-m-d H:i:s') . '")', true);
    } else {
        return bxc_db_query('UPDATE bxc_checkouts SET title = "' . bxc_db_escape($checkout['title']) . '", description = "' . bxc_db_escape(bxc_isset($checkout, 'description', '')) . '", price = "' . bxc_db_escape($checkout['price'], true) . '", currency = "' . bxc_db_escape(bxc_isset($checkout, 'currency', '')) . '", type = "' . bxc_db_escape($checkout['type']) . '", redirect = "' . bxc_db_escape(bxc_isset($checkout, 'redirect', '')) . '", hide_title = ' . (empty($checkout['hide_title']) ? 0 : 1) . ', external_reference = "' . bxc_db_escape(bxc_isset($checkout, 'external_reference', '')) . '" WHERE id = "' . bxc_db_escape($checkout['id'], true) . '"');
    }
}

function bxc_checkout_delete($checkout_id) {
    return bxc_db_query('DELETE FROM bxc_checkouts WHERE id = "' . bxc_db_escape($checkout_id) . '"');
}

function bxc_checkout_direct() {
    if (isset($_GET['checkout_id'])) {
        echo '<div data-boxcoin="' . $_GET['checkout_id'] . '" data-price="' . bxc_isset($_GET, 'price') . '" data-external-reference="' . bxc_isset($_GET, 'external_reference', bxc_isset($_GET, 'external-reference', '')) . '" data-redirect="' . bxc_isset($_GET, 'redirect', '') . '" data-currency="' . bxc_isset($_GET, 'currency', '') . '"' . (isset($_GET['title']) ? ' data-title="' . $_GET['title'] . '"' : '') . (isset($_GET['description']) ? ' data-description="' . $_GET['description'] . '"' : '') . (isset($_GET['note']) ? ' data-note="' . $_GET['note'] . '"' : '') . '></div>';
        require_once(__DIR__ . '/init.php');
        echo '</div>';
    }
}

/*
 * -----------------------------------------------------------
 * CRYPTO
 * -----------------------------------------------------------
 *
 * 1. Get balances
 * 2. Get the API key
 * 3. Get the fiat value of a cryptocurrency value
 * 4. Get the cryptocurrency value of a fiat value
 * 5. Get blockchain data
 * 6. Get cryptocurrency name
 * 7. Get the crypto payment address
 * 8. Get USD exchange rate
 * 9. Get exchange rate
 * 10. Convert to FIAT
 * 11. Transfer cryptocurrencies
 * 12. Get crypto network
 * 13. Return the base cryptocurrency code of a token
 * 14. Verify an address
 * 15. Get all custom tokens
 * 16. Get cryptocurrency codes by blockchain
 * 17. Get decimals of a cryptocurrency
 *
 */

function bxc_crypto_balances($cryptocurrency_code = false) {
    $cryptocurrencies = $cryptocurrency_code ? [$cryptocurrency_code] : ['btc', 'eth', 'xrp', 'doge', 'usdt', 'usdt_tron', 'usdt_bsc', 'usdc', 'busd', 'bnb', 'shib', 'ltc', 'link', 'bat', 'algo', 'bch'];
    $currency = bxc_settings_get('currency', 'USD');
    $response = ['balances' => []];
    $total = 0;
    $custom_token_images = [];
    if (!$cryptocurrency_code) {
        $custom_tokens = bxc_get_custom_tokens();
        foreach ($custom_tokens as $key => $value) {
            array_push($cryptocurrencies, $value['code']);
            $custom_token_images[$key] = $value['img'];
        }
    }
    for ($i = 0; $i < count($cryptocurrencies); $i++) {
        $cryptocurrency_code = $cryptocurrencies[$i];
        if (bxc_settings_get_address($cryptocurrency_code)) {
            $balance = bxc_blockchain($cryptocurrency_code, 'balance');
            $fiat = 0;
            if ($balance && is_numeric($balance)) {
                $fiat = bxc_crypto_get_fiat_value($balance, bxc_crypto_get_base_code($cryptocurrency_code), $currency);
                $total += $fiat;
            } else {
                $balance = 0;
            }
            $response['balances'][$cryptocurrency_code] = ['amount' => $balance, 'fiat' => $fiat, 'name' => bxc_crypto_name($cryptocurrency_code, true)];
        }
    }
    $response['total'] = round($total, 2);
    $response['currency'] = strtoupper($currency);
    $response['token_images'] = $custom_token_images;
    return $response;
}

function bxc_crypto_api_key($service, $url = false) {
    $key = false;
    $key_parameter = false;
    switch ($service) {
    	case 'etherscan':
            $keys = ['TBGQBHIXM113HT94ZWYY8MXGWFP9257541', 'GHAQC5VG536H7MSZR5PZF27GZJUSGH94TK', 'F1HZ35IJCR8DQC4SGVJBYMYB928UFV58MP', 'ADR46A53KIXDJ6BMJYK5EEGKQJDDQH6H1K', 'AIJ9S76757JZ7B9KQMJTAN3SRNKF5F5P4M'];
            $key_parameter = 'apikey';
            break;
        case 'ethplorer':
            $keys = ['EK-feNiM-th8gYm7-qECAq', 'EK-qCQHY-co6TwoA-ASWUm', 'EK-51EKh-8cvKWm5-qhjuU', 'EK-wmJ14-faiQNhf-C5Gsj', 'EK-i6f3K-1BtBfUf-Ud7Lo'];
            $key_parameter = 'apiKey';
            break;
        case 'bscscan':
            $keys = ['2Z5V3AZV5P4K95M9UXPABQ19CAVWR7RM78', '6JG8B7F5CC5APF2Q1C3BXRMZSS92F1RGKX', '2BAPYF16Z6BR8TY2SZGN74231JNZ8TFQKU', '1DNAQ7C2UAYPS5WW7HQXPCF8WFYG8CP3XQ', 'MP3XAXN1D7XVYZQVNCMGII5JZTBRASG996'];
            $key_parameter = 'apiKey';
            break;
        case 'blockdaemon':
            $keys = ['5inALCDK3NzmSoA-EC4ribZEDAvj0zy95tPaorxMZYzTRR0u', 'i1-LMC4x9ZgSlZ-kSrCf3pEeckZadAsKCJxuvXRq9pusgK2T', 'ktbzuPccKUwnnMI73YLEK7h29dEOQfFBOCNAXJ0SnHw8rn69', 'FI2b6Cfpf8lee2xaTs98IprkPb1OuxjW11M2Sq-vlIrqzKsR', '1nvtfBzPsjByQPYBr0xoxc1jv9KrntMnOhkjKTkTt3ejxUXk'];
            $key_parameter = '-';
            break;
        case 'tatum':
            $keys = ['90a07172-cd5e-452e-9b81-56f37c9693bb', '573c3fea-8325-4088-a35e-e97fdf2bc365', '330c5774-0de7-4963-895f-2b0c784011d2', '2f9a0a5f-f587-4545-8c38-f72007461e7a', '076a59f5-7cb5-4169-a038-3decda950b41'];
            $key_parameter = '-';
            break;
    }
    if ($key_parameter) {
        $key = bxc_settings_get($service . '-key');
        if (!$key) $key = $keys[rand(0, 4)];
    }
    return $key ? ($url ? ($url . (strpos($url, '?') ? '&' : '?') . $key_parameter . '=' . $key) : $key) : ($url ? $url : false);
}

function bxc_crypto_get_fiat_value($amount, $cryptocurrency_code, $currency_code) {
    if (!is_numeric($amount)) return bxc_error('Invalid amount (' . $amount . ')', 'bxc_crypto_get_fiat_value');
    $cryptocurrency_code = strtoupper($cryptocurrency_code);
    $unsupported = ['BNB', 'BUSD'];
    if (in_array($cryptocurrency_code, $unsupported)) {
        $usd_rates = $currency_code == 'USD' ? 1 : bxc_usd_rates($currency_code);
        $crypto_rate_usd = json_decode(bxc_curl('https://api.binance.us/api/v3/ticker/price?symbol=' . $cryptocurrency_code . 'USD'), true)['price'];
        $rate = 1 / (floatval($crypto_rate_usd) * $usd_rates);
    } else {
        $rate = bxc_exchange_rates($currency_code, $cryptocurrency_code);
    }
    return round((1 / $rate) * floatval($amount), 2);
}

function bxc_crypto_get_cryptocurrency_value($amount, $cryptocurrency_code, $currency_code) {
    $unsupported = ['BNB', 'BUSD'];
    $cryptocurrency_code = strtoupper($cryptocurrency_code);
    $rate = false;
    if (in_array($cryptocurrency_code, $unsupported)) {
        $usd_rates = $currency_code == 'USD' ? 1 : bxc_usd_rates($currency_code);
        $crypto_rate_usd = json_decode(bxc_curl('https://api.binance.us/api/v3/ticker/price?symbol=' . $cryptocurrency_code . 'USD'), true)['price'];
        $rate = 1 / (floatval($crypto_rate_usd) * $usd_rates);
    } else {
        $rate = bxc_exchange_rates($currency_code, $cryptocurrency_code);
    }
    return bxc_decimal_number($rate * floatval($amount));
}

function bxc_blockchain($cryptocurrency_code, $action, $extra = false, $address = false) {
    $services = [
        'btc' => [['https://mempool.space/api/', 'address/{R}', 'address/{R}/txs', 'tx/{R}', 'blocks/tip/height', 'mempool'], ['https://blockstream.info/api/', 'address/{R}', 'address/{R}/txs', 'tx/{R}', 'blocks/tip/height', 'blockstream'], ['https://blockchain.info/', 'q/addressbalance/{R}', 'rawaddr/{R}?limit=30', 'rawtx/{R}', 'q/getblockcount', 'blockchain'], 'blockdaemon'],
        'eth' => [['https://api.etherscan.io/api?', 'module=account&action=balance&address={R}', 'module=account&action=txlist&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', 'module=account&action=txlist&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', false, 'etherscan'], ['https://api.ethplorer.io/', 'getAddressInfo/{R}', 'getAddressTransactions/{R}?limit=99&showZeroValues=false', 'getTxInfo/{R}', 'getLastBlock', 'ethplorer'], ['https://blockscout.com/eth/mainnet/api?', 'module=account&action=balance&address={R}', 'module=account&action=txlist&address={R}', 'module=transaction&action=gettxinfo&txhash={R}', false, 'blockscout'], 'blockdaemon'],
        'xrp' => [['https://data.ripple.com/v2/', 'accounts/{R}/balances', 'accounts/{R}/transactions', 'transactions/{R}', false, 'ripple'], 'blockdaemon'],
        'doge' => ['blockcypher','blockdaemon'],
        'algo' => [['https://algoindexer.algoexplorerapi.io/v2/', 'accounts/{R}', 'accounts/{R}/transactions?limit=99', 'transactions/{R}', 'accounts/{R}', 'algoexplorerapi'], 'blockdaemon'],
        'bnb' => [['https://api.bscscan.com/api?', 'module=account&action=balance&address={R}', 'module=account&action=txlist&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', 'module=account&action=txlist&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', false, 'bscscan']],
        'ltc' => ['tatum', 'blockdaemon'],
        'bch' => [['https://rest1.biggestfan.net/v2/address/', 'details/{R}', 'transactions/{R}', 'transactions/{R}', false, 'biggestfan'], 'blockdaemon'],
        'trx' => [['https://apilist.tronscan.org/api/', 'account?address={R}', 'transaction?sort=-timestamp&count=true&limit=99&start=0&address={R}', 'transaction-info?hash={R}', false, 'tronscan'], 'tatum']
    ];
    $address = $address ? $address : bxc_settings_get_address($cryptocurrency_code);
    $address_lowercase = strtolower($address);
    $cryptocurrency_code_base = bxc_crypto_get_base_code($cryptocurrency_code);

    // Tokens
    $custom_token_code = ['eth' => false, 'trx' => false, 'bsc' => false];
    $custom_token = bxc_isset(bxc_get_custom_tokens(), $cryptocurrency_code);
    $is_token = in_array($cryptocurrency_code, bxc_get_cryptocurrency_codes('eth')) || ($custom_token && $custom_token['type'] == 'erc-20') ? 'eth' : (in_array($cryptocurrency_code, ['usdt_tron']) ? 'trx' : (in_array($cryptocurrency_code, ['busd', 'usdt_bsc']) ? 'bsc' : false));
    if ($is_token) {
        switch ($is_token) {
            case 'eth':
                require_once(__DIR__ . '/web3.php');
                $services = [['https://api.etherscan.io/api?', 'module=account&action=tokenbalance&contractaddress={A}&address={R}&tag=latest', 'module=account&action=tokentx&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', 'module=account&action=tokentx&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', false, 'etherscan', 'module=account&action=tokentx&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc'], ['https://api.ethplorer.io/', 'getAddressInfo/{R}', 'getAddressHistory/{R}?limit=99&showZeroValues=false', 'getTxInfo/{R}', false, 'ethplorer', 'getAddressHistory/{R}?limit=99&showZeroValues=false'], ['https://blockscout.com/eth/mainnet/api?', 'module=account&action=tokenbalance&contractaddress={A}&address={R}', 'module=account&action=tokentx&address={R}&offset=99', 'module=account&action=tokentx&address={R}&offset=99', false, 'blockscout', 'module=account&action=tokenlist&address={R}']];
                $contract_address = bxc_eth_get_contract($cryptocurrency_code_base);
                $contract_address = $contract_address ? $contract_address[0] : false;
                break;
            case 'trx':
                $services = $services['trx'];
                $services[0][2] = 'contract/events?address={R}&start=0&limit=30';
                $contract_address = bxc_tron_get_contract_address($cryptocurrency_code_base);
                break;
            case 'bsc':
                $services = [['https://api.bscscan.com/api?', 'module=account&action=tokenbalance&contractaddress={A}&address={R}&tag=latest', 'module=account&action=tokentx&contractaddress={A}&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', 'module=account&action=tokentx&contractaddress={A}&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc', false, 'bscscan', 'module=account&action=tokentx&address={R}&startblock=0&endblock=99999999&offset=99&sort=asc']];
                $contract_address = bxc_binance_get_contract_address($cryptocurrency_code_base);
                break;
        }
        $custom_token_code[$is_token] = $cryptocurrency_code;
    } else {
        $services = bxc_isset($services, $cryptocurrency_code);
    }
    if ($custom_token) {
        $contract_address = bxc_isset($custom_token, 'contract_address', $contract_address);
    }

    $slugs = false;
    $transactions = [];
    $single_transaction = $action == 'transaction';
    $divider = 1;

    // Custom Blockchain explorer
    $custom_explorer = bxc_settings_get('custom-explorer-active') ? bxc_settings_get('custom-explorer-' . $action . '-url') : false;
    if ($custom_explorer) {
        $path = bxc_settings_get('custom-explorer-' . $action . '-path');
        $data = bxc_curl(str_replace(['{R}', '{N}', '{N2}'], [$single_transaction ? $extra : $address, $cryptocurrency_code, bxc_crypto_name($cryptocurrency_code)], $custom_explorer));
        $data = bxc_get_array_value_by_path($action == 'transactions' ? trim(explode(',', $path)[0]) : $path, json_decode($data, true));
        if ($data) {
            $custom_explorer_divider = 1;
            if (bxc_settings_get('custom-explorer-divider')) {
                $custom_explorer_divider = $cryptocurrency_code == 'eth' ? 1000000000000000000 : 100000000;
            }
            switch ($action) {
                case 'balance':
                    if (is_numeric($data)) {
                        return floatval($data) / $custom_explorer_divider;
                    }
                    break;
                case 'transaction':
                    if (is_array($data) && $data[0]) {
                        return ['time' => $data[0], 'address' => $data[1], 'value' => floatval($data[2]) / $custom_explorer_divider, 'confirmations' => $data[3], 'hash' => $data[4]];
                    }
                    break;
                case 'transactions':
                    if (is_array($data)) {
                        for ($i = 0; $i < count($data); $i++) {
                            $transaction = bxc_get_array_value_by_path($path, $data[$i]);
                            array_push($transactions, ['time' => $transaction[1], 'address' => $transaction[2], 'value' => floatval($transaction[3]) / $custom_explorer_divider, 'confirmations' => $transaction[4], 'hash' => $transaction[5]]);
                        }
                        return $transactions;
                    }
                    break;
            }
        }
    }

    // Multi Network Explorers
    $data_original = false;
    $global_explorer = false;
    for ($i = 0; $i < count($services); $i++) {
        if ($services[$i] === 'tatum') {
            $base_url = 'https://api.tatum.io/v3/' . bxc_crypto_get_network($cryptocurrency_code, 'full_name') . '/';
            $header = ['x-api-key: ' . (BXC_CLOUD ? TATUM_API_KEY : bxc_crypto_api_key('tatum'))];
            switch ($action) {
                case 'balance':
                    if ($cryptocurrency_code == 'usdt_tron') {
                        $json = bxc_curl($base_url . 'account/' . $address);
                        $data = json_decode($json, true);
                        if ($is_token) {
                            $trc_20 = bxc_isset($data, 'trc20');
                            if ($trc_20 && count($trc_20) && isset($trc_20[0][$contract_address])) return bxc_decimal_number($trc_20[0][$contract_address] / (10 ** bxc_crypto_get_decimals($cryptocurrency_code)));
                        } else if (isset($data['balance'])) {
                            return bxc_decimal_number($data['balance'] / (10 ** bxc_crypto_get_decimals($cryptocurrency_code)));
                        }
                    } else {
                        $json = bxc_curl($base_url . 'address/balance/' . $address);
                        $data = json_decode($json, true);
                        if (isset($data['incoming'])) {
                            return bxc_decimal_number($data['incoming'] - $data['outgoing']);
                        }
                    }
                    bxc_error($json, 'tatum');
                    continue 2;
                case 'transactions':
                    if ($cryptocurrency_code == 'usdt_tron') {
                        $json = bxc_curl($base_url . 'transaction/account/' . $address . ($is_token ? '/trc20' : ''), '', $header);
                        $data = json_decode($json, true);
                        if (isset($data['transactions'])) {
                            $slugs = [false, 'from', 'value', false, 'txID', false];
                            $transactions = $data['transactions'];
                            $transactions_data = [];
                            for ($j = 0; $j < count($transactions); $j++) {
                                $token_info = $transactions[$i]['tokenInfo'];
                                if (strtolower($token_info['symbol']) == $cryptocurrency_code_base) {
                                    $divider = 10 ** $token_info['decimals'];
                                    array_push($transactions_data, $transactions[$j]);
                                }
                            }
                            $data = $transactions_data;
                            $global_explorer = true;
                        } else {
                            bxc_error($json, 'tatum');
                            continue 2;
                        }
                    } else {
                        $json = bxc_curl($base_url . 'transaction/address/' . $address . '?pageSize=30', '', $header);
                        $data = json_decode($json, true);
                        if (is_array($data) && isset($data[0]['inputs'])) {
                            $slugs = ['ts', 'from', 'value', false, 'hash', 'blockNumber'];
                            for ($j = 0; $j < count($data); $j++) {
                                $data[$j]['address'] = $data[$j]['inputs'][0]['coin']['address'];
                                $data[$j]['value'] = 0;
                                $outputs = $data[$j]['outputs'];
                                for ($y = 0; $y < count($outputs); $y++) {
                                    if (strtolower($outputs[$y]['address']) == $address_lowercase) {
                                        $data[$j]['value'] = $outputs[$y]['value'];
                                        break;
                                    }
                                }
                            }
                            $global_explorer = true;
                        }
                    }
                    break;
                case 'transaction':
                    $json = bxc_curl($base_url . 'transaction/' . $extra, '', $header);
                    $data = json_decode($json, true);
                    if ($cryptocurrency_code == 'usdt_tron') {
                        if (isset($data['txID'])) {
                            $slugs = ['time', 'from', 'value', 'confirmations', 'txID', 'blockNumber'];
                            $raw = $data['rawData'];
                            $data['time'] = $raw['timestamp'];
                            $data['from'] = $raw['contract'][0]['parameter']['value']['ownerAddressBase58'];
                            $data['confirmations'] = bxc_isset(json_decode(bxc_curl($base_url . 'info', '', $header), true), 'blockNumber', $data['blockNumber']) - $data['blockNumber'];
                            $data['value'] = bxc_decimal_number(hexdec($data['log'][0]['data']) / (10 ** bxc_crypto_get_decimals($cryptocurrency_code)));
                            $data = [$data];
                            $global_explorer = true;
                        } else {
                            bxc_error($json, 'tatum');
                            continue 2;
                        }
                    } else {
                        if (isset($data['hash'])) {
                            $slugs = ['time', 'from', 'value', 'confirmations', 'hash', 'blockNumber'];
                            $data['address'] = $data['inputs'][0]['coin']['address'];
                            $data['value'] = 0;
                            $outputs = $data['outputs'];
                            for ($y = 0; $y < count($outputs); $y++) {
                                if (strtolower($outputs[$y]['address']) == $address_lowercase) {
                                    $data['value'] = $outputs[$y]['value'];
                                    break;
                                }
                            }
                            $blocks_count = json_decode(bxc_curl($base_url . 'info', '', $header), true);
                            $data['confirmations'] = isset($blocks_count['blocks']) ? $blocks_count['blocks'] - $data['blockNumber'] + 1 : 0;
                            $data = [$data];
                            $global_explorer = true;
                        } else {
                            bxc_error($json, 'tatum');
                            continue 2;
                        }
                    }
                    break;
            }
        } else if ($services[$i] === 'blockdaemon') {
            $base_url = 'https://svc.blockdaemon.com/universal/v1/' . bxc_crypto_name($cryptocurrency_code) . '/mainnet/';
            $header = ['Content-Type: application/json', 'Authorization: Bearer ' . (BXC_CLOUD ? BLOCKDAEMON_API_KEY : bxc_crypto_api_key('blockdaemon'))];
            switch ($action) {
                case 'balance':
                    $json = bxc_curl($base_url . 'account/' . $address, '', $header);
                    $data = json_decode($json, true);
                    if (is_array($data) && isset($data[0]['confirmed_balance'])) {
                        return bxc_decimal_number($data[0]['confirmed_balance'] / (10 ** $data[0]['currency']['decimals']));
                    }
                    bxc_error($json, 'blockdaemon');
                    continue 2;
                case 'transactions':
                case 'transaction':
                    $json = bxc_curl($base_url . ($single_transaction ? 'tx/' . $extra : 'account/' . $address . '/txs'), '', $header);
                    $data = json_decode($json, true);
                    if ($data) {
                        if ($single_transaction) {
                            if (isset($data['events'])) $data = [$data];
                        } else if (isset($data['data'])) $data = $data['data'];
                    }
                    if (is_array($data)) {
                        if (count($data) && isset($data[0]['events'])) {
                            $slugs = ['date', 'address', 'value', 'confirmations', 'id', 'block_number'];
                            for ($j = 0; $j < count($data); $j++) {
                                $events = $data[$j]['events'];
                                $transaction_value = 0;
                                $sender_address = '';
                                for ($y = 0; $y < count($events); $y++) {
                                    switch ($cryptocurrency_code) {
                                        case 'btc':
                                            if (!empty($events[$y]['meta']) && !empty($events[$y]['meta']['addresses'])) {
                                                $event_address = $events[$y]['meta']['addresses'][0];
                                                $amount = $events[$y]['amount'];
                                                if ($events[$y]['type'] == 'utxo_output' && strtolower($event_address) == $address_lowercase) {
                                                    $transaction_value += $amount;
                                                    if (isset($events[$y]['decimals'])) $divider = 10 ** $events[$y]['decimals'];
                                                } else if ($events[$y]['type'] == 'utxo_input') {
                                                    $sender_address = $event_address;
                                                }
                                            }
                                            break;
                                        case 'xrp':
                                        case 'bch':
                                        case 'algo':
                                        case 'ltc':
                                        case 'doge':
                                        case 'eth':
                                            $get_address = false;
                                            if (strtolower(bxc_isset($events[$y], 'destination')) == $address_lowercase) {
                                                $transaction_value += $events[$y]['amount'];
                                                $get_address = true;
                                                if (isset($events[$y]['decimals'])) $divider = 10 ** $events[$y]['decimals'];
                                            } else if (bxc_isset($events[$y], 'type') == 'utxo_input') {
                                                $get_address = true;
                                            }
                                            if ($get_address && !empty($events[$y]['source'])) $sender_address = $events[$y]['source'];
                                            break;
                                    }
                                }
                                $data[$j]['value'] = $transaction_value;
                                $data[$j]['address'] = $sender_address;
                            }
                        }
                        $global_explorer = true;
                    } else {
                        bxc_error($json, 'blockdaemon');
                        continue 2;
                    }
                    break;
            }
        } else if ($services[$i] === 'blockcypher') {
            $base_url = 'https://api.blockcypher.com/v1/' . $cryptocurrency_code . '/main/';
            switch ($action) {
                case 'balance':
                    $json = bxc_curl($base_url . 'addrs/' . $address);
                    $data = json_decode($json, true);
                    if ($data && isset($data['balance'])) {
                        return bxc_decimal_number($data['balance'] / (10 ** bxc_crypto_get_decimals($cryptocurrency_code)));
                    }
                    bxc_error($json, 'blockdaemon');
                    continue 2;
                case 'transactions':
                case 'transaction':
                    $json = bxc_curl($base_url . ($single_transaction ? 'txs/' . $extra : 'addrs/' . $address . '/full'));
                    $data = json_decode($json, true);
                    if ($data) {
                        if ($single_transaction) {
                            if (isset($data['hash'])) $data = [$data];
                        } else if (isset($data['txs'])) $data = $data['txs'];
                    }
                    if ($data && is_array($data)) {
                        if (count($data)) {
                            $slugs = ['time', 'address', 'value', 'confirmations', 'hash', 'block_height'];
                            $divider = 10 ** bxc_crypto_get_decimals($cryptocurrency_code_base);
                            for ($j = 0; $j < count($data); $j++) {
                                $outputs = bxc_isset($data[$j], 'outputs', []);
                                $data[$j]['time'] = strtotime($data[$j]['received']);
                                $data[$j]['address'] = $data[$j]['inputs'][0]['addresses'][0];
                                $data[$j]['value'] = 0;
                                for ($y = 0; $y < count($outputs); $y++) {
                                    if (strtolower($outputs[$y]['addresses'][0]) == $address_lowercase) {
                                        $data[$j]['value'] = $outputs[$y]['value'];
                                        break;
                                    }
                                }
                            }
                        }
                        $global_explorer = true;
                    } else {
                        bxc_error($json, 'blockdaemon');
                        continue 2;
                    }
                    break;
            }
        }

        // Other explorers
        if (!$global_explorer) {
            $url_part = $services[$i][$action == 'balance' ? 1 : ($action == 'transactions' ? 2 : ($single_transaction ? 3 : 4))];
            if ($url_part === false) continue;
            $url = $services[$i][0] . str_replace('{R}', $single_transaction && !in_array($services[$i][5], ['etherscan', 'bscscan', 'biggestfan']) ? $extra : $address, $url_part);
            if ($is_token) $url = str_replace('{A}', $contract_address, $url);
            $data = $data_original = bxc_curl(bxc_crypto_api_key($services[$i][5], $url));
            switch ($cryptocurrency_code) {
                case 'btc':
                    switch ($action) {
                        case 'balance':
                            $data = json_decode($data, true);
                            switch ($i) {
                                case 0:
                                case 2:
                                    if (isset($data['chain_stats'])) {
                                        return ($data['chain_stats']['funded_txo_sum'] - $data['chain_stats']['spent_txo_sum']) / 100000000;
                                    }
                                    break;
                                case 1:
                                    if (isset($data['data'])) {
                                        return $data['data']['confirmed_balance'];
                                    }
                                    break;
                                case 3:
                                    if (is_numeric($data)) {
                                        return intval($data) / 100000000;
                                    }
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            $data = json_decode($data, true);
                            $input_slug = false;
                            $output_slug = false;
                            $confirmations = false;
                            $continue = false;

                            // Get transaction and verify the API is working
                            switch ($i) {
                                case 0:
                                case 2:
                                    if (is_array($data) && empty($data['error'])) {
                                        $output_slug = 'vout';
                                        $input_slug = 'vin';
                                        $continue = true;
                                    }
                                    break;
                                case 1:
                                    if (isset($data['data']) && (($single_transaction && isset($data['data']['txid'])) || isset($data['data']['txs']))) {
                                        $data = $single_transaction ? $data['data'] : $data['data']['txs'];
                                        if ($single_transaction) {
                                            $input_slug = 'inputs';
                                            $output_slug = 'outputs';
                                        }
                                        $continue = true;
                                    }
                                    break;
                                case 3:
                                    if (($single_transaction && isset($data['inputs'])) || isset($data['txs'])) {
                                        if (!$single_transaction) $data = $data['txs'];
                                        $input_slug = 'inputs';
                                        $output_slug = 'out';
                                        $continue = true;
                                    }
                                    break;
                            }
                            if ($continue) {
                                $slugs = ['time', 'address', 'value', 'confirmations', 'hash', 'block_height'];
                                $sender_address = '';
                                $time = 0;
                                $block_height = 0;
                                $hash = '';
                                $divider = $i === 1 ? 1 : 100000000;
                                if ($single_transaction) $data = [$data];

                                // Get transactions details
                                for ($j = 0; $j < count($data); $j++) {
                                    $transaction_value = 0;
                                    switch ($i) {
                                        case 0:
                                        case 2:
                                            if (bxc_isset($data[$j]['status'], 'confirmed')) {
                                                $time = $data[$j]['status']['block_time'];
                                                $block_height = $data[$j]['status']['block_height'];
                                            }
                                            $hash = $data[$j]['txid'];
                                            break;
                                        case 1:
                                            $time = $data[$j]['time'];
                                            $block_height = false;
                                            $confirmations = $data[$j]['confirmations'];
                                            $transaction_value = $single_transaction ? 0 : $data[$j]['value'];
                                            $hash = $data[$j]['txid'];
                                            break;
                                        case 3:
                                            $time = $data[$j]['time'];
                                            $block_height = $data[$j]['block_height'];
                                            $hash = $data[$j]['hash'];
                                            break;
                                    }

                                    // Get transaction amount
                                    $outputs = $output_slug ? $data[$j][$output_slug] : [];
                                    for ($y = 0; $y < count($outputs); $y++) {
                                        switch ($i) {
                                            case 0:
                                            case 2:
                                                $value = $outputs[$y]['value'];
                                                $output_address = $outputs[$y]['scriptpubkey_address'];
                                                break;
                                            case 1:
                                                $value = $outputs[$y]['value'];
                                                $output_address = $outputs[$y]['address'];
                                                break;
                                            case 3:
                                                $value = $outputs[$y]['value'];
                                                $output_address = $outputs[$y]['addr'];
                                                break;
                                        }
                                        if (strtolower($output_address) == $address_lowercase) {
                                            $transaction_value += $value;
                                        }
                                        $outputs[$y] = ['value' => $value, 'address' => $output_address];
                                    }

                                    // Get sender address
                                    $input = bxc_isset($data[$j], $input_slug);
                                    if ($input && count($input)) {
                                        $input = $input[0];
                                        switch ($i) {
                                            case 0:
                                            case 2:
                                                $sender_address = $input['prevout']['scriptpubkey_address'];
                                                break;
                                            case 1:
                                                $sender_address = $input['address'];
                                                break;
                                            case 3:
                                                $sender_address = $input['prev_out']['addr'];
                                                break;
                                        }
                                    }

                                    // Assign transaction values
                                    $data[$j]['time'] = $time;
                                    $data[$j]['address'] = $sender_address;
                                    $data[$j]['confirmations'] = $confirmations;
                                    $data[$j]['value'] = $transaction_value;
                                    $data[$j]['hash'] = $hash;
                                    $data[$j]['block_height'] = $block_height;
                                }
                            }
                            break;
                        case 'blocks_count':
                            switch ($i) {
                                case 0:
                                case 2:
                                case 3:
                                    if (is_numeric($data)) {
                                        return intval($data);
                                    }
                                    break;
                                case 1:
                                    if (isset($data['data']) && isset($data['data']['blocks'])) {
                                        return intval($data['data']['blocks']);
                                    }
                                    break;
                            }
                    }
                    break;
                case $custom_token_code['eth']:
                case 'link':
                case 'shib':
                case 'bat':
                case 'usdt':
                case 'usdc':
                case 'eth':
                    $data = json_decode($data, true);
                    switch ($action) {
                        case 'balance':
                            switch ($i) {
                                case 2:
                                case 0:
                                    $data = bxc_isset($data, 'result');
                                    if (is_numeric($data)) {
                                        require_once(__DIR__ . '/web3.php');
                                        return floatval($data) / ($is_token ? 10 ** ($custom_token ? $custom_token['decimals'] : bxc_eth_get_contract($cryptocurrency_code)[1]) : 1000000000000000000);
                                    }
                                    break;
                                case 1:
                                    if ($is_token) {
                                        $data = bxc_isset($data, 'tokens', []);
                                        for ($j = 0; $j < count($data); $j++) {
                                            if (strtolower(bxc_isset(bxc_isset($data, 'tokenInfo'), 'symbol')) == $cryptocurrency_code) {
                                                return floatval($data['balance']) / (10 ** intval($data['tokenInfo']['decimals']));
                                            }
                                        }
                                    } else {
                                        $data = bxc_isset(bxc_isset($data, 'ETH'), 'balance');
                                        if (is_numeric($data)) {
                                            return floatval($data);
                                        }
                                    }
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            switch ($i) {
                                case 2:
                                case 0:
                                    $data = bxc_isset($data, 'result');
                                    if (is_array($data)) {
                                        $count = count($data);
                                        $slugs = ['timeStamp', 'from', 'value', 'confirmations', 'hash', 'blockNumber'];
                                        $divider = $is_token ? 1000000 : 1000000000000000000;
                                        if ($single_transaction) {
                                            if ($i === 0) {
                                                $data_single = [];
                                                for ($j = 0; $j < $count; $j++) {
                                                    if ($data[$j]['hash'] == $extra) {
                                                        $data_single = [$data[$j]];
                                                        break;
                                                    }
                                                }
                                                $data = $data_single;
                                            } else {
                                                $data = [$data];
                                            }
                                        } else if ($is_token) {
                                            $data_temp = [];
                                            for ($j = 0; $j < $count; $j++) {
                                                if (strtolower($data[$j]['tokenSymbol']) == $cryptocurrency_code) {
                                                    array_push($data_temp, $data[$j]);
                                                }
                                            }
                                            $data = $data_temp;
                                        }
                                        if ($count && isset($data[0]['tokenDecimal'])) $divider = 10 ** intval($data[0]['tokenDecimal']);
                                    }
                                    break;
                                case 1:
                                    if ($single_transaction || is_array($data) || $is_token) {
                                        $slugs = ['timestamp', 'from', 'value', 'confirmations', 'hash', 'blockNumber'];
                                        if ($single_transaction) $data = [$data];
                                    }
                                    if ($is_token) {
                                        if ($single_transaction) {
                                            if (count($data)) {
                                                $transaction_value = 0;
                                                if (isset($data[0]['operations'])) {
                                                    $operations = $data[0]['operations'];
                                                    $address = strtolower($address);
                                                    for ($j = 0; $j < count($operations); $j++) {
                                                        if ($operations[$j]['type'] == 'transfer' && strtolower($operations[$j]['to']) == $address_lowercase) {
                                                            $transaction_value += $operations[$j]['value'];
                                                        }
                                                    }
                                                    $divider = 10 ** intval($operations[0]['tokenInfo']['decimals']);
                                                    $data[0]['value'] = $transaction_value;
                                                }
                                            }
                                        } else {
                                            $data = bxc_isset($data, 'operations', []);
                                            $data_temp = [];
                                            for ($j = 0; $j < count($data); $j++) {
                                                if (strtolower($data[$j]['tokenInfo']['symbol']) == $cryptocurrency_code) {
                                                    array_push($data_temp, $data[$j]);
                                                    $divider = 10 ** intval($data[$j]['tokenInfo']['decimals']);
                                                }
                                            }
                                            $slugs[4] = 'transactionHash';
                                            $data = $data_temp;
                                        }
                                    }
                                    break;
                            }
                            if ($slugs && (!$data || (count($data) && (!isset($data[0]) || !bxc_isset($data[0], $slugs[0]))))) $slugs = false;
                            break;
                        case 'blocks_count':
                            switch ($i) {
                                case 1:
                                    if (is_numeric($data['lastBlock'])) {
                                        return intval($data['lastBlock']);
                                    }
                                    break;
                            }
                    }
                    break;
                case 'doge':
                    $data = json_decode($data, true);
                    switch ($action) {
                        case 'balance':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'data');
                                    if ($data && isset($data['confirmed_balance'])) {
                                        return $data['confirmed_balance'];
                                    }
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'data');
                                    if ($data) {
                                        if (!$single_transaction) $data = bxc_isset($data, 'txs');
                                        $slugs = ['time', 'address', 'value', 'confirmations', 'txid', false];
                                    } else if (is_array($data)) return [];
                                    break;
                            }
                            if ($slugs) {
                                if (is_array($data)) {
                                    if ($single_transaction && ($i === 0 || $i === 1)) {
                                        $data['address'] = $data['inputs'][0]['address'];
                                        $outputs = $data['outputs'];
                                        for ($j = 0; $j < count($outputs); $j++) {
                                            if (strtolower($outputs[$j]['address']) == $address_lowercase) {
                                                $data['value'] = $outputs[$j]['value'];
                                                break;
                                            }
                                        }
                                        $data = [$data];
                                    }
                                }
                                if (!$data || (count($data) && (!isset($data[0]) || (!bxc_isset($data[0], $slugs[0]) && !bxc_isset($data[0], $slugs[1]))))) $slugs = false;
                            }
                            break;
                        case 'blocks_count':
                            switch ($i) {
                                case 0:
                                    if (is_numeric($data['lastBlock'])) {
                                        return intval($data['lastBlock']);
                                    }
                                    break;
                            }
                    }
                    break;
                case 'algo':
                    $data = json_decode($data, true);
                    switch ($action) {
                        case 'balance':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset(bxc_isset($data, 'account'), 'amount');
                                    if (is_numeric($data)) {
                                        return floatval($data) / 1000000;
                                    }
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            switch ($i) {
                                case 0:
                                    $current_round = bxc_isset($data, 'current-round');
                                    $data = bxc_isset($data, $single_transaction ? 'transaction' : 'transactions');
                                    if ($data) {
                                        $slugs = ['round-time', 'sender', 'amount', 'confirmations', 'id', 'confirmed-round'];
                                        $divider = 1000000;
                                        if ($single_transaction) {
                                            $data['amount'] = bxc_isset(bxc_isset($data, 'payment-transaction'), 'amount', -1);
                                            $data['confirmations'] = $current_round - bxc_isset($data, 'confirmed-round');
                                            $data = [$data];
                                        } else {
                                            for ($j = 0; $j < count($data); $j++) {
                                                $data[$j]['amount'] = bxc_isset(bxc_isset($data[$j], 'payment-transaction'), 'amount', -1);
                                                $data[$j]['confirmations'] = $current_round - bxc_isset($data[$j], 'confirmed-round');
                                            }
                                        }
                                    } else if (is_array($data)) return [];
                                    break;
                            }
                            break;
                        case 'blocks_count':
                            switch ($i) {
                                case 1:
                                    if (is_numeric($data['current-round'])) {
                                        return intval($data['current-round']);
                                    }
                                    break;
                            }
                    }
                    break;
                case $custom_token_code['bsc']:
                case 'usdt_bsc':
                case 'busd':
                case 'bnb':
                    $data = json_decode($data, true);
                    switch ($action) {
                        case 'balance':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'result');
                                    if (is_numeric($data)) {
                                        return floatval($data) / 1000000000000000000;
                                    }
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'result');
                                    if (is_array($data)) {
                                        $slugs = ['timeStamp', 'from', 'value', 'confirmations', 'hash', 'blockNumber'];
                                        $divider = 1000000000000000000;
                                        if ($single_transaction) {
                                            if ($i === 0) {
                                                $data_single = [];
                                                for ($j = 0; $j < count($data); $j++) {
                                                    if ($data[$j]['hash'] == $extra) {
                                                        $data_single = [$data[$j]];
                                                        break;
                                                    }
                                                }
                                                $data = $data_single;
                                            } else {
                                                $data = [$data];
                                            }
                                        }
                                    }
                                    break;
                            }
                            break;
                    }
                    break;
                case 'ltc':
                    $data = json_decode($data, true);
                    switch ($action) {
                        case 'balance':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'data');
                                    if ($data && isset($data['confirmed_balance'])) {
                                        return $data['confirmed_balance'];
                                    }
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'data');
                                    if ($data) {
                                        if (!$single_transaction) $data = bxc_isset($data, 'txs');
                                        $slugs = ['time', 'address', 'value', 'confirmations', 'txid', false];
                                    } else if (is_array($data)) return [];
                                    break;
                            }
                            if ($slugs) {
                                if (is_array($data)) {
                                    if ($single_transaction && ($i === 0 || $i === 1)) {
                                        $data['address'] = $data['inputs'][0]['address'];
                                        $outputs = $data['outputs'];
                                        for ($j = 0; $j < count($outputs); $j++) {
                                            if (strtolower($outputs[$j]['address']) == $address_lowercase) {
                                                $data['value'] = $outputs[$j]['value'];
                                                break;
                                            }
                                        }
                                        $data = [$data];
                                    }
                                }
                                if (!$data || (count($data) && (!isset($data[0]) || (!bxc_isset($data[0], $slugs[0]) && !bxc_isset($data[0], $slugs[1]))))) $slugs = false;
                            }
                            break;
                    }
                    break;
                case 'bch':
                    $data = json_decode($data, true);
                    switch ($action) {
                        case 'balance':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'balance');
                                    if ($data) return $data;
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'txs');
                                    if ($data) {
                                        $slugs = ['time', 'address', 'value', 'confirmations', 'txid', false];
                                    } else if (is_array($data)) return [];
                                    break;
                            }
                            if ($slugs) {
                                if (is_array($data)) {
                                    for ($j = 0; $j < count($data); $j++) {
                                        $data_transaction = $data[$j][0];
                                        $data_transaction['address'] = str_replace('bitcoincash:', '', $data_transaction['vin'][0]['cashAddress']);
                                        $outputs = $data_transaction['vout'];
                                        $address_prefix = 'bitcoincash:' . $address;
                                        for ($y = 0; $y < count($outputs); $y++) {
                                            if (strtolower($outputs[$y]['scriptPubKey']['addresses'][0]) == $address_prefix) {
                                                $data_transaction['value'] = $outputs[$y]['value'];
                                                break;
                                            }
                                        }
                                        $data[$j] = $data_transaction;
                                    }
                                    if ($single_transaction) {
                                        for ($j = 0; $j < count($data); $j++) {
                                            if ($data[$j]['txid'] == $extra) {
                                                $data = [$data[$j]];
                                                break;
                                            }
                                        }
                                    }
                                }
                                if (!$data || (count($data) && (!isset($data[0]) || (!bxc_isset($data[0], $slugs[0]) && !bxc_isset($data[0], $slugs[1]))))) $slugs = false;
                            }
                            break;
                    }
                    break;
                case $custom_token_code['trx']:
                case 'trx':
                case 'usdt_tron':
                    $data = json_decode($data, true);
                    if (isset($data)) {
                        switch ($action) {
                            case 'balance':
                                switch ($i) {
                                    case 0:
                                        if ($is_token) {
                                            $data = bxc_isset($data, 'trc20token_balances');
                                            if (is_array($data)) {
                                                $cryptocurrency_code = bxc_crypto_get_base_code($cryptocurrency_code);
                                                for ($j = 0; $j < count($data); $j++) {
                                                    if (strtolower($data[$j]['tokenAbbr']) == $cryptocurrency_code) return floatval($data[$j]['balance']) / 1000000;
                                                }
                                            }
                                        }
                                        break;
                                }
                                break;
                            case 'transaction':
                                switch ($i) {
                                    case 0:
                                        if (isset($data['contractData']) && bxc_isset($data['contractData'], 'contract_address') == $contract_address && isset($data['trigger_info'])) {
                                            $data['value'] = $data['trigger_info']['parameter']['_value'];
                                            $divider = 10 ** $data['tokenTransferInfo']['decimals'];
                                            $data = [$data];
                                            $slugs = ['timestamp', 'ownerAddress', 'value', 'confirmations', 'hash', 'block'];
                                        }
                                        break;
                                }
                                break;
                            case 'transactions':
                                switch ($i) {
                                    case 0:
                                        if (isset($data['data'])) {
                                            $data = $data['data'];
                                            $transactions_data = [];
                                            if ($is_token) {
                                                for ($j = 0; $j < count($data); $j++) {
                                                    $data[$j]['amount'] = bxc_decimal_number($data[$j]['amount'] / (10 ** $data[$j]['decimals']));
                                                }
                                            }
                                            $slugs = ['timestamp', 'transferFromAddress', 'amount', 'confirmed', 'transactionHash', 'block'];
                                        }
                                        break;
                                }
                                break;
                        }
                    }
                    break;
                case 'xrp':
                    $data = json_decode($data, true);
                    switch ($action) {
                        case 'balance':
                            switch ($i) {
                                case 0:
                                    $data = bxc_isset($data, 'balances', []);
                                    for ($j = 0; $j < count($data); $j++) {
                                        if ($data[$j]['currency'] === 'XRP') {
                                            return $data[$j]['value'];
                                        }
                                    }
                                    break;
                            }
                            break;
                        case 'transaction':
                        case 'transactions':
                            switch ($i) {
                                case 0:
                                    $data = $single_transaction ? [bxc_isset($data, 'transaction')] : bxc_isset($data, 'transactions', []);
                                    $transactions_data = [];
                                    if ($data && count($data) && $data[0]) {
                                        for ($j = 0; $j < count($data); $j++) {
                                            $tx = bxc_isset($data[$j], 'tx');
                                            if ($tx) {
                                                $data[$j]['address'] = $tx['Account'];
                                                $data[$j]['value'] = $tx['Amount'];
                                                $data[$j]['time'] = strtotime($data[$j]['date']);
                                                $data[$j]['confirmations'] = 9999;
                                                array_push($transactions_data, $data[$i]);
                                            }
                                        }
                                        $data = $transactions_data;
                                        $slugs = ['time', 'address', 'value', 'confirmations', 'hash', false];
                                    } else if (is_array($data)) return [];
                                    break;
                            };
                    }
                    break;
            }
        }

        // Add the transactions
        if ($slugs) {
            $transactions = [];
            for ($j = 0; $j < count($data); $j++) {
                $transaction = $data[$j];
                array_push($transactions, ['time' => bxc_isset($transaction, $slugs[0]), 'address' => bxc_isset($transaction, $slugs[1], ''), 'value' => bxc_decimal_number($transaction[$slugs[2]] / $divider), 'confirmations' => bxc_isset($transaction, $slugs[3], 0), 'hash' => $transaction[$slugs[4]], 'block_height' => bxc_isset($transaction, $slugs[5], '')]);
            }
            return $single_transaction ? $transactions[0] : $transactions;
        }
    }
    return $data_original;
}

function bxc_crypto_name($cryptocurrency_code = false, $uppercase = false) {
    $names = ['btc' => ['bitcoin', 'Bitcoin'], 'eth' => ['ethereum', 'Ethereum'], 'xrp' => ['xrp', 'XRP'], 'doge' => ['dogecoin', 'Dogecoin'], 'algo' => ['algorand', 'Algorand'], 'usdt' => ['tether', 'Tether'], 'usdt_tron' => ['tether', 'Tether'], 'usdt_bsc' => ['tether', 'Tether'], 'usdc' => ['usdcoin', 'USD Coin'], 'link' => ['chainlink', 'Chainlink'], 'shib' => ['shibainu', 'Shiba Inu'], 'bat' => ['basicattentiontoken', 'Basic Attention Token'], 'busd' => ['binanceusd', 'Binance USD'], 'bnb' => ['bnb', 'BNB'], 'ltc' => ['litecoin', 'Litecoin'], 'bch' => ['bitcoincash', 'Bitcoin Cash']];
    $custom_tokens = bxc_get_custom_tokens();
    foreach ($custom_tokens as $key => $value) {
        $names[$key] = [strtolower($value['name']), $value['name']];
    }
    return $cryptocurrency_code ? $names[$cryptocurrency_code][$uppercase] : $names;
}

function bxc_crypto_get_address($cryptocurrency_code) {
    $address = false;
    $cryptocurrency_name = bxc_crypto_name($cryptocurrency_code);
    $cryptocurrency_code = strtolower($cryptocurrency_code);
    if (($cryptocurrency_code === 'btc' && bxc_settings_get('btc-node-address-generation')) || bxc_is_eth_address_generation($cryptocurrency_code)) {
        $ethereum = $cryptocurrency_code != 'btc';
        $addresses = bxc_settings_db(($ethereum ? 'eth' : 'btc') . '-addresses');
        $addresses_count = 0;
        if ($addresses) {
            $addresses = json_decode(bxc_encryption($addresses, false), true);
            $addresses_count = count($addresses);
            $now_plus_24h = time() + 86400;
            for ($i = 0; $i < $addresses_count; $i++) {
                if ($addresses[$i][1] > $now_plus_24h) {
                    $address = $addresses[$i][0];
                    break;
                }
            }
        } else $addresses = [];
        if (!$address) {
            require_once(__DIR__ . ($ethereum ? '/web3.php' : '/bitcoin.php'));
            if ($ethereum) {
                $address = bxc_eth_generate_address();
            } else if (bxc_settings_get('btc-node-xpub') && !bxc_settings_get('btc-node-transfer')) {
                $address = bxc_isset(bxc_btc_generate_address_xpub(false, [$addresses_count, $addresses_count]), 'address');
                if (is_array($address)) $address = $address[0];
                else bxc_error(json_encode($address), 'bxc_crypto_get_address');
            } else {
                $address = bxc_btc_generate_address();
            }
            if ($address && isset($address['address'])) {
                array_push($addresses, [$address, time()]);
                bxc_settings_db(($ethereum ? 'eth' : 'btc') . '-addresses', json_encode(bxc_encryption($addresses)));
                $address = $address['address'];
            } else bxc_error(json_encode($address), 'bxc_crypto_get_address');
        }
    }
    if (!$address && bxc_settings_get('custom-explorer-active')) {
        $data = bxc_curl(str_replace(['{N}', '{N2}'], [$cryptocurrency_code, $cryptocurrency_name], bxc_settings_get('custom-explorer-address')));
        $data = bxc_get_array_value_by_path(bxc_settings_get('custom-explorer-address-path'), json_decode($data, true));
        if ($data) $address = $data;
    }
    if (!$address && bxc_settings_get('gemini-address-generation')) {
        $data = bxc_gemini_curl('deposit/' . $cryptocurrency_name . '/newAddress');
        $address = bxc_isset($data, 'address');
        if (bxc_isset($data, 'result') === 'error') bxc_error($data['message'], 'bxc_crypto_get_address');
    }
    if (!$address && bxc_settings_get('coinbase-address-generation')) {
        $account = bxc_coinbase_get_accounts($cryptocurrency_code);
        if ($account) {
            $data = bxc_coinbase_curl('/v2/accounts/' . $account['id'] . '/addresses');
            $address = bxc_isset(bxc_isset($data, 'data'), 'address');
            if (isset($data['error'])) bxc_error($data['errors'][0]['message'], 'bxc_crypto_get_address');
        }
    }
    if ($address) {
        $pos = strpos($address, ':');
        return $pos ? substr($address, $pos + 1) : $address;
    }
    return bxc_settings_get_address($cryptocurrency_code);
}

function bxc_usd_rates($currency_code = false) {
    $fiat_rates = json_decode(bxc_settings_db('fiat_rates'), true);
    if (!$fiat_rates || $fiat_rates[0] != date('H')) {
        $app_id = BXC_CLOUD ? OPEN_EXCHANGE_RATE_APP_ID : bxc_settings_get('openexchangerates-app-id');
        if (!$app_id) return bxc_error('Missing Open Exchange Rates App ID. Set it in the Boxcoin settings area.', 'bxc_usd_rates', true);
        $fiat_rates = json_decode(bxc_curl('https://openexchangerates.org/api/latest.json?app_id=' . $app_id), true)['rates'];
        bxc_settings_db('fiat_rates', [date('H'), json_encode($fiat_rates)]);
    } else {
        $fiat_rates = json_decode($fiat_rates[1], true);
    }
    return $currency_code ? $fiat_rates[strtoupper($currency_code)] : floatval($fiat_rates);
}

function bxc_exchange_rates($currency_code, $cryptocurrency_code) {
    global $BXC_EXCHANGE_RATE;
    $custom_tokens = bxc_get_custom_tokens();
    if ($custom_tokens && isset($custom_tokens[strtolower($cryptocurrency_code)])) return floatval($custom_tokens[strtolower($cryptocurrency_code)]['rate']);
    $response = json_decode(bxc_curl('https://api.coinbase.com/v2/exchange-rates?currency=' . $currency_code), true);
    if ($response && isset($response['errors'])) return bxc_error($response['errors'][0]['message'], 'bxc_exchange_rates', true);
    $rates = $BXC_EXCHANGE_RATE ? $BXC_EXCHANGE_RATE : json_decode(bxc_curl('https://api.coinbase.com/v2/exchange-rates?currency=' . $currency_code), true)['data']['rates'];
    return floatval($rates[strtoupper(bxc_crypto_get_base_code($cryptocurrency_code))]);
}

function bxc_crypto_convert($transaction_id, $cryptocurrency_code, $amount) {
    $response = false;
    $success = false;
    $ethereum = ($cryptocurrency_code == 'eth' || in_array($cryptocurrency_code, bxc_get_cryptocurrency_codes('eth'))) && bxc_settings_get('eth-node-conversion');
    if ($ethereum) {
        require_once(__DIR__ . '/web3.php');
        $history = json_decode(bxc_settings_db('fiat_conversion', false, '[]'), true);
        if (!in_array($transaction_id, $history)) {
            $response = bxc_eth_swap($amount, $cryptocurrency_code);
            $success = substr($response, 0, 2) == '0x';
        }
    }
    if (!$success) {
        $gemini = bxc_settings_get('gemini-conversion');
        $coinbase = bxc_settings_get('coinbase-conversion');
        if ($gemini || $coinbase) {
            $history = json_decode(bxc_settings_db('fiat_conversion', false, '[]'), true);
            if (!in_array($transaction_id, $history)) {
                array_push($history, $transaction_id);
                if ($gemini) $response = bxc_gemini_convert_to_fiat($cryptocurrency_code, $amount);
                if ($response == false && $coinbase) {
                    $account = bxc_coinbase_get_accounts($cryptocurrency_code);
                    if ($account) {
                        $response = bxc_coinbase_curl('/v2/accounts/' . $account['id'] . '/sells', ['amount' => $amount, 'currency' => $cryptocurrency_code]);
                    }
                }
                $success = ($coinbase && bxc_isset(bxc_isset($response, 'data', []), 'status') == 'created') || ($gemini && bxc_isset($response, 'order_id'));
            }
        }
    }
    if ($success) array_push($history, $transaction_id);
    if ($ethereum || $gemini || $coinbase) {
        bxc_settings_db('fiat_conversion', $history);
        if (bxc_settings_get('notifications-conversion')) {
            $language = bxc_settings_get('language-admin');
            $value = $amount . ' ' . strtoupper($cryptocurrency_code);
            $subject = $value . ' ' . bxc_m('converted', $language);
            $name =  $ethereum ? 'Ethereum' : ($gemini ? 'Gemini' : 'Coinbase');
            $message = str_replace(['{T}', '{T2}'], [$value, $name], bxc_m('{T} were converted to ' .  strtoupper($ethereum ? bxc_settings_get('eth-node-conversion-currency') : ($gemini ? bxc_settings_get('gemini-conversion-currency') : 'FIAT')) . ' through {T2}.', $language));
            if (!$success) {
                $subject = 'The conversion of ' . $value . ' failed';
                $message = 'The conversion of ' . $value . ' through ' . $name . ' failed. Response details: <br><br>' . json_encode($response);
            }
            bxc_email_notification($subject,  $message);
        }
    }
    return $response;
}

function bxc_crypto_transfer($transaction_id, $cryptocurrency_code, $amount) {
    $response = false;
    $success = false;
    $cryptocurrency_code = strtolower($cryptocurrency_code);
    $ethereum = ($cryptocurrency_code == 'eth' || in_array($cryptocurrency_code, bxc_get_cryptocurrency_codes('eth'))) && bxc_settings_get('eth-node-transfer') && !bxc_settings_get('eth-node-conversion');
    $bitcoin = $cryptocurrency_code == 'btc' && bxc_settings_get('btc-node-transfer');
    if ($bitcoin || $ethereum) {
        require_once(__DIR__ . ($bitcoin ? '/bitcoin.php' : '/web3.php'));
        $history = json_decode(bxc_settings_db('crypto_transfers', false, '[]'), true);
        if (!in_array($transaction_id, $history)) {
            if ($bitcoin) {
                $transaction = bxc_transactions_get($transaction_id);
                $response = bxc_btc_transfer($amount, false, $transaction[$to]);
                $success = is_string($response);
            } else {
                $response = bxc_eth_transfer($amount, $cryptocurrency_code);
                $success = substr($response, 0, 2) == '0x';
            }
        }
    }
    if (!$success) {
        $gemini = bxc_settings_get('gemini-transfer') && !bxc_settings_get('gemini-conversion') && bxc_settings_get('gemini-address-generation');
        $coinbase = bxc_settings_get('coinbase-transfer') && !bxc_settings_get('coinbase-conversion') && bxc_settings_get('coinbase-address-generation');
        if ($gemini || $coinbase) {
            $history = json_decode(bxc_settings_db('crypto_transfers', false, '[]'), true);
            if (!in_array($transaction_id, $history)) {
                $address = bxc_settings_get_address($cryptocurrency_code);
                if ($address && !bxc_crypto_whitelist_invalid($address, false)) {
                    if ($gemini) {
                        $response = bxc_gemini_curl('withdraw/' . $cryptocurrency_code, ['address' => $address, 'amount' => $amount]);
                    } else if ($coinbase) {
                        $account = bxc_coinbase_get_accounts($cryptocurrency_code);
                        if ($account) {
                            $response = bxc_coinbase_curl('/v2/accounts/' . $account['id'] . '/transactions', ['to' => $address, 'amount' => $amount, 'currency' => $cryptocurrency_code, 'type' => 'send']);
                        }
                    }
                    $success = ($coinbase && bxc_isset(bxc_isset($response, 'data', []), 'status') == 'pending') || ($gemini && bxc_isset($response, 'address'));
                }
            }
        }
    }
    if ($success) array_push($history, $transaction_id);
    if ($bitcoin || $ethereum || $gemini || $coinbase) {
        bxc_settings_db('crypto_transfers', $history);
        if (bxc_settings_get('notifications-transfer')) {
            $language = bxc_settings_get('language-admin');
            $value = $amount . ' ' . strtoupper($cryptocurrency_code);
            $subject = $value . ' ' . bxc_m('sent to', $language) . ' ' . $address;
            $name =  $ethereum ? 'Ethereum' : ($bitcoin ? 'Bitcoin' : ($gemini ? 'Gemini' : 'Coinbase'));
            $message = str_replace(['{T}', '{T2}', '{T3}'], [$value, '<b>' . $address . '</b>', $name], bxc_m('{T} sent to {T2} through {T3}.', $language));
            if (!$success) {
                $subject = 'The transfer of ' . $value . ' failed';
                $message = 'The transfer of ' . $value . ' to <b>' . $address . '</b> through ' . $name . ' failed. Response details: <br><br>' . json_encode($response);
            }
            bxc_email_notification($subject,  $message);
        }
    }
    return $response;
}

function bxc_crypto_get_base_code($cryptocurrency_code) {
    $cryptocurrency_code = strtolower($cryptocurrency_code);
    return bxc_isset(['usdt_tron' => 'usdt', 'usdt_bsc' => 'usdt'], $cryptocurrency_code, $cryptocurrency_code);
}

function bxc_crypto_get_network($cryptocurrency_code, $label = false) {
    $networks = bxc_get_cryptocurrency_codes();
    $cryptocurrency_code = strtolower($cryptocurrency_code);
    foreach ($networks as $key => $value) {
        if (in_array($cryptocurrency_code, $networks[$key])) {
            $text = $key . ' ' . bxc_('network');
            return $label === true ? '<span class="bxc-label">' . $text . '</span>' : ($label === 'full_name' ? bxc_isset(['BTC' => 'bitcoin', 'ETH' => 'ethereum', 'TRX' => 'tron', 'LTC' => 'litecoin'], $key) : strtolower($key));
        }
    }
    return '';
}

function bxc_crypto_whitelist_invalid($address, $check_address_generation = true, $cryptocurrency_code = false) {
    if ($check_address_generation && bxc_is_address_generation($cryptocurrency_code)) return false;
    if (!defined('BXC_WHITELIST') || in_array($address, BXC_WHITELIST)) return false;
    bxc_error('The address ' . $address . ' is not on the whitelist. Edit the config.php file and add it to the constant BXC_WHITELIST.', 'bxc_crypto_address_verification', true);
    return true;
}

function bxc_get_custom_tokens() {
    $index = 1;
    $tokens = [];
    while ($index) {
        $suffix = $index > 1 ? '-' . $index : '';
        $token = bxc_settings_get('custom-token-code' . $suffix);
        if ($token) {
            $type = bxc_settings_get('custom-token-type' . $suffix);
            if ($type) {
                $tokens[$token] = ['type' => $type, 'code' => $token, 'name' => bxc_settings_get('custom-token-name' . $suffix), 'address' => bxc_settings_get('custom-token-address' . $suffix), 'contract_address' => bxc_settings_get('custom-token-contract-address' . $suffix), 'img' => bxc_settings_get('custom-token-img' . $suffix), 'decimals' => bxc_settings_get('custom-token-decimals' . $suffix), 'rate' => bxc_settings_get('custom-token-rate' . $suffix)];
            }
            $index++;
        } else {
            $index = false;
        }
    }
    return $tokens;
}

function bxc_get_cryptocurrency_codes($blockchain = false) {
    $cryptocurrencies = ['ETH' => ['usdt', 'usdc', 'link', 'shib', 'bat'], 'TRX' => ['usdt_tron'], 'BSC' => ['bnb', 'busd', 'usdt_bsc'], 'XRP' => ['xrp'], 'LTC' => ['ltc']];
    return $blockchain ? $cryptocurrencies[strtoupper($blockchain)] : $cryptocurrencies;
}

function bxc_crypto_get_decimals($cryptocurrency_code) {
    return bxc_isset(['btc' => 8, 'eth' => 8, 'xrp' => 6, 'usdt' => 6, 'usdt_tron' => 6, 'usdt_bsc' => 6, 'usdc' => 6, 'link' => 5, 'doge' => 8, 'algo' => 6, 'shib' => 1, 'bat' => 3, 'bnb' => 7, 'busd' => 6, 'ltc' => 8, 'bch' => 8], $cryptocurrency_code);
}

/*
 * -----------------------------------------------------------
 * # ACCOUNT
 * -----------------------------------------------------------
 *
 * 1. Admin login
 * 2. Verify the admin login
 * 3. Get the active account
 * 4. Get wallet key
 *
 */

function bxc_login($username, $password) {
    if (strtolower($username) == strtolower(BXC_USER) && password_verify($password, BXC_PASSWORD)) {
        $data = [BXC_USER];
        $GLOBALS['BXC_LOGIN'] = $data;
        return [bxc_encryption(json_encode($data, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE))];
    }
    return false;
}

function bxc_verify_admin() {
    $account = bxc_account();
    return $account && $account[0] === BXC_USER ? true : false;
}

function bxc_account() {
    global $BXC_LOGIN;
    if (!defined('BXC_USER')) return false;
    if ($BXC_LOGIN) return $BXC_LOGIN;
    if (isset($_COOKIE['BXC_LOGIN'])) {
        $data = json_decode(bxc_encryption($_COOKIE['BXC_LOGIN'], false), true);
        if ($data) {
            $GLOBALS['BXC_LOGIN'] = $data;
            return $data;
        }
    }
    return false;
}

function bxc_get_wallet_key($cryptocurrency_code) {
    $key = bxc_settings_get(strtolower($cryptocurrency_code) . '-wallet-key');
    return $key ? bxc_encryption($key, false) : false;
}

/*
 * -----------------------------------------------------------
 * SETTINGS
 * -----------------------------------------------------------
 *
 * 1. Populate the admin area with the settings of the file /resources/settings.json
 * 2. Return the HTML code of a setting element
 * 3. Save all settings
 * 4. Return a single setting
 * 5. Return all settings
 * 6. Return JS settings for admin side
 * 7. Return or save a database setting
 * 8. Get a saved address
 *
 */

function bxc_settings_populate() {
    global $BXC_APPS;
    $settings = json_decode(file_get_contents(__DIR__ . '/resources/settings.json'), true);
    $code = '';
    $language = bxc_language(true);
    $translations = [];
    for ($i = 0; $i < count($BXC_APPS); $i++) {
        $path = __DIR__ . '/apps/' . $BXC_APPS[$i] . '/settings.json';
        if (file_exists($path)) {
            $settings = array_merge($settings, json_decode(file_get_contents($path), true));
        }
    }
    if ($language) {
        $path = __DIR__ . '/resources/languages/settings/' . $language . '.json';
        if (file_exists($path)) {
            $translations = json_decode(file_get_contents($path), true);
        }
    }
    for ($i = 0; $i < count($settings); $i++) {
        $code .= bxc_settings_get_code($settings[$i], $translations);
    }
    echo $code;
}

function bxc_settings_get_code($setting, &$translations = []) {
    if (isset($setting)) {
        $id = $setting['id'];
        $type = $setting['type'];
        $title = $setting['title'];
        $content = $setting['content'];
        $code = '<div id="' . $id . '" data-type="' . $type . '" class="bxc-input"><div class="bxc-setting-content"><span>' . bxc_isset($translations, $title, $title) . '</span><p>' . bxc_isset($translations, $content, $content) . (isset($setting['help']) ? '<a href="' . (BXC_CLOUD ? CLOUD_DOCS : 'https://boxcoin.dev/docs') . '/' . $setting['help'] . '" target="_blank" class="bxc-icon-help"></a>' : '') . '</p></div><div class="bxc-setting-input">';
        switch ($type) {
            case 'color':
            case 'text':
                $code .= '<input type="text">';
                break;
            case 'password':
                $code .= '<input type="password">';
                break;
            case 'textarea':
                $code .= '<textarea></textarea>';
                break;
            case 'select':
                $values = $setting['value'];
                $code .= '<select>';
                for ($i = 0; $i < count($values); $i++) {
                    $code .= '<option value="' . $values[$i][0] . '">' . bxc_isset($translations, $values[$i][1], $values[$i][1]) . '</option>';
                }
                $code .= '</select>';
                break;
            case 'checkbox':
                $code .= '<input type="checkbox">';
                break;
            case 'number':
                $code .= '<input type="number">';
                break;
            case 'multi-input':
                $values = $setting['value'];
                for ($i = 0; $i < count($values); $i++) {
                    $sub_type = $values[$i]['type'];
                    $sub_title = $values[$i]['title'];
                    $code .= '<div id="' . $values[$i]['id'] . '" data-type="' . $sub_type . '"><span>' . bxc_isset($translations, $sub_title, $sub_title) . (isset($values[$i]['label']) ? '<span class="bxc-label">' . $values[$i]['label'] . '</span>' : '') . '</span>';
                    switch ($sub_type) {
                        case 'color':
                        case 'text':
                            $code .= '<input type="text">';
                            break;
                        case 'password':
                            $code .= '<input type="password">';
                            break;
                        case 'number':
                            $code .= '<input type="number">';
                            break;
                        case 'textarea':
                            $code .= '<textarea></textarea>';
                            break;
                        case 'checkbox':
                            $code .= '<input type="checkbox">';
                            break;
                        case 'select':
                            $code .= '<select>';
                            $items = $values[$i]['value'];
                            for ($j = 0; $j < count($items); $j++) {
                                $code .= '<option value="' . $items[$j][0] . '">' . bxc_isset($translations, $items[$j][1], $items[$j][1]) . '</option>';
                            }
                            $code .= '</select>';
                            break;
                        case 'button':
                            $code .= '<a class="bxc-btn" href="' . $values[$i]['button-url'] . '">' . bxc_isset($translations, $values[$i]['button-text'], $values[$i]['button-text']) . '</a>';
                            break;
                    }
                    $code .= '</div>';
                }
                if (isset($setting['repeater'])) $code .= '<div class="bxc-btn bxc-btn-repater" data-index="2">' . bxc_isset($translations, $setting['repeater_button'], $setting['repeater_button']) . '</div>';
                break;
        }
        return $code . '</div></div>';
    }
    return '';
}

function bxc_settings_save($settings) {
    $settings = json_decode($settings, true);
    $settings_old = bxc_settings_get_all();
    if (!$settings) return false;
    $encryption = ['btc-wallet-key', 'eth-wallet-key'];
    for ($i = 0; $i < count($encryption); $i++) {
        $key = $encryption[$i];
        if (!empty($settings[$key])) {
            $settings[$key] = $settings[$key] == '********' ? $settings_old[$key] : bxc_encryption($settings[$encryption[$i]]);
        }
    }
    $settings = str_replace(['"false"', '"true"'], ['false', 'true'], json_encode($settings, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE));
    if (json_last_error() != JSON_ERROR_NONE || !$settings) return json_last_error();
    $settings = bxc_encryption($settings);
    return bxc_db_query('INSERT INTO bxc_settings (name, value) VALUES (\'settings\', \'' . $settings . '\') ON DUPLICATE KEY UPDATE value = \'' . $settings . '\'');
}

function bxc_settings_get($id, $default = false) {
    global $BXC_SETTINGS;
    if (!$BXC_SETTINGS) $BXC_SETTINGS = bxc_settings_get_all();
    return bxc_isset($BXC_SETTINGS, $id, $default);
}

function bxc_settings_get_all() {
    global $BXC_SETTINGS;
    if (!$BXC_SETTINGS) {
        $BXC_SETTINGS = bxc_settings_db('settings');
        if ($BXC_SETTINGS) {
            if (substr($BXC_SETTINGS, 0, 1) !== '{') $BXC_SETTINGS = bxc_encryption($BXC_SETTINGS, false); // temp, the if check will be removed soon
            $BXC_SETTINGS = json_decode($BXC_SETTINGS, true);
        } else $BXC_SETTINGS = [];
    }
    return $BXC_SETTINGS;
}

function bxc_settings_js_admin() {
    $language = bxc_language(true);
    $code = 'var BXC_LANG = "' . $language . '"; var BXC_AJAX_URL = "' . BXC_URL . 'ajax.php' . '"; var BXC_TRANSLATIONS = ' . ($language ? file_get_contents(__DIR__ . '/resources/languages/admin/' . $language . '.json') : '{}') . '; var BXC_CURRENCY = "' . bxc_settings_get('currency', 'USD') . '"; var BXC_URL = "' . BXC_URL . '"; var BXC_ADMIN = true; var BXC_ADDRESS = { btc: "' . bxc_settings_get('address-btc') . '", eth: "' . bxc_settings_get('address-eth') . '", doge: "' . bxc_settings_get('address-doge') . '", algo: "' . bxc_settings_get('address-algo') . '", link: "' . bxc_settings_get('address-link') . '", usdt: "' . bxc_settings_get('address-usdt') . '", usdt_tron: "' . bxc_settings_get('address-usdt_tron') . '", usdt_bsc: "' . bxc_settings_get('address-usdt_bsc') . '", bat: "' . bxc_settings_get('address-bat') . '", usdc: "' . bxc_settings_get('address-usdc') . '", shib: "' . bxc_settings_get('address-shib') . '", bnb: "' . bxc_settings_get('address-bnb') . '", busd: "' . bxc_settings_get('address-busd') . '", ltc: "' . bxc_settings_get('address-ltc') . '", bch: "' . bxc_settings_get('address-bch') . '", xrp: "' . bxc_settings_get('address-xrp') . '"};';
    if (!BXC_CLOUD) $code .= 'var BXC_CLOUD = false;';
    return $code;
}

function bxc_settings_db($name, $value = false, $default = false) {
    if ($value === false) return bxc_isset(bxc_db_get('SELECT value FROM bxc_settings WHERE name = "' . bxc_db_escape($name) . '"'), 'value', $default);
    if (is_string($value) || is_numeric($value)) {
        $value = bxc_db_escape($value);
    } else {
        $value = bxc_db_json_escape($value);
        if (json_last_error() != JSON_ERROR_NONE || !$value) return json_last_error();
    }
    return bxc_db_query('INSERT INTO bxc_settings (name, value) VALUES (\'' . bxc_db_escape($name) . '\', \'' . $value . '\') ON DUPLICATE KEY UPDATE value = \'' . $value . '\'');
}

function bxc_settings_get_address($cryptocurrency_code) {
    $custom_tokens = bxc_get_custom_tokens();
    return $custom_tokens && isset($custom_tokens[$cryptocurrency_code]) ? $custom_tokens[$cryptocurrency_code]['address'] : bxc_settings_get('address-' . $cryptocurrency_code);
}

/*
 * -----------------------------------------------------------
 * # LANGUAGE
 * -----------------------------------------------------------
 *
 * 1. Initialize the translations
 * 2. Get the active language
 * 3. Return the translation of a string
 * 4. Echo the translation of a string
 *
 */

function bxc_init_translations() {
    global $BXC_TRANSLATIONS;
    global $BXC_LANGUAGE;
    if (!empty($BXC_LANGUAGE) && $BXC_LANGUAGE[0] != 'en') {
        $path = __DIR__ . '/resources/languages/' . $BXC_LANGUAGE[1] . '/' . $BXC_LANGUAGE[0] . '.json';
        if (file_exists($path)) {
            $BXC_TRANSLATIONS = json_decode(file_get_contents($path), true);
        }  else {
            $BXC_TRANSLATIONS = false;
        }
    } else if (!isset($BXC_LANGUAGE)) {
        $BXC_LANGUAGE = false;
        $BXC_TRANSLATIONS = false;
        $admin = bxc_verify_admin();
        $language = bxc_language($admin);
        $area = $admin ? 'admin' : 'client';
        if ($language) {
            $path = __DIR__ . '/resources/languages/' . $area . '/' . $language . '.json';
            if (file_exists($path)) {
                $BXC_TRANSLATIONS = json_decode(file_get_contents($path), true);
                $BXC_LANGUAGE = [$language, $area];
            }  else {
                $BXC_TRANSLATIONS = false;
            }
        }
    }
    if ($BXC_LANGUAGE && $BXC_TRANSLATIONS && file_exists(__DIR__ . '/translations.json')) {
        $custom_translations = json_decode(file_get_contents(__DIR__ . '/translations.json'), true);
        if ($custom_translations && isset($custom_translations[$BXC_LANGUAGE[0]])) {
            $BXC_TRANSLATIONS = array_merge($BXC_TRANSLATIONS, $custom_translations[$BXC_LANGUAGE[0]]);
        }
    }
}

function bxc_language($admin = false) {
    $language = bxc_settings_get($admin ? 'language-admin' : 'language');
    if ($language == 'auto') $language = strtolower(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : false);
    if (!$language) $language = bxc_isset($_POST, 'language');
    return $language == 'en' ? false : $language;
}

function bxc_($string) {
    global $BXC_TRANSLATIONS;
    if (!isset($BXC_TRANSLATIONS)) {
        bxc_init_translations();
    }
    return empty($BXC_TRANSLATIONS[$string]) ? $string : $BXC_TRANSLATIONS[$string];
}

function bxc_e($string) {
    echo bxc_($string);
}

function bxc_m($string, $language_code) {
    global $BXC_TRANSLATIONS_2;
    if (!$language_code) return $string;
    if (!isset($BXC_TRANSLATIONS_2)) {
        $path = __DIR__ . '/resources/languages/client/' . $language_code . '.json';
        if (file_exists($path)) $BXC_TRANSLATIONS_2 = json_decode(file_get_contents($path), true);
    }
    return empty($BXC_TRANSLATIONS_2[$string]) ? $string : $BXC_TRANSLATIONS_2[$string];
}

/*
 * -----------------------------------------------------------
 * DATABASE
 * -----------------------------------------------------------
 *
 * 1. Connection to the database
 * 2. Get database values
 * 3. Insert or update database values
 * 4. Escape and sanatize values prior to databse insertion
 * 5. Escape a JSON string prior to databse insertion
 * 6. Set default database environment settings
 *
 */

function bxc_db_connect() {
    global $BXC_CONNECTION;
    if (!defined('BXC_DB_NAME') || !BXC_DB_NAME) return false;
    if ($BXC_CONNECTION) {
        bxc_db_init_settings();
        return true;
    }
    $BXC_CONNECTION = new mysqli(BXC_DB_HOST, BXC_DB_USER, BXC_DB_PASSWORD, BXC_DB_NAME, defined('BXC_DB_PORT') && BXC_DB_PORT ? intval(BXC_DB_PORT) : ini_get('mysqli.default_port'));
    if ($BXC_CONNECTION->connect_error) {
        echo 'Connection error. Visit the admin area for more details or open the config.php file and check the database information. Message: ' . $BXC_CONNECTION->connect_error . '.';
        return false;
    }
    bxc_db_init_settings();
    return true;
}

function bxc_db_get($query, $single = true) {
    global $BXC_CONNECTION;
    $status = bxc_db_connect();
    $value = ($single ? '' : []);
    if ($status) {
        $result = $BXC_CONNECTION->query($query);
        if ($result) {
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    if ($single) {
                        $value = $row;
                    } else {
                        array_push($value, $row);
                    }
                }
            }
        } else {
            return $BXC_CONNECTION->error;
        }
    } else {
        return $status;
    }
    return $value;
}

function bxc_db_query($query, $return = false) {
    global $BXC_CONNECTION;
    $status = bxc_db_connect();
    if ($status) {
        $result = $BXC_CONNECTION->query($query);
        if ($result) {
            if ($return) {
                if (isset($BXC_CONNECTION->insert_id) && $BXC_CONNECTION->insert_id > 0) {
                    return $BXC_CONNECTION->insert_id;
                } else {
                    return $BXC_CONNECTION->error;
                }
            } else {
                return true;
            }
        } else {
            return $BXC_CONNECTION->error;
        }
    } else {
        return $status;
    }
}

function bxc_db_escape($value, $numeric = -1) {
    if (is_numeric($value)) return $value;
    else if ($numeric === true) return false;
    if ($value === false) return false;
    global $BXC_CONNECTION;
    bxc_db_connect();
    if ($BXC_CONNECTION) $value = $BXC_CONNECTION->real_escape_string($value);
    $value = str_replace(['\"', '"'], ['"', '\"'], $value);
    $value = str_replace(['<script', '</script'], ['&lt;script', '&lt;/script'], $value);
    $value = str_replace(['javascript:', 'onclick=', 'onerror='], '', $value);
    $value = htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'utf-8');
    return $value;
}

function bxc_db_json_escape($array) {
    global $BXC_CONNECTION;
    bxc_db_connect();
    $value = str_replace(['"false"', '"true"'], ['false', 'true'], json_encode($array, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE));
    $value = str_replace(['<script', '</script'], ['&lt;script', '&lt;/script'], $value);
    $value = str_replace(['javascript:', 'onclick=', 'onerror='], '', $value);
    return $BXC_CONNECTION ? $BXC_CONNECTION->real_escape_string($value) : $value;
}

function bxc_db_check_connection($name = false, $user = false, $password = false, $host = false, $port = false) {
    global $BXC_CONNECTION;
    $response = true;
    if ($name === false && defined('BXC_DB_NAME')) {
        $name = BXC_DB_NAME;
        $user = BXC_DB_USER;
        $password = BXC_DB_PASSWORD;
        $host = BXC_DB_HOST;
        $port = defined('BXC_DB_PORT') && BXC_DB_PORT ? intval(BXC_DB_PORT) : false;
    }
    try {
        set_error_handler(function() {}, E_ALL);
    	$BXC_CONNECTION = new mysqli($host, $user, $password, $name, $port === false ? ini_get('mysqli.default_port') : intval($port));
    }
    catch (Exception $e) {
        $response = $e->getMessage();
    }
    if ($BXC_CONNECTION->connect_error) {
        $response = $BXC_CONNECTION->connect_error;
    }
    restore_error_handler();
    return $response;
}

function bxc_db_init_settings() {
    global $BXC_CONNECTION;
    $BXC_CONNECTION->set_charset('utf8mb4');
    $BXC_CONNECTION->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
}

/*
 * -----------------------------------------------------------
 * MISCELLANEOUS
 * -----------------------------------------------------------
 *
 * 1. Encryption
 * 2. Check if a key is set and return it
 * 3. Update or create config file
 * 4. Installation
 * 5. Check if database connection is working
 * 6. Curl
 * 7. Cron jobs
 * 8. Scientific number to decimal number
 * 9. Get array value by path
 * 10. Updates
 * 11. Check if demo URL
 * 12. Check if RTL
 * 13. Debug
 * 14. CSV
 * 15. Apply admin colors
 * 16. Load the custom .js and .css files
 * 17. Generate the payment redirect URL
 * 18. Apply version updates
 * 19. Error reporting
 * 20. Env check
 * 21. Check if address generation or not
 * 22. Vbox
 * 23. Email
 * 24. Email notifications
 * 25. Check if ETH address generation
 * 26. Get Tron contract addresses
 * 27. Get Binance contract addresses
 *
 */

function bxc_encryption($string, $encrypt = true) {
    $output = false;
    $encrypt_method = 'AES-256-CBC';
    $secret_key = BXC_PASSWORD . BXC_USER;
    $key = hash('sha256', $secret_key);
    $iv = substr(hash('sha256', BXC_PASSWORD), 0, 16);
    if ($encrypt) {
        $output = openssl_encrypt(is_string($string) ? $string : json_encode($string, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE), $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
        if (substr($output, -1) == '=') $output = substr($output, 0, -1);
    } else {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }
    return $output;
}

function bxc_isset($array, $key, $default = false) {
    return !empty($array) && isset($array[$key]) && $array[$key] !== '' ? $array[$key] : $default;
}

function bxc_config($content) {
    $file = fopen(__DIR__ . '/config.php', 'w');
    fwrite($file, $content);
    fclose($file);
    return true;
}

function bxc_installation($data) {
    if (!defined('BXC_USER') || !defined('BXC_DB_HOST') || BXC_CLOUD) {
        if (is_string($data)) $data = json_decode($data, true);
        $connection_check = bxc_db_check_connection($data['db-name'], $data['db-user'], $data['db-password'], $data['db-host'], $data['db-port']);
        if ($connection_check === true) {

            // Create the config.php file
            $code = '<?php' . PHP_EOL;
            if (empty($data['db-host'])) $data['db-host'] = 'localhost';
            if (empty($data['db-port'])) $data['db-port'] = ini_get('mysqli.default_port');
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password-check']);
            foreach ($data as $key => $value) {
                if (!$value && $key != 'db-password') return 'Empty ' . $key;
                $code .= 'define(\'BXC_' . str_replace('-', '_', strtoupper($key)) . '\', \'' . str_replace('\'', '\\\'', $value) . '\');' . PHP_EOL;
            }
            $file = fopen(__DIR__ . (!empty($data['token']) ? '/cloud/config/' . $data['token'] . '.php' : '/config.php'), 'w');
            fwrite($file, $code . '?>');
            fclose($file);

            // Create the database tables
            $connection = new mysqli($data['db-host'], $data['db-user'], $data['db-password'], $data['db-name'], $data['db-port']);
            $connection->set_charset('utf8mb4');
            $connection->query('CREATE TABLE IF NOT EXISTS bxc_transactions (id INT NOT NULL AUTO_INCREMENT, `from` VARCHAR(255) NOT NULL DEFAULT "", `to` VARCHAR(255), hash VARCHAR(255) NOT NULL DEFAULT "", `title` VARCHAR(500) NOT NULL DEFAULT "", description VARCHAR(1000) NOT NULL DEFAULT "", amount VARCHAR(100) NOT NULL, amount_fiat VARCHAR(100) NOT NULL, cryptocurrency VARCHAR(10) NOT NULL, currency VARCHAR(10) NOT NULL, external_reference VARCHAR(1000) NOT NULL DEFAULT "", creation_time DATETIME NOT NULL, status VARCHAR(1) NOT NULL, webhook TINYINT NOT NULL, billing TINYTEXT, vat FLOAT, vat_details TINYTEXT, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci');
            $connection->query('CREATE TABLE IF NOT EXISTS bxc_checkouts (id INT NOT NULL AUTO_INCREMENT, title VARCHAR(255), description TEXT, price VARCHAR(100) NOT NULL, currency VARCHAR(10) NOT NULL, type VARCHAR(1), redirect VARCHAR(255), hide_title TINYINT, external_reference VARCHAR(1000) NOT NULL DEFAULT "", creation_time DATETIME NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci');
            $connection->query('CREATE TABLE IF NOT EXISTS bxc_settings (name VARCHAR(255) NOT NULL, value LONGTEXT, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci');

            return true;
        }
        return $connection_check;
    }
    return false;
}

function bxc_curl($url, $post_fields = '', $header = [], $type = 'GET') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BOXCOIN');
    switch ($type) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post_fields) ? $post_fields : http_build_query($post_fields));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 7);
            if ($type != 'POST') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
            }
            break;
        case 'GET':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 7);
            curl_setopt($ch, CURLOPT_HEADER, false);
            break;
        case 'DOWNLOAD':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($ch, CURLOPT_TIMEOUT, 70);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            break;
        case 'FILE':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
            curl_setopt($ch, CURLOPT_TIMEOUT, 400);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            if (strpos($url, '?')) $url = substr($url, 0, strpos($url, '?'));
            $file = fopen(__DIR__ . '/uploads/' . basename($url), 'wb');
            curl_setopt($ch, CURLOPT_FILE, $file);
            break;
    }
    if (!empty($header)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        if (bxc_isset($header, 'CURLOPT_USERPWD')) {
            curl_setopt($ch, CURLOPT_USERPWD, $header['CURLOPT_USERPWD']);
        }
    }
    $response = curl_exec($ch);
    if (curl_errno($ch) > 0) {
        $error = curl_error($ch);
        curl_close($ch);
        return $error;
    }
    curl_close($ch);
    return $response;
}

function bxc_download($url) {
    return bxc_curl($url, '', '', 'DOWNLOAD');
}

function bxc_cron() {

    // Updates
    if (!BXC_CLOUD && bxc_settings_get('update-auto')) {
        bxc_update($_POST['domain']);
        bxc_version_updates();
    }

    // Invoice deletion
    if (!BXC_CLOUD && bxc_settings_get('invoice-active')) {
        $path = __DIR__ . '/uploads/';
        $files = scandir($path);
        $expiration = strtotime('-1 days');
        for ($i = 0; $i < count($files); $i++) {
            $file = $files[$i];
            if (strpos($file, 'inv-') === 0 && (filemtime($path . $file) < $expiration)) {
                unlink($path . '/' . $file);
            }
        }
    }

    // Delete pending transactions
    bxc_transactions_delete_pending();

    // Check payment for pending transactions
    bxc_transactions_check_pending();
}

function bxc_decimal_number($number) {
    $number = rtrim(number_format($number, 10, '.', ''),'0');
    return substr($number, -1) == '.' ? substr($number, 0, -1) : $number;
}

function bxc_get_array_value_by_path($path, $array) {
    $path = str_replace(' ', '', $path);
    if (strpos($path, ',')) {
        $response = [];
        $paths = explode(',', $path);
        for ($i = 0; $i < count($paths); $i++) {
            array_push($response, bxc_get_array_value_by_path($paths[$i], $array));
        }
        return $response;
    }
    $path = explode('>', $path);
    for ($i = 0; $i < count($path); $i++) {
        $array = $array ? bxc_isset($array, $path[$i]) : false;
    }
    return $array;
}

function bxc_update($domain) {
    $envato_purchase_code = bxc_settings_get('envato-purchase-code');
    if (!$envato_purchase_code) return 'envato-purchase-code-not-found';
    if (!class_exists('ZipArchive')) return 'no-zip-archive';
    $latest_version = bxc_versions();
    if (bxc_isset($latest_version, 'boxcoin') == BXC_VERSION) return 'latest-version-installed';
    $response = json_decode(bxc_download('https://boxcoin.dev/sync/updates.php?key=' . trim($envato_purchase_code) . '&domain=' . $domain), true);
    if (empty($response['boxcoin'])) return 'invalid-envato-purchase-code';
    $zip = bxc_download('https://boxcoin.dev/sync/temp/' . $response['boxcoin']);
    if ($zip) {
        $file_path = __DIR__ . '/boxcoin.zip';
        file_put_contents($file_path, $zip);
        if (file_exists($file_path)) {
            $zip = new ZipArchive;
            if ($zip->open($file_path) === true) {
                $zip->extractTo(__DIR__);
                $zip->close();
                unlink($file_path);
                return true;
            }
            return 'zip-error';
        }
        return 'file-not-found';
    }
    return 'download-error';
}

function bxc_versions() {
    return json_decode(bxc_download('https://boxcoin.dev/sync/versions.json'), true);
}

function bxc_is_demo($attributes = false) {
    $url = bxc_isset($_SERVER, 'HTTP_REFERER');
    if (strpos($url, 'demo=true')) {
        if ($attributes) {
            parse_str($url, $url);
            return $url;
        }
        return true;
    }
    return false;
}

function bxc_is_rtl($language) {
    return in_array($language, ['ar', 'he', 'ku', 'fa', 'ur']);
}

function bxc_debug($value) {
    $value = is_string($value) ? $value : json_encode($value);
    if (file_exists('debug.txt')) {
        $value = file_get_contents('debug.txt') . PHP_EOL . $value;
    }
    bxc_file(__DIR__ . '/debug.txt', $value);
}

function bxc_file($path, $content) {
    try {
        $file = fopen($path, 'w');
        fwrite($file, $content);
        fclose($file);
        return true;
    }
    catch (Exception $e) {
        return $e->getMessage();
    }
}

function bxc_csv($rows, $header, $filename) {
    $filename .= '-' . rand(999999,999999999) . '.csv';
    $file = fopen(__DIR__ . '/uploads/' . $filename, 'w');
    if ($header) {
        fputcsv($file, $header);
    }
    for ($i = 0; $i < count($rows); $i++) {
    	fputcsv($file, $rows[$i]);
    }
    fclose($file);
    return BXC_URL . 'uploads/' . $filename;
}

function bxc_colors_admin() {
    $color_1 = bxc_settings_get('color-admin-1');
    $color_2 = bxc_settings_get('color-admin-2');
    $code = '';
    if ($color_1) {
        $code = '.bxc-btn,.datepicker-cell.range-end:not(.selected), .datepicker-cell.range-start:not(.selected), .datepicker-cell.selected, .datepicker-cell.selected:hover,.bxc-select ul li:hover,.bxc-underline:hover:after { background-color: ' . $color_1 . '; }';
        $code .= '.bxc-nav>div:hover, .bxc-nav>div.bxc-active,.bxc-btn-icon:hover,.bxc-btn.bxc-btn-border:hover, .bxc-btn.bxc-btn-border:active,[data-type="multi-input"] .bxc-btn:hover { border-color: ' . $color_1 . ' !important; color: ' . $color_1 . '; }';
        $code .= '.bxc-link:hover, .bxc-link:active,.bxc-input input[type="checkbox"]:checked:before,.bxc-loading:before, [data-boxcoin]:empty:before,.bxc-search input:focus+input+i,.bxc-select p:hover { color: ' . $color_1 . '; }';
        $code .= '.bxc-input input:focus, .bxc-input input.bxc-focus, .bxc-input select:focus, .bxc-input select.bxc-focus, .bxc-input textarea:focus, .bxc-input textarea.bxc-focus { border-color: ' . $color_1 . '; }';
        $code .= '.datepicker-cell.range,.bxc-btn-icon:hover,.bxc-input input:focus, .bxc-input input.bxc-focus, .bxc-input select:focus, .bxc-input select.bxc-focus, .bxc-input textarea:focus, .bxc-input textarea.bxc-focus,.bxc-table tr:hover td { background-color: rgb(105 105 105 / 5%); }';
        $code .= '.bxc-input input, .bxc-input select, .bxc-input textarea, .bxc-input input[type="checkbox"] { background-color: #fafafa; }';
    }
    if ($color_2) {
        $code .= '.bxc-btn:hover, .bxc-btn:active { background-color: ' . $color_2 . '; }';
    }
    if ($code) echo '<style>' . $code . '</style>';
}

function bxc_load_custom_js_css() {
    $js = BXC_CLOUD ? false : bxc_settings_get('js-admin');
    $css = bxc_settings_get('css-admin');
    if ($js) echo '<script src="' . $js . '"></script>';
    if ($css) echo '<link rel="stylesheet" href="' . $css . '" media="all" />';
}

function bxc_payment_redirect_url($url, $client_reference_id) {
    $mark = strpos($url, '?') ? '&' : '?';
    $pos = strpos($url, 'cc=');
    if ($pos) $url = substr($url, 0, $pos - 1);
    return urlencode($url . $mark . 'cc=' . bxc_encryption(json_encode(['id' => $client_reference_id])));
}

function bxc_version_updates() {
    if (bxc_settings_db('version') != BXC_VERSION) {
        try {

            // 09-22
            bxc_db_query('ALTER TABLE bxc_checkouts ADD COLUMN hide_title TINYINT');
            bxc_db_query('ALTER TABLE bxc_transactions ADD COLUMN billing TINYTEXT COLLATE utf8mb4_unicode_ci');

            // 10-22
            bxc_db_query('ALTER TABLE bxc_transactions ADD COLUMN vat FLOAT');
            bxc_db_query('ALTER TABLE bxc_transactions ADD COLUMN vat_details TINYTEXT');
        } catch (Exception $e) {}
        bxc_settings_db('version', BXC_VERSION);
    }
}

function bxc_error($message, $function_name, $force = false) {
    $message = 'Boxcoin error [' . $function_name . ']: ' . $message;
    if ($force || bxc_isset($_GET, 'debug')) trigger_error($message);
    return $message;
}

function bxc_is_address_generation($cryptocurrency_code = false) {
    return bxc_settings_get('gemini-address-generation') || bxc_settings_get('coinbase-address-generation') || (strtolower($cryptocurrency_code) === 'btc' && bxc_settings_get('btc-node-address-generation')) || bxc_is_eth_address_generation($cryptocurrency_code) || (bxc_settings_get('custom-explorer-active') && bxc_settings_get('custom-explorer-address'));
}

function bxc_ve_box() {
    if (!isset($_COOKIE['TR_' . 'VUU' . 'KMILO']) || !password_verify('YTYFUJG', $_COOKIE['TR_' . 'VUU' . 'KMILO'])) {
        echo file_get_contents(__DIR__ . '/resources/epc.html');
        return false;
    }
    return true;
}

function bxc_ve($code, $domain) {
    if ($code == 'auto') $code = bxc_settings_get('en' . 'vato-purc' . 'hase-code');
    if (empty($code)) return [false, ''];
    $response = bxc_curl('htt' . 'ps://boxcoin' . '.dev/sync/ve' . 'r' . 'ification.p' . 'hp?ve' . 'rifi' . 'cation&code=' . $code . '&domain=' . $domain);
    if ($response == 've' . 'rific' . 'ation-success') {
        return [true, password_hash('YTYFUJG', PASSWORD_DEFAULT)];
    }
    return [false, $response];
}

function bxc_email_send($to, $subject, $body) {
    $settings = BXC_CLOUD ? ['smtp-host' => CLOUD_SMTP_HOST, 'smtp-user' => CLOUD_SMTP_USERNAME, 'smtp-password' => CLOUD_SMTP_PASSWORD, 'smtp-from' => CLOUD_SMTP_SENDER, 'email-sender-name' => CLOUD_SMTP_SENDER_NAME, 'smtp-port' => CLOUD_SMTP_PORT] : ['smtp-host' => bxc_settings_get('smtp-host'), 'smtp-user' => bxc_settings_get('smtp-user'), 'smtp-password' => bxc_settings_get('smtp-password'), 'smtp-from' => bxc_settings_get('smtp-from'), 'email-sender-name' => bxc_settings_get('smtp-name'), 'smtp-port' => bxc_settings_get('smtp-port')];
    if (empty($to)) return false;
    if (!is_string($body)) {
        $code = file_get_contents(__DIR__ . '/resources/email.html');
        $code = str_replace('{message}', $body['message'], $code);
        $code = str_replace('{title}', isset($body['title']) ? '<h1 style="text-align:left;font-size: 25px;line-height: 40px;font-weight: 500;color: #283c49;text-decoration: none;">' . $body['title'] . '</h1>' : '', $code);
        $code = str_replace('{tagline}', isset($body['title']) ? $body['title'] : substr($body['message'], 0, 100), $code);
        $code = str_replace('{image}', BXC_CLOUD ? CLOUD_LOGO_PNG : (bxc_settings_get('logo-admin') ? bxc_settings_get('logo-url', BXC_URL . 'media/logo.svg') : BXC_URL . 'media/logo.svg'), $code);
        $code = str_replace('{link}', BXC_CLOUD ? CLOUD_URL : BXC_URL . '/admin.php', $code);
        $code = str_replace('{footer}', BXC_CLOUD ? CLOUD_EMAIL : bxc_settings_get('notifications-footer', ''), $code);
        $body = $code;
    } else {
        $body = nl2br(trim($body));
    }
    if ($settings['smtp-host']) {
        require_once __DIR__ . '/vendor/phpmailer/PHPMailerAutoload.php';
        $port = $settings['smtp-port'];
        $mail = new PHPMailer;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isSMTP();
        $mail->Host = $settings['smtp-host'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['smtp-user'];
        $mail->Password = $settings['smtp-password'];
        $mail->SMTPSecure = $port == 25 ? '' : ($port == 465 ? 'ssl' : 'tls');
        $mail->Port = $port;
        $mail->setFrom($settings['smtp-from'], bxc_isset($settings, 'email-sender-name', ''));
        $mail->isHTML(true);
        $mail->Subject = trim($subject);
        $mail->Body    = $body;
        $mail->AltBody = $body;
        if (strpos($to, ',') > 0) {
            $emails = explode(',', $to);
            for ($i = 0; $i < count($emails); $i++) {
                $mail->addAddress($emails[$i]);
            }
        } else {
            $mail->addAddress($to);
        }
        return $mail->send() ? true : bxc_error($mail->ErrorInfo, 'bxc_email_send');
    } else {
        return mail($to, $subject, $body);
    }
}

function bxc_email_notification($subject, $message) {
    $to = bxc_settings_get('notifications-email');
    if (!$to) return bxc_error('Missing recipient email.', 'bxc_email_notification');
    return bxc_email_send($to, $subject, ['message' => $message]);
}

function bxc_is_eth_address_generation($cryptocurrency_code) {
    $cryptocurrency_code = strtolower($cryptocurrency_code);
    return ($cryptocurrency_code === 'eth' || in_array($cryptocurrency_code, bxc_get_cryptocurrency_codes('eth'))) && bxc_settings_get('eth-node-address-generation');
}

function bxc_tron_get_contract_address($cryptocurrency_code) {
    return bxc_isset(['usdt' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'], strtolower($cryptocurrency_code));
}

function bxc_binance_get_contract_address($cryptocurrency_code) {
    return bxc_isset(['busd' => '0xe9e7CEA3DedcA5984780Bafc599bD69ADd087D56', 'usdt' => '0x69bab60997a2f5cbee668e5087dd9f91437206bb'], strtolower($cryptocurrency_code));
}

/*
 * -----------------------------------------------------------
 * FIAT
 * -----------------------------------------------------------
 *
 */

function bxc_stripe_payment($price_amount, $checkout_url, $client_reference_id, $currency_code = false) {
    $response = bxc_stripe_create_session(bxc_stripe_get_price($price_amount, $currency_code)['id'], $checkout_url, $client_reference_id);
    return isset($response['url']) ? $response['url'] : $response;
}

function bxc_stripe_get_price($price_amount, $currency_code = false) {
    $product_id = bxc_settings_get('stripe-product-id');
    $prices =  bxc_stripe_curl('prices?product=' . $product_id . '&limit=100&type=one_time', 'GET');
    if (!isset($prices['data'])) return $prices;
    $prices = $prices['data'];
    for ($i = 0; $i < count($prices); $i++) {
    	if ($price_amount == $prices[$i]['unit_amount']) return $prices[$i];
    }
    return bxc_stripe_curl('prices?unit_amount=' . $price_amount . '&currency=' . ($currency_code ? $currency_code : bxc_settings_get('currency')) . '&product=' . $product_id);
}

function bxc_stripe_create_session($price_id, $checkout_url, $client_reference_id = false) {
    return bxc_stripe_curl('checkout/sessions?metadata[source]=boxcoin' . (BXC_CLOUD ? '&metadata[cloud]=' . bxc_cloud_get_data() : '') . '&cancel_url=' . urlencode($checkout_url) . '&success_url=' . bxc_payment_redirect_url($checkout_url, $client_reference_id) . '&line_items[0][price]=' . $price_id . '&mode=payment&line_items[0][quantity]=1&client_reference_id=' . $client_reference_id);
}

function bxc_stripe_curl($url_part, $type = 'POST') {
    $response = bxc_curl('https://api.stripe.com/v1/' . $url_part, '',  [ 'Authorization: Basic ' . base64_encode(bxc_settings_get('stripe-key')) ], $type);
    return json_decode($response, true);
}

function bxc_verifone_create_checkout($price_amount, $checkout_url, $client_reference_id, $title, $currency_code = false) {
    $url = 'https://secure.2checkout.com/checkout/buy?currency=' . ($currency_code ? $currency_code : bxc_settings_get('currency')) . '&dynamic=1&merchant=' . bxc_settings_get('verifone-merchant-id') . '&order-ext-ref=' . bxc_encryption($client_reference_id . '|||' . bxc_settings_get('verifone-key')) . '&price=' . $price_amount . '&prod=' . $title . '&qty=1&return-type=redirect&return-url=' . bxc_payment_redirect_url($checkout_url, $client_reference_id) . '&type=digital';
    return $url . '&signature=' . bxc_verifone_get_signature($url);
}

function bxc_verifone_get_signature($url) {
    parse_str(substr($url, strpos($url, '?') + 1), $values);
    $serialized = '';
    foreach ($values as $key => $value) {
        if (!in_array($key, ['merchant', 'dynamic', 'email'])) {
            $serialized .= mb_strlen($value) . $value;
        }
    }
    return hash_hmac('sha256', $serialized, bxc_settings_get('verifone-word'));
}

function bxc_verifone_curl($url_part, $type = 'POST') {
    $merchant_id = bxc_settings_get('verifone-merchant-id');
    $date = gmdate('Y-m-d H:i:s');
    $string = strlen($merchant_id) . $merchant_id . strlen($date) . $date;
    $hash = hash_hmac('md5', $string, bxc_settings_get('verifone-key'));
    $response = bxc_curl('https://api.2checkout.com/rest/6.0/' . $url_part, '',  ['Content-Type: application/json', 'Accept: application/json', 'X-Avangate-Authentication: code="' . $merchant_id . '" date="' . $date . '" hash="' . $hash . '"'], $type);
    return is_string($response) ? json_decode($response, true) : $response;
}

function bxc_vat($amount, $country_code = false, $currency_code = false) {
    $rates = json_decode(file_get_contents(__DIR__ . '/resources/vat.json'), true)['rates'];
    $ip = $country_code ? ['countryCode' => $country_code] : (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && substr_count($_SERVER['HTTP_CF_CONNECTING_IP'], '.') == 3 ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR']);
    if (!$country_code && strlen($ip) > 6) {
        $ip = strlen($ip) > 6 ? json_decode(bxc_download('http://ip-api.com/json/' . $ip . '?fields=country,countryCode'), true) : false;
    }
    if (isset($ip['countryCode'])) {
        for ($i = 0; $i < count($rates); $i++) {
            if ($rates[$i]['country_code'] == $ip['countryCode']) {
                $amount = floatval($amount);
                $rate_percentage = $rates[$i]['standard_rate'];
                $rate = $amount * ($rate_percentage / 100);
                return [round($amount + $rate, 2), round($rate, 2), $rates[$i]['country_code'], $rates[$i]['country_name'], str_replace(['{1}', '{2}'], [strtoupper($currency_code), round($rate, 2)], bxc_('Including {1} {2} for VAT in')) . ' ' . bxc_($rates[$i]['country_name']), $rate_percentage];
            }
        }
    }
    return [$amount, 0, '', bxc_isset($ip, 'country', ''), '', 0];
}

function bxc_vat_validation($vat_number) {
    $key = bxc_settings_get('vatsense-key');
    if (!$key) return bxc_error('Missing Vatsense key. Set it in the Boxcoin settings area.', 'bxc_vat_validation', true);
    return json_decode(bxc_curl('https://api.vatsense.com/1.0/validate?vat_number=' . $vat_number, '', ['CURLOPT_USERPWD' => 'user:' . $key]), true);
}

/*
 * -----------------------------------------------------------
 * EXCHANGES
 * -----------------------------------------------------------
 *
 */

function bxc_gemini_curl($url_part, $parameters = [], $type = 'POST') {
    $signature = base64_encode(utf8_encode(json_encode(array_merge(['request' => '/v1/' . $url_part, 'nonce' => time()], $parameters))));
    $header = [
        'Content-Type: text/plain',
        'Content-Length: 0',
        'X-GEMINI-APIKEY: ' . bxc_settings_get('gemini-key'),
        'X-GEMINI-PAYLOAD: ' . $signature,
        'X-GEMINI-SIGNATURE: ' . hash_hmac('sha384', $signature, utf8_encode(bxc_settings_get('gemini-key-secret'))),
        'Cache-Control: no-cache'
    ];
    return json_decode(bxc_curl('https://api' . (bxc_settings_get('gemini-sandbox') ? '.sandbox' : '') . '.gemini.com/v1/' . $url_part, '', $header, $type), true);
}

function bxc_gemini_convert_to_fiat($cryptocurrency_code, $amount) {
    $symbol = strtolower($cryptocurrency_code . bxc_settings_get('gemini-conversion-currency'));
    $symbol_uppercase = strtoupper($symbol);
    $price = json_decode(bxc_curl('https://api.gemini.com/v1/pricefeed'), true);
    if (!$price) return bxc_gemini_convert_to_fiat($cryptocurrency_code, $amount);
    for ($i = 0; $i < count($price); $i++) {
        if ($price[$i]['pair'] == $symbol_uppercase) {
            $response = ['remaining_amount' => 1];
            $continue = 5;
            while ($continue && bxc_isset($response, 'remaining_amount') != '0' && !bxc_isset($response, 'is_live')) {
                $response = bxc_gemini_curl('order/new', ['symbol' => $symbol, 'amount' => $amount, 'price' => round(floatval($price[$i]['price']) * 0.999, 2), 'side' => 'sell', 'type' => 'exchange limit']);
            	$continue--;
            }
            return $response;
        }
    }
    return false;
}

function bxc_coinbase_curl($url_part, $parameters = [], $type = 'POST') {
    $body = $parameters ? json_encode($parameters) : '';
    $time = time();
    $signature = $time . $type . $url_part . $body;
    $signature = hash_hmac('SHA256' , $signature, trim(bxc_settings_get('coinbase-key-secret')));
    $header = ['Content-Type: application/json', 'CB-ACCESS-KEY: ' . trim(bxc_settings_get('coinbase-key')), 'CB-ACCESS-SIGN: ' . $signature, 'CB-ACCESS-TIMESTAMP: ' . $time, 'CB-VERSION: 2017-05-24'];
    return json_decode(bxc_curl('https://api.coinbase.com' . $url_part, $body, $header, $type), true);
}

function bxc_coinbase_get_accounts($currency_code = false) {
    $url = '/v2/accounts';
    $accounts = bxc_settings_db('coinbase_accounts');
    if ($accounts) {
        $accounts = json_decode($accounts, true);
    } else {
        $accounts = [];
        while ($url) {
            $accounts_2 = bxc_coinbase_curl($url, [], 'GET');
            $accounts = array_merge($accounts, bxc_isset($accounts_2, 'data', []));
            $url = isset($accounts_2['pagination']) ? bxc_isset($accounts_2['pagination'], 'next_uri') : false;
        }
        bxc_settings_db('coinbase_accounts', $accounts);
    }
    if ($currency_code) {
        $currency_code = strtoupper($currency_code);
        for ($i = 0; $i < count($accounts); $i++) {
            if (bxc_isset($accounts[$i], 'currency', [])['code'] == $currency_code) {
                return $accounts[$i];
            }
        }
        return false;
    }
    return $accounts;
}

/*
 * -----------------------------------------------------------
 * CLOUD
 * -----------------------------------------------------------
 *
 */

function bxc_cloud_load() {
    if (!defined('BXC_DB_NAME') && defined('CLOUD_URL')) {
        $data = bxc_cloud_get_data();
        if ($data) {
            require_once(__DIR__ . '/cloud/functions.php');
            $data = json_decode(bxc_cloud_encryption($data, false), true);
            $path = __DIR__ . '/cloud/config/' . $data['token'] . '.php';
            if (file_exists($path)) {
                require_once($path);
                return true;
            }
            return 'config-file-missing';
        } else return 'cloud-data-not-found';
    }
    return true;
}

function bxc_cloud_spend_credit($transaction_amount, $currency) {
    $fee_usd = floatval($transaction_amount) * 0.008;
    if (strtoupper($currency) != 'USD') {
        $rate = bxc_usd_rates($currency);
        $fee_usd = $fee_usd / $rate;
    }
    $credit_balance = floatval(bxc_settings_db('credit_balance')) - $fee_usd;
    bxc_cloud_credit_email($credit_balance);
    return bxc_db_query('UPDATE bxc_settings SET value = ' . bxc_db_escape($credit_balance, true) . ' WHERE name = "credit_balance"');
}

function bxc_cloud_credit_email($credit_balance) {
    if ($credit_balance < 5) {
        $account = bxc_account();
        if ($account) {
            if ($credit_balance > 0 && intval(bxc_settings_db('credit_balance_email')) > (time() - 604800)) return;
            $emails = $credit_balance < 0 ? ['Action required: your account has been suspended.', 'Your account has been suspended because your balance is negative. Your checkouts are blocked and your customers cannot make payments.'] : ['Your balance is low: Add credit to avoid service disruption', 'Your balance is less than USD 5. When the balance drops below zero, the checkouts will stop working and your customers will not be able to make payments.'];
            $response = bxc_email_send($account[0], $emails[0], ['title' => $emails[0], 'message' => $emails[1] . ' Click the button below to add credit to your account. <br /> <br /> <a href="' . BXC_URL . '#account" style="display:block;text-decoration:none;border:none;background:#2acad6;color:#fff;font-weight:500;margin:30px auto;max-width:200px;padding:15px 30px;border-radius:6px;font-size:17px;white-space:nowrap;text-align:center;cursor:pointer">Add credit to your account</a>']);
            if ($response === true && $credit_balance > 0) bxc_settings_db('credit_balance_email', time());
        }
    }
}

function bxc_cloud_get_data() {
    return isset($_COOKIE['BXC_CLOUD']) ? $_COOKIE['BXC_CLOUD'] : (isset($_GET['cloud']) ? $_GET['cloud'] : bxc_isset($_POST, 'cloud'));
}

?>