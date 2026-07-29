<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bitrix24.php';
require_once __DIR__ . '/docx.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $action = $_REQUEST['action'] ?? '';

    // Получить следующий номер договора (без инкремента)
    if ($action === 'get_number') {
        $b24 = Bitrix24API::fromTokens();
        if (!$b24) {
            echo json_encode(['number' => CONTRACT_PREFIX . '0001']);
            exit;
        }
        echo json_encode(['number' => $b24->previewNextContractNumber()]);
        exit;
    }

    // Генерация договора
    if ($action === 'generate') {
        $b24 = Bitrix24API::fromTokens();
        if (!$b24) {
            throw new RuntimeException('Приложение не авторизовано. Переустановите приложение.');
        }

        $guestType = $_POST['guest_type'] ?? '';
        $service = $_POST['service'] ?? '';
        $contractDate = $_POST['contract_date'] ?? date('Y-m-d');
        $contractAmount = floatval($_POST['contract_amount'] ?? 0);
        $ndsEnabled = !empty($_POST['nds_enabled']);
        $ndsPercent = floatval($_POST['nds_percent'] ?? DEFAULT_VAT);

        if (!$service) throw new RuntimeException('Выберите услугу');
        if ($contractAmount <= 0) throw new RuntimeException('Укажите сумму договора');

        // 1. Получить номер договора
        $contractNumber = $b24->getNextContractNumber();

        // 2. Найти storage проекта "Туган Як"
        $storage = $b24->findStorageByName(PROJECT_NAME);
        if (!$storage) {
            throw new RuntimeException('Не найден диск проекта «' . PROJECT_NAME . '»');
        }
        $storageId = $storage['ID'];

        // 3. Найти/создать папку шаблонов
        $templateFolder = $b24->ensureFolder($storageId, FOLDER_TEMPLATES, $storageId);

        // 4. Найти шаблон по названию услуги
        $templateFile = $b24->findTemplateFile($storageId, $templateFolder['ID'], $service);
        if (!$templateFile) {
            throw new RuntimeException('Шаблон для услуги «' . $service . '» не найден в папке «' . FOLDER_TEMPLATES . '»');
        }

        // 5. Скачать шаблон
        $templateContent = $b24->downloadFile($templateFile['ID']);

        // 6. Подготовить данные для подстановки
        $ndsAmount = $ndsEnabled ? round($contractAmount * $ndsPercent / 100, 2) : 0;

        $placeholders = [
            'CONTRACT_NUMBER' => $contractNumber,
            'CONTRACT_DATE' => $contractDate,
            'SERVICE_NAME' => $service,
            'CONTRACT_AMOUNT' => number_format($contractAmount, 2, '.', ' '),
            'CONTRACT_AMOUNT_TEXT' => num2str($contractAmount),
            'NDS_ENABLED' => $ndsEnabled ? 'в т.ч. НДС' : 'без НДС',
            'NDS_PERCENT' => $ndsEnabled ? (string)$ndsPercent : '0',
            'NDS_AMOUNT' => $ndsEnabled ? number_format($ndsAmount, 2, '.', ' ') : '0.00',
            'NDS_AMOUNT_TEXT' => $ndsEnabled ? num2str($ndsAmount) : '0',
            'CURRENT_DATE' => date('Y-m-d'),
            'CURRENT_YEAR' => date('Y'),
        ];

        if ($guestType === 'individual') {
            $fullName = $_POST['full_name'] ?? '';

            $placeholders = array_merge($placeholders, [
                'GUEST_TYPE' => 'Физическое лицо',
                'FULL_NAME' => $fullName,
                'PASSPORT_NUMBER' => $_POST['passport_number'] ?? '',
                'PASSPORT_ISSUE_DATE' => $_POST['passport_issue_date'] ?? '',
                'PASSPORT_ISSUED_BY' => $_POST['passport_issued_by'] ?? '',
                'REGISTRATION_ADDRESS' => $_POST['registration_address'] ?? '',
                // Empty legal fields
                'COMPANY_NAME' => '',
                'INN' => '',
                'OGRN' => '',
                'LEGAL_ADDRESS' => '',
                'BANK_ACCOUNT' => '',
                'CORRESPONDENT_ACCOUNT' => '',
                'BIK' => '',
                'PHONE' => '',
            ]);
        } else {
            $placeholders = array_merge($placeholders, [
                'GUEST_TYPE' => 'Юридическое лицо',
                'FULL_NAME' => '',
                'PASSPORT_NUMBER' => '',
                'PASSPORT_ISSUE_DATE' => '',
                'PASSPORT_ISSUED_BY' => '',
                'REGISTRATION_ADDRESS' => '',
                'COMPANY_NAME' => $_POST['company_name'] ?? '',
                'INN' => $_POST['inn'] ?? '',
                'OGRN' => $_POST['ogrn'] ?? '',
                'LEGAL_ADDRESS' => $_POST['legal_address'] ?? '',
                'BANK_ACCOUNT' => $_POST['bank_account'] ?? '',
                'CORRESPONDENT_ACCOUNT' => $_POST['correspondent_account'] ?? '',
                'BIK' => $_POST['bik'] ?? '',
                'PHONE' => $_POST['phone'] ?? '',
            ]);
        }

        // 7. Обработать шаблон
        $docx = new DocxTemplate($templateContent);
        $docx->setPlaceholders($placeholders);
        $filledContent = $docx->process();

        // 8. Определить целевую папку
        $targetFolderName = $guestType === 'legal' ? FOLDER_LEGAL : FOLDER_INDIVIDUAL;
        $targetFolder = $b24->ensureFolder($storageId, $targetFolderName, $storageId);

        // 9. Загрузить заполненный договор
        $fileName = 'Договор_' . $contractNumber . '_' . $service . '.docx';
        $uploadResult = $b24->uploadFile($targetFolder['ID'], $fileName, $filledContent);

        $tokens = json_decode(file_get_contents(TOKEN_FILE), true);
        $domain = $tokens['domain'] ?? '';

        $response = [
            'success' => true,
            'contract_number' => $contractNumber,
            'file_url' => $uploadResult['DOWNLOAD_URL'] ?? $uploadResult['DETAIL_URL'] ?? '',
            'file_id' => $uploadResult['ID'] ?? '',
            'domain' => $domain,
        ];

        // 10. Если юр. лицо — создать задачу бухгалтеру
        if ($guestType === 'legal') {
            $fileUrl = $uploadResult['DOWNLOAD_URL'] ?? '';
            $taskDesc = str_replace(
                ['#CONTRACT_URL#', '#CONTRACT_NUMBER#'],
                [$fileUrl, $contractNumber],
                TASK_DESCRIPTION
            );

            // Найти бухгалтера по группе (или отправить ответственному)
            $taskResult = $b24->createTask([
                'TITLE' => TASK_TITLE . ' - ' . $contractNumber,
                'DESCRIPTION' => $taskDesc,
                'RESPONSIBLE_ID' => $b24->getCurrentUserId(),
                'GROUP_ID' => $storage['ENTITY_ID'],
                'UF_CRM_TASK' => [],
            ]);

            $response['task_id'] = $taskResult['task']['id'] ?? '';
            $response['task_created_by'] = $b24->getCurrentUserId();
        }

        echo json_encode($response);
        exit;
    }

    throw new RuntimeException('Неизвестное действие');

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

// Вспомогательная функция: число прописью
function num2str(float $num): string
{
    $nul = 'ноль';
    $ten = [
        ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
        ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
    ];
    $a20 = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
    $unit = [
        ['копейка', 'копейки', 'копеек', 1],
        ['рубль', 'рубля', 'рублей', 0],
        ['тысяча', 'тысячи', 'тысяч', 1],
        ['миллион', 'миллиона', 'миллионов', 0],
        ['миллиард', 'миллиарда', 'миллиардов', 0],
    ];

    list($rub, $kop) = explode('.', sprintf('%015.2f', $num));
    $rub = (int)$rub;
    $kop = (int)$kop;

    $out = [];
    if ($rub === 0) {
        $out[] = $nul;
    }

    $unitIndex = 4;
    $unitRub = $unit[1];
    while ($unitIndex > 0) {
        $triad = (int)substr((string)$rub, (4 - $unitIndex) * 3, 3);
        if ($triad === 0) {
            $unitIndex--;
            continue;
        }

        $g = (int)($triad / 100);
        $h = (int)(($triad - $g * 100) / 10);
        $i = $triad % 10;

        $out[] = $hundreds[$g];
        if ($h === 1) {
            $out[] = $a20[$i];
        } else {
            $out[] = $tens[$h];
            $gender = $unit[$unitIndex][3];
            $out[] = $ten[$gender][$i];
        }

        $unitText = morph($triad, $unit[$unitIndex][0], $unit[$unitIndex][1], $unit[$unitIndex][2]);
        $out[] = $unitText;

        $unitIndex--;
    }

    $morphRub = morph($rub % 1000, $unitRub[0], $unitRub[1], $unitRub[2]);
    $out[count($out) - 1] = $morphRub;

    $out[] = sprintf('%02d', $kop) . ' ' . morph($kop, $unit[0][0], $unit[0][1], $unit[0][2]);

    return implode(' ', array_filter($out));
}

function morph(int $n, string $f1, string $f2, string $f5): string
{
    $n = abs($n) % 100;
    if ($n > 10 && $n < 20) return $f5;
    $n = $n % 10;
    if ($n > 1 && $n < 5) return $f2;
    if ($n === 1) return $f1;
    return $f5;
}
