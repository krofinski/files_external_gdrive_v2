<?php
namespace OCA\Files_external_gdrive_v2\Storage;

use OC\Files\Storage\Common;

class FileWriteWrapper {
    public $context;
    private $tempFile;
    private $path;
    private $storage;

    public function stream_open($path, $mode, $options, &$opened_path) {
        $opts = stream_context_get_options($this->context);
        $this->storage = $opts['gdrive']['storage'] ?? null;
        $this->path = $opts['gdrive']['path'] ?? null;
        if (!$this->storage) return false;
        
        $this->tempFile = tmpfile();
        return true;
    }

    public function stream_write($data) {
        return fwrite($this->tempFile, $data);
    }

    public function stream_close() {
        fflush($this->tempFile);
        fseek($this->tempFile, 0);
        $data = stream_get_contents($this->tempFile);
        fclose($this->tempFile);
        $this->storage->uploadFile($this->path, $data);
    }
    
    public function stream_tell() { return ftell($this->tempFile); }
    public function stream_seek($offset, $whence) { return fseek($this->tempFile, $offset, $whence) === 0; }
    public function stream_eof() { return feof($this->tempFile); }
    public function stream_stat() { return fstat($this->tempFile); }
}

class DirWrapper {
    public $context;
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
        return 'gdrive::' . md5($this->clientId . $this->token['access_token'] ?? '');
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
        if (is_resource($ch)) {
            curl_close($ch);
        }
        $tokenData = json_decode($response, true);
        if (isset($tokenData['access_token'])) {
            $this->token['access_token'] = $tokenData['access_token'];
            if (isset($tokenData['refresh_token'])) {
                $this->token['refresh_token'] = $tokenData['refresh_token'];
            }
            return true;
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
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (is_resource($ch)) curl_close($ch);
        
        if ($httpCode === 401 && $retry && $this->refreshToken()) {
            return $this->apiRequest($endpoint, $method, $params, $body, false);
        }
        
        return json_decode($response, true);
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
        $seen = [];
        foreach ($res['files'] as $file) {
            $baseName = str_replace('/', '-', $file['name']);
            if (isset($seen[$baseName])) {
                $ext = pathinfo($baseName, PATHINFO_EXTENSION);
                $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
                $i = 1;
                do {
                    $newName = $nameWithoutExt . " ({$i})" . ($ext ? ".$ext" : '');
                    $i++;
                } while (isset($seen[$newName]));
                $baseName = $newName;
            }
            $seen[$baseName] = true;
            
            $names[] = $baseName;
            $childPath = empty($path) ? $baseName : $path . '/' . $baseName;
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
    
    public function uploadFile(string $path, string $data) {
        $parentPath = dirname($path);
        if ($parentPath === '.') $parentPath = '';
        $parentId = $this->getFileIdByPath($parentPath);
        if (!$parentId) return false;
        
        $filename = basename($path);
        $existingId = $this->getFileIdByPath($path);
        
        $boundary = '-------' . uniqid();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        if ($existingId) {
            $url = 'https://www.googleapis.com/upload/drive/v3/files/' . $existingId . '?uploadType=multipart';
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            $meta = ['name' => $filename];
        } else {
            $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
            curl_setopt($ch, CURLOPT_POST, true);
            $meta = ['name' => $filename, 'parents' => [$parentId]];
        }
        
        $body = "--$boundary\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($meta) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $data . "\r\n";
        $body .= "--$boundary--\r\n";
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token['access_token'],
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body)
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code >= 200 && $code < 300) {
            unset($this->idCache[$path]);
            $this->clearCacheForFolder($parentPath);
            return true;
        }
        return false;
    }

    private function clearCacheForFolder($path) {
        unset($this->idCache[$path]);
        // Also clear children list cache if implemented
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
            $mimeRes = $this->apiRequest('/files/' . $id, 'GET', ['fields' => 'mimeType']);
            if (!$mimeRes) return false;
            
            $mime = $mimeRes['mimeType'] ?? '';
            $url = $this->driveApiUrl . '/files/' . $id;
            
            if ($mime === 'application/vnd.google-apps.document') {
                $url .= '/export?mimeType=application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            } elseif ($mime === 'application/vnd.google-apps.spreadsheet') {
                $url .= '/export?mimeType=application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } elseif ($mime === 'application/vnd.google-apps.presentation') {
                $url .= '/export?mimeType=application/vnd.openxmlformats-officedocument.presentationml.presentation';
            } elseif (strpos($mime, 'application/vnd.google-apps.') === 0) {
                $url .= '/export?mimeType=application/pdf';
            } else {
                $url .= '?alt=media';
            }

            $opts = ['http' => [
                'method' => 'GET', 
                'header' => "Authorization: Bearer " . $this->token['access_token'] . "\r\n",
                'follow_location' => 1
            ]];
            return fopen($url, 'rb', false, stream_context_create($opts));
        }
        
        if (strpos($mode, 'w') !== false) {
            if (!in_array('gdrive-file', stream_get_wrappers(), true)) {
                stream_wrapper_register('gdrive-file', FileWriteWrapper::class);
            }
            $opts = ['gdrive' => ['storage' => $this, 'path' => $path]];
            return fopen('gdrive-file://temp', $mode, false, stream_context_create($opts));
        }
        
        return false;
    }
    
    public function mkdir(string $path): bool {
        $parentPath = dirname($path);
        if ($parentPath === '.') $parentPath = '';
        $parentId = $this->getFileIdByPath($parentPath);
        if (!$parentId) return false;
        
        $body = json_encode([
            'name' => basename($path),
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId]
        ]);
        
        $res = $this->apiRequest('/files', 'POST', [], $body);
        if (isset($res['id'])) {
            $this->clearCacheForFolder($parentPath);
            return true;
        }
        return false;
    }
    
    public function rmdir(string $path): bool { return $this->unlink($path); }
    
    public function unlink(string $path): bool {
        $id = $this->getFileIdByPath($path);
        if (!$id) return false;
        
        $res = $this->apiRequest('/files/' . $id, 'DELETE');
        $this->clearCacheForFolder(dirname($path));
        unset($this->idCache[$path]);
        return true; 
    }
    
    public function touch(string $path, ?int $mtime = null): bool {
        if ($this->file_exists($path)) return true;
        return $this->uploadFile($path, '');
    }
}