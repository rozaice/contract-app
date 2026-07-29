<?php
define('APP_NAME', 'Договоры - Туган Як');
define('APP_VERSION', '1.0');

// Заполнить после регистрации локального приложения в Битрикс24
define('CLIENT_ID', '');
define('CLIENT_SECRET', '');
define('SCOPE', 'disk,tasks,user,app');

// Название проекта/группы в Битрикс24
define('PROJECT_NAME', 'Туган Як');

// Папки на диске Битрикс24
define('FOLDER_TEMPLATES', 'Шаблоны договоров');
define('FOLDER_LEGAL', 'Договоры юридические лица');
define('FOLDER_INDIVIDUAL', 'Договоры физические лица');

// Настройки задачи бухгалтеру
define('TASK_TITLE', 'Нужен счет');
define('TASK_DESCRIPTION', 'По договору [url=#CONTRACT_URL#]#CONTRACT_NUMBER#[/url] необходимо выставить счет.');

// Ставка НДС по умолчанию
define('DEFAULT_VAT', 20);

// Префикс номера договора
define('CONTRACT_PREFIX', 'Д-');

// Путь к файлу счётчика договоров
define('COUNTER_FILE', __DIR__ . '/storage/counter.json');

// Путь для временных файлов
define('TEMP_DIR', __DIR__ . '/storage/tmp/');

// Файл токенов
define('TOKEN_FILE', __DIR__ . '/storage/tokens.json');
