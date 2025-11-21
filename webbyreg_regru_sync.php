<?php
// /includes/hooks/webbyreg_regru_real_sync.php
// WebbyReg + REG.RU Real Sync Hook
// Версия: 2.0 (очищенная и оптимизированная)

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// =============================================================================
// 🎯 ОСНОВНОЙ HOOK: Панель синхронизации на странице домена
// =============================================================================

add_hook('AdminAreaHeaderOutput', 1, function($vars) {
    
    $currentPage = $vars['filename'] ?? '';
    $isDomainPage = $currentPage == 'clientsdomains' && isset($_GET['id']);
    
    if ($isDomainPage) {
        
        $domainId = (int)$_GET['id'];
        
        // 🎯 Проверяем что домен использует WebbyReg
        $domainData = full_query("SELECT domain, registrar FROM tbldomains WHERE id = {$domainId}");
        $domain = mysql_fetch_assoc($domainData);
        
        if (!$domain || $domain['registrar'] != 'webbyreg') {
            return ''; // Не наш регистратор
        }
        
        logActivity("🎯 WEBBYREG: Loading domain {$domainId} for sync panel");
        
        // 🎯 Безопасно экранируем переменные для JavaScript
        $jsDomainId = $domainId;
        $jsDomainName = addslashes($domain['domain']);
        
        return <<<HTML
<div id="webbyreg-regru-real-sync" style="
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 10000;
        background: linear-gradient(135deg, #5698ff 0%, #0250c8 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        text-align: center;
        min-width: 400px;
        display: none;
    ">
    <h3 style="color: white; margin-top: 0;">🎯 WebbyReg + REG.RU</h3>
    <p><strong>Синхронизация данных домена</strong></p>
    
    <button type="button" id="webbyreg-regru-real-btn" style="    
            background: #6fb839;
            color: white;
            border: none;
            padding: 9px 15px;
            font-size: 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            margin: 10px;">
        🔄 Синхронизировать
    </button>
    
    <button type="button" id="webbyreg-close-panel" style="
            background: #01327e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            margin: 10px;">
        ❌ Закрыть
    </button>
    
    <button type="button" id="webbyreg-debug-fields" style="
        background: #ff9900; 
        color: white; 
        border: none; 
        padding: 8px 15px; 
        border-radius: 6px; 
        cursor: pointer; 
        margin: 5px;">
    🔍 Диагностика полей
</button>

    <div style="margin-top: 15px; font-size: 12px;">
        Домен: <strong>{$domain['domain']}</strong><br>
        ID: <strong>{$domainId}</strong>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    console.log('🎯 WEBBYREG: Sync panel loaded for domain {$jsDomainName}');
    
    var syncPanel = document.getElementById('webbyreg-regru-real-sync');
    var syncBtn = document.getElementById('webbyreg-regru-real-btn');
    var closeBtn = document.getElementById('webbyreg-close-panel');
    var debugBtn = document.getElementById('webbyreg-debug-fields');
    
    // 🎯 СРАЗУ СКРЫВАЕМ МОДАЛКУ - показываем только по кнопке
    if (syncPanel) {
        syncPanel.style.display = 'none';
    }
    
    if (!syncBtn || !closeBtn || !debugBtn) {
        console.error('❌ WEBBYREG: Buttons not found!');
        return;
    }
    
    // 🎯 Закрытие панели
    closeBtn.addEventListener('click', function() {
        syncPanel.style.display = 'none';
        console.log('✅ WEBBYREG: Panel closed');
    });
    
    // 🎯 Диагностика полей
    debugBtn.addEventListener('click', function() {
        console.log('🔍 WEBBYREG: Simple field scan...');
        
        var allElements = document.querySelectorAll('input, select, textarea');
        var allFields = [];
        
        allElements.forEach(function(element) {
            var name = element.name || 'no-name';
            var value = element.value || '(empty)';
            var type = element.type || 'none';
            
            // Просто собираем все поля
            allFields.push({
                name: name,
                value: value,
                type: type
            });
        });
        
        // Выводим ВСЕ поля в консоль
        console.log('📋 WEBBYREG ALL FIELDS:');
        allFields.forEach(function(field, index) {
            console.log((index + 1) + '. ' + field.name + ' (' + field.type + ') = "' + field.value + '"');
        });
        
        // Ищем поле телефона вручную
        var phoneFields = allFields.filter(function(field) {
            return field.name.toLowerCase().includes('phone') || 
                   field.name.toLowerCase().includes('tel') ||
                   field.value.match(/\+7|8\d{10}|\d{11}/);
        });
        
        if (phoneFields.length > 0) {
            console.log('📞 WEBBYREG PHONE FIELDS FOUND:');
            phoneFields.forEach(function(field) {
                console.log('→ ' + field.name + ' = "' + field.value + '"');
            });
        }
        
        showMessage('success', 'Проверьте консоль (F12) для списка полей');
    });
    

    // 🎯 Синхронизация с REG.RU
    syncBtn.addEventListener('click', async function() {
        var originalText = syncBtn.innerHTML;
        syncBtn.innerHTML = '🔄 Запрос...';
        syncBtn.disabled = true;
        
        console.log('🎯 WEBBYREG: Starting sync for domain ID {$jsDomainId}');
        
        try {
            const response = await fetch('index.php?webbyreg_regru_real_sync=1&domainid={$jsDomainId}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });
            
            const responseText = await response.text();
            console.log('📡 WEBBYREG Response:', responseText);
            
            if (responseText.trim().startsWith('{')) {
                const result = JSON.parse(responseText);
                
                if (result.success) {
                    syncBtn.innerHTML = '✅ Успешно!';
                    showMessage('success', result.message);
                    
                    // Заполняем форму данными
                    if (result.registrant_data) {
                        var filledCount = fillFormWithRealData(result.registrant_data);
                        showMessage('success', 'Заполнено ' + filledCount + ' полей');
                    }
                    
                } else {
                    syncBtn.innerHTML = '❌ Ошибка';
                    showMessage('error', result.message);
                }
            } else {
                throw new Error('API вернул HTML вместо JSON');
            }
            
        } catch (error) {
            console.error('❌ WEBBYREG Error:', error);
            syncBtn.innerHTML = '❌ Ошибка';
            showMessage('error', error.message);
        }
        
        setTimeout(() => {
            syncBtn.innerHTML = originalText;
            syncBtn.disabled = false;
        }, 5000);
    });
    
    // 🎯 Заполнение формы данными
     function fillFormWithRealData(registrantData) {
        console.log('🎯 WEBBYREG: Filling form with data:', registrantData);
        
        // 🎯 ДИАГНОСТИКА ПОЛЯ СТРАНЫ
        var countrySelects = document.querySelectorAll('select');
        console.log('🔍 WEBBYREG: All select fields on page:');
        countrySelects.forEach(function(select, index) {
            if (select.name && select.name.toLowerCase().includes('country')) {
                console.log('=== COUNTRY FIELD ===');
                console.log('Name:', select.name);
                console.log('ID:', select.id);
                console.log('Current value:', select.value);
                console.log('Options:', Array.from(select.options).map(opt => 
                    'value: "' + opt.value + '", text: "' + opt.text + '"'
                ));
            }
        });
        
        // 🎯 ДИАГНОСТИКА - какие domainfield[] есть на странице
        var domainFields = document.querySelectorAll('[name^="domainfield["]');
        console.log('🔍 WEBBYREG: All domainfield[]:');
        domainFields.forEach(function(field) {
            var match = field.name.match(/domainfield\[(\d+)\]/);
            if (match) {
                console.log(field.name, 'type:', field.type, 'value:', field.value);
            }
        });
        
        var filledCount = 0;
        var allInputs = document.querySelectorAll('input, select, textarea');
        
        console.log('🔍 WEBBYREG: Scanning ' + allInputs.length + ' fields on page...');
        
        allInputs.forEach(function(input) {
            if (!input.name) return;
            
            var fieldName = input.name.toLowerCase();
            
            // 🎯 Логика для domainfield[]
            if (fieldName.startsWith('domainfield[')) {
                var fieldIndex = parseInt(fieldName.match(/domainfield\[(\d+)\]/)[1]);
                var fieldType = getDomainFieldType(fieldIndex);
                
                if (fieldType && registrantData[fieldType]) {
                    input.value = registrantData[fieldType];
                    console.log('✅ WEBBYREG: Filled domainfield[' + fieldIndex + '] with ' + fieldType + ':', registrantData[fieldType]);
                    filledCount++;
                    
                    var event = new Event('change', { bubbles: true });
                    input.dispatchEvent(event);
                }
            }
        });
        
        console.log('✅ WEBBYREG: Universal fill completed. Filled ' + filledCount + ' fields');
        return filledCount;
    }
    
    // 🎯 Сопоставление domainfield[] индексов - ОБНОВЛЕННОЕ
    function getDomainFieldType(index) {
        var fieldMapping = {
            '1': 'birthdate',         // Дата рождения физ. лица
            '2': 'passportnumber',    // Паспортные данные
            '4': 'passportissuer',    // Кем выдан паспорт
            '8': 'fax',               // Факс
            '9': 'middlename',        // Отчество
            '10': 'birthdate',        // Дата рождения
            '11': 'passportnumber',   // Паспортные данные
            '12': 'passportissuedate', // Дата выдачи паспорта
            '13': 'passportissuedate', // Дата выдачи паспорта
            '14': 'smsphone',         // Телефонный номер SMS-безопасности ← ИЗМЕНИЛИ
            '15': 'transferemail',    // Email для переноса
            '16': 'latinname',        // ФИО на латинице
            '17': 'postalcode',       // Почтовый индекс
            '18': 'region',           // Область
            '19': 'city',             // Город
            '20': 'streetaddress',    // Адрес
            '21': 'recipient',        // Получатель
            '22': 'country',          // Страна
            // 🎯 ДОБАВИМ ДОПОЛНИТЕЛЬНЫЕ ПОЛЯ:
            '23': 'phone'             // Обычный телефон ← НОВОЕ ПОЛЕ
        };
        
        return fieldMapping[index] || null;
    }

    
    // 🎯 Функция показа сообщений
    function showMessage(type, text) {
        var oldAlerts = document.querySelectorAll('.webbyreg-alert');
        oldAlerts.forEach(function(alert) { alert.remove(); });
        
        var alertClass = 'alert-' + type;
        var alertIcon = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
        
        var alertHtml = '<div class="alert ' + alertClass + ' alert-dismissible webbyreg-alert" style="position: fixed; top: 20px; right: 20px; z-index: 10001; min-width: 400px;">' +
            '<button type="button" class="close" onclick="this.parentElement.remove()" style="float: right; background: none; border: none; font-size: 20px; cursor: pointer;">×</button>' +
            '<strong>' + alertIcon + ' WebbyReg:</strong> ' + text +
            '</div>';
        
        document.body.insertAdjacentHTML('beforeend', alertHtml);
        
        setTimeout(function() {
            var alertElement = document.querySelector('.webbyreg-alert:last-child');
            if (alertElement) alertElement.remove();
        }, 5000);
    }
});

// Добавляем кнопку на страницу доменов
function addSyncButton() {
    // Ищем заголовок страницы
    const pageHeader = document.querySelector('.page-header h1');
    if (!pageHeader) return;
    
    // Создаем кнопку
    const syncButton = document.createElement('button');
    syncButton.type = 'button';
    syncButton.className = 'btn btn-primary';
    syncButton.innerHTML = '🔄 Синхронизация REG.RU';
    syncButton.style.marginLeft = '15px';
    syncButton.onclick = function() {
        // Получаем выбранный домен
        const domainSelect = document.querySelector('select[name="domainid"]');
        const domainId = domainSelect ? domainSelect.value : '';
        
        if (domainId) {
            // Загружаем данные для выбранного домена
            loadDomainData(domainId);
        } else {
            alert('Пожалуйста, выберите домен из списка');
        }
    };
    
    // Вставляем кнопку после заголовка
    pageHeader.parentNode.insertBefore(syncButton, pageHeader.nextSibling);
}

// Вызываем при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(addSyncButton, 1000);
});
</script>
HTML;
    }
    
    return '';
});

// =============================================================================
// 🎯 AJAX ОБРАБОТЧИК: Синхронизация данных
// =============================================================================

add_hook('AdminAreaHeaderOutput', 1, function($vars) {
    
    if (isset($_GET['webbyreg_regru_real_sync']) && isset($_GET['domainid'])) {
        
        // Отключаем вывод WHMCS
        $_GET['skipTemplate'] = true;
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        $domainId = (int)$_GET['domainid'];
        logActivity("🚨 WEBBYREG: AJAX sync for domain {$domainId}");
        
        // Получаем данные домена
        $domainData = full_query("SELECT domain, userid FROM tbldomains WHERE id = {$domainId}");
        $domain = mysql_fetch_assoc($domainData);
        
        if (!$domain) {
            echo json_encode(['success' => false, 'message' => 'Домен не найден']);
            exit;
        }
        
        // 🎯 Получаем данные из WHMCS
        $apiResult = getRegistrantDataFromWHMCS($domain['domain']);
        
        if ($apiResult['success']) {
            logActivity("✅ WEBBYREG: Successfully got data for domain {$domainId}");
            echo json_encode([
                'success' => true,
                'message' => 'Данные загружены из профиля клиента WHMCS',
                'registrant_data' => $apiResult['data']
            ]);
        } else {
            logActivity("❌ WEBBYREG: Failed to get data for domain {$domainId}");
            echo json_encode(['success' => false, 'message' => $apiResult['message']]);
        }
        
        exit;
    }
    
    return '';
});

// =============================================================================
// 🎯 ОСНОВНЫЕ ФУНКЦИИ
// =============================================================================

/**
 * 🎯 РЕАЛЬНАЯ функция получения данных из REG.RU API
 */
function getRegistrantDataFromWHMCS($domain) {
    
    logActivity("📡 WEBBYREG: Getting REAL data from REG.RU API for domain {$domain}");
    
    $settings = getWebbyRegModuleSettings();
    
    if (!$settings || !isset($settings['Username'])) {
        return getWHMCSDataAsFallback($domain, "Настройки username не найдены");
    }
    
    $password = $settings['Password'] ?? '';
    
    $apiData = [
        'username' => $settings['Username'],
        'password' => $password,
        'domain_name' => $domain,
        'output_format' => 'json'
    ];
    
    $apiUrl = 'https://api.reg.ru/api/regru2/service/get_details';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($apiData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logActivity("📡 WEBBYREG: REG.RU API Response - HTTP {$httpCode}");
    
    if ($httpCode === 200) {
        $apiResult = json_decode($response, true);
        
        if ($apiResult && $apiResult['result'] == 'success') {
            logActivity("✅ WEBBYREG: Successfully got REAL data from REG.RU");
            
            // 🎯 ВАЖНО: вызываем парсер для реальных данных!
            $registrantData = parseRegRuServiceDetails($apiResult);
            
            // 🎯 ДИАГНОСТИКА: что вернул парсер?
            logActivity("🔍 WEBBYREG: Parser returned: " . print_r($registrantData, true));
            
            return [
                'success' => true,
                'data' => $registrantData, // 🎯 РЕАЛЬНЫЕ данные!
                'message' => 'Данные успешно получены из REG.RU API'
            ];
        } else {
            $error = $apiResult['error_text'] ?? 'Unknown error';
            logActivity("❌ WEBBYREG: REG.RU API Error: {$error}");
            return getWHMCSDataAsFallback($domain, $error);
        }
    } else {
        logActivity("❌ WEBBYREG: REG.RU API HTTP Error: {$httpCode}");
        return getWHMCSDataAsFallback($domain, "HTTP Error: {$httpCode}");
    }
}




// 🎯 ПРОВЕРКА ЗАГРУЗКИ НАСТРОЕК
add_hook('AdminAreaHeaderOutput', 1, function($vars) {
    if (isset($_GET['check_webbyreg_config'])) {
        
        $settings = getWebbyRegModuleSettings();
        
        header('Content-Type: text/plain; charset=utf-8');
        echo "=== ПРОВЕРКА НАСТРОЕК WEBBYREG ===\n";
        
        if ($settings) {
            foreach ($settings as $key => $value) {
                // Скрываем пароли для безопасности
                $displayValue = (strpos(strtolower($key), 'password') !== false || strpos(strtolower($key), 'key') !== false) 
                    ? '***HIDDEN***' 
                    : $value;
                echo "{$key}: {$displayValue}\n";
            }
        } else {
            echo "No settings loaded!\n";
        }
        
        // Проверяем существование файла конфига
        $configFile = __DIR__ . '/../../modules/registrars/webbyreg/webbyreg_regru_config.php';
        echo "\nConfig file: {$configFile}\n";
        echo "File exists: " . (file_exists($configFile) ? 'YES' : 'NO') . "\n";
        
        if (file_exists($configFile)) {
            echo "File content (first 200 chars):\n" . substr(file_get_contents($configFile), 0, 200) . "\n";
        }
        
        exit;
    }
    return '';
});




/**
 * 🎯 Пробуем авторизацию с паролем
 */
function tryRegRuApiWithPassword($settings, $domain) {
    logActivity("🔑 WEBBYREG: Trying password authorization...");
    
    $apiData = [
        'username' => $settings['Username'],
        'password' => $settings['Password'],
        'domain_name' => $domain,
        'output_content_type' => 'plain'
    ];
    
    $apiUrl = 'https://api.reg.ru/api/regru2/service/get_details';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($apiData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return processApiResponse($response, $httpCode, $domain);
}

/**
 * 🎯 Обработка ответа API
 */
function processApiResponse($response, $httpCode, $domain) {
    logActivity("📡 WEBBYREG: REG.RU API Response - HTTP {$httpCode}");
    
    if ($httpCode === 200) {
        $apiResult = json_decode($response, true);
        
        if ($apiResult && $apiResult['result'] == 'success') {
            $registrantData = parseRegRuServiceDetails($apiResult);
            logActivity("✅ WEBBYREG: Successfully got REAL data from REG.RU");
            
            return [
                'success' => true,
                'data' => $registrantData,
                'message' => 'Данные успешно получены из REG.RU API'
            ];
        } else {
            $error = $apiResult['error_text'] ?? 'Unknown error';
            logActivity("❌ WEBBYREG: REG.RU API Error: {$error}");
            return getWHMCSDataAsFallback($domain, $error);
        }
    } else {
        logActivity("❌ WEBBYREG: REG.RU API HTTP Error: {$httpCode}");
        return getWHMCSDataAsFallback($domain, "HTTP Error: {$httpCode}");
    }
}

// 🎯 ДИАГНОСТИКА НАСТРОЕК MODULE
add_hook('AdminAreaHeaderOutput', 1, function($vars) {
    if (isset($_GET['debug_webbyreg_settings'])) {
        
        $settings = getWebbyRegModuleSettings();
        
        header('Content-Type: text/plain; charset=utf-8');
        echo "=== WEBBYREG SETTINGS DEBUG ===\n";
        
        if ($settings) {
            foreach ($settings as $key => $value) {
                // Скрываем пароли для безопасности
                $displayValue = (strpos(strtolower($key), 'password') !== false) ? '***HIDDEN***' : $value;
                echo "{$key}: {$displayValue}\n";
            }
        } else {
            echo "No settings found!\n";
        }
        
        exit;
    }
    return '';
});



/**
 * 🎯 Запасной вариант - данные из WHMCS
 */
function getWHMCSDataAsFallback($domain, $apiError) {
    logActivity("🔄 WEBBYREG: Using WHMCS data as fallback. API Error: {$apiError}");
    
    $domainData = full_query("SELECT id, userid FROM tbldomains WHERE domain = '{$domain}'");
    $domainInfo = mysql_fetch_assoc($domainData);
    
    if (!$domainInfo) {
        return ['success' => false, 'message' => 'Домен не найден в WHMCS'];
    }
    
    $userId = $domainInfo['userid'];
    $clientData = full_query("SELECT firstname, lastname, email, phonenumber, address1, address2, city, state, postcode, country FROM tblclients WHERE id = {$userId}");
    $client = mysql_fetch_assoc($clientData);
    
    if (!$client) {
        return ['success' => false, 'message' => 'Клиент не найден'];
    }
    
    $registrantData = convertClientToRegistrantData($client, $domain);
    
    return [
        'success' => true,
        'data' => $registrantData,
        'message' => 'Данные из WHMCS (REG.RU API недоступен: ' . $apiError . ')'
    ];
}

/**
 * 🎯 Форматируем дату для формы (DD.MM.YYYY)
 */
function formatDateForForm($date) {
    if (empty($date)) return '';
    
    logActivity("🔍 WEBBYREG: Formatting date: {$date}");
    
    // Если дата в формате "1975-07-10" → "10.07.1975"
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
        $formatted = "{$matches[3]}.{$matches[2]}.{$matches[1]}";
        logActivity("🔍 WEBBYREG: Date formatted: {$date} → {$formatted}");
        return $formatted;
    }
    
    // Если дата уже в формате "10.07.1975" - оставляем как есть
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
        logActivity("🔍 WEBBYREG: Date already in correct format: {$date}");
        return $date;
    }
    
    logActivity("🔍 WEBBYREG: Date format unknown: {$date}");
    return $date;
}

/**
 * 🎯 Парсим РЕАЛЬНЫЕ данные из ответа service/get_details
 */
function parseRegRuServiceDetails($apiResult) {
    
    $service = $apiResult['answer']['services'][0] ?? [];
    $details = $service['details'] ?? [];
    
    // 🎯 Парсим паспортные данные
    $passportData = parsePassportData($details['passport'] ?? '');
    
    // 🎯 Парсим адрес
    $addressData = parseAddressData($details['p_addr'] ?? '');
    
    return [
        'fax' => $details['fax'] ?? '',
        'middlename' => extractMiddlename($details['person_r'] ?? ''),
        'birthdate' => formatDateForForm($details['birth_date'] ?? ''),
        'passportnumber' => $passportData['number'] ?? '',
        'passportissuer' => $passportData['issuer'] ?? '',
        'passportissuedate' => formatDateForForm($passportData['issue_date'] ?? ''),
        'smsphone' => $details['sms_security_number'] ?? '',
        'transferemail' => $details['transfer_email'] ?? '',
        'latinname' => $details['person'] ?? '',
        'postalcode' => $addressData['postalcode'] ?? '',
        'region' => $addressData['region'] ?? '',
        'city' => $addressData['city'] ?? '',
        'streetaddress' => $addressData['address'] ?? '',
        'recipient' => $details['person_r'] ?? '',
        'country' => mapCountryToSelectFormat($details['country'] ?? ''), // 🎯 Исправленный формат!
        'phone' => $details['phone'] ?? ''
    ];
}

/**
 * 🎯 Преобразуем код страны в формат для select поля
 */
function mapCountryToSelectFormat($countryCode) {
    $countryMap = [
        'RU' => 'RU=Россия',
        'US' => 'US=США',
        'DE' => 'DE=Германия',
        'FR' => 'FR=Франция',
        // Добавь другие страны по необходимости
    ];
    
    return $countryMap[$countryCode] ?? $countryCode;
}

/**
 * 🎯 Парсим паспортные данные из строки
 */
function parsePassportData($passportString) {
    if (empty($passportString)) return [];
    
    logActivity("🔍 WEBBYREG: Parsing passport: {$passportString}");
    
    // Формат: "60 10 855149, выдан Отделом УФМС России по Ростовской области в городе Азове, 27.10.2010"
    $parts = explode(', выдан ', $passportString);
    
    $passportNumber = trim($parts[0] ?? '');
    $rest = $parts[1] ?? '';
    
    $issuer = '';
    $issueDate = '';
    
    if (!empty($rest)) {
        // 🎯 ПРОСТОЙ СПОСОБ: разделяем по последней запятой
        $lastCommaPos = strrpos($rest, ',');
        
        if ($lastCommaPos !== false) {
            $issuer = trim(substr($rest, 0, $lastCommaPos));
            $issueDate = trim(substr($rest, $lastCommaPos + 1));
        } else {
            // Если нет запятой - вся строка это issuer
            $issuer = $rest;
        }
    }
    
    // 🎯 ВРЕМЕННО: МЕНЯЕМ МЕСТАМИ issuer и issueDate чтобы проверить
    $temp = $issuer;
    $issuer = $issueDate;
    $issueDate = $temp;
    
    logActivity("🔍 WEBBYREG: Passport parsed - Number: {$passportNumber}, Issuer: {$issuer}, Date: {$issueDate}");
    
    return [
        'number' => $passportNumber,
        'issuer' => $issuer,
        'issue_date' => $issueDate
    ];
}

/**
 * 🎯 Парсим адрес из строки
 */
function parseAddressData($addressString) {
    if (empty($addressString)) return [];
    
    // Формат: "346780, Ростовская область, Ростов-на-Дону, ул.2-я Володарского д.168 кв.197, Репяхова Надежда Михайловна"
    $parts = explode(', ', $addressString);
    
    return [
        'postalcode' => $parts[0] ?? '',
        'region' => $parts[1] ?? '',
        'city' => $parts[2] ?? '',
        'address' => $parts[3] ?? '',
        'recipient' => $parts[4] ?? ''
    ];
}

/**
 * 🎯 Извлекаем отчество из ФИО
 */
function extractMiddlename($fullName) {
    $parts = explode(' ', $fullName);
    return $parts[2] ?? ''; // Третье слово - отчество
}

/**
 * 🎯 Форматируем дату в стандартный формат
 */
function formatDate($date) {
    if (empty($date)) return '';
    
    // Преобразуем "03.10.1990" в "1990-10-03"
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $matches)) {
        return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
    }
    
    return $date;
}

/**
 * Получаем данные регистранта ИЗ WHMCS

function getRegistrantDataFromWHMCS($domain) {
    
    logActivity("📡 WEBBYREG: Getting data from WHMCS for domain {$domain}");
    
    // Получаем ID домена
    $domainData = full_query("SELECT id, userid FROM tbldomains WHERE domain = '{$domain}'");
    $domainInfo = mysql_fetch_assoc($domainData);
    
    if (!$domainInfo) {
        return ['success' => false, 'message' => 'Домен не найден в WHMCS'];
    }
    
    $userId = $domainInfo['userid'];
    
    // Получаем основные данные клиента
    $clientData = full_query("
        SELECT firstname, lastname, email, phonenumber, 
               companyname, address1, address2, city, state, postcode, country
        FROM tblclients WHERE id = {$userId}
    ");
    $client = mysql_fetch_assoc($clientData);
    
    if (!$client) {
        return ['success' => false, 'message' => 'Клиент не найден'];
    }
    
    logActivity("✅ WEBBYREG: Found client data for domain {$domain}");
    
    // Преобразуем данные клиента
    $registrantData = convertClientToRegistrantData($client, $domain);
    
    return [
        'success' => true,
        'data' => $registrantData,
        'message' => 'Данные загружены из профиля клиента'
    ];
}
 */
/**
 * Конвертация данных клиента в данные регистранта - С ПРАВИЛЬНЫМ ФОРМАТОМ ТЕЛЕФОНА
 
function convertClientToRegistrantData($client, $domain) {
    
    $fullName = trim($client['firstname'] . ' ' . $client['lastname']);
    $phone = formatPhoneNumber($client['phonenumber'] ?? '');
    
    return [
        'fax' => $phone,
        'middlename' => '', // 🎯 ПОКА ПУСТО - нужно настроить
        'birthdate' => '',  // 🎯 ПОКА ПУСТО - нужно настроить
        'passportnumber' => '', // 🎯 ПОКА ПУСТО - нужно настроить
        'passportissuer' => '', // 🎯 ПОКА ПУСТО - нужно настроить
        'passportissuedate' => '', // 🎯 ПОКА ПУСТО - нужно настроить
        'smsphone' => $phone,
        'transferemail' => $client['email'] ?? '',
        'latinname' => transliterateName($fullName),
        'postalcode' => $client['postcode'] ?? '',
        'region' => $client['state'] ?? '',
        'city' => $client['city'] ?? '',
        'streetaddress' => ($client['address1'] ?? '') . ($client['address2'] ? ', ' . $client['address2'] : ''),
        'recipient' => $fullName,
        'country' => $client['country'] ?? 'RU',
        'phone' => $phone
    ];
}*/

// 🎯 ВРЕМЕННЫЙ ХУК - тест REG.RU API
add_hook('AdminAreaHeaderOutput', 1, function($vars) {
    if (isset($_GET['test_regru_api_real']) && isset($_GET['domainid'])) {
        
        $domainId = (int)$_GET['domainid'];
        $domainData = full_query("SELECT domain FROM tbldomains WHERE id = {$domainId}");
        $domain = mysql_fetch_assoc($domainData);
        
        if (!$domain) {
            echo "Домен не найден";
            exit;
        }
        
        $settings = getWebbyRegModuleSettings();
        if (!$settings) {
            echo "Настройки не найдены";
            exit;
        }
        
        header('Content-Type: text/plain; charset=utf-8');
        echo "=== ТЕСТ REG.RU API ===\n";
        echo "Домен: {$domain['domain']}\n";
        echo "Логин: {$settings['Username']}\n\n";
        
        // Тестовый запрос
        $apiData = [
            'username' => $settings['Username'],
            'password' => $settings['Password'],
            'domain_name' => $domain['domain'], // Пробуем с domain_name
            'output_content_type' => 'plain'
        ];
        
        $apiUrl = 'https://api.reg.ru/api/regru2/service/get_details';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($apiData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "HTTP Code: {$httpCode}\n";
        echo "Response:\n{$response}\n";
        
        exit;
    }
    return '';
});


/**
 * 🎯 Функция форматирования номера телефона
 */
function formatPhoneNumber($phone) {
    if (empty($phone)) {
        return '';
    }
    
    // Убираем все нецифровые символы
    $cleanPhone = preg_replace('/[^\d]/', '', $phone);
    
    // Если номер начинается с 7 или 8 (российский номер)
    if (preg_match('/^[78]\d{10}$/', $cleanPhone)) {
        $cleanPhone = '7' . substr($cleanPhone, -10); // Приводим к формату 7XXXXXXXXXX
    }
    
    // Форматируем в красивый вид: +7 XXX XXX-XX-XX
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '7') {
        return '+7 ' . substr($cleanPhone, 1, 3) . ' ' . substr($cleanPhone, 4, 3) . '-' . substr($cleanPhone, 7, 2) . '-' . substr($cleanPhone, 9, 2);
    }
    
    // Форматируем в красивый вид: +7 (XXX) XXX-XX-XX
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '7') {
        return '+7 (' . substr($cleanPhone, 1, 3) . ') ' . substr($cleanPhone, 4, 3) . '-' . substr($cleanPhone, 7, 2) . '-' . substr($cleanPhone, 9, 2);
    }
    
    // Если не удалось отформатировать, возвращаем оригинал
    return $phone;
}


/**
 * Транслитерация имени на латиницу
 */
function transliterateName($name) {
    $translit = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ы' => 'y', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya'
    ];
    
    $name = mb_strtolower($name, 'UTF-8');
    $name = strtr($name, $translit);
    return ucwords($name);
}

// =============================================================================
// 🎯 ДОПОЛНИТЕЛЬНЫЕ ФУНКЦИИ (закомментированы для будущего использования)
// =============================================================================

/*
// 🎯 Функция получения настроек модуля WebbyReg
*/
function getWebbyRegModuleSettings() {
    
    $configFile = __DIR__ . '/../../modules/registrars/webbyreg/webbyreg_regru_config.php';
    
    if (!file_exists($configFile)) {
        logActivity("❌ WEBBYREG: Config file not found: {$configFile}");
        return null;
    }
    
    // 🎯 Читаем конфиг который возвращает массив
    $config = include $configFile;
    
    if (!is_array($config)) {
        logActivity("❌ WEBBYREG: Config file does not return array");
        return null;
    }
    
    // 🎯 Преобразуем в наш формат
    $settings = [];
    
    if (isset($config['username'])) $settings['Username'] = $config['username'];
    if (isset($config['password'])) $settings['Password'] = $config['password'];
    if (isset($config['api_username'])) $settings['APIUsername'] = $config['api_username'];
    if (isset($config['api_key'])) $settings['APIKey'] = $config['api_key'];
    if (isset($config['reseller_id'])) $settings['ResellerID'] = $config['reseller_id'];
    
    logActivity("✅ WEBBYREG: Loaded settings from config: " . implode(', ', array_keys($settings)));
    
    return !empty($settings) ? $settings : null;
}

// 🎯 Функция получения дополнительных полей клиента
function getClientCustomFields($userId) {
    $customFields = [];
    $result = full_query("
        SELECT f.fieldname, v.value 
        FROM tblcustomfieldsvalues v
        JOIN tblcustomfields f ON f.id = v.fieldid
        WHERE v.relid = {$userId}
    ");
    
    while ($row = mysql_fetch_assoc($result)) {
        $customFields[$row['fieldname']] = $row['value'];
    }
    
    return $customFields;
}
