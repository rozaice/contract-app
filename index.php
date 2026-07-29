<?php
require_once __DIR__ . '/config.php';

// Bitrix24 передаёт параметры auth при загрузке iframe
$authToken = $_GET['AUTH_ID'] ?? $_GET['auth_token'] ?? '';
$refreshToken = $_GET['REFRESH_ID'] ?? $_GET['refresh_token'] ?? '';
$domain = $_GET['DOMAIN'] ?? $_GET['domain'] ?? '';

if ($authToken && $domain) {
    // Сохраняем токены
    $dir = dirname(TOKEN_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(TOKEN_FILE, json_encode([
        'domain' => $domain,
        'auth_token' => $authToken,
        'refresh_token' => $refreshToken,
        'user_id' => $_GET['USER_ID'] ?? $_GET['user_id'] ?? null,
        'updated_at' => date('Y-m-d H:i:s'),
    ]));
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= APP_NAME ?></title>
<style>
:root {
    --bx-blue: #1f6ebe;
    --bx-green: #90be00;
    --bx-gray: #a8adb4;
    --bx-light: #f5f5f5;
    --bx-border: #d5d8db;
    --bx-text: #333;
    --bx-radius: 4px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
    background: var(--bx-light);
    color: var(--bx-text);
    padding: 20px;
    font-size: 14px;
    line-height: 1.5;
}
.container {
    max-width: 860px;
    margin: 0 auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    padding: 30px;
}
h1 {
    font-size: 22px;
    font-weight: 600;
    color: var(--bx-text);
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--bx-blue);
}
h2 {
    font-size: 16px;
    font-weight: 600;
    color: var(--bx-text);
    margin-top: 24px;
    margin-bottom: 16px;
    padding-left: 8px;
    border-left: 3px solid var(--bx-blue);
}
.form-row {
    display: flex;
    gap: 16px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.form-group {
    flex: 1;
    min-width: 200px;
}
.form-group.full { flex: 0 0 100%; }
label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #555;
    margin-bottom: 4px;
}
input, select, textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--bx-border);
    border-radius: var(--bx-radius);
    font-size: 14px;
    background: #fff;
    transition: border-color 0.2s;
}
input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--bx-blue);
    box-shadow: 0 0 0 2px rgba(31,110,190,0.15);
}
input[readonly] { background: #f9f9f9; color: #888; }
textarea { resize: vertical; min-height: 60px; }
.radio-group {
    display: flex;
    gap: 20px;
}
.radio-group label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 400;
    cursor: pointer;
}
.radio-group input[type="radio"] { width: auto; }
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
}
.checkbox-group input[type="checkbox"] { width: auto; }
.checkbox-group label { margin-bottom: 0; font-weight: 400; }
.checkbox-group input[type="number"] { width: 80px; }
.actions {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--bx-border);
    text-align: center;
}
.btn {
    display: inline-block;
    padding: 10px 32px;
    font-size: 15px;
    font-weight: 600;
    border: none;
    border-radius: var(--bx-radius);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-primary {
    background: var(--bx-blue);
    color: #fff;
}
.btn-primary:hover { background: #1a5a9e; }
.btn-primary:disabled { background: #a0b4c8; cursor: not-allowed; }
.btn-secondary {
    background: var(--bx-gray);
    color: #fff;
}
.btn-secondary:hover { background: #8e949b; }
.hidden { display: none; }
.success { color: #3a8a00; }
.error { color: #d32f2f; }
#result {
    margin-top: 20px;
    padding: 16px;
    border-radius: var(--bx-radius);
    font-weight: 500;
}
#result.success { background: #eaf7d9; }
#result.error { background: #fbe9e7; }
#result.info { background: #e3f2fd; }
.field-hint { font-size: 12px; color: #888; margin-top: 2px; }
.section-divider {
    border: none;
    border-top: 1px dashed #ddd;
    margin: 20px 0;
}
@media (max-width: 600px) {
    .container { padding: 16px; }
    .form-row { flex-direction: column; gap: 10px; }
    .form-group { min-width: 100%; }
}
</style>
</head>
<body>
<div class="container">
    <h1>📋 Создание договора</h1>

    <form id="contractForm" autocomplete="off">
        <!-- Тип гостя -->
        <h2>Тип гостя</h2>
        <div class="form-row">
            <div class="form-group">
                <div class="radio-group">
                    <label><input type="radio" name="guest_type" value="individual" checked onchange="toggleGuestType()"> Физическое лицо</label>
                    <label><input type="radio" name="guest_type" value="legal" onchange="toggleGuestType()"> Юридическое лицо</label>
                </div>
            </div>
        </div>

        <!-- Услуга -->
        <h2>Услуга</h2>
        <div class="form-row">
            <div class="form-group">
                <select name="service" required>
                    <option value="">— Выберите услугу —</option>
                    <option value="Аренда бани">Аренда бани</option>
                    <option value="Аренда дома">Аренда дома</option>
                    <option value="Аренда шатра">Аренда шатра</option>
                </select>
            </div>
        </div>

        <!-- Данные гостя: Физ. лицо -->
        <div id="individualFields">
            <h2>Данные гостя (физ. лицо)</h2>
            <div class="form-row">
                <div class="form-group full"><label>ФИО полностью</label><input type="text" name="full_name" placeholder="Иванов Иван Иванович"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Серия и номер паспорта</label><input type="text" name="passport_number" placeholder="4510 123456"></div>
                <div class="form-group"><label>Дата выдачи</label><input type="date" name="passport_issue_date"></div>
            </div>
            <div class="form-row">
                <div class="form-group full"><label>Кем выдан</label><input type="text" name="passport_issued_by" placeholder="Отделом УФМС России..."></div>
            </div>
            <div class="form-row">
                <div class="form-group full"><label>Адрес по прописке</label><textarea name="registration_address" rows="2" placeholder="г. Москва, ул. Ленина, д. 1, кв. 1"></textarea></div>
            </div>
        </div>

        <!-- Данные гостя: Юр. лицо -->
        <div id="legalFields" class="hidden">
            <h2>Данные гостя (юр. лицо)</h2>
            <div class="form-row">
                <div class="form-group full"><label>Наименование организации / ИП</label><input type="text" name="company_name" placeholder="ООО «Ромашка»"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>ИНН</label><input type="text" name="inn" placeholder="1234567890"></div>
                <div class="form-group"><label>ОГРН / ОГРНИП</label><input type="text" name="ogrn" placeholder="1234567890123"></div>
            </div>
            <div class="form-row">
                <div class="form-group full"><label>Юридический адрес</label><textarea name="legal_address" rows="2" placeholder="г. Москва, ул. Ленина, д. 1, оф. 1"></textarea></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Расчётный счёт</label><input type="text" name="bank_account" placeholder="40702810123456789012"></div>
                <div class="form-group"><label>Корреспондентский счёт</label><input type="text" name="correspondent_account" placeholder="30101810123456789012"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>БИК</label><input type="text" name="bik" placeholder="044525225"></div>
                <div class="form-group"><label>Телефон</label><input type="text" name="phone" placeholder="+7 (999) 123-45-67"></div>
            </div>
        </div>

        <!-- Параметры договора -->
        <h2>Параметры договора</h2>
        <div class="form-row">
            <div class="form-group"><label>Дата договора</label><input type="date" name="contract_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>Номер договора</label><input type="text" name="contract_number" readonly placeholder="Присвоится автоматически"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Сумма договора (₽)</label><input type="number" name="contract_amount" step="0.01" min="0" placeholder="0.00"></div>
            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="nds_enabled" name="nds_enabled" value="1" onchange="toggleNds()">
                    <label for="nds_enabled">НДС</label>
                    <input type="number" name="nds_percent" id="nds_percent" value="<?= DEFAULT_VAT ?>" min="0" max="100" step="0.1" style="width:70px" disabled>
                    <span>%</span>
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn-primary" id="submitBtn">Сформировать договор</button>
        </div>
    </form>

    <div id="result" class="hidden"></div>
</div>

<script>
function toggleGuestType() {
    const isLegal = document.querySelector('input[name="guest_type"]:checked').value === 'legal';
    document.getElementById('individualFields').classList.toggle('hidden', isLegal);
    document.getElementById('legalFields').classList.toggle('hidden', !isLegal);
}

function toggleNds() {
    document.getElementById('nds_percent').disabled = !document.getElementById('nds_enabled').checked;
}

document.getElementById('contractForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const resultDiv = document.getElementById('result');
    btn.disabled = true;
    btn.textContent = 'Обработка...';
    resultDiv.className = 'hidden';

    const formData = new FormData(this);
    formData.set('action', 'generate');

    try {
        const response = await fetch('handler.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'success';
            resultDiv.innerHTML = '✅ Договор успешно сформирован!<br>'
                + 'Номер: <strong>' + (data.contract_number || '') + '</strong><br>'
                + (data.file_url ? 'Файл: <a href="' + data.file_url + '" target="_blank">открыть</a><br>' : '')
                + (data.task_id ? 'Задача бухгалтеру: <a href="https://' + data.domain + '/company/personal/user/' + data.task_created_by + '/tasks/task/view/' + data.task_id + '/" target="_blank">перейти</a>' : '');
        } else {
            resultDiv.className = 'error';
            resultDiv.innerHTML = '❌ Ошибка: ' + (data.error || 'Неизвестная ошибка');
        }
    } catch (err) {
        resultDiv.className = 'error';
        resultDiv.innerHTML = '❌ Ошибка соединения: ' + err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Сформировать договор';
        resultDiv.classList.remove('hidden');
    }
});

// Получить номер договора при загрузке
fetch('handler.php?action=get_number')
    .then(r => r.json())
    .then(d => {
        if (d.number) document.querySelector('input[name="contract_number"]').value = d.number;
    });
</script>
</body>
</html>
