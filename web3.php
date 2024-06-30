<?php

/*
 * ==========================================================
 * WEB3.PHP
 * ==========================================================
 *
 * © 2022-2023 boxcoin.dev. All rights reserved.
 *
 */

use Web3\Web3;
use Web3\Utils;
use Web3\Contract;
use Web3p\EthereumTx\Transaction;
use kornrunner\Ethereum\Address;

function bxc_eth_load() {
    require(__DIR__ . '/vendor/web3/composer/autoload_real.php');
    require(__DIR__ . '/vendor/web3/composer_2/autoload_real.php');
    ComposerAutoloaderInit05e2d86f8150ca0d0233ceafbbc1f468::getLoader();
    ComposerAutoloaderInit973ba30e2e2a845742c00ce2013c1734::getLoader();
}

function bxc_eth_swap($amount, $cryptocurrency_code_from, $cryptocurrency_code_to = false, $address = false) {
    bxc_eth_load();
    if (!$address) $address = bxc_settings_get('address-eth');
    if (!$cryptocurrency_code_to) $cryptocurrency_code_to = bxc_settings_get('eth-node-conversion-currency');
    if (bxc_crypto_whitelist_invalid($address)) return 'whitelist-invalid';
    $address_router = '0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D';
    $node = new Web3(bxc_settings_get('eth-node-url'));
    $contract = (new Contract($node->provider, json_decode(file_get_contents(__DIR__ . '/vendor/web3/UNIV2Router.json'))->abi))->at($address_router);
    $network = bxc_settings_get('eth-network', 'mainnet');
    $is_mainnet = $network == 'mainnet' ;
    $contract_info = bxc_eth_get_contract($cryptocurrency_code_to, $network);
    $is_eth = $cryptocurrency_code_from === 'eth';
    $path = [$is_mainnet ? '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2' : '0xb4fbf271143f4fbf7b91a5ded31805e42b2208d6', $contract_info[0]];
    if (!$is_eth) array_unshift($path, bxc_eth_get_contract($cryptocurrency_code_from, $network)[0]);
    $chain_id = bxc_web3_chain_id($is_mainnet ? 'eth' : 'goerli');
    $amount = Utils::toWei(strval($amount), $is_eth ? 'ether' : bxc_eth_decimals_to_name($contract_info[1]));
    $amount_out = '';
    $token = (new Contract($node->provider, json_decode(file_get_contents(__DIR__ . '/vendor/web3/ERC20.json'))->abi))->at($path[0]);
    $gas_price = '0x' . Utils::toWei($is_mainnet ? '25' : '50', 'gwei')->toHex();
    $hash = '';
    if (!$is_eth) {
        $contract->call('getAmountsOut', $amount, $path, ['from' => $address], function ($error, $result) use (&$amount_out) {
            $amount_out = $error ? $error->getMessage() : $result['amounts'][2];
        });
    }
    $data = $token->getData('approve', $address_router, $amount);
    $transaction = new Transaction([
        'nonce' => '0x' . bxc_eth_nonce($node->eth, $address)->toHex(),
        'gas' => '0x30d40',
        'gasPrice' => $gas_price,
        'data' => '0x' . $data,
        'chainId' => $chain_id,
        'to' => $is_eth ? $address : $path[0],
        'value' => $is_eth ? '0x' . $amount->toHex() : ''
    ]);
    $transaction->sign(bxc_encryption(bxc_settings_get('eth-wallet-key'), false));
    $node->eth->sendRawTransaction('0x' . $transaction->serialize(), function ($error, $transaction) use (&$hash) {
        $hash = $error ? $error->getMessage() : $transaction;
    });
    if (strpos($hash, '0x') !== 0) return bxc_error($hash, 'bxc_eth_swap');
    bxc_eth_wait_confirmation($hash);
    $data = $is_eth ? $contract->getData('swapExactETHForTokens', '0', $path, $address, time() + 180) : $contract->getData('swapExactTokensForTokens', $amount, $amount_out, $path, $address, time() + 180);
    $transaction = new Transaction([
        'nonce' => '0x' . bxc_eth_nonce($node->eth, $address)->toHex(),
        'gas' => '0x30d40',
        'gasPrice' => $gas_price,
        'data' => '0x' . $data,
        'chainId' => $chain_id,
        'to' => $address_router,
        'value' => $is_eth ? '0x' . $amount->toHex() : ''
    ]);
    $transaction->sign(bxc_encryption(bxc_settings_get('eth-wallet-key'), false));
    $node->eth->sendRawTransaction('0x' . $transaction->serialize(), function ($error, $transaction) use (&$hash) {
        $hash = $error ? $error->getMessage() : $transaction;
    });
    return $hash;
}

function bxc_eth_transfer($amount, $cryptocurrency_code = 'eth', $to = false, $from = false, $wallet_key = false) {
    bxc_eth_load();
    $node = new Web3(bxc_settings_get('eth-node-url'));
    $data = false;
    $response = false;
    $is_eth = $cryptocurrency_code === 'eth';
    $network = bxc_settings_get('eth-network', 'mainnet');
    $gas = $is_eth ? '0x5208' : '0x186a0';
    $gas_price = bxc_eth_curl('eth_gasPrice');
    if (!$from) $from = bxc_settings_get('address-eth');
    if (!$to) $to = bxc_settings_get('eth-node-transfer-address');
    if (bxc_crypto_whitelist_invalid($to, false, $cryptocurrency_code)) return 'whitelist-invalid';
    $balance = bxc_eth_get_balance('eth', $from, 'wei');
    $contract_info = $is_eth ? false : bxc_eth_get_contract($cryptocurrency_code, $network);
    if (!$balance) {
        $hash = bxc_eth_transfer((hexdec($gas) * hexdec($gas_price) * 1.1) / (10 ** 18), 'eth', $from, bxc_settings_get('address-eth'));
        if (strpos($hash, '0x') !== 0) return bxc_error($hash, 'bxc_eth_transfer');
        bxc_eth_wait_confirmation($hash);
        $balance = bxc_eth_get_balance('eth', $from, 'wei');
    }
    if ($balance - bcmul(strval($amount), 10 ** ($is_eth ? 18 : $contract_info[1])) - (hexdec($gas) * hexdec($gas_price)) < 0) {
        $amount = ($balance - (hexdec($gas) * hexdec($gas_price) * 1.1)) / (10 ** ($is_eth ? 18 : $contract_info[1]));
    }
    if (!$is_eth) {
        $contract = (new Contract($node->provider, json_decode(file_get_contents(__DIR__ . '/vendor/web3/ERC20.json'))->abi))->at($contract_info[0]);
        $data = $contract->getData('transfer', $to, '0x' . Utils::toHex(bcmul(strval($amount), 10 ** $contract_info[1])));
    }
    $nonce = bxc_eth_nonce($node->eth, $from)->toHex();
    $transaction = new Transaction([
        'nonce' => '0x' . ($nonce ? $nonce : '0'),
        'to' => $is_eth ? $to : $contract_info[0],
        'gas' => $gas,
        'gasPrice' => $gas_price,
        'value' => $is_eth ? '0x' . Utils::toWei(strval($amount), 'ether')->toHex() : '',
        'data' => $data ? '0x' . $data : '',
        'chainId' => bxc_web3_chain_id($network == 'mainnet' ? 'eth' : 'goerli')
    ]);
    $transaction->sign($wallet_key ? $wallet_key : bxc_encryption(bxc_settings_get('eth-wallet-key'), false));
    $node->eth->sendRawTransaction('0x' . $transaction->serialize(), function ($error, $transaction) use (&$response) {
        $response = $error ? bxc_error($error->getMessage(), 'bxc_eth_transfer') : $transaction;
    });
    return $response;
}

function bxc_eth_nonce($eth, $address) {
    $nonce = 0;
    $eth->getTransactionCount($address, function ($error, $count) use (&$nonce) {
        $nonce = $error ? $error->getMessage() : $count;
    });
    return $nonce;
}

function bxc_eth_get_contract($cryptocurrency_code = false, $network = 'mainnet') {
    $tokens = json_decode(file_get_contents(__DIR__ . '/resources/tokens.json'), true)[$network];
    return $cryptocurrency_code ? bxc_isset($tokens, strtoupper($cryptocurrency_code)) : $tokens;
}

function bxc_eth_wait_confirmation($hash) {
    $continue = 120;
    while ($continue) {
        $response = bxc_eth_curl('eth_getTransactionByHash', [$hash]);
        if ($response && (!empty($response['transactionIndex']) || (isset($response['result']) && !empty($response['result']['transactionIndex'])))) {
            $continue = false;
        } else {
            sleep(1);
            $continue--;
        }
    }
    return true;
}

function bxc_web3_chain_id($chain = 'eth') {
    $ids = ['eth' => 1, 'goerli' => 5];
    return $ids[$chain];
}

function bxc_eth_decimals_to_name($decimals) {
    $values = ['3' => 'kwei', '6' => 'mwei', '9' => 'gwei', '12' => 'szabo', '15' => 'finney', '18' => 'ether', '21' => 'kether'];
    return $values[strval($decimals)];
}

function bxc_eth_curl($method, $params = []) {
    $node_url = bxc_settings_get('eth-node-url');
    $node_headers = bxc_settings_get('eth-node-headers', []);
    if ($node_headers) $node_headers = explode(',', $node_headers);
    if (!$node_url) bxc_error('Ethereum node not found', 'bxc_eth_curl', true);
    $response = json_decode(bxc_curl($node_url, json_encode(['method' => $method, 'params' => $params, 'id' => 1, 'jsonrpc' => '2.0']), array_merge(['accept: application/json', 'content-type: application/json'], $node_headers), 'POST'), true);
    return bxc_isset($response, 'result', $response);
}

function bxc_eth_get_transactions_after_timestamp($timestamp) {
    $limit = 10;
    $transactions = [];
    $block_hash = bxc_eth_curl('eth_getBlockByNumber', ['latest', false])['hash'];
    while ($limit) {
        $block = bxc_eth_curl('eth_getBlockByHash', [$block_hash, true]);
        $transactions = array_merge($transactions, $block['transactions']);
        if (hexdec($block['timestamp']) < $timestamp) {
            $limit = false;
        } else {
            $block_hash = $block['parentHash'];
        }
        $limit--;
    }
    return $transactions;
}

function bxc_eth_generate_address() {
    $address = new Address;
    return ['address' => '0x' . $address->get(), 'private_key' => $address->getPrivateKey(), 'public_key' => $address->getPublicKey()];
}

function bxc_eth_get_balance($cryptocurrency_code = 'eth', $address = false, $unit = 'dec') {
    $cryptocurrency_code = strtolower($cryptocurrency_code);
    if (!$address) $address = bxc_settings_get('address-' . $cryptocurrency_code);
    if ($address) {
        $balance = false;
        $is_ethereum = $cryptocurrency_code === 'eth';
        if ($is_ethereum) {
            $balance = bxc_eth_curl('eth_getBalance', [$address]);
            if ($unit !== 'hex') $balance = hexdec($balance);
            if ($unit === 'dec') $balance = $balance / (10 ** 18);
        } else {
            bxc_eth_load();
            $node = new Web3(bxc_settings_get('eth-node-url'));
            $contract_info = bxc_eth_get_contract($cryptocurrency_code,  bxc_settings_get('eth-network', 'mainnet'));
            $contract = new Contract($node->provider, json_decode(file_get_contents(__DIR__ . '/vendor/web3/ERC20.json'))->abi);
            $contract->at($contract_info[0])->call('balanceOf', $address, function ($error, $account) use (&$balance, &$unit, &$contract_info) {
                if ($error) {
                    $balance = bxc_error($error->getMessage(), 'bxc_eth_get_balance');
                } else {
                    if ($unit === 'hex') {
                        $balance = $account[0]->toHex();
                    } else {
                        $balance = $account[0]->toString();
                        if ($unit === 'dec') $balance = intval($balance) / (10 ** $contract_info[1]);
                        else $balance = intval(Utils::toWei($balance, bxc_eth_decimals_to_name($contract_info[1]))->ToString());
                    }

                }
            });
        }
        return $balance;
    }
    return false;
}

?>