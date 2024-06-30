/*
 * ==========================================================
 * ADMINISTRATION SCRIPT
 * ==========================================================
 *
 * Main Javascript admin file. © 2022-2023 boxcoin.dev. All rights reserved.
 *  
 */

'use strict';
(function () {

    var body;
    var main;
    var timeout;
    var areas;
    var active_area;
    var active_area_id;
    var transactions_table;
    var transactions_filters = [false, false, false, false];
    var transaction_statuses = { P: 'Pending', C: 'Completed', R: 'Refunded', X: 'Underpayment' };
    var pagination = 0;
    var pagination_count = true;
    var today = new Date();
    var BXC_CHECKOUTS = false;
    var responsive = window.innerWidth < 769;
    var datepicker = false;
    var WP;
    var _ = window._query;

    /*
    * ----------------------------------------------------------
    * BXCTransactions
    * ----------------------------------------------------------
    */

    var BXCTransactions = {

        get: function (onSuccess, search = false, status = false, cryptocurrency = false, date_range = false) {
            ajax('get-transactions', { pagination: pagination, search: search, status: status, cryptocurrency: cryptocurrency, date_range: date_range }, (response) => {
                pagination++;
                onSuccess(response);
            });
        },

        print: function (onSuccess, search = transactions_filters[0], status = transactions_filters[1], cryptocurrency = transactions_filters[2], date_range = transactions_filters[3]) {
            this.get((response) => {
                let code = '';
                pagination_count = response.length;
                for (var i = 0; i < pagination_count; i++) {
                    let transaction = response[i];
                    let from = transaction.from ? `<a href="${BXCAdmin.explorer(transaction.cryptocurrency, transaction.from)}" target="_blank" class="bxc-link">${transaction.from}</a>` : '';
                    let amount = transaction.currency.toUpperCase() + ' ' + transaction.amount_fiat;
                    let vat = '';
                    let notes = transaction.description.replace(/\[|\]|"/g, '');
                    if (transaction.vat_details) {
                        vat = JSON.parse(transaction.vat_details);
                        vat = ` data-vat="${transaction.currency} ${vat.amount} (${vat.percentage}%, ${vat.country})"`;
                    }
                    code += `<tr data-id="${transaction.id}" data-title="${transaction.title}" data-cryptocurrency="${transaction.cryptocurrency}" data-hash="${transaction.hash}" data-notes="${notes.replace(/,/g, '<br>')}" data-status="${transaction.status}"${transaction.billing ? ' data-invoice="true"' : ''}${vat}><td class="bxc-td-id">${transaction.id}</td><td class="bxc-td-time"><div class="bxc-title">${BOXCoin.beautifyTime(transaction.creation_time, true)}</div></td><td class="bxc-td-from">${from}</td><td class="bxc-td-to">${transaction.to ? `<a href="${BXCAdmin.explorer(transaction.cryptocurrency, transaction.to)}" target="_blank" class="bxc-link">${transaction.to}</a>` : ''}</td><td class="bxc-td-status"><span class="bxc-status-${transaction.status}">${bxc_(transaction_statuses[transaction.status])}</span></td><td class="bxc-td-amount"><div class="bxc-title">${transaction.amount ? transaction.amount + ' ' + BOXCoin.baseCode(transaction.cryptocurrency.toUpperCase()) : amount}</div><div class="bxc-text">${transaction.amount ? amount : BOXCoin.baseCode(transaction.cryptocurrency)}</div></td><td><i class="bxc-transaction-menu-btn bxc-icon-menu"></i></td></tr>`;
                }
                print(transactions_table.find('tbody'), code, true);
                if (onSuccess) onSuccess(response);
            }, search, status, cryptocurrency, date_range);
        },

        query: function (icon = false) {
            if (loading(transactions_table)) return;
            transactions_table.find('tbody').html('');
            pagination = 0;
            transactions_filters[0] = _(main).find('#bxc-search-transactions').val().toLowerCase().trim();
            transactions_filters[1] = _(main).find('#bxc-filter-status li.bxc-active').attr('data-value');
            transactions_filters[2] = _(main).find('#bxc-filter-cryptocurrency li.bxc-active').attr('data-value');
            transactions_filters[3] = datepicker ? datepicker.getDates('yyyy-mm-dd') : false;
            this.print(() => {
                if (icon) loading(icon, false);
                loading(transactions_table, false);
            });
        },

        download: function (onSuccess) {
            ajax('download-transactions', { search: transactions_filters[0], status: transactions_filters[1], cryptocurrency: transactions_filters[2], date_range: transactions_filters[3] }, (response) => {
                onSuccess(response);
            });
        }
    }

    /*
    * ----------------------------------------------------------
    * BXCCheckout
    * ----------------------------------------------------------
    */

    var BXCCheckout = {

        row: function (checkout) {
            return `<tr data-checkout-id="${checkout.id}"><td><div class="bxc-title"><span>${checkout.id}</span><span>${checkout.title}</span></div></td><td><div class="bxc-text">${checkout.currency ? checkout.currency : BXC_CURRENCY} ${checkout.price}</div></td></tr>`;
        },

        embed: function (id = false) {
            let index = WP ? 3 : 2;
            for (var i = 0; i < index; i++) {
                let elements = active_area.find('#bxc-checkout-' + (i == 0 ? 'payment-link' : (i == 1 ? 'embed-code' : 'shortcode'))).find('div, i');
                if (id) {
                    let content = i == 0 ? `${BXC_URL}pay.php?checkout_id=${id}${BXC_CLOUD.cloud ? '&cloud=' + BXC_CLOUD.cloud : ''}` : (i == 1 ? `<div data-boxcoin="${id}"></div>${BXC_CLOUD.cloud ? ' <script id="boxcoin" src="' + BXC_URL + 'js/client.js?cloud=' + BXC_CLOUD.cloud + '"></script>' : ''}` : `[boxcoin id="${id}"]`);
                    _(elements.e[0]).html(content.replace(/</g, '&lt;'));
                    _(elements.e[1]).attr('data-text', window.btoa(content));
                } else {
                    _(elements.e[0]).html('');
                    _(elements.e[1]).attr('data-text', '');
                }
            }
        },

        get: function (id, remove = false) {
            for (var i = 0; i < BXC_CHECKOUTS.length; i++) {
                if (id == BXC_CHECKOUTS[i].id) {
                    if (remove) {
                        BXC_CHECKOUTS.splice(i, 1);
                        return true;
                    }
                    return BXC_CHECKOUTS[i];
                }
            }
            return false;
        }
    }

    /*
    * ----------------------------------------------------------
    * BXCAdmin
    * ----------------------------------------------------------
    */

    var BXCAdmin = {
        active_element: false,

        card: function (message, type = false) {
            var card = main.find('.bxc-info-card');
            if (!type) {
                card.removeClass('bxc-info-card-error bxc-info-card-warning bxc-info-card-info');
                clearTimeout(timeout);
            } else if (type == 'error') {
                card.addClass('bxc-info-card-error');
            } else {
                card.addClass('bxc-info-card-info');
            }
            card.html(bxc_(message));
            timeout = setTimeout(() => { card.html('') }, 5000);
        },

        error: function (message, loading_area = false) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            main.find('.bxc-info').html(message);
            if (loading_area) loading(loading_area, false);
        },

        balance: function (area) {
            if (!loading(area)) {
                let table = main.find('#bxc-table-balances tbody');
                if (!table.e.length) return;
                if (!table.html()) area.addClass('bxc-loading-first');
                ajax('get-balances', {}, (response) => {
                    let code = '';
                    let balances = response.balances;
                    main.find('#bxc-balance-total').html(`${BXC_CURRENCY} ${response.total}`);
                    for (var key in balances) {
                        let img = key in response.token_images ? response.token_images[key] : `${BXC_URL}media/icon-${key}.svg`;
                        code += `<tr data-cryptocurrency="${key}"><td><div class="bxc-flex"><img src="${img}" /> ${balances[key].name}${BOXCoin.network(key)}</div></td><td><div class="bxc-balance bxc-title">${balances[key].amount} ${BOXCoin.baseCode(key).toUpperCase()}</div><div class="bxc-text">${BXC_CURRENCY} ${balances[key].fiat}</div></td></tr>`;
                    }
                    code ? table.html(code) : table.parent().html('<p class="bxc-text">Add your addresses from the Settings area.</p>');
                    area.removeClass('bxc-loading-first');
                    loading(area, false);
                });
            }
        },

        explorer: function (cryptocurrency, value, type = 'address') {
            let explorer = '';
            let replace = type == 'address' ? 'address' : (['ltc', 'usdt_tron'].includes(cryptocurrency)? 'transaction' : 'tx');
            switch (cryptocurrency) {
                case 'btc':
                    explorer = 'https://www.blockchain.com/btc/{R}/{V}';
                    break;
                case 'eth':
                    explorer = 'https://www.blockchain.com/eth/{R}/{V}';
                    break;
                case 'doge':
                    explorer = 'https://dogechain.info/{R}/{V}';
                    break;
                case 'link':
                case 'bat':
                case 'shib':
                case 'usdc':
                case 'usdt':
                    explorer = 'https://etherscan.io/{R}/{V}#tokentxns';
                    break;
                case 'usdt_tron':
                    explorer = 'https://tronscan.org/#/{R}/{V}';
                    break;
                    break;
                case 'algo':
                    explorer = 'https://algoexplorer.io/{R}/{V}';
                    break;
                case 'stripe':
                    explorer = 'https://dashboard.stripe.com/customers/{V}';
                    break;
                case 'verifone':
                    explorer = 'https://secure.2checkout.com/cpanel/order_info.php?refno={V}';
                    break;
                case 'usdt_bsc':
                case 'busd':
                case 'bnb':
                    explorer = 'https://bscscan.com/{R}/{V}';
                    break;
                case 'ltc':
                    explorer = 'https://blockchair.com/litecoin/{R}/{V}'
                    break;
                case 'bch':
                    explorer = 'https://www.blockchain.com/bch/{R}/{V}'
                    break;
                case 'xrp':
                    replace = type == 'address' ? 'accounts' : 'transactions';
                    explorer = 'https://livenet.xrpl.org/{R}/{V}'
                    break;
            }
            return explorer.replace('{R}', replace).replace('{V}', value);
        }
    }

    window.BXCTransactions = BXCTransactions;
    window.BXCCheckout = BXCCheckout;
    window.BXCAdmin = BXCAdmin;

    /*
    * ----------------------------------------------------------
    * Functions
    * ----------------------------------------------------------
    */

    function loading(element, action = -1) {
        return BOXCoin.loading(element, action);
    }

    function ajax(function_name, data = {}, onSuccess = false) {
        return BOXCoin.ajax(function_name, data, onSuccess);
    }

    function activate(element, activate = true) {
        return BOXCoin.activate(element, activate);
    }

    function card(message, type = false) {
        BXCAdmin.card(message, type);
    }

    function bxc_(text) {
        return BXC_TRANSLATIONS && text in BXC_TRANSLATIONS ? BXC_TRANSLATIONS[text] : text;
    }

    function showError(message, loading_area = false) {
        BXCAdmin.error(message, loading_area);
    }

    function scrollBottom() {
        window.scrollTo(0, document.body.scrollHeight - 800);
    }

    function inputValue(input, value = -1) {
        if (!input || !_(input).length()) return '';
        input = _(input).e[0];
        if (value === -1) return _(input).is('checkbox') ? input.checked : input.value.trim();
        if (_(input).is('checkbox')) {
            input.checked = value == 0 ? false : value;
        } else {
            input.value = value;
        }
        if (_(input).is('textarea')) resizeOnInput(input);
    }

    function inputGet(parent) {
        return _(parent).find('input, select, textarea');
    }

    function openURL() {
        let url = window.location.href;
        if (url.includes('#')) {
            let anchor = url.substr(url.indexOf('#'));
            if (anchor.includes('?')) anchor = anchor.substr(0, anchor.indexOf('?'));
            if (anchor.length > 1) {
                let item = main.find('.bxc-nav ' + anchor);
                if (item.length) {
                    nav(item);
                    return true;
                }
            }
        }
        return false;
    }

    function nav(nav_item) {
        if (!_(nav_item).e.length) return;
        let items = main.find('main > div');
        let index = nav_item.index();
        active_area = _(items.e[index]);
        active_area_id = nav_item.attr('id');
        main.removeClass('bxc-area-transactions bxc-area-checkouts bxc-area-balances bxc-area-settings').addClass('bxc-area-' + active_area_id);
        activate(items, false);
        activate(nav_item.siblings(), false);
        activate(nav_item);
        activate(active_area);
        if (!window.location.href.includes(active_area_id)) window.history.pushState('', '', '#' + active_area_id);
        switch (active_area_id) {
            case 'transactions':
                if (!loading(active_area)) {
                    loading(active_area);
                    pagination = 0;
                    transactions_table.find('tbody').html('');
                    BXCTransactions.print(() => { loading(items.e[index], false) });
                }
                break;
            case 'checkouts':
                if (active_area.hasClass('bxc-loading')) {
                    ajax('get-checkouts', {}, (response) => {
                        let code = '';
                        BXC_CHECKOUTS = response;
                        for (var i = 0; i < response.length; i++) {
                            code += BXCCheckout.row(response[i]);
                        }
                        print(main.find('#bxc-table-checkouts tbody'), code);
                        loading(items.e[index], false);
                    });
                }
                break;
            case 'balances':
                BXCAdmin.balance(active_area);
                break;
            case 'settings':
                if (active_area.hasClass('bxc-loading')) {
                    ajax('get-settings', {}, (response) => {
                        let code = '';
                        active_area.find('.bxc-btn-repater').e.forEach(e => {
                            let index = 2;
                            while (e.parentElement.firstChild.id + '-' + index in response) {
                                code += repeater(e, false, index);
                                index++;
                            }
                            if (code) {
                                code += e.outerHTML.replace('"2"', '"' + index + '"');
                                _(e).parent().append(code);
                                e.remove();
                            }
                        });
                        for (var key in response) {
                            let item = main.find(`#${key}`);
                            if (item) inputValue(inputGet(item), response[key]);
                        }
                        active_area.find('#btc-wallet-key input, #eth-wallet-key input').e.forEach(e => {
                            if (e.value) e.value = '********';
                        });
                        loading(items.e[index], false);
                    });
                }
                break;
        }
    }

    function slugToString(string) {
        string = string.replace(/_/g, ' ').replace(/-/g, ' ');
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    function print(area, code, append = false) {
        if (!code) return area.html() ? false : area.html(`<p class="bxc-not-found">${bxc_('There\'s nothing here yet.')}</p>`);
        area.find('.bxc-not-found').remove();
        area.html((append ? area.html() : '') + code);
    }

    function resizeOnInputListener() {
        resizeOnInput(this);
    }

    function resizeOnInput(e) {
        e.style.height = 'auto';
        e.style.height = (e.scrollHeight) + 'px';
    }

    function repeater(button, print = true, index = false) {
        let parent = _(button).parent();
        let items = parent.find('[data-type], hr');
        let code = '<div class="bxc-repater-line"><hr /><i class="bxc-icon-close"></i></div>';
        index = index ? index : parseInt(_(button).attr('data-index'));
        for (var i = 0; i < items.e.length; i++) {
            if (items.e[i].nodeName == 'HR') {
                break;
            } else {
                let id = _(items.e[i]).attr('id');
                code += items.e[i].outerHTML.replace(id, id + '-' + index);
            }
        }
        if (print) code += button.outerHTML.replace('"' + index + '"', '"' + (index + 1) + '"');
        else return code;
        button.remove();
        parent.append(code);
    }

    document.addEventListener('DOMContentLoaded', () => {

        /*
        * ----------------------------------------------------------
        * Init
        * ----------------------------------------------------------
        */

        body = _(document.body);
        main = body.find('.bxc-main');
        if (!main.e.length) return;
        areas = main.find('main > div');
        active_area = areas.e[0];
        transactions_table = main.find('#bxc-table-transactions');
        WP = typeof BXC_WP != 'undefined';
        if (BOXCoin.cookie('BXC_LOGIN') && !main.hasClass('bxc-installation')) {
            active_area_id = 'transactions';
            if (!openURL()) {
                BXCTransactions.print(() => { loading(active_area, false) });
            }
            if (localStorage.getItem('bxc-cron') != today.getDate()) {
                ajax('cron', { domain: BXC_URL });
                localStorage.setItem('bxc-cron', today.getDate());
            }
            let textareas = document.getElementsByTagName('textarea');
            for (let i = 0; i < textareas.length; i++) {
                textareas[i].setAttribute('style', 'height:' + (textareas[i].scrollHeight) + 'px;overflow-y:hidden;');
                textareas[i].addEventListener('input', resizeOnInputListener, false);
            }
            BXCAdmin.balance(main.find('main > [data-area="balance"]'));
        }

        /*
        * ----------------------------------------------------------
        * Transactions
        * ----------------------------------------------------------
        */

        transactions_table.on('click', 'tr', function (e) {
            if (['A', 'I', 'LI'].includes(e.target.nodeName)) return;
            let hash = _(this).attr('data-hash');
            if (hash) {
                let cryptocurrency = _(this).attr('data-cryptocurrency');
                let url = BXCAdmin.explorer(cryptocurrency, hash, 'tx');
                if (url) window.open(url);
            }
        });

        main.on('click', '#bxc-filters', function () {
            main.find('.bxc-nav-filters').toggleClass('bxc-active');
        });

        main.on('click', '#bxc-filter-date,#bxc-filter-date-2', function () {
            let settings = {
                maxNumberOfDates: 2,
                maxDate: new Date(),
                dateDelimiter: ' - ',
                clearBtn: true
            };
            if (!datepicker) {
                _.load(BXC_URL + 'vendor/datepicker/datepicker.min.css', false);
                _.load(BXC_URL + 'vendor/datepicker/datepicker.min.js', true, () => {
                    if (BXC_LANG) {
                        settings.language = BXC_LANG;
                        _.load(`${BXC_URL}vendor/datepicker/locales/${BXC_LANG}.js`, true, () => {
                            datepicker = new DateRangePicker(_(this).parent().e[0], settings);
                            datepicker.datepickers[0].show();
                        });
                    } else {
                        datepicker = new DateRangePicker(_(this).parent().e[0], settings);
                        datepicker.datepickers[0].show();
                    }
                });
            }
        });

        main.on('click', '#bxc-filter-status li,#bxc-filter-cryptocurrency li, .datepicker-cell, .datepicker .clear-btn', function () {
            setTimeout(() => { BXCTransactions.query() }, 100);
        });

        main.on('click', '#bxc-download-transitions', function () {
            if (loading(this)) return;
            BXCTransactions.download((response) => {
                window.open(response);
                loading(this, false);
            });
        });

        transactions_table.on('click', '.bxc-transaction-menu-btn:not(.bxc-loading)', function () {
            let active = _(this).hasClass('bxc-active');
            let row = _(this.closest('tr'));
            let code = `<ul class="bxc-transaction-menu bxc-ul"><li data-value="details">${bxc_('Details')}</li>`;
            let status = row.attr('data-status');
            activate(transactions_table.find('.bxc-transaction-menu-btn'), false);
            transactions_table.find('.bxc-transaction-menu').remove();
            if (!active) {
                activate(this);
                if (row.attr('data-invoice') && status == 'C') code += `<li data-value="invoice">${bxc_('Invoice')}</li>`;
                if (status == 'P') code += `<li data-value="payment-link">${bxc_('Payment link')}</li>`;
                for (var key in transaction_statuses) {
                    if (key != status) code += `<li data-value="${key}">${bxc_('Mark as ' + transaction_statuses[key].toLowerCase())}</li>`;
                }
                _(this).parent().append(code + '</ul>');
            }
        });

        transactions_table.on('click', '.bxc-transaction-menu li', function () {
            let row = _(this.closest('tr'));
            let menu = row.find('.bxc-transaction-menu-btn');
            if (loading(menu)) return;
            let value = _(this).attr('data-value');
            let id = row.attr('data-id');
            if (['C', 'P', 'R'].includes(value)) {
                ajax('update-transaction', { transaction_id: id, values: { status: value } }, () => {
                    row.attr('data-status', value).find('.bxc-td-status span').attr('class', 'bxc-status-' + value).html(bxc_(transaction_statuses[value]));
                    loading(menu, false);
                });
            }
            if (value == 'invoice' || value == 'payment-link') {
                ajax(value, { transaction_id: id }, (response) => {
                    window.open(response);
                    loading(menu, false);
                });
            }
            if (value == 'details') {
                let code = '<div class="bxc-text-list">';
                let details = [['ID', 'id'], ['Checkout', 'data-title'], ['Hash', 'data-hash'], ['Time', 'time'], ['Notes', 'data-notes'], ['Status', 'status'], ['From', 'from'], ['To', 'to'], ['Amount', 'amount'], ['VAT', 'data-vat']];
                for (var i = 0; i < details.length; i++) {
                    let value = details[i][1].includes('data') ? row.attr(details[i][1]) : row.find('.bxc-td-' + details[i][1]).html();
                    if (value) code += `<div><div>${bxc_(details[i][0])}</div><div>${value}</div></div>`;
                }
                BOXCoin.lightbox('Transaction details', code);
                loading(menu, false);
            }
            row.find('.bxc-ul').remove();
            activate(menu, false);
        });

        /*
        * ----------------------------------------------------------
        * Checkouts
        * ----------------------------------------------------------
        */

        main.on('click', '#bxc-create-checkout, #bxc-table-checkouts td', function () {
            main.addClass('bxc-area-create-checkout');
            if (_(this).is('td')) {
                let id = _(this).parent().attr('data-checkout-id');
                let checkout = BXCCheckout.get(id);
                active_area.attr('data-checkout-id', id);
                for (var key in checkout) {
                    let input = inputGet(active_area.find(`#bxc-checkout-${key}`));
                    let value = checkout[key];
                    inputValue(input, value);
                }
                BXCCheckout.embed(id);
            } else {
                inputGet(active_area).e.forEach(e => {
                    inputValue(e, '');
                });
                active_area.find('#bxc-checkout-type select').val('I');
                active_area.attr('data-checkout-id', '');
                BXCCheckout.embed();
            }
        });

        main.on('click', '#bxc-checkouts-list', function () {
            main.removeClass('bxc-area-create-checkout');
            active_area.attr('data-checkout-id', '');
        });

        main.on('click', '#bxc-save-checkout', function () {
            if (loading(this)) return;
            let error = false;
            let checkout = {};
            let inputs = active_area.find('.bxc-input');
            let checkout_id = active_area.attr('data-checkout-id');
            main.find('.bxc-info').html('');
            inputs.removeClass('bxc-error');
            inputs.e.forEach(e => {
                let id = _(e).attr('id');
                let input = _(e).find('input, select');
                let value = inputValue(input);
                if (!value && input.length() && input.e[0].hasAttribute('required')) {
                    error = true;
                    _(e).addClass('bxc-error');
                }
                checkout[id.replace('bxc-checkout-', '')] = value;
            });
            if (error) {
                showError('Fields in red are required.', this);
                return;
            }
            if (checkout_id) checkout['id'] = checkout_id;
            ajax('save-checkout', { checkout: JSON.stringify(checkout) }, (response) => {
                loading(this, false);
                if (Number.isInteger(response)) {
                    checkout['id'] = response;
                    active_area.attr('data-checkout-id', response);
                    active_area.find('#bxc-table-checkouts tbody').append(BXCCheckout.row(checkout));
                    BXCCheckout.embed(response);
                    BXC_CHECKOUTS.push(checkout);
                    card('Checkout saved successfully');
                } else if (response === true) {
                    BXCCheckout.get(checkout_id, true);
                    BXC_CHECKOUTS.push(checkout);
                    active_area.find(`tr[data-checkout-id="${checkout_id}"]`).replace(BXCCheckout.row(checkout));
                    card('Checkout saved successfully');
                } else {
                    showError(response, this.closest('form'));
                }
            });
        });

        main.on('click', '#bxc-delete-checkout', function () {
            if (loading(this)) return;
            let id = active_area.attr('data-checkout-id');
            ajax('delete-checkout', { checkout_id: id }, () => {
                loading(this, false);
                active_area.attr('data-checkout-id', '');
                active_area.find(`tr[data-checkout-id="${id}"]`).remove();
                active_area.find('#bxc-checkouts-list').e[0].click();
                card('Checkout deleted', 'error');
            });
        });

        /*
        * ----------------------------------------------------------
        * Settings
        * ----------------------------------------------------------
        */

        main.on('click', '#bxc-save-settings', function () {
            if (loading(this)) return;
            let settings = {};
            main.find('[data-area="settings"]').find('.bxc-input[id]:not([data-type="multi-input"]),[data-type="multi-input"] [id]').e.forEach(e => {
                settings[_(e).attr('id')] = inputValue(inputGet(e));
            });
            ajax('save-settings', { settings: JSON.stringify(settings) }, (response) => {
                card(response === true ? 'Settings saved' : response, response === true ? false : 'error');
                loading(this, false);
            });
        });

        main.on('click', '#update-btn a', function (e) {
            if (loading(this)) return;
            ajax('update', { domain: BXC_URL }, (response) => {
                if (response === true) {
                    card('Update completed. Reload in progress...');
                    setTimeout(() => { location.reload(); }, 500);
                } else if (response == 'latest-version-installed') {
                    card('You have the latest version');
                } else {
                    card(slugToString(response), 'error');
                }
                loading(this, false);
            });
            e.preventDefault();
            return false;
        });

        main.on('click', '#email-test-btn a', function (e) {
            if (loading(this)) return;
            ajax('email-test', {}, (response) => {
                if (response === true) card('Email successfully sent.');
                loading(this, false);
            });
            e.preventDefault();
            return false;
        });

        main.on('click', '.bxc-btn-repater', function () {
            repeater(this);
        });

        main.on('click', '.bxc-repater-line i', function () {
            let parent = _(this).parent();
            let items = Array.from(parent.parent().e[0].childNodes);
            let index = items.indexOf(parent.e[0]) + 1;
            let button = parent.parent().find('.bxc-btn-repater');
            for (var i = index; i < items.length; i++) {
                if (_(items[i]).hasClass('bxc-repater-line') || _(items[i]).hasClass('bxc-btn-repater')) {
                    break;
                } else {
                    items[i].remove();
                }
            }
            button.attr('data-index', parseInt(button.attr('data-index')) - 1);
            parent.remove();
        });

        /*
        * ----------------------------------------------------------
        * Responsive
        * ----------------------------------------------------------
        */

        if (responsive) {
            main.on('click', '.bxc-icon-menu', function () {
                let area = _(this).parent();
                activate(area, !area.hasClass('bxc-active'));
            });
        }

        /*
        * ----------------------------------------------------------
        * Miscellaneous
        * ----------------------------------------------------------
        */

        main.on('click', '#bxc-submit-installation', function () {
            if (loading(this)) return;
            let error = false;
            let installation_data = {};
            let url = window.location.href.replace('/admin', '').replace('.php', '').replace(/#$|\/$/, '');
            let inputs = main.find('.bxc-input');
            inputs.removeClass('bxc-error');
            main.find('.bxc-info').html('');
            inputs.e.forEach(e => {
                let id = _(e).attr('id');
                let input = _(e).find('input');
                let value = input.val().trim();
                if (!value && input.e[0].hasAttribute('required')) {
                    error = true;
                    _(e).addClass('bxc-error');
                }
                installation_data[id] = value;
            });
            if (error) {
                error = 'Username, password and the database details are required.';
                let password = installation_data.password;
                if (password) {
                    if (password.length < 8) {
                        error = 'Minimum password length is 8 characters.';
                    } else if (password != installation_data['password-check']) {
                        error = 'The passwords do not match.';
                    }
                }
                showError(error, this);
                return;
            }
            if (url.includes('?')) url = url.substr(0, url.indexOf('?'));
            installation_data['url'] = url + '/';
            ajax('installation', { installation_data: JSON.stringify(installation_data) }, (response) => {
                if (response === true) {
                    location.reload();
                } else {
                    showError(response, this);
                }
            });
        });

        main.on('click', '.bxc-nav > div', function () {
            nav(_(this));
        });

        main.on('click', '#bxc-submit-login', function () {
            if (loading(this)) return;
            ajax('login', { username: main.find('#username input').val().trim(), password: main.find('#password input').val().trim() }, (response) => {
                if (response) {
                    BOXCoin.cookie('BXC_LOGIN', response, 365, 'set');
                    location.reload();
                } else {
                    main.find('.bxc-info').html('Invalid username or password.');
                    loading(this, false);
                }
            });
        });

        main.on('click', '#bxc-logout', function () {
            BOXCoin.cookie('BXC_LOGIN', false, false, 'delete');
            BOXCoin.cookie('BXC_CLOUD', false, false, 'delete');
            location.reload();
        });

        main.on('click', '#bxc-card', function () {
            _(this).html('');
        });

        main.on('input', '.bxc-search-input', function () {
            BOXCoin.search(this, (search, icon) => {
                if (active_area_id == 'transactions') {
                    BXCTransactions.query(icon, search);
                }
            });
        });

        main.on('click', '#bxc-table-balances tr', function () {
            let cryptocurrency = _(this).attr('data-cryptocurrency');
            let url = BXCAdmin.explorer(cryptocurrency, BXC_ADDRESS[cryptocurrency]);
            if (url) window.open(url);
        });

        window.onscroll = function () {
            if (active_area && (document.documentElement || document.body.parentNode || document.body).scrollTop + window.innerHeight == _.documentHeight() && pagination_count) {
                if (active_area_id == 'transactions') {
                    if (!_(active_area).find('> .bxc-loading-global').e.length) {
                        BXCTransactions.print(() => {
                            scrollBottom();
                            _(active_area).find('> .bxc-loading-global').remove();
                        });
                        _(active_area).append('<div class="bxc-loading-global bxc-loading"></div>');
                    }
                }
            }
        };

        window.onpopstate = function () {
            openURL();
        }

        document.addEventListener('click', function (e) {
            if (BXCAdmin.active_element && !BXCAdmin.active_element.contains(e.target)) {
                activate(BXCAdmin.active_element, false);
                BXCAdmin.active_element = false;
            }
        });
    });

}());