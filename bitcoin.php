<?php

/*
 * ==========================================================
 * BITCOIN.PHP
 * ==========================================================
 *
 * © 2022-2023 boxcoin.dev. All rights reserved.
 *
 */

use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\Factory\P2pkhScriptDataFactory;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Address\AddressCreator;

function bxc_btc_load() {
    require(__DIR__ . '/vendor/bitcoin/composer/autoload_real.php');
    ComposerAutoloaderInit9bdfd86aa6c5dea69b9fac2a253fcf91::getLoader();
}

function bxc_btc_generate_address() {
    bxc_btc_load();
    $addrReader = new AddressCreator();
    $privFactory = new PrivateKeyFactory();
    $priv = $privFactory->generateCompressed(new Random());
    $publicKey = $priv->getPublicKey();
    $helper = new P2pkhScriptDataFactory();
    $scriptData = $helper->convertKey($publicKey);
    $p2pkh = $scriptData->getAddress($addrReader);
    return ['address' => $p2pkh->getAddress(), 'private_key'=> $priv->toWif()];
}

function bxc_btc_generate_address_xpub($xpub = false, $range = [0, 99]) {
    if (!$xpub) $xpub = trim(bxc_settings_get('btc-node-xpub'));
    if (!$xpub) return bxc_error('Xpub not found.' , 'bxc_btc_generate_address');
    $response = bxc_btc_curl('getdescriptorinfo', ['wpkh(' . $xpub . '/0/*)']);
    if ($response && empty($response['errors'])) {
        return ['address' => bxc_btc_curl('deriveaddresses', [$response['descriptor'], $range])];
    }
    return bxc_error(json_encode($response), 'bxc_btc_transfer');
}

function bxc_btc_transfer($amount, $to = false, $from = false, $wallet_key = false) {
    $response = false;
    $params = [[], []];
    $utxo_amount = 0;
    $fee = bxc_isset(bxc_btc_curl('estimatesmartfee', [4]), 'feerate', 0.00001);
    $amount_plus_fee = $amount + $fee;
    if (!$from) $from = bxc_settings_get('address-btc');
    if (!$to) $to = bxc_settings_get('btc-node-transfer-address');
    if (!$wallet_key) $wallet_key = bxc_encryption(bxc_settings_get('btc-wallet-key'), false);
    if (bxc_crypto_whitelist_invalid($to, false, 'btc')) return 'whitelist-invalid';
    $utxo = bxc_btc_get_utxo($from);
    for ($i = 0; $i < count($utxo); $i++) {
        if ($utxo_amount < $amount_plus_fee) {
            array_push($params[0], ['txid' => $utxo[$i]['txid'], 'vout' => $utxo[$i]['n']]);
            $utxo_amount += $utxo[$i]['value'];
        } else break;
    }
    if ($utxo_amount < $amount_plus_fee)  {
        $amount = $utxo_amount - $fee;
        $amount_plus_fee = $amount + $fee;
    }
    $param_1 = [];
    $param_1[$to] = bxc_decimal_number($amount);
    array_push($params[1], $param_1);
    $param_1 = [];
    $param_1[$from] = bxc_decimal_number($utxo_amount - $amount_plus_fee);
    if ($param_1[$from]) array_push($params[1], $param_1);
    $response = bxc_btc_curl('createrawtransaction', $params);
    if (empty($response['errors'])) {
        $response = bxc_btc_curl('signrawtransactionwithkey', [$response, [$wallet_key]]);//[$wallet_key, $wallet_key2]
        if (empty($response['errors'])) {
            return bxc_btc_curl('sendrawtransaction', [$response['hex']]);
        }
    }
    return bxc_error(json_encode($response), 'bxc_btc_transfer');
}

function bxc_btc_get_utxo($address = false, $transaction_hashes = false) {
    if (!$address) $address = bxc_settings_get('address-btc');
    $address_lowercase = strtolower($address);
    $transactions = json_decode(bxc_settings_db('btc-transactions-' . $address_lowercase, false, '[]'), true);
    $save = false;
    $unspent_outputs = [];
    if (!$transaction_hashes) {
        $transaction_hashes = [];
        $transactions_blockchain = bxc_blockchain('btc', 'transactions', false, $address);
        for ($i = 0; $i < count($transactions_blockchain); $i++) {
            array_push($transaction_hashes, $transactions_blockchain[$i]['hash']);
        }
    }
    for ($i = 0; $i < count($transaction_hashes); $i++) {
        $id = $transaction_hashes[$i];
        if (!isset($transactions[$id])) {
            $response = bxc_btc_curl('getrawtransaction', [$id, true]);
            if (isset($response['txid'])) {
                $transactions[$id] = $response;
                $save = true;
            } else {
                return bxc_error(json_encode($response), 'bxc_btc_get_unspent');
            }
        }
    }
    foreach ($transactions as $id => $transaction) {
        $outputs = bxc_isset($transaction, 'vout', []);
        $transaction_id = $transaction['txid'];
        for ($i = 0; $i < count($outputs); $i++) {
            if ($outputs[$i]['scriptPubKey']['address'] === $address) {
                $output_number = $outputs[$i]['n'];
                $spent = false;
                foreach ($transactions as $transaction_2) {
                    $inputs = bxc_isset($transaction_2, 'vin', []);
                    for ($y = 0; $y < count($inputs); $y++) {
                        if ($inputs[$y]['txid'] === $transaction_id && $inputs[$y]['vout'] === $output_number) {
                            $spent = true;
                            break;
                        }
                    }
                    if ($spent) break;
                }
                if (!$spent) {
                    $outputs[$i]['txid'] = $transaction_id;
                    $outputs[$i]['value'] = bxc_decimal_number($outputs[$i]['value']);
                    array_push($unspent_outputs, $outputs[$i]);
                }
            }
        }
    }
    if ($save) bxc_settings_db('btc-transactions-' . $address_lowercase, $transactions);
    return $unspent_outputs;
}

function bxc_btc_curl($method, $params = []) {
    $node_url = bxc_settings_get('btc-node-url');
    $node_headers = bxc_settings_get('btc-node-headers', []);
    if (!$node_url) bxc_error('Bitcoin node not found', 'bxc_btc_curl', true);
    if ($node_headers) $node_headers = explode(',', $node_headers);
    $response = json_decode(bxc_curl($node_url, json_encode(['method' => $method, 'params' => $params, 'jsonrpc' => '2.0']), array_merge(['accept: application/json', 'content-type: application/json'], $node_headers), 'POST'), true);
    return bxc_isset($response, 'result', $response);
}

?>

