<?php
namespace OCA\Files_external_gdrive_v2\Storage;

use OC\Files\Storage\Common;

class DirWrapper {
    private static $dirs = [];
    private $index = 0;
    private $id;

    public static function wrap(array $array) {
        if (!in_array('gdrive-dir', stream_get_wrappers(), true)) {
            stream_wrapper_register('gdrive-dir', self::class);
        }
        self::$dirs[] = $array;
        end(self::$dirs);
        $id = key(self::$dirs);
        return opendir('gdrive-dir://' . $id);
    }
    
    public function dir_opendir($path, $options) {
        $this->id = (int)substr($path, 13);
        return isset(self::$dirs[$this->id]);
    }
    
    public function dir_readdir() {
        if (!isset(self::$dirs[$this->id])) return false;
        if ($this->index < count(self::$dirs[$this->id])) {
            $res = self::$dirs[$this->id][$this->index];
            $this->index++;
            return $res;
        }
        return false;
    }
    
    public function dir_closedir() {
        unset(self::$dirs[$this->id]);
        return true;
    }
    
    public function dir_rewinddir() {
        $this->index = 0;
        return true;
    }
}

class GoogleDrive extends Common {
    private $clientId;
    private $clientSecret;
    private $token;
    private $driveApiUrl = 'https://www.googleapis.com/drive/v3';
    private $idCache = [];
    private $statCache = [];

    public function __construct($arguments) {
        parent::__construct($arguments);

        $this->clientId = (string)($arguments['client_id'] ?? '');
        $this->clientSecret = (string)($arguments['client_secret'] ?? '');
        
        $tokenData = $arguments['token'] ?? '{}';
        if (is_string($tokenData)) {
            $clean = html_entity_decode($tokenData, ENT_QUOTES);
            $decoded = json_decode($clean, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(stripslashes($tokenData), true);
            }
            $this->token = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tokenData)) {
            $this->token = $tokenData;
        } else {
            $this->token = [];
        }
    }

    public function getId(): string { 
        return 'gdrive::' . $this->clientId; 
    }
    
    public function test(bool $isPersonal = false): bool {
        return !empty($this->token['access_token']) || !empty($this->token['refresh_token']);
    }

    private function refreshToken(): bool {
        if (empty($this->token['refresh_token'])) return false;
        $postData = [
            'client_id' => $this->clientId, 
            'client_secret' => $this->clientSecret, 
            'refresh_token' => $this->token['refresh_token'], 
            'grant_type' => 'refresh_token'
        ];
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response !== false) {
            $newData = json_decode($response, true);
            if (isset($newData['access_token'])) {
                $this->token['access_token'] = $newData['access_token'];
                return true;
            }
        }
        return false;
    }

    private function apiRequest($endpoint, $method = 'GET', $params = [], $body = null, $retry = true) {
        if (empty($this->token['access_token'])) {
            if ($retry && $this->refreshToken()) return $this->apiRequest($endpoint, $method, $params, $body, false);
            return false;
        }
        $url = $this->driveApiUrl . $endpoint;
        if (!empty($params)) $url .= '?' . http_build_query($params);
        
        $headers = [
            'Authorization: Bearer ' . $this->token['access_token'], 
            'Accept: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        if ($body !== null) {
            if (is_array($body)) {
                $body = json_encode($body);
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401 && $retry) {
            if ($this->refreshToken()) return $this->apiRequest($endpoint, $method, $params, $body, false);
        }
        if ($httpCode >= 400) return false;
        $decoded = json_decode($response, true);
        return $decoded !== null ? $decoded : false;
    }

    private function getFileIdByPath(string $path) {
        $path = trim($path, '/');
        if (empty($path) || $path === '.') return 'root';
        if (isset($this->idCache[$path])) return $this->idCache[$path];

        $parts = explode('/', $path);
        $parentId = 'root';
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath = empty($currentPath) ? $part : $currentPath . '/' . $part;
            if (isset($this->idCache[$currentPath])) {
                $parentId = $this->idCache[$currentPath];
                continue;
            }
            $query = "'{$parentId}' in parents and name = '" . str_replace("'", "\\'", $part) . "' and trashed = false";
            $res = $this->apiRequest('/files', 'GET', ['q' => $query, 'fields' => 'files(id, mimeType)']);
            if (empty($res['files'])) return false;
            $parentId = $res['files'][0]['id'];
            $this->idCache[$currentPath] = $parentId;
        }
        return $this->idCache[$path] ?? false;
    }

    public function stat(string $path) {
        $path = trim($path, '/');
        if ($path === '' || $path === '.') {
            $now = time();
            return ['mtime' => $now, 'atime' => $now, 'ctime' => $now, 'size' => 0, 'type' => 'dir', 'permissions' => 31];
        }
        if (isset($this->statCache[$path])) return $this->statCache[$path];
        
        $id = $this->getFileIdByPath($path);
        if (!$id) return false;
        
        $res = $this->apiRequest('/files/' . $id, 'GET', ['fields' => 'id, name, mimeType, size, modifiedTime']);
        if (empty($res['id'])) return false;
        
        $isDir = ($res['mimeType'] === 'application/vnd.google-apps.folder');
        $mtime = strtotime($res['modifiedTime'] ?? 'now');
        $stat = [
            'mtime' => $mtime, 'atime' => $mtime, 'ctime' => $mtime,
            'size' => $isDir ? 0 : (isset($res['size']) ? (int)$res['size'] : 0),
            'type' => $isDir ? 'dir' : 'file',
            'permissions' => 31
        ];
        $this->statCache[$path] = $stat;
        return $stat;
    }

    public function opendir(string $path) {
        $path = trim($path, '/');
        $id = $this->getFileIdByPath($path);
        if (!$id) return false;
        $query = "'{$id}' in parents and trashed = false";
        $res = $this->apiRequest('/files', 'GET', ['q' => $query, 'fields' => 'files(id, name, mimeType, size, modifiedTime)', 'pageSize' => 1000]);
        if ($res === false || !isset($res['files'])) return DirWrapper::wrap([]);
        
        $names = [];
        foreach ($res['files'] as $file) {
            $names[] = $file['name'];
            $childPath = empty($path) ? $file['name'] : $path . '/' . $file['name'];
            $this->idCache[$childPath] = $file['id'];
            $isDir = ($file['mimeType'] === 'application/vnd.google-apps.folder');
            $mtime = strtotime($file['modifiedTime'] ?? 'now');
            $this->statCache[$childPath] = [
                'mtime' => $mtime, 'atime' => $mtime, 'ctime' => $mtime,
                'size' => $isDir ? 0 : (isset($file['size']) ? (int)$file['size'] : 0),
                'type' => $isDir ? 'dir' : 'file',
                'permissions' => 31
            ];
        }
        return DirWrapper::wrap($names);
    }
    
    public function filetype(string $path) { $stat = $this->stat($path); return $stat ? $stat['type'] : false; }
    public function file_exists(string $path) { return $this->getFileIdByPath($path) !== false; }
    
    public function free_space(string $path): int|float|false { return \OCP\Files\FileInfo::SPACE_UNKNOWN; }
    public function isReadable(string $path): bool { return true; }
    public function isUpdatable(string $path): bool { return true; }
    public function isCreatable(string $path): bool { return true; }
    public function isDeletable(string $path): bool { return true; }
    public function isSharable(string $path): bool { return true; }
    
    public function fopen(string $path, string $mode) {
        $id = $this->getFileIdByPath($path);
        if (strpos($mode, 'r') !== false && $id) {
            $url = $this->driveApiUrl . '/files/' . $id . '?alt=media';
            $opts = ['http' => ['method' => 'GET', 'header' => "Authorization: Bearer " . $this->token['access_token'] . "\r\n"]];
            return fopen($url, 'rb', false, stream_context_create($opts));
        }
        return false;
    }
    
    public function mkdir(string $path): bool { return false; }
    public function rmdir(string $path): bool { return false; }
    public function unlink(string $path): bool { return false; }
    public function touch(string $path, int $mtime = null): bool { return false; }
}
