<?php

/*
 * ==========================================================
 * ADMIN_CODE.PHP
 * ==========================================================
 *
 * Admin area. © 2022-2023 boxcoin.dev. All rights reserved.
 *
 */

function bxc_box_admin() { 
    bxc_version_updates();
    bxc_colors_admin();
    bxc_load_custom_js_css();
    if (!BXC_CLOUD && !bxc_ve_box()) return;
?>
<div class="bxc-main bxc-admin bxc-area-transactions<?php if (bxc_is_rtl(bxc_language(true))) echo ' bxc-rtl'; ?>">
    <div class="bxc-sidebar">
        <div>
            <img class="bxc-logo" src="<?php echo BXC_CLOUD ? CLOUD_LOGO : (bxc_settings_get('logo-admin') ? bxc_settings_get('logo-url', BXC_URL . 'media/logo.svg') : BXC_URL . 'media/logo.svg') ?>" />
            <img class="bxc-logo-icon" src="<?php echo BXC_CLOUD ? CLOUD_ICON : (bxc_settings_get('logo-admin') ? bxc_settings_get('logo-icon-url', BXC_URL . 'media/icon.svg') : BXC_URL . 'media/icon.svg') ?>" />
        </div>
        <div class="bxc-nav">
            <div id="transactions" class="bxc-active">
                <i class="bxc-icon-shuffle"></i><span><?php bxc_e('Transactions') ?></span>
            </div>
            <div id="checkouts">
                <i class="bxc-icon-automation"></i><span><?php bxc_e('Checkouts') ?></span>
            </div>
            <div id="balances">
                <i class="bxc-icon-bar-chart"></i><span><?php bxc_e('Balances') ?></span>
            </div>
            <div id="settings">
                <i class="bxc-icon-settings"></i><span><?php bxc_e('Settings') ?></span>
            </div>
            <?php if (BXC_CLOUD) echo '<div id="account"><i class="bxc-icon-user"></i><span>' . bxc_('Account') . '</span></div>'; ?>
        </div>
        <div class="bxc-bottom">
            <div id="bxc-create-checkout" class="bxc-btn">
                <?php bxc_e('Create checkout') ?>
            </div>
            <div id="bxc-save-settings" class="bxc-btn">
                <?php bxc_e('Save settings') ?>
            </div>
            <div class="bxc-mobile-menu">
                <i class="bxc-icon-menu"></i>
                <div class="bxc-flex">
                    <div class="bxc-link" id="bxc-logout">
                        <?php bxc_e('Logout') ?>
                    </div>
                    <div id="bxc-version">
                        <?php echo BXC_VERSION ?>
                    </div>
                    <a class="bxc-btn-icon" href="<?php echo BXC_CLOUD ? CLOUD_DOCS : 'https://boxcoin.dev/docs' ?>" target="_blank">
                        <i class="bxc-icon-help"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="bxc-body">
        <main>      
            <div data-area="transactions" class="bxc-active">
                <div class="bxc-nav-wide">
                    <div class="bxc-input bxc-search">
                        <input type="text" id="bxc-search-transactions" class="bxc-search-input" name="bxc-search" placeholder="<?php bxc_e('Search all transactions') ?>" autocomplete="false" />
                        <input type="text" class="bxc-hidden" />
                        <i class="bxc-icon-search"></i>
                    </div>
                    <div id="bxc-download-transitions" class="bxc-btn-icon">
                        <i class="bxc-icon-download"></i>
                    </div>
                    <div class="bxc-nav-filters">
                        <div class="bxc-input">
                            <input id="bxc-filter-date" placeholder="<?php bxc_e('Start date...') ?>" type="text" readonly>
                            <input id="bxc-filter-date-2" placeholder="<?php bxc_e('End date...') ?>" type="text" readonly>
                        </div>
                        <div id="bxc-filter-status" class="bxc-select bxc-right">
                            <p>
                                <?php bxc_e('All statuses') ?>
                            </p>
                            <ul>
                                <li data-value="" class="bxc-active">
                                    <?php bxc_e('All statuses') ?>
                                </li>
                                <li data-value="C">
                                    <?php bxc_e('Completed') ?>                                 
                                </li>
                                <li data-value="P">
                                    <?php bxc_e('Pending') ?>                                   
                                </li>
                            </ul>
                        </div>
                        <div id="bxc-filter-cryptocurrency" class="bxc-select bxc-right">
                            <p>
                                <?php bxc_e('All cryptocurrencies') ?>
                            </p>
                            <ul>
                                <li data-value="" class="bxc-active">
                                    <?php bxc_e('All cryptocurrencies') ?>
                                </li>
                                <li data-value="btc">
                                    Bitcoin                                    
                                </li>
                                <li data-value="eth">
                                    Ethereum                                   
                                </li>
                                <li data-value="xrp">
                                    XRP                                
                                </li>
                                <li data-value="usdt">
                                    Tether                                   
                                </li>
                                <li data-value="usdt_tron">
                                    Tether on Tron                                
                                </li>
                                <li data-value="usdt_bsc">
                                    Tether on BSC                                
                                </li>
                                <li data-value="usdc">
                                    USD Coin                                   
                                </li>
                                <li data-value="busd">
                                    Binance USD                                   
                                </li>
                                <li data-value="bnb">
                                    BNB                            
                                </li>
                                <li data-value="link">
                                    Chainlink                                   
                                </li>
                                <li data-value="doge">
                                    Dogecoin                                   
                                </li>
                                <li data-value="algo">
                                    Algorand                                   
                                </li>
                                <li data-value="shib">
                                    Shiba Inu                                   
                                </li>
                                <li data-value="ltc">
                                    Litecoin                                 
                                </li>
                                <li data-value="bch">
                                    Bitcoin Cash                                 
                                </li>
                                <li data-value="bat">
                                    Basic Attention Token                                   
                                </li>
                                <li data-value="erc-20">
                                    ERC-20                                   
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div id="bxc-filters" class="bxc-btn-icon">
                        <i class="bxc-icon-filters"></i>
                    </div>
                </div>
                <hr />
                <table id="bxc-table-transactions" class="bxc-table">
                    <thead>
                        <tr>
                            <th data-field="id"></th>
                            <th data-field="date">
                                <?php bxc_e('Date') ?>
                            </th>
                            <th data-field="from">
                                <?php bxc_e('From') ?>
                            </th>
                                <th data-field="to">
                                <?php bxc_e('To') ?>
                            </th>
                            <th data-field="status">
                                <?php bxc_e('Status') ?>
                            </th>
                            <th data-field="amount">
                                <?php bxc_e('Amount') ?>
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div data-area="checkouts" class="bxc-loading">
                <table id="bxc-table-checkouts" class="bxc-table">
                    <tbody></tbody>
                </table>
                <div id="bxc-checkouts-form">
                    <form>
                        <div class="bxc-info"></div>
                        <div class="bxc-top">
                            <div id="bxc-checkouts-list" class="bxc-btn bxc-btn-border">
                                <i class="bxc-icon-back"></i>
                                <?php bxc_e('Checkouts list') ?>
                            </div>
                        </div>
                        <div id="bxc-checkout-title" class="bxc-input">
                            <span>
                                <?php bxc_e('Title') ?>
                            </span>
                            <input type="text" required />
                        </div>
                        <div id="bxc-checkout-description" class="bxc-input">
                            <span>
                                <?php bxc_e('Description') ?>
                            </span>
                            <input type="text" />
                        </div>
                        <div class="bxc-flex">
                            <div id="bxc-checkout-price" data-type="select" class="bxc-input">
                                <span>
                                    <?php bxc_e('Price') ?>
                                </span>
                                <input type="number" />
                            </div>
                            <div id="bxc-checkout-currency" data-type="select" class="bxc-input">
                                <select>
                                    <option value="" selected>Default</option><option value="crypto" selected><?php bxc_e('Cryptocurrency') ?></option><option value="AED">United Arab Emirates Dirham</option><option value="AFN">Afghan Afghani</option><option value="ALL">Albanian Lek</option><option value="AMD">Armenian Dram</option><option value="ANG">Netherlands Antillean Guilder</option><option value="AOA">Angolan Kwanza</option><option value="ARS">Argentine Peso</option><option value="AUD">Australian Dollar</option><option value="AWG">Aruban Florin</option><option value="AZN">Azerbaijani Manat</option><option value="BAM">Bosnia-Herzegovina Convertible Mark</option><option value="BBD">Barbadian Dollar</option><option value="BDT">Bangladeshi Taka</option><option value="BGN">Bulgarian Lev</option><option value="BHD">Bahraini Dinar</option><option value="BIF">Burundian Franc</option><option value="BMD">Bermudan Dollar</option><option value="BND">Brunei Dollar</option><option value="BOB">Bolivian Boliviano</option><option value="BRL">Brazilian Real</option><option value="BSD">Bahamian Dollar</option><option value="BTN">Bhutanese Ngultrum</option><option value="BWP">Botswanan Pula</option><option value="BYN">Belarusian Ruble</option><option value="BZD">Belize Dollar</option><option value="CAD">Canadian Dollar</option><option value="CDF">Congolese Franc</option><option value="CHF">Swiss Franc</option><option value="CLF">Chilean Unit of Account (UF)</option><option value="CLP">Chilean Peso</option><option value="CNH">Chinese Yuan (Offshore)</option><option value="CNY">Chinese Yuan</option><option value="COP">Colombian Peso</option><option value="CRC">Costa Rican Colón</option><option value="CUC">Cuban Convertible Peso</option><option value="CUP">Cuban Peso</option><option value="CVE">Cape Verdean Escudo</option><option value="CZK">Czech Republic Koruna</option><option value="DJF">Djiboutian Franc</option><option value="DKK">Danish Krone</option><option value="DOP">Dominican Peso</option><option value="DZD">Algerian Dinar</option><option value="EGP">Egyptian Pound</option><option value="ERN">Eritrean Nakfa</option><option value="ETB">Ethiopian Birr</option><option value="EUR">Euro</option><option value="FJD">Fijian Dollar</option><option value="FKP">Falkland Islands Pound</option><option value="GBP">British Pound Sterling</option><option value="GEL">Georgian Lari</option><option value="GGP">Guernsey Pound</option><option value="GHS">Ghanaian Cedi</option><option value="GIP">Gibraltar Pound</option><option value="GMD">Gambian Dalasi</option><option value="GNF">Guinean Franc</option><option value="GTQ">Guatemalan Quetzal</option><option value="GYD">Guyanaese Dollar</option><option value="HKD">Hong Kong Dollar</option><option value="HNL">Honduran Lempira</option><option value="HRK">Croatian Kuna</option><option value="HTG">Haitian Gourde</option><option value="HUF">Hungarian Forint</option><option value="IDR">Indonesian Rupiah</option><option value="ILS">Israeli New Sheqel</option><option value="IMP">Manx pound</option><option value="INR">Indian Rupee</option><option value="IQD">Iraqi Dinar</option><option value="IRR">Iranian Rial</option><option value="ISK">Icelandic Króna</option><option value="JEP">Jersey Pound</option><option value="JMD">Jamaican Dollar</option><option value="JOD">Jordanian Dinar</option><option value="JPY">Japanese Yen</option><option value="KES">Kenyan Shilling</option><option value="KGS">Kyrgystani Som</option><option value="KHR">Cambodian Riel</option><option value="KMF">Comorian Franc</option><option value="KPW">North Korean Won</option><option value="KRW">South Korean Won</option><option value="KWD">Kuwaiti Dinar</option><option value="KYD">Cayman Islands Dollar</option><option value="KZT">Kazakhstani Tenge</option><option value="LAK">Laotian Kip</option><option value="LBP">Lebanese Pound</option><option value="LKR">Sri Lankan Rupee</option><option value="LRD">Liberian Dollar</option><option value="LSL">Lesotho Loti</option><option value="LYD">Libyan Dinar</option><option value="MAD">Moroccan Dirham</option><option value="MDL">Moldovan Leu</option><option value="MGA">Malagasy Ariary</option><option value="MKD">Macedonian Denar</option><option value="MMK">Myanma Kyat</option><option value="MNT">Mongolian Tugrik</option><option value="MOP">Macanese Pataca</option><option value="MRU">Mauritanian Ouguiya</option><option value="MUR">Mauritian Rupee</option><option value="MVR">Maldivian Rufiyaa</option><option value="MWK">Malawian Kwacha</option><option value="MXN">Mexican Peso</option><option value="MYR">Malaysian Ringgit</option><option value="MZN">Mozambican Metical</option><option value="NAD">Namibian Dollar</option><option value="NGN">Nigerian Naira</option><option value="NIO">Nicaraguan Córdoba</option><option value="NOK">Norwegian Krone</option><option value="NPR">Nepalese Rupee</option><option value="NZD">New Zealand Dollar</option><option value="OMR">Omani Rial</option><option value="PAB">Panamanian Balboa</option><option value="PEN">Peruvian Nuevo Sol</option><option value="PGK">Papua New Guinean Kina</option><option value="PHP">Philippine Peso</option><option value="PKR">Pakistani Rupee</option><option value="PLN">Polish Zloty</option><option value="PYG">Paraguayan Guarani</option><option value="QAR">Qatari Rial</option><option value="RON">Romanian Leu</option><option value="RSD">Serbian Dinar</option><option value="RUB">Russian Ruble</option><option value="RWF">Rwandan Franc</option><option value="SAR">Saudi Riyal</option><option value="SBD">Solomon Islands Dollar</option><option value="SCR">Seychellois Rupee</option><option value="SDG">Sudanese Pound</option><option value="SEK">Swedish Krona</option><option value="SGD">Singapore Dollar</option><option value="SHP">Saint Helena Pound</option><option value="SLL">Sierra Leonean Leone</option><option value="SOS">Somali Shilling</option><option value="SRD">Surinamese Dollar</option><option value="SSP">South Sudanese Pound</option><option value="STD">São Tomé and Príncipe Dobra (pre-2018)</option><option value="STN">São Tomé and Príncipe Dobra</option><option value="SVC">Salvadoran Colón</option><option value="SYP">Syrian Pound</option><option value="SZL">Swazi Lilangeni</option><option value="THB">Thai Baht</option><option value="TJS">Tajikistani Somoni</option><option value="TMT">Turkmenistani Manat</option><option value="TND">Tunisian Dinar</option><option value="TOP">Tongan Pa'anga</option><option value="TRY">Turkish Lira</option><option value="TTD">Trinidad and Tobago Dollar</option><option value="TWD">New Taiwan Dollar</option><option value="TZS">Tanzanian Shilling</option><option value="UAH">Ukrainian Hryvnia</option><option value="UGX">Ugandan Shilling</option><option value="USD">United States Dollar</option><option value="UYU">Uruguayan Peso</option><option value="UZS">Uzbekistan Som</option><option value="VEF">Venezuelan Bolívar Fuerte (Old)</option><option value="VES">Venezuelan Bolívar Soberano</option><option value="VND">Vietnamese Dong</option><option value="VUV">Vanuatu Vatu</option><option value="WST">Samoan Tala</option><option value="XAF">CFA Franc BEAC</option><option value="XAG">Silver Ounce</option><option value="XAU">Gold Ounce</option><option value="XCD">East Caribbean Dollar</option><option value="XDR">Special Drawing Rights</option><option value="XOF">CFA Franc BCEAO</option><option value="XPD">Palladium Ounce</option><option value="XPF">CFP Franc</option><option value="XPT">Platinum Ounce</option><option value="YER">Yemeni Rial</option><option value="ZAR">South African Rand</option><option value="ZMW">Zambian Kwacha</option><option value="ZWL">Zimbabwean Dollar</option>
                                                            <?php 
    $cryptocurrencies = bxc_crypto_name();
    $code = '';
    foreach ($cryptocurrencies as $key => $value) {
    	$code .= '<option value="' . strtoupper($key) . '">' . $value[1] . '</option>';
    }
    echo $code;
                                                            ?>
                                </select>
                            </div>
                        </div>
                        <div id="bxc-checkout-type" data-type="select" class="bxc-input">
                            <span>
                                <?php bxc_e('Type') ?>
                            </span>
                            <select>
                                <option value="I" selected><?php bxc_e('Inline') ?></option>
                                <option value="L"><?php bxc_e('Link') ?></option>
                                <option value="P"><?php bxc_e('Popup') ?></option>
                                <option value="H"><?php bxc_e('Hidden') ?></option>
                            </select>
                        </div>
                        <div id="bxc-checkout-redirect" class="bxc-input">
                            <span>
                                <?php bxc_e('Redirect URL') ?>
                            </span>
                            <input type="url" />
                        </div>
                        <div id="bxc-checkout-external_reference" class="bxc-input">
                            <span>
                                <?php bxc_e('External reference') ?>
                            </span>
                            <input type="text" />
                        </div>
                        <div id="bxc-checkout-hide_title" class="bxc-input">
                            <span>
                                <?php bxc_e('Hide title') ?>
                            </span>
                            <input type="checkbox" />
                        </div>
                        <?php if (defined('BXC_WP')) echo '<div id="bxc-checkout-shortcode" class="bxc-input"><span>Shortcode</span><div></div><i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt"><span class="bxc-toolip">' . bxc_('Copy to clipboard') . '</span></i></div>' ?>
                        <div id="bxc-checkout-embed-code" class="bxc-input">
                            <span>
                                <?php bxc_e('Embed code') ?>
                            </span>
                            <div></div>
                            <i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt">
                                <span class="bxc-toolip"><?php bxc_e('Copy to clipboard') ?></span>
                            </i>
                        </div>
                        <div id="bxc-checkout-payment-link" class="bxc-input">
                            <span>
                                <?php bxc_e('Payment link') ?>
                            </span>
                            <div></div>
                            <i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt">
                                <span class="bxc-toolip"><?php bxc_e('Copy to clipboard') ?></span>
                            </i>
                        </div>
                        <div class="bxc-bottom">
                            <div id="bxc-save-checkout" class="bxc-btn">
                                <?php bxc_e('Save checkout') ?>
                            </div>
                            <a id="bxc-delete-checkout" class="bxc-btn-icon bxc-btn-red">
                                <i class="bxc-icon-delete"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            <div data-area="balance">
                <div>
                    <div id="bxc-balance-total" class="bxc-title"></div>
                    <div class="bxc-text">
                        <?php bxc_e('Available balance') ?>
                    </div>
                </div>
                <table id="bxc-table-balances" class="bxc-table">
                    <thead>
                        <tr>
                            <th data-field="cryptocurrency">
                                <?php bxc_e('Crypto currency') ?>
                            </th>
                            <th data-field="balance">
                                <?php bxc_e('Balance') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div data-area="settings" class="bxc-loading">
                <?php bxc_settings_populate() ?>
            </div>
            <?php if (BXC_CLOUD) require(__DIR__ . '/cloud/account.php') ?>
        </main>
    </div>
    <div id="bxc-card" class="bxc-info-card"></div>
</div>
<div id="bxc-lightbox">
    <div>
        <div class="bxc-top">
            <div class="bxc-title"></div>
            <div>
                <div class="bxc-lightbox-buttons"></div>
                <div id="bxc-lightbox-close" class="bxc-btn-icon bxc-btn-red">
                    <i class="bxc-icon-close"></i>
                </div>
            </div>
        </div>
        <div id="bxc-lightbox-main" class="bxc-scrollbar"></div>
    </div>
    <span></span>
</div>
<div id="bxc-lightbox-loading" class="bxc-loading"></div>
<?php } ?>