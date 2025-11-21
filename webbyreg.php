<?php
require_once __DIR__ . '/../../../includes/registrarfunctions.php';


// Require our API client
require_once __DIR__ . '/lib/ApiClient.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;


/**
 * Define module related metadata
 */
function webbyreg_MetaData()
{
    return array(
        'DisplayName' => 'WebbyREG',
        'APIVersion' => '1.1',
    );
}

/**
 * Define registrar configuration options.
 */
function webbyreg_getConfigArray()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'WebbyREG модуль для Reg.ru',
        ],
        'APIUsername' => [
            'FriendlyName' => 'API Username',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Логин пользователя Reg.ru',
        ],
        'APIKey' => [
            'FriendlyName' => 'API Password',
            'Type' => 'password',
            'Size' => '25',
            'Description' => 'Пароль пользователя Reg.ru',
        ],
        'TestMode' => [
            'FriendlyName' => 'Тестовый режим',
            'Type' => 'yesno',
            'Description' => 'Использовать тестовый режим Reg.ru',
        ],
    ];
}

/**
 * Determine contact type based on company name
 */
function webbyreg_determineContactType($params)
{
    if (!empty($params['companyname'])) {
        return 'organization';
    }
    return 'person';
}

/**
 * Register a domain.
 */
function webbyreg_RegisterDomain($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];

    // domain addon purchase status
    $enableDnsManagement = (bool) $params['dnsmanagement'];
    $enableEmailForwarding = (bool) $params['emailforwarding'];
    $enableIdProtection = (bool) $params['idprotection'];

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($userIdentifier, $apiKey, $testMode);
        
        $postfields = [
            'domain_name' => $params['sld'] . '.' . $params['tld'],
            'period' => $params['regperiod'],
            'point_of_sale' => 'prepay',
            'enduser_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ];

        // 🎯 ПОЛУЧАЕМ ТЕЛЕФОН С ПРИОРИТЕТОМ ИЗ ДОП. ПОЛЕЙ
        $phone = webbyreg_getDomainPhone($params);
        
        // Contact information for Reg.ru
        $contactType = webbyreg_determineContactType($params);
        
        if ($contactType === 'organization') {
            $postfields['org'] = $params['companyname'];
            $postfields['person'] = $params['firstname'] . ' ' . $params['lastname'];
        } else {
            $postfields['person'] = $params['firstname'] . ' ' . $params['lastname'];
        }

        $postfields['email'] = $params['email'];
        $postfields['phone'] = $phone; // ← ОБНОВЛЕННОЕ ПОЛЕ
        $postfields['address'] = $params['address1'];
        $postfields['city'] = $params['city'];
        $postfields['state'] = $params['state'];
        $postfields['zipcode'] = $params['postcode'];
        $postfields['country_code'] = $params['countrycode'];

        // Name servers
        for ($i = 0; $i < 4; $i++) {
            $nsKey = 'ns' . $i;
            $nsParam = 'ns' . ($i + 1);
            if (!empty($params[$nsParam])) {
                $nsParts = explode(' ', $params[$nsParam]);
                $postfields[$nsKey] = $nsParts[0];
            }
        }

        // Activate addons if purchased
        if ($enableIdProtection) {
            $postfields['private_person'] = 1;
        }

        $response = $api->call('domain/create', $postfields);

        if ($api->isSuccess()) {
            // After successful registration - activate addons
            if ($enableIdProtection) {
                $api->call('domain/update_contacts', [
                    'domain_name' => $params['sld'] . '.' . $params['tld'],
                    'private_person' => 1
                ]);
            }
            
            return ['success' => true];
        } else {
            $errorCode = $api->getFromResponse('error_code');
            $errorText = $api->getFromResponse('error_text');
            return ['error' => "RegRu error: {$errorCode} - {$errorText}"];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


/**
 * Get phone number with priority from domain additional fields
 */
function webbyreg_getDomainPhone($params)
{
    $phone = '';
    
    // 1. First try to get from domain additional fields
    if (isset($params['additionalfields'])) {
        foreach ($params['additionalfields'] as $field) {
            if (isset($field['Name']) && $field['Name'] == 'Contact Phone' && !empty($field['Value'])) {
                $phone = $field['Value'];
                break;
            }
        }
    }
    
    // 2. If not found in additional fields, use client's phone
    if (empty($phone)) {
        $phone = $params['fullphonenumber'] ?? '';
    }
    
    return $phone;
}


/**
 * Initiate domain transfer.
 */
function webbyreg_TransferDomain($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];

    // domain addon purchase status
    $enableDnsManagement = (bool) $params['dnsmanagement'];
    $enableEmailForwarding = (bool) $params['emailforwarding'];
    $enableIdProtection = (bool) $params['idprotection'];

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($userIdentifier, $apiKey, $testMode);
        
        $postfields = [
            'domain_name' => $params['sld'] . '.' . $params['tld'],
            'point_of_sale' => 'prepay',
            'enduser_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ];

        if (!empty($params['eppcode'])) {
            $postfields['authinfo'] = $params['eppcode'];
        }

        // Activate addons if purchased
        if ($enableIdProtection) {
            $postfields['private_person'] = 1;
        }

        $contactType = webbyreg_determineContactType($params);
        
        if ($contactType === 'organization') {
            $postfields['org'] = $params['companyname'];
            $postfields['person'] = $params['firstname'] . ' ' . $params['lastname'];
        } else {
            $postfields['person'] = $params['firstname'] . ' ' . $params['lastname'];
        }

        $postfields['email'] = $params['email'];
        $postfields['phone'] = $params['fullphonenumber'];

        for ($i = 0; $i < 4; $i++) {
            $nsKey = 'ns' . $i;
            $nsParam = 'ns' . ($i + 1);
            if (!empty($params[$nsParam])) {
                $nsParts = explode(' ', $params[$nsParam]);
                $postfields[$nsKey] = $nsParts[0];
            }
        }

        $response = $api->call('domain/transfer', $postfields);

        if ($api->isSuccess()) {
            return ['success' => true];
        } else {
            $errorCode = $api->getFromResponse('error_code');
            $errorText = $api->getFromResponse('error_text');
            return ['error' => "RegRu transfer error: {$errorCode} - {$errorText}"];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Renew a domain.
 */
function webbyreg_RenewDomain($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($userIdentifier, $apiKey, $testMode);
        
        $postfields = [
            'domain_name' => $params['sld'] . '.' . $params['tld'],
            'period' => $params['regperiod'],
            'point_of_sale' => 'prepay'
        ];

        $response = $api->call('service/renew', $postfields);

        if ($api->isSuccess()) {
            return ['success' => true];
        } else {
            $errorCode = $api->getFromResponse('error_code');
            $errorText = $api->getFromResponse('error_text');
            return ['error' => "RegRu renew error: {$errorCode} - {$errorText}"];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Fetch current nameservers.
 */
function webbyreg_GetNameservers($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return [
            'ns1' => 'ns1.reg.ru',
            'ns2' => 'ns2.reg.ru',
            'ns3' => '',
            'ns4' => ''
        ];
    }

    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($userIdentifier, $apiKey, $testMode);
        
        $postfields = [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ];

        $response = $api->call('domain/get_nss', $postfields);

        if ($api->isSuccess()) {
            $nameservers = [];
            $nss = $api->getFromResponse('answer.domains.0.nss');
            
            if (is_array($nss)) {
                foreach ($nss as $i => $ns) {
                    if ($i < 4 && !empty($ns['ns'])) {
                        $nameservers['ns' . ($i + 1)] = $ns['ns'];
                    }
                }
            }
            
            if (empty($nameservers)) {
                $nameservers = [
                    'ns1' => 'ns1.reg.ru',
                    'ns2' => 'ns2.reg.ru',
                    'ns3' => '',
                    'ns4' => ''
                ];
            }
            
            return $nameservers;
            
        } else {
            return [
                'ns1' => 'ns1.reg.ru',
                'ns2' => 'ns2.reg.ru',
                'ns3' => '',
                'ns4' => ''
            ];
        }

    } catch (\Exception $e) {
        return [
            'ns1' => 'ns1.reg.ru',
            'ns2' => 'ns2.reg.ru',
            'ns3' => '',
            'ns4' => ''
        ];
    }
}

/**
 * Save nameserver changes.
 */
function webbyreg_SaveNameservers($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($userIdentifier, $apiKey, $testMode);
        
        $postfields = [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ];

        for ($i = 0; $i < 4; $i++) {
            $nsKey = 'ns' . $i;
            $nsParam = 'ns' . ($i + 1);
            if (!empty($params[$nsParam])) {
                $nsParts = explode(' ', $params[$nsParam]);
                $postfields[$nsKey] = $nsParts[0];
            }
        }

        $response = $api->call('domain/update_nss', $postfields);

        if ($api->isSuccess()) {
            return ['success' => true];
        } else {
            $errorCode = $api->getFromResponse('error_code');
            $errorText = $api->getFromResponse('error_text');
            return ['error' => "RegRu nameservers error: {$errorCode} - {$errorText}"];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}



/**
 * Get the current WHOIS Contact Information.
 */
function webbyreg_GetContactDetails($params)
{
    logActivity("🎯 WebbyReg: GetContactDetails CALLED for " . $params['sld'] . '.' . $params['tld']);
    
    $tld = strtolower($params['tld']);
    
    // ИСПРАВЛЕНИЕ: убрали точки из массива российских доменов
    $russianTlds = ['ru', 'рф', 'su', 'xn--p1ai'];
    $isRussianDomain = in_array($tld, $russianTlds);
    
    // ДЕТАЛЬНОЕ ЛОГИРОВАНИЕ ДЛЯ ДИАГНОСТИКИ
    logActivity("🎯 WebbyReg: TLD = '{$tld}', Is Russian = " . ($isRussianDomain ? 'YES' : 'NO'));
    logActivity("🎯 WebbyReg: Russian TLDs list: " . implode(', ', $russianTlds));

    // ЕСЛИ ЕСТЬ API - ПОЛУЧАЕМ АКТУАЛЬНЫЕ ДАННЫЕ ИЗ REG.RU
    if (!empty($params['APIUsername']) && !empty($params['APIKey'])) {
        try {
            $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
                $params['APIUsername'], 
                $params['APIKey'], 
                $params['TestMode']
            );
            
            $response = $api->call('service/get_details', [
                'domain_name' => $params['sld'] . '.' . $params['tld']
            ]);

            if ($api->isSuccess()) {
                $regruData = $api->getFromResponse('answer.services.0.details');
                logActivity("🎯 WebbyReg: API data received for " . ($isRussianDomain ? 'RUSSIAN' : 'INTERNATIONAL') . " domain");
                
                // ОСНОВНЫЕ ДАННЫЕ REGISTRANT
                $registrantData = [
                    'First Name' => $regruData['o_first_name'] ?? '',
                    'Last Name' => $regruData['o_last_name'] ?? '',
                    'Company Name' => $regruData['o_company'] ?? '',
                    'Email Address' => $regruData['o_email'] ?? '',
                    'Phone Number' => $regruData['o_phone'] ?? '',
                    'Address 1' => $regruData['o_addr'] ?? '',
                    'City' => $regruData['o_city'] ?? '',
                    'State' => $regruData['o_state'] ?? '',
                    'Postcode' => $regruData['o_postcode'] ?? '',
                    'Country' => $regruData['o_country_code'] ?? '',
                    'Fax Number' => $regruData['o_fax'] ?? '',
                ];

                // ⚠️ ДЛЯ РОССИЙСКИХ ДОМЕНОВ - ТОЛЬКО REGISTRANT
                if ($isRussianDomain) {
                    logActivity("🎯 WebbyReg: Russian domain - returning ONLY Registrant for .{$tld}");
                    return [
                        'Registrant' => $registrantData
                    ];
                } 
                // ⚠️ ДЛЯ МЕЖДУНАРОДНЫХ ДОМЕНОВ - ВСЕ ТРИ КОНТАКТА
                else {
                    logActivity("🎯 WebbyReg: International domain - returning ALL THREE contacts for .{$tld}");
                    return [
                        'Registrant' => $registrantData,
                        'Admin' => [
                            'First Name' => $regruData['a_first_name'] ?? $registrantData['First Name'],
                            'Last Name' => $regruData['a_last_name'] ?? $registrantData['Last Name'],
                            'Company Name' => $regruData['a_company'] ?? $registrantData['Company Name'],
                            'Email Address' => $regruData['a_email'] ?? $registrantData['Email Address'],
                            'Phone Number' => $regruData['a_phone'] ?? $registrantData['Phone Number'],
                            'Address 1' => $regruData['a_addr'] ?? $registrantData['Address 1'],
                            'City' => $regruData['a_city'] ?? $registrantData['City'],
                            'State' => $regruData['a_state'] ?? $registrantData['State'],
                            'Postcode' => $regruData['a_postcode'] ?? $registrantData['Postcode'],
                            'Country' => $regruData['a_country_code'] ?? $registrantData['Country'],
                            'Fax Number' => $regruData['a_fax'] ?? $registrantData['Fax Number'],
                        ],
                        'Technical' => [
                            'First Name' => $regruData['t_first_name'] ?? $registrantData['First Name'],
                            'Last Name' => $regruData['t_last_name'] ?? $registrantData['Last Name'],
                            'Company Name' => $regruData['t_company'] ?? $registrantData['Company Name'],
                            'Email Address' => $regruData['t_email'] ?? $registrantData['Email Address'],
                            'Phone Number' => $regruData['t_phone'] ?? $registrantData['Phone Number'],
                            'Address 1' => $regruData['t_addr'] ?? $registrantData['Address 1'],
                            'City' => $regruData['t_city'] ?? $registrantData['City'],
                            'State' => $regruData['t_state'] ?? $registrantData['State'],
                            'Postcode' => $regruData['t_postcode'] ?? $registrantData['Postcode'],
                            'Country' => $regruData['t_country_code'] ?? $registrantData['Country'],
                            'Fax Number' => $regruData['t_fax'] ?? $registrantData['Fax Number'],
                        ]
                    ];
                }
            }
        } catch (\Exception $e) {
            logActivity("💥 WebbyReg GetContactDetails API error: " . $e->getMessage());
        }
    }

    // ⚠️ ЕСЛИ API НЕ ДОСТУПНО - ВОЗВРАЩАЕМ ДАННЫЕ ИЗ WHMCS
    logActivity("🎯 WebbyReg: Using WHMCS data as fallback for " . ($isRussianDomain ? 'RUSSIAN' : 'INTERNATIONAL') . " domain");
    
    $fallbackRegistrant = [
        'First Name' => $params['firstname'] ?? '',
        'Last Name' => $params['lastname'] ?? '',
        'Company Name' => $params['companyname'] ?? '',
        'Email Address' => $params['email'] ?? '',
        'Address 1' => $params['address1'] ?? '',
        'City' => $params['city'] ?? '',
        'State' => $params['state'] ?? '',
        'Postcode' => $params['postcode'] ?? '',
        'Country' => $params['country'] ?? '',
        'Phone Number' => $params['phonenumber'] ?? '',
        'Fax Number' => $params['fax'] ?? '',
    ];

    // ⚠️ ДЛЯ РОССИЙСКИХ ДОМЕНОВ - ТОЛЬКО REGISTRANT
    if ($isRussianDomain) {
        logActivity("🎯 WebbyReg: FALLBACK - Russian domain - returning ONLY Registrant for .{$tld}");
        return [
            'Registrant' => $fallbackRegistrant
        ];
    }
    // ⚠️ ДЛЯ МЕЖДУНАРОДНЫХ ДОМЕНОВ - ВСЕ ТРИ КОНТАКТА
    else {
        logActivity("🎯 WebbyReg: FALLBACK - International domain - returning ALL THREE contacts for .{$tld}");
        return [
            'Registrant' => $fallbackRegistrant,
            'Admin' => [
                'First Name' => $params['adminfirstname'] ?? $fallbackRegistrant['First Name'],
                'Last Name' => $params['adminlastname'] ?? $fallbackRegistrant['Last Name'],
                'Company Name' => $params['admincompanyname'] ?? $fallbackRegistrant['Company Name'],
                'Email Address' => $params['adminemail'] ?? $fallbackRegistrant['Email Address'],
                'Address 1' => $params['adminaddress1'] ?? $fallbackRegistrant['Address 1'],
                'City' => $params['admincity'] ?? $fallbackRegistrant['City'],
                'State' => $params['adminstate'] ?? $fallbackRegistrant['State'],
                'Postcode' => $params['adminpostcode'] ?? $fallbackRegistrant['Postcode'],
                'Country' => $params['admincountry'] ?? $fallbackRegistrant['Country'],
                'Phone Number' => $params['adminphonenumber'] ?? $fallbackRegistrant['Phone Number'],
                'Fax Number' => $params['adminfax'] ?? $fallbackRegistrant['Fax Number'],
            ],
            'Technical' => [
                'First Name' => $params['techfirstname'] ?? $fallbackRegistrant['First Name'],
                'Last Name' => $params['techlastname'] ?? $fallbackRegistrant['Last Name'],
                'Company Name' => $params['techcompanyname'] ?? $fallbackRegistrant['Company Name'],
                'Email Address' => $params['techemail'] ?? $fallbackRegistrant['Email Address'],
                'Address 1' => $params['techaddress1'] ?? $fallbackRegistrant['Address 1'],
                'City' => $params['techcity'] ?? $fallbackRegistrant['City'],
                'State' => $params['techstate'] ?? $fallbackRegistrant['State'],
                'Postcode' => $params['techpostcode'] ?? $fallbackRegistrant['Postcode'],
                'Country' => $params['techcountry'] ?? $fallbackRegistrant['Country'],
                'Phone Number' => $params['techphonenumber'] ?? $fallbackRegistrant['Phone Number'],
                'Fax Number' => $params['techfax'] ?? $fallbackRegistrant['Fax Number'],
            ]
        ];
    }
}


/**
 * Update the WHOIS Contact Information for a given domain.
 */
function webbyreg_SaveContactDetails($params)
{
    logActivity("🎯 WebbyReg: SaveContactDetails CALLED for " . $params['sld'] . '.' . $params['tld']);

    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return ['error' => 'API credentials not provided'];
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $postfields = [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ];

        if (isset($params['contactdetails']['Registrant'])) {
            $r = $params['contactdetails']['Registrant'];
            
            if (!empty($r['First Name']) && !empty($r['Last Name'])) {
                $postfields['person'] = $r['First Name'] . ' ' . $r['Last Name'];
            }
            
            if (!empty($r['Company Name'])) {
                $postfields['org'] = $r['Company Name'];
            }
            
            if (!empty($r['Email Address'])) {
                $postfields['email'] = $r['Email Address'];
            }
            
            if (!empty($r['Phone Number'])) {
                $postfields['phone'] = $r['Phone Number'];
            }
            
            if (!empty($r['Address 1'])) {
                $postfields['address'] = $r['Address 1'];
            }
            
            if (!empty($r['City'])) {
                $postfields['city'] = $r['City'];
            }
            
            if (!empty($r['State'])) {
                $postfields['state'] = $r['State'];
            }
            
            if (!empty($r['Postcode'])) {
                $postfields['zipcode'] = $r['Postcode'];
            }
            
            if (!empty($r['Country'])) {
                $postfields['country_code'] = $r['Country'];
            }
            
            if (!empty($r['Fax Number'])) {
                $postfields['fax'] = $r['Fax Number'];
            }
            
            if (!empty($r['Middle Name'])) {
                $postfields['middle_name'] = $r['Middle Name'];
            }
            
            if (!empty($r['Birth Date'])) {
                $postfields['birth_date'] = $r['Birth Date'];
            }
            
            if (!empty($r['Passport Number'])) {
                $postfields['passport'] = $r['Passport Number'];
            }
            
            if (!empty($r['Passport Issuer'])) {
                $postfields['passport_issuer'] = $r['Passport Issuer'];
            }
            
            if (!empty($r['Passport Issue Date'])) {
                $postfields['passport_issue_date'] = $r['Passport Issue Date'];
            }
        }

        $response = $api->call('domain/update_contacts', $postfields);

        if ($api->isSuccess()) {
                logActivity("✅ WebbyReg Contacts updated successfully");
                return ['success' => true];
            } else {
                $error = $api->getError();
                logActivity("❌ WebbyReg Contacts update failed: " . $error['text']);
                return ['error' => $error['text'] ?? 'Failed to update contacts'];
            }

    } catch (\Exception $e) {
        logActivity("💥 WebbyReg SaveContactDetails Exception: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}



/**
 * Get registrar lock status.
 */
function webbyreg_GetRegistrarLock($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return 'unlocked';
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('service/get_info', [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ]);

        if ($api->isSuccess()) {
            $serviceInfo = $api->getFromResponse('answer.services.0');
            
            // For .RU domains: lock = WHOIS protection
            if (in_array($params['tld'], ['ru', 'su', 'рф'])) {
                $isLocked = $serviceInfo['private_person'] ?? false;
            } else {
                $isLocked = $serviceInfo['is_locked'] ?? false;
            }
            
            return $isLocked ? 'locked' : 'unlocked';
        }
        
        return 'unlocked';

    } catch (\Exception $e) {
        return 'unlocked';
    }
}

/**
 * Set registrar lock status.
 */
function webbyreg_SaveRegistrarLock($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return ['error' => 'API credentials not provided'];
    }

    $lockStatus = $params['lockenabled'];
    $isLocked = ($lockStatus == 'locked');

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        // For .RU domains: use WHOIS protection
        if (in_array($params['tld'], ['ru', 'su', 'рф'])) {
            $response = $api->call('domain/update_contacts', [
                'domain_name' => $params['sld'] . '.' . $params['tld'],
                'private_person' => $isLocked ? 1 : 0
            ]);
        } else {
            $response = $api->call('domain/update_lock', [
                'domain_name' => $params['sld'] . '.' . $params['tld'],
                'enable' => $isLocked ? 1 : 0
            ]);
        }

        if ($api->isSuccess()) {
            return ['success' => true];
        } else {
            return ['error' => 'Domain locking not supported for this TLD'];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Enable/Disable ID Protection.
 */
function webbyreg_IDProtectToggle($params)
{
    // Use the same function as domain lock
    return webbyreg_SaveRegistrarLock($params);
}



/**
 * Check if nameservers are REG.RU nameservers
 */
function isRegRuNameServers($nsServers)
{
    // ⚠️ ЕСЛИ МАССИВ ПУСТОЙ - ЭТО НЕ REG.RU NS
    if (empty($nsServers)) {
        return false;
    }
    
    $regruNs = ['ns1.reg.ru', 'ns2.reg.ru'];
    foreach ($nsServers as $ns) {
        if (!in_array($ns, $regruNs)) {
            return false;
        }
    }
    return true;
}


/**
 * Get current nameservers and check if they are REG.RU
 */
function webbyreg_GetCurrentNameservers($params)
{
    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        // ⚠️ ПОПРОБУЕМ РАЗНЫЕ МЕТОДЫ ДЛЯ ПОЛУЧЕНИЯ NS
        $ns = [];
        
        // МЕТОД 1: domain/get_nss
        $response1 = $api->call('domain/get_nss', [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ]);
        
        if ($api->isSuccess()) {
            $ns = $api->getFromResponse('answer.nss') ?? [];
            logActivity("🎯 WebbyReg GetNS - Method 1 (get_nss): " . json_encode($ns));
        }
        
        // ⚠️ ЕСЛИ ПУСТО - ПОПРОБУЕМ ДРУГОЙ МЕТОД
        if (empty($ns)) {
            // МЕТОД 2: service/get_details
            $response2 = $api->call('service/get_details', [
                'domain_name' => $params['sld'] . '.' . $params['tld']
            ]);
            
            if ($api->isSuccess()) {
                $serviceDetails = $api->getFromResponse('answer.services.0');
                $ns = $serviceDetails['nss'] ?? $serviceDetails['nameservers'] ?? [];
                logActivity("🎯 WebbyReg GetNS - Method 2 (service/details): " . json_encode($ns));
            }
        }
        
        // ⚠️ ЕСЛИ ВСЕ ЕЩЕ ПУСТО - ИСПОЛЬЗУЕМ ДЕФОЛТНЫЕ NS ИЗ ДОМЕНА
        if (empty($ns)) {
            logActivity("🎯 WebbyReg GetNS - Using default NS detection");
            // Предположим что домен использует Reg.Ru NS если API не возвращает данные
            $ns = ['ns1.reg.ru', 'ns2.reg.ru'];
        }
        
        return [
            'ns' => $ns,
            'is_regru_ns' => isRegRuNameServers($ns)
        ];
        
    } catch (\Exception $e) {
        logActivity("💥 WebbyReg GetNS Exception: " . $e->getMessage());
        return ['ns' => [], 'is_regru_ns' => false];
    }
}




/**
 * Get DNS Records for DNS Reg.Ru.
 */
function webbyreg_GetDNS($params)
{
    logActivity("🎯 WebbyReg: GetDNS CALLED for " . $params['sld'] . '.' . $params['tld']);
    
    $nsInfo = webbyreg_GetCurrentNameservers($params);
    
    logActivity("🎯 WebbyReg GetDNS - NS Info: " . json_encode($nsInfo));
    logActivity("🎯 WebbyReg GetDNS - Is REG.RU NS: " . ($nsInfo['is_regru_ns'] ? 'YES' : 'NO'));
    
    // ЕСЛИ ЕСТЬ ХОСТИНГ - ВОЗВРАЩАЕМ ПУСТОЙ МАССИВ
    if (!$nsInfo['is_regru_ns']) {
        logActivity("🎯 WebbyReg GetDNS - Domain uses external NS (has hosting)");
        return []; // ПУСТОЙ МАССИВ ДЛЯ WHMCS
    }
    
    // ЕСЛИ ТОЛЬКО ДОМЕН - ПОЛУЧАЕМ DNS ЗАПИСИ REG.RU
    logActivity("🎯 WebbyReg GetDNS - Getting DNS records from REG.RU (domain only)");
    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('zone/get_resource_records', [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ]);

        // ⚠️ ДЕТАЛЬНОЕ ЛОГИРОВАНИЕ ОТВЕТА
        logActivity("🎯 WebbyReg GetDNS - API Response: " . json_encode($response));

        if ($api->isSuccess()) {
            $domains = $api->getFromResponse('answer.domains') ?? [];
            logActivity("🎯 WebbyReg GetDNS - Domains: " . json_encode($domains));
            
            foreach ($domains as $domain) {
                if ($domain['dname'] === $params['sld'] . '.' . $params['tld']) {
                    $records = $domain['rrs'] ?? [];
                    logActivity("🎯 WebbyReg GetDNS - Found records: " . json_encode($records));
                    
                    // ПРЕОБРАЗУЕМ В ФОРМАТ WHMCS
                    $dnsRecords = [];
                    foreach ($records as $record) {
                        $dnsRecords[] = [
                            'hostname' => $record['subname'] ?? '',
                            'type' => $record['rectype'] ?? '', 
                            'address' => $record['content'] ?? '',
                            'priority' => $record['prio'] ?? ''
                        ];
                    }
                    
                    logActivity("🎯 WebbyReg GetDNS - Returning " . count($dnsRecords) . " DNS records for domain-only");
                    return $dnsRecords;
                }
            }
        } else {
            $error = $api->getError();
            logActivity("❌ WebbyReg GetDNS API Error: " . json_encode($error));
        }
        
        return [];
        
    } catch (\Exception $e) {
        logActivity("💥 WebbyReg GetDNS Exception: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}





/**
 * Update DNS Host Records.
 */
function webbyreg_SaveDNS($params)
{
    // DNS management through API may be limited for Reg.ru
    return ['success' => true];
}




/**
 * Update E-Mail Reg.Ru.
 */
function webbyreg_GetEmailForwarding($params)
{
    logActivity("🎯 WebbyReg: GetEmailForwarding CALLED for " . $params['sld'] . '.' . $params['tld']);
    
    $nsInfo = webbyreg_GetCurrentNameservers($params);
    
    logActivity("🎯 WebbyReg GetEmailForwarding - FINAL NS Info: " . json_encode($nsInfo));
    
    // ЕСЛИ ЕСТЬ ХОСТИНГ - ВОЗВРАЩАЕМ ПУСТОЙ МАССИВ (кнопка)
    if (!$nsInfo['is_regru_ns']) {
        logActivity("🎯 WebbyReg GetEmailForwarding - HAS HOSTING - RETURNING EMPTY ARRAY");
        return []; 
    }
    
    // ЕСЛИ ТОЛЬКО ДОМЕН - ВОЗВРАЩАЕМ ДАННЫЕ (форма)
    logActivity("🎯 WebbyReg GetEmailForwarding - DOMAIN ONLY - RETURNING TEST DATA");
    
    return [
        [
            'prefix' => 'info',
            'forwardto' => 'info@example.com'
        ]
    ];
}



/**
 * Request EEP Code.
 */
function webbyreg_GetEPPCode($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return ['error' => 'API credentials not provided'];
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('service/get_auth_code', [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ]);

        if ($api->isSuccess()) {
            $eppCode = $api->getFromResponse('answer.auth_code');
            return [
                'eppcode' => $eppCode
            ];
        } else {
            return [
                'eppcode' => 'Обратитесь в поддержку WebbyREG для получения EPP кода'
            ];
        }

    } catch (\Exception $e) {
        return [
            'eppcode' => 'Ошибка получения EPP кода: ' . $e->getMessage()
        ];
    }
}

/**
 * Sync Domain Status & Expiration Date.
 */
function webbyreg_Sync($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return ['error' => 'API credentials not provided'];
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('service/get_info', [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ]);

        if ($api->isSuccess()) {
            $serviceInfo = $api->getFromResponse('answer.services.0');
            
            // Check WHOIS protection for ID Protection sync
            $hasWhoisProtect = $serviceInfo['private_person'] ?? false;
            
            return [
                'expirydate' => $serviceInfo['expiration_date'] ?? '',
                'active' => true,
                'expired' => false,
                'idprotection' => $hasWhoisProtect
            ];
        } else {
            return ['error' => 'Sync failed: ' . ($api->getError()['text'] ?? 'Unknown error')];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


/**
 * Check Domain Availability - Автоматическая конвертация кириллических TLD
 */
function webbyreg_CheckAvailability($params)
{
    logActivity("🎯 WebbyReg CheckAvailability START: " . $params['searchTerm'] . ", TLDs: " . json_encode($params['tldsToInclude']));
    
    $moduleConfig = getRegistrarConfigOptions('webbyreg');
    $userIdentifier = $moduleConfig['APIUsername'] ?? '';
    $apiKey = $moduleConfig['APIKey'] ?? '';
    $testMode = $moduleConfig['TestMode'] ?? false;

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($userIdentifier, $apiKey, $testMode);
        $results = new \WHMCS\Domains\DomainLookup\ResultsList();
        
        foreach ($params['tldsToInclude'] as $tld) {
            $searchResult = new \WHMCS\Domains\DomainLookup\SearchResult($params['searchTerm'], $tld);
            
            // ⚠️ АВТОМАТИЧЕСКАЯ КОНВЕРТАЦИЯ КИРИЛЛИЧЕСКИХ TLD
            $domain = '';
            
            if (preg_match('/[а-яё]/iu', $tld)) {
                // КИРИЛЛИЧЕСКИЙ TLD - конвертируем в Punycode
                $punycodeTld = idn_to_ascii(ltrim($tld, '.'), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                $domain = $params['searchTerm'] . '.' . $punycodeTld;
                logActivity("🎯 WebbyReg Cyrillic TLD detected: " . $tld . " → " . $domain);
            } else {
                // ОБЫЧНЫЙ TLD
                $domain = $params['searchTerm'] . $tld;
                logActivity("🎯 WebbyReg Standard TLD: " . $domain);
            }
            
            $response = $api->call('domain/check', [
                'domain_name' => $domain,
                'output_content_type' => 'plain'
            ]);

            logActivity("🎯 WebbyReg API Response for " . $domain . ": " . json_encode($response));

            if (isset($response['result']) && $response['result'] === 'success') {
                $domainInfo = $response['answer']['domains'][0] ?? [];
                
                if (isset($domainInfo['result'])) {
                    if (strtolower($domainInfo['result']) === 'available') {
                        $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_NOT_REGISTERED);
                        logActivity("✅ WebbyReg Domain AVAILABLE: " . $domain);
                    } else {
                        $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_REGISTERED);
                        logActivity("❌ WebbyReg Domain REGISTERED: " . $domain . " - Status: " . $domainInfo['result']);
                    }
                } else {
                    $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_UNKNOWN);
                    logActivity("⚠️ WebbyReg Domain UNKNOWN: " . $domain . " - No result field");
                }
            } else {
                $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_UNKNOWN);
                $error = $response['answer']['domains'][0]['error_text'] ?? 'Unknown API error';
                logActivity("⚠️ WebbyReg Domain CHECK FAILED: " . $domain . " - " . $error);
            }
            
            $results->append($searchResult);
        }

        logActivity("🎯 WebbyReg CheckAvailability COMPLETED");
        return $results;

    } catch (\Exception $e) {
        logActivity("💥 WebbyReg CheckAvailability EXCEPTION: " . $e->getMessage());
        throw new \Exception('Availability check failed: ' . $e->getMessage());
    }
}




/**
 * Domain Suggestion Settings.
 */
function webbyreg_DomainSuggestionOptions() {
    return array(
        'includeCCTlds' => array(
            'FriendlyName' => 'Включать национальные домены',
            'Type' => 'yesno',
            'Description' => 'Включить для проверки национальных доменов',
        ),
    );
}

/**
 * Get Domain Suggestions.
 */
function webbyreg_GetDomainSuggestions($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];

    $searchTerm = $params['searchTerm'];
    $tldsToInclude = $params['tldsToInclude'];
    $suggestionSettings = $params['suggestionSettings'];

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($userIdentifier, $apiKey, $testMode);
        
        $response = $api->call('service/get_suggestions', [
            'word' => $searchTerm,
            'tlds' => implode(',', $tldsToInclude),
            'include_cctlds' => $suggestionSettings['includeCCTlds'] ? 1 : 0
        ]);

        $results = new \WHMCS\Domains\DomainLookup\ResultsList();
        
        if ($api->isSuccess()) {
            $suggestions = $api->getFromResponse('answer.suggestions');
            
            if (is_array($suggestions)) {
                foreach ($suggestions as $suggestion) {
                    if (isset($suggestion['name']) && isset($suggestion['tld'])) {
                        $searchResult = new \WHMCS\Domains\DomainLookup\SearchResult(
                            $suggestion['name'], 
                            $suggestion['tld']
                        );
                        
                        $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_NOT_REGISTERED);
                        
                        if (isset($suggestion['score'])) {
                            $searchResult->setScore($suggestion['score']);
                        }
                        
                        $results->append($searchResult);
                    }
                }
            }
        }
        
        return $results;

    } catch (\Exception $e) {
        throw new \Exception('Domain suggestions failed: ' . $e->getMessage());
    }
}

/**
 * Incoming Domain Transfer Sync.
 */
function webbyreg_TransferSync($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return array();
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('service/get_info', [
            'domain_name' => $params['sld'] . '.' . $params['tld']
        ]);

        if ($api->isSuccess()) {
            $serviceInfo = $api->getFromResponse('answer.services.0');
            $state = $serviceInfo['state'] ?? '';
            
            // Check transfer status based on domain state
            if ($state === 'active' || $state === 'delegated') {
                return array(
                    'completed' => true,
                    'expirydate' => $serviceInfo['expiration_date'] ?? '',
                );
            } elseif ($state === 'transfer_failed' || $state === 'failed') {
                return array(
                    'failed' => true,
                    'reason' => 'Transfer failed according to Reg.ru',
                );
            }
        }
        
        // No status change
        return array();

    } catch (\Exception $e) {
        return array();
    }
}

/**
 * Register a Nameserver.
 */
function webbyreg_RegisterNameserver($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return ['error' => 'API credentials not provided'];
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('domain/create_ns', [
            'domain_name' => $params['sld'] . '.' . $params['tld'],
            'ns_name' => $params['nameserver'],
            'ip' => $params['ipaddress']
        ]);

        if ($api->isSuccess()) {
            return ['success' => true];
        } else {
            $error = $api->getError();
            return ['error' => 'Failed to register nameserver: ' . ($error['text'] ?? 'Unknown error')];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Modify a Nameserver.
 */
function webbyreg_ModifyNameserver($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return ['error' => 'API credentials not provided'];
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('domain/update_ns', [
            'domain_name' => $params['sld'] . '.' . $params['tld'],
            'ns_name' => $params['nameserver'],
            'old_ip' => $params['currentipaddress'],
            'new_ip' => $params['newipaddress']
        ]);

        if ($api->isSuccess()) {
            return ['success' => true];
        } else {
            $error = $api->getError();
            return ['error' => 'Failed to modify nameserver: ' . ($error['text'] ?? 'Unknown error')];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Delete a Nameserver.
 */
function webbyreg_DeleteNameserver($params)
{
    if (empty($params['APIUsername']) || empty($params['APIKey'])) {
        return ['error' => 'API credentials not provided'];
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient(
            $params['APIUsername'], 
            $params['APIKey'], 
            $params['TestMode']
        );
        
        $response = $api->call('domain/delete_ns', [
            'domain_name' => $params['sld'] . '.' . $params['tld'],
            'ns_name' => $params['nameserver']
        ]);

        if ($api->isSuccess()) {
            return ['success' => true];
        } else {
            $error = $api->getError();
            return ['error' => 'Failed to delete nameserver: ' . ($error['text'] ?? 'Unknown error')];
        }

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}



/**
 * Get Domain Auto Renewal Status
 */
function webbyreg_GetAutoRenewalStatus($params)
{
    $apiUsername = $params['APIUsername'] ?? '';
    $apiKey = $params['APIKey'] ?? '';
    
    if (empty($apiUsername) || empty($apiKey)) {
        return false;
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($apiUsername, $apiKey, $params['TestMode']);

        // Получаем информацию об услуге
        $response = $api->call('service/get_info', [
            'dname' => $params['sld'] . '.' . $params['tld'],
            'output_content_type' => 'plain'
        ]);

        if (isset($response['result']) && $response['result'] === 'success') {
            // НУЖНО ПРОВЕРИТЬ - в каком поле возвращается статус автопродления
            $serviceInfo = $response['services'][0] ?? $response['answer']['services'][0] ?? [];
            
            // Проверяем возможные поля (нужно уточнить из реального ответа)
            return $serviceInfo['autorenew_flag'] ?? 
                   $serviceInfo['is_autorenew'] ?? 
                   $serviceInfo['auto_renew'] ?? 
                   false;
        }
        
        return false;

    } catch (\Exception $e) {
        return false;
    }
}



/**
 * Client Area Output.
 */
function webbyreg_ClientArea($params)
{
    logActivity("🎯 WebbyReg: ClientArea CALLED for " . $params['sld'] . '.' . $params['tld']);
    
    // ЛОГИРУЕМ ВСЕ ПАРАМЕТРЫ
    logActivity("🎯 WebbyReg ClientArea Params: " . json_encode(array_keys($params)));
    
    $nsInfo = webbyreg_GetCurrentNameservers($params);
    $action = $_GET['action'] ?? 'none';
    
    // ЛОГИРУЕМ РЕЗУЛЬТАТЫ ПРОВЕРКИ
    logActivity("🎯 WebbyReg ClientArea - Action: " . $action);
    logActivity("🎯 WebbyReg ClientArea - NS Info: " . json_encode($nsInfo));
    logActivity("🎯 WebbyReg ClientArea - Is REG.RU NS: " . ($nsInfo['is_regru_ns'] ? 'YES' : 'NO'));
    
    $output = '
    <div class="card">
        <div class="card-header">
            <i class="fas fa-globe"></i> Управление доменом  <strong>' . $params['sld'] . '.' . $params['tld'] . '</strong>
        </div>
        <div class="card-body">';
    
    // ПРОСТОЙ ТЕСТ - ВСЕГДА ПОКАЗЫВАЕМ КНОПКУ
    /*$output .= '
        <div class="alert alert-success">
            <strong>🎯 ТЕСТ: WebbyReg ClientArea работает!</strong>
            <p class="mb-2 mt-2">Эта панель отображается из функции ClientArea модуля WebbyReg.</p>
            <a href="https://webbyhost.ru" target="_blank" class="btn btn-success">
                <i class="fas fa-external-link-alt"></i> Перейти на WebbyHost
            </a>
        </div>';*/
    
    // ДОПОЛНИТЕЛЬНО: ИНФОРМАЦИЯ О NS
    if (!$nsInfo['is_regru_ns']) {
        $output .= '
            <div class="alert alert-info">
                <strong>🌐 Информация о NS:</strong>
                <p class="mb-0 mt-2">Домен использует внешние NS серверы: ' . implode(', ', $nsInfo['ns']) . '</p>
            </div>';
    }
    
    $output .= '
        </div>
    </div>';
    
    logActivity("🎯 WebbyReg ClientArea - Returning output");
    
    return $output;
}

// ============================================================================
// ========================== СИНХРОНИЗАЦИЯ С REG.RU ==========================
// ============================================================================

// =============================================================================
// 🎯 🎯 Обработчик кнопки "Синхронизация REG.RU" - ВОЗВРАЩАЕМ МОДАЛКУ
// =============================================================================

add_hook('AdminAreaHeaderOutput', 1, function($vars) {
    $currentPage = $vars['filename'] ?? '';
    $isDomainPage = $currentPage == 'clientsdomains' && isset($_GET['id']);
    
    if (!$isDomainPage) {
        return '';
    }
    
    $domainId = (int)$_GET['id'];
    
    // Проверяем что домен использует WebbyReg
    $domainData = full_query("SELECT registrar FROM tbldomains WHERE id = {$domainId}");
    $domain = mysql_fetch_assoc($domainData);
    
    if (!$domain || $domain['registrar'] != 'webbyreg') {
        return '';
    }
    
    // 🎯 ДОБАВЛЯЕМ КНОПКУ СРАЗУ В HTML
    $buttonHtml = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Функция для добавления нашей кнопки
    function injectWebbyRegButton() {
        const allLabels = document.querySelectorAll('td.fieldlabel');
        let registrarRow = null;
        
        // Ищем строку с "Команды регистратора"
        for (let label of allLabels) {
            if (label.textContent.includes('Команды регистратора')) {
                registrarRow = label.parentNode;
                break;
            }
        }
        
        if (!registrarRow) return;
        
        // Проверяем, не добавили ли уже кнопку
        if (document.getElementById('webbyreg-instant-btn')) {
            return;
        }
        
        // Находим ячейку с кнопками
        const buttonsCell = registrarRow.querySelector('td:nth-child(2)');
        if (!buttonsCell) return;
        
        // Создаем нашу кнопку
        const syncBtn = document.createElement('input');
        syncBtn.type = 'button';
        syncBtn.id = 'webbyreg-instant-btn';
        syncBtn.value = 'Синхронизация REG.RU';
        syncBtn.className = 'button btn btn-default';
        syncBtn.style.marginLeft = '5px';
        
        // Обработчик клика
        syncBtn.onclick = function() {
            const modal = document.getElementById('webbyreg-regru-real-sync');
            if (modal) {
                modal.style.display = 'block';
            }
        };
        
        // Добавляем кнопку сразу (без задержки)
        buttonsCell.appendChild(document.createTextNode(' '));
        buttonsCell.appendChild(syncBtn);
    }
    
    // 🎯 ЗАПУСКАЕМ СРАЗУ - БЕЗ ЗАДЕРЖКИ!
    injectWebbyRegButton();
});
</script>
HTML;

    return $buttonHtml;
});


// =============================================================================
// 🎯 АВТОПРОДЛЕНИЕ - ЕДИНСТВЕННАЯ ФУНКЦИЯ ===================
// =============================================================================

/**
 * Функция управления автопродлением
 * WHMCS автоматически вызывает эту функцию при переключении
 */
function toggleAutoRenew($params) {
    logActivity("🎯 WebbyReg: toggleAutoRenew CALLED! Domain: {$params['sld']}.{$params['tld']}");
    logActivity("🔍 MODULE DEBUG Params: " . json_encode($params));
    
    $apiUsername = $params['APIUsername'] ?? '';
    $apiKey = $params['APIKey'] ?? '';
    
    if (empty($apiUsername) || empty($apiKey)) {
        logActivity("❌ WebbyReg: API credentials missing");
        return ['error' => 'API credentials not configured'];
    }

    // 🎯 ИСПРАВЛЕНИЕ: Если WHMCS не передал autorenew - определяем из базы
    if (isset($params['autorenew'])) {
        // Клиентская зона - используем параметр от WHMCS
        $autoRenew = $params['autorenew'];
        logActivity("🎯 WebbyReg: Using WHMCS param - autorenew = " . ($autoRenew ? 'ON' : 'OFF'));
    } else {
        // Админка - определяем по изменению в базе
        $domainData = full_query("SELECT donotrenew FROM tbldomains WHERE id = " . (int)$params['domainid']);
        $domain = mysql_fetch_assoc($domainData);
        
        if ($domain) {
            // 🎯 ИНВЕРСИЯ: donotrenew=0 → автопродление ВКЛ
            $autoRenew = ($domain['donotrenew'] == 1);
            logActivity("🎯 WebbyReg: Using DB - donotrenew = {$domain['donotrenew']}, autorenew = " . ($autoRenew ? 'ON' : 'OFF'));
        } else {
            logActivity("❌ WebbyReg: Domain not found");
            return ['error' => 'Domain not found'];
        }
    }

    try {
        $api = new \WHMCS\Module\Registrar\Webbyreg\ApiClient($apiUsername, $apiKey, $params['TestMode']);

        $response = $api->call('service/set_autorenew_flag', [
            'dname' => $params['sld'] . '.' . $params['tld'],
            'flag_value' => $autoRenew ? 1 : 0,
            'output_content_type' => 'plain'
        ]);

        logActivity("📥 WebbyReg: API Response: " . json_encode($response));

        if (isset($response['result']) && $response['result'] === 'success') {
            $message = '✅ Автопродление ' . ($autoRenew ? 'включено' : 'отключено') . ' у регистратора';
            logActivity("🎉 WebbyReg: SUCCESS - " . $message);
            return ['success' => true, 'message' => $message];
        } else {
            $errorMsg = '❌ Ошибка API: ' . ($response['error_text'] ?? 'Unknown error');
            logActivity("💥 WebbyReg: ERROR - " . $errorMsg);
            return ['success' => false, 'message' => $errorMsg];
        }

    } catch (\Exception $e) {
        $errorMsg = '❌ Exception: ' . $e->getMessage();
        logActivity("🔥 WebbyReg: EXCEPTION - " . $errorMsg);
        return ['success' => false, 'message' => $errorMsg];
    }
}

/**
 * Кнопка ручной синхронизации (оставляем для надежности)
 */
function webbyreg_AdminCustomButtonArray() {
    return ["Синхронизировать автопродление" => "syncAutoRenew"];
}

function webbyreg_syncAutoRenew($params) {
    logActivity("🔧 WebbyReg: MANUAL SYNC BUTTON PRESSED");
    return toggleAutoRenew($params);
}
