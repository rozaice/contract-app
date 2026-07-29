<?php
require_once __DIR__ . '/config.php';

class Bitrix24API
{
    private string $domain;
    private string $authToken;
    private ?string $refreshToken;

    public function __construct(string $domain, string $authToken, ?string $refreshToken = null)
    {
        $this->domain = $domain;
        $this->authToken = $authToken;
        $this->refreshToken = $refreshToken;
    }

    public static function fromTokens(): ?self
    {
        if (!file_exists(TOKEN_FILE)) {
            return null;
        }
        $data = json_decode(file_get_contents(TOKEN_FILE), true);
        if (!$data || empty($data['domain']) || empty($data['auth_token'])) {
            return null;
        }
        return new self($data['domain'], $data['auth_token'], $data['refresh_token'] ?? null);
    }

    public function call(string $method, array $params = []): array
    {
        $url = "https://{$this->domain}/rest/{$method}";
        $params['auth'] = $this->authToken;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("HTTP $httpCode при запросе к $method");
        }

        $result = json_decode($response, true);

        if (isset($result['error'])) {
            if (in_array($result['error'], ['expired_token', 'invalid_token']) && $this->refreshToken) {
                $this->refreshAuth();
                return $this->call($method, $params);
            }
            throw new RuntimeException($result['error_description'] ?? $result['error']);
        }

        return $result['result'] ?? [];
    }

    public function downloadFile(int $fileId): string
    {
        $fileInfo = $this->call('disk.file.get', ['id' => $fileId]);
        $downloadUrl = $fileInfo['DOWNLOAD_URL'] ?? $fileInfo['DOWNLOAD']['URL'] ?? '';

        if (!$downloadUrl) {
            throw new RuntimeException('Не удалось получить ссылку для скачивания файла');
        }

        $downloadUrl .= (strpos($downloadUrl, '?') === false ? '?' : '&') . 'auth=' . $this->authToken;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $downloadUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $content === false) {
            throw new RuntimeException("Не удалось скачать файл ID=$fileId");
        }

        return $content;
    }

    public function uploadFile(int $folderId, string $fileName, string $fileContent): array
    {
        $url = "https://{$this->domain}/rest/disk.folder.uploadfile";
        $boundary = '--------------------------' . microtime(true);

        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"auth\"\r\n\r\n{$this->authToken}\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"id\"\r\n\r\n$folderId\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"fileContent\"; filename=\"$fileName\"\r\n"
            . "Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document\r\n\r\n"
            . $fileContent . "\r\n"
            . "--$boundary--\r\n";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: multipart/form-data; boundary=$boundary"],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("Ошибка загрузки файла: HTTP $httpCode");
        }

        $result = json_decode($response, true);
        if (isset($result['error'])) {
            throw new RuntimeException($result['error_description'] ?? $result['error']);
        }

        return $result['result'] ?? [];
    }

    public function findStorageByName(string $name): ?array
    {
        $storages = $this->call('disk.storage.getlist', [
            'filter' => ['ENTITY_TYPE' => 'group'],
            'order' => ['NAME' => 'ASC'],
        ]);

        foreach ($storages as $s) {
            if ($s['ENTITY_TYPE'] === 'group') {
                $groupInfo = $this->call('sonet_group.get', ['ID' => $s['ENTITY_ID']]);
                $groupName = $groupInfo[0]['NAME'] ?? $groupInfo['NAME'] ?? '';
                if (stripos($groupName, $name) !== false) {
                    return $s;
                }
            }
        }

        // fallback: поиск по всем
        foreach ($storages as $s) {
            if (stripos($s['NAME'], $name) !== false) {
                return $s;
            }
        }

        return null;
    }

    public function findFolder(int $storageId, string $folderName, int $parentId = 0): ?array
    {
        $filter = ['STORAGE_ID' => $storageId, '=NAME' => $folderName];
        if ($parentId > 0) {
            $filter['PARENT_ID'] = $parentId;
        }

        $folders = $this->call('disk.folder.getchildren', [
            'id' => $parentId ?: $storageId,
            'filter' => ['=NAME' => $folderName],
        ]);

        foreach ($folders as $f) {
            if ($f['NAME'] === $folderName && $f['TYPE'] === 'folder') {
                return $f;
            }
        }

        return null;
    }

    public function ensureFolder(int $storageId, string $folderName, int $parentId = 0): array
    {
        $existing = $this->findFolder($storageId, $folderName, $parentId);
        if ($existing) {
            return $existing;
        }

        return $this->call('disk.folder.add', [
            'STORAGE_ID' => $storageId,
            'NAME' => $folderName,
            'PARENT_ID' => $parentId ?: $storageId,
        ]);
    }

    public function findTemplateFile(int $storageId, int $templateFolderId, string $serviceName): ?array
    {
        $files = $this->call('disk.folder.getchildren', [
            'id' => $templateFolderId,
            'filter' => ['TYPE' => 'file'],
        ]);

        $expectedName = $serviceName . '.docx';
        foreach ($files as $f) {
            if ($f['NAME'] === $expectedName) {
                return $f;
            }
        }

        // fuzzy match
        foreach ($files as $f) {
            if (stripos($f['NAME'], $serviceName) !== false && stripos($f['NAME'], '.docx') !== false) {
                return $f;
            }
        }

        return null;
    }

    public function createTask(array $fields): array
    {
        return $this->call('tasks.task.add', ['fields' => $fields]);
    }

    public function getCurrentUserId(): int
    {
        $user = $this->call('user.current');
        return (int)($user['ID'] ?? 0);
    }

    public function getNextContractNumber(): string
    {
        $counter = $this->getRawCounter();
        $counter++;
        $this->saveCounter($counter);
        return CONTRACT_PREFIX . str_pad((string)$counter, 4, '0', STR_PAD_LEFT);
    }

    public function previewNextContractNumber(): string
    {
        $counter = $this->getRawCounter();
        $counter++;
        return CONTRACT_PREFIX . str_pad((string)$counter, 4, '0', STR_PAD_LEFT);
    }

    private function getRawCounter(): int
    {
        $dir = dirname(COUNTER_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_exists(COUNTER_FILE)) {
            $data = json_decode(file_get_contents(COUNTER_FILE), true);
            return (int)($data['last_number'] ?? 0);
        }
        return 0;
    }

    private function saveCounter(int $value): void
    {
        file_put_contents(COUNTER_FILE, json_encode([
            'last_number' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
    }

    private function refreshAuth(): void
    {
        $url = "https://{$this->domain}/oauth/token/";
        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => CLIENT_ID,
            'client_secret' => CLIENT_SECRET,
            'refresh_token' => $this->refreshToken,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException('Не удалось обновить токен');
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Ошибка обновления токена: ' . ($data['error_description'] ?? 'unknown'));
        }

        $this->authToken = $data['access_token'];
        $this->refreshToken = $data['refresh_token'] ?? $this->refreshToken;

        $this->saveTokens($data);
    }

    private function saveTokens(array $data): void
    {
        $dir = dirname(TOKEN_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(TOKEN_FILE, json_encode([
            'domain' => $this->domain,
            'auth_token' => $data['access_token'] ?? $data['auth_token'],
            'refresh_token' => $data['refresh_token'],
            'user_id' => $data['user_id'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
    }
}
