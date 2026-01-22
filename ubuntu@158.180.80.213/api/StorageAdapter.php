<?php
/**
 * Storage Adapter Interface and Implementations
 * 각 스토리지 타입별 파일 작업 처리
 * 
 * ⚠️ 미완성 상태 (2026-01-17)
 * - FTP, SFTP, WebDAV, S3 어댑터가 정의되어 있으나 FileManager에서 실제로 사용되지 않음
 * - 현재는 LocalAdapter만 부분적으로 사용됨
 * - 향후 원격 스토리지 지원 시 활용 예정
 */

interface StorageAdapterInterface {
    public function connect(): bool;
    public function disconnect(): void;
    public function list(string $path): array;
    public function read(string $path): string;
    public function write(string $path, string $content): bool;
    public function delete(string $path): bool;
    public function mkdir(string $path): bool;
    public function rename(string $from, string $to): bool;
    public function exists(string $path): bool;
    public function isDir(string $path): bool;
    public function getSize(string $path): int;
    public function getMime(string $path): string;
    public function getModified(string $path): int;
}

/**
 * 스토리지 어댑터 팩토리
 */
class StorageAdapterFactory {
    public static function create(array $storage): ?StorageAdapterInterface {
        $type = $storage['storage_type'] ?? 'local';
        $config = [];
        
        if (!empty($storage['config'])) {
            $config = is_array($storage['config']) 
                ? $storage['config'] 
                : (json_decode(base64_decode($storage['config']), true) ?: []);
        }
        
        switch ($type) {
            case 'local':
                return new LocalAdapter($storage['path']);
            case 'ftp':
                return new FtpAdapter($config);
            case 'sftp':
                return new SftpAdapter($config);
            case 'webdav':
                return new WebDavAdapter($config);
            case 's3':
                return new S3Adapter($config);
            case 'smb':
                // SMB는 로컬처럼 처리 (Windows에서 네트워크 드라이브)
                $path = "\\\\{$config['host']}\\{$config['share']}";
                return new LocalAdapter($path);
            default:
                return new LocalAdapter($storage['path'] ?? '');
        }
    }
}

/**
 * 로컬 파일시스템 어댑터
 */
class LocalAdapter implements StorageAdapterInterface {
    private string $basePath;
    
    public function __construct(string $basePath) {
        $this->basePath = rtrim($basePath, '/\\');
    }
    
    public function connect(): bool {
        return is_dir($this->basePath);
    }
    
    public function disconnect(): void {}
    
    public function list(string $path): array {
        $fullPath = $this->getFullPath($path);
        if (!is_dir($fullPath)) return [];
        
        $items = [];
        foreach (scandir($fullPath) as $item) {
            if ($item === '.' || $item === '..') continue;
            $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            $items[] = [
                'name' => $item,
                'path' => ltrim($path . '/' . $item, '/'),
                'is_dir' => is_dir($itemPath),
                'size' => is_file($itemPath) ? filesize($itemPath) : 0,
                'modified' => filemtime($itemPath)
            ];
        }
        return $items;
    }
    
    public function read(string $path): string {
        return file_get_contents($this->getFullPath($path)) ?: '';
    }
    
    public function write(string $path, string $content): bool {
        return file_put_contents($this->getFullPath($path), $content) !== false;
    }
    
    public function delete(string $path): bool {
        $fullPath = $this->getFullPath($path);
        if (is_dir($fullPath)) {
            return $this->deleteDir($fullPath);
        }
        return unlink($fullPath);
    }
    
    private function deleteDir(string $dir): bool {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        return rmdir($dir);
    }
    
    public function mkdir(string $path): bool {
        return mkdir($this->getFullPath($path), 0755, true);
    }
    
    public function rename(string $from, string $to): bool {
        return rename($this->getFullPath($from), $this->getFullPath($to));
    }
    
    public function exists(string $path): bool {
        return file_exists($this->getFullPath($path));
    }
    
    public function isDir(string $path): bool {
        return is_dir($this->getFullPath($path));
    }
    
    public function getSize(string $path): int {
        return filesize($this->getFullPath($path)) ?: 0;
    }
    
    public function getMime(string $path): string {
        return mime_content_type($this->getFullPath($path)) ?: 'application/octet-stream';
    }
    
    public function getModified(string $path): int {
        return filemtime($this->getFullPath($path)) ?: 0;
    }
    
    public function getFullPath(string $path): string {
        return $this->basePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

/**
 * FTP 어댑터
 */
class FtpAdapter implements StorageAdapterInterface {
    private $connection = null;
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function connect(): bool {
        $host = $this->config['host'] ?? '';
        $port = $this->config['port'] ?? 21;
        $ssl = $this->config['ssl'] ?? false;
        
        $this->connection = $ssl 
            ? @ftp_ssl_connect($host, $port, 30)
            : @ftp_connect($host, $port, 30);
            
        if (!$this->connection) return false;
        
        $username = $this->config['username'] ?? 'anonymous';
        $password = $this->config['password'] ?? '';
        
        if (!@ftp_login($this->connection, $username, $password)) {
            $this->disconnect();
            return false;
        }
        
        if ($this->config['passive'] ?? true) {
            ftp_pasv($this->connection, true);
        }
        
        return true;
    }
    
    public function disconnect(): void {
        if ($this->connection) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }
    
    public function list(string $path): array {
        if (!$this->connection) return [];
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        $items = [];
        $list = @ftp_mlsd($this->connection, $fullPath);
        
        if ($list === false) {
            // ftp_mlsd가 지원되지 않으면 ftp_nlist 사용
            $files = @ftp_nlist($this->connection, $fullPath);
            if ($files) {
                foreach ($files as $file) {
                    $name = basename($file);
                    if ($name === '.' || $name === '..') continue;
                    $items[] = [
                        'name' => $name,
                        'path' => ltrim($path . '/' . $name, '/'),
                        'is_dir' => @ftp_size($this->connection, $file) === -1,
                        'size' => max(0, @ftp_size($this->connection, $file)),
                        'modified' => @ftp_mdtm($this->connection, $file)
                    ];
                }
            }
        } else {
            foreach ($list as $item) {
                if ($item['name'] === '.' || $item['name'] === '..') continue;
                $items[] = [
                    'name' => $item['name'],
                    'path' => ltrim($path . '/' . $item['name'], '/'),
                    'is_dir' => $item['type'] === 'dir',
                    'size' => (int)($item['size'] ?? 0),
                    'modified' => isset($item['modify']) ? strtotime($item['modify']) : 0
                ];
            }
        }
        
        return $items;
    }
    
    public function read(string $path): string {
        if (!$this->connection) return '';
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        $temp = tmpfile();
        $tempPath = stream_get_meta_data($temp)['uri'];
        
        if (@ftp_get($this->connection, $tempPath, $fullPath, FTP_BINARY)) {
            $content = file_get_contents($tempPath);
            fclose($temp);
            return $content;
        }
        
        fclose($temp);
        return '';
    }
    
    public function write(string $path, string $content): bool {
        if (!$this->connection) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        $temp = tmpfile();
        fwrite($temp, $content);
        rewind($temp);
        
        $result = @ftp_fput($this->connection, $fullPath, $temp, FTP_BINARY);
        fclose($temp);
        
        return $result;
    }
    
    public function delete(string $path): bool {
        if (!$this->connection) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        if ($this->isDir($path)) {
            return @ftp_rmdir($this->connection, $fullPath);
        }
        return @ftp_delete($this->connection, $fullPath);
    }
    
    public function mkdir(string $path): bool {
        if (!$this->connection) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        return @ftp_mkdir($this->connection, $fullPath) !== false;
    }
    
    public function rename(string $from, string $to): bool {
        if (!$this->connection) return false;
        
        $root = $this->config['root'] ?? '/';
        $fromPath = rtrim($root, '/') . '/' . ltrim($from, '/');
        $toPath = rtrim($root, '/') . '/' . ltrim($to, '/');
        
        return @ftp_rename($this->connection, $fromPath, $toPath);
    }
    
    public function exists(string $path): bool {
        return $this->getSize($path) !== -1 || $this->isDir($path);
    }
    
    public function isDir(string $path): bool {
        if (!$this->connection) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        return @ftp_size($this->connection, $fullPath) === -1;
    }
    
    public function getSize(string $path): int {
        if (!$this->connection) return 0;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        return max(0, @ftp_size($this->connection, $fullPath));
    }
    
    public function getMime(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = [
            'txt' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html',
            'css' => 'text/css', 'js' => 'application/javascript',
            'json' => 'application/json', 'xml' => 'application/xml',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4',
            'webm' => 'video/webm', 'pdf' => 'application/pdf',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed'
        ];
        return $mimes[$ext] ?? 'application/octet-stream';
    }
    
    public function getModified(string $path): int {
        if (!$this->connection) return 0;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        return @ftp_mdtm($this->connection, $fullPath);
    }
}

/**
 * SFTP 어댑터 (ssh2 확장 필요)
 */
class SftpAdapter implements StorageAdapterInterface {
    private $connection = null;
    private $sftp = null;
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function connect(): bool {
        if (!function_exists('ssh2_connect')) {
            return false; // ssh2 확장이 설치되지 않음
        }
        
        $host = $this->config['host'] ?? '';
        $port = $this->config['port'] ?? 22;
        
        $this->connection = @ssh2_connect($host, $port);
        if (!$this->connection) return false;
        
        $username = $this->config['username'] ?? '';
        $authType = $this->config['auth_type'] ?? 'password';
        
        if ($authType === 'key') {
            $privateKey = $this->config['private_key'] ?? '';
            // 키 파일로 저장 후 인증
            $tempKey = tempnam(sys_get_temp_dir(), 'sftp_key');
            file_put_contents($tempKey, $privateKey);
            $result = @ssh2_auth_pubkey_file($this->connection, $username, $tempKey . '.pub', $tempKey);
            @unlink($tempKey);
        } else {
            $password = $this->config['password'] ?? '';
            $result = @ssh2_auth_password($this->connection, $username, $password);
        }
        
        if (!$result) {
            $this->disconnect();
            return false;
        }
        
        $this->sftp = @ssh2_sftp($this->connection);
        return $this->sftp !== false;
    }
    
    public function disconnect(): void {
        $this->sftp = null;
        $this->connection = null;
    }
    
    private function getSftpPath(string $path): string {
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        return "ssh2.sftp://{$this->sftp}{$fullPath}";
    }
    
    public function list(string $path): array {
        if (!$this->sftp) return [];
        
        $sftpPath = $this->getSftpPath($path);
        $handle = @opendir($sftpPath);
        if (!$handle) return [];
        
        $items = [];
        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') continue;
            $itemPath = $sftpPath . '/' . $item;
            $stat = @stat($itemPath);
            $items[] = [
                'name' => $item,
                'path' => ltrim($path . '/' . $item, '/'),
                'is_dir' => is_dir($itemPath),
                'size' => $stat['size'] ?? 0,
                'modified' => $stat['mtime'] ?? 0
            ];
        }
        closedir($handle);
        
        return $items;
    }
    
    public function read(string $path): string {
        return @file_get_contents($this->getSftpPath($path)) ?: '';
    }
    
    public function write(string $path, string $content): bool {
        return @file_put_contents($this->getSftpPath($path), $content) !== false;
    }
    
    public function delete(string $path): bool {
        $sftpPath = $this->getSftpPath($path);
        if (is_dir($sftpPath)) {
            return @rmdir($sftpPath);
        }
        return @unlink($sftpPath);
    }
    
    public function mkdir(string $path): bool {
        return @mkdir($this->getSftpPath($path), 0755, true);
    }
    
    public function rename(string $from, string $to): bool {
        if (!$this->sftp) return false;
        $root = $this->config['root'] ?? '/';
        $fromPath = rtrim($root, '/') . '/' . ltrim($from, '/');
        $toPath = rtrim($root, '/') . '/' . ltrim($to, '/');
        return @ssh2_sftp_rename($this->sftp, $fromPath, $toPath);
    }
    
    public function exists(string $path): bool {
        return @file_exists($this->getSftpPath($path));
    }
    
    public function isDir(string $path): bool {
        return @is_dir($this->getSftpPath($path));
    }
    
    public function getSize(string $path): int {
        $stat = @stat($this->getSftpPath($path));
        return $stat['size'] ?? 0;
    }
    
    public function getMime(string $path): string {
        return (new FtpAdapter([]))->getMime($path);
    }
    
    public function getModified(string $path): int {
        $stat = @stat($this->getSftpPath($path));
        return $stat['mtime'] ?? 0;
    }
}

/**
 * WebDAV 어댑터
 */
class WebDavAdapter implements StorageAdapterInterface {
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    private function request(string $method, string $path, array $options = []): array {
        $url = rtrim($this->config['url'] ?? '', '/') . '/' . ltrim($path, '/');
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => ($this->config['username'] ?? '') . ':' . ($this->config['password'] ?? ''),
            CURLOPT_TIMEOUT => 30
        ]);
        
        if (!empty($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }
        if (!empty($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ['code' => $httpCode, 'body' => $response];
    }
    
    public function connect(): bool {
        $result = $this->request('PROPFIND', '', ['headers' => ['Depth: 0']]);
        return $result['code'] >= 200 && $result['code'] < 400;
    }
    
    public function disconnect(): void {}
    
    public function list(string $path): array {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 1']]);
        if ($result['code'] !== 207) return [];
        
        $items = [];
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return [];
        
        $xml->registerXPathNamespace('d', 'DAV:');
        foreach ($xml->xpath('//d:response') as $response) {
            $href = (string)$response->xpath('d:href')[0];
            $props = $response->xpath('d:propstat/d:prop')[0];
            
            $name = basename(urldecode($href));
            if (empty($name) || $name === basename($path)) continue;
            
            $isDir = !empty($props->xpath('d:resourcetype/d:collection'));
            $size = (int)($props->xpath('d:getcontentlength')[0] ?? 0);
            $modified = strtotime((string)($props->xpath('d:getlastmodified')[0] ?? ''));
            
            $items[] = [
                'name' => $name,
                'path' => ltrim($path . '/' . $name, '/'),
                'is_dir' => $isDir,
                'size' => $size,
                'modified' => $modified
            ];
        }
        
        return $items;
    }
    
    public function read(string $path): string {
        $result = $this->request('GET', $path);
        return $result['code'] === 200 ? $result['body'] : '';
    }
    
    public function write(string $path, string $content): bool {
        $result = $this->request('PUT', $path, ['body' => $content]);
        return $result['code'] >= 200 && $result['code'] < 300;
    }
    
    public function delete(string $path): bool {
        $result = $this->request('DELETE', $path);
        return $result['code'] >= 200 && $result['code'] < 300;
    }
    
    public function mkdir(string $path): bool {
        $result = $this->request('MKCOL', $path);
        return $result['code'] === 201;
    }
    
    public function rename(string $from, string $to): bool {
        $destUrl = rtrim($this->config['url'] ?? '', '/') . '/' . ltrim($to, '/');
        $result = $this->request('MOVE', $from, ['headers' => ["Destination: $destUrl"]]);
        return $result['code'] >= 200 && $result['code'] < 300;
    }
    
    public function exists(string $path): bool {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        return $result['code'] === 207;
    }
    
    public function isDir(string $path): bool {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        if ($result['code'] !== 207) return false;
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return false;
        $xml->registerXPathNamespace('d', 'DAV:');
        return !empty($xml->xpath('//d:resourcetype/d:collection'));
    }
    
    public function getSize(string $path): int {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        if ($result['code'] !== 207) return 0;
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return 0;
        $xml->registerXPathNamespace('d', 'DAV:');
        return (int)($xml->xpath('//d:getcontentlength')[0] ?? 0);
    }
    
    public function getMime(string $path): string {
        return (new FtpAdapter([]))->getMime($path);
    }
    
    public function getModified(string $path): int {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        if ($result['code'] !== 207) return 0;
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return 0;
        $xml->registerXPathNamespace('d', 'DAV:');
        return strtotime((string)($xml->xpath('//d:getlastmodified')[0] ?? ''));
    }
}

/**
 * S3 어댑터 (S3 호환 스토리지)
 */
class S3Adapter implements StorageAdapterInterface {
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    private function sign(string $method, string $uri, array $headers, string $payload = ''): array {
        $accessKey = $this->config['access_key'] ?? '';
        $secretKey = $this->config['secret_key'] ?? '';
        $region = $this->config['region'] ?? 'us-east-1';
        $service = 's3';
        
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');
        
        $headers['x-amz-date'] = $date;
        $headers['x-amz-content-sha256'] = hash('sha256', $payload);
        
        // Canonical request
        ksort($headers);
        $signedHeaders = implode(';', array_map('strtolower', array_keys($headers)));
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
        }
        
        $canonicalRequest = implode("\n", [
            $method,
            $uri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $headers['x-amz-content-sha256']
        ]);
        
        // String to sign
        $scope = "$dateShort/$region/$service/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date,
            $scope,
            hash('sha256', $canonicalRequest)
        ]);
        
        // Signing key
        $kDate = hash_hmac('sha256', $dateShort, "AWS4$secretKey", true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
        
        return $headers;
    }
    
    private function request(string $method, string $path, string $body = '', array $query = []): array {
        $endpoint = $this->config['endpoint'] ?? 's3.amazonaws.com';
        $bucket = $this->config['bucket'] ?? '';
        $prefix = $this->config['prefix'] ?? '';
        
        $fullPath = '/' . $bucket . '/' . ltrim($prefix . $path, '/');
        $uri = 'https://' . $endpoint . $fullPath;
        
        if (!empty($query)) {
            $uri .= '?' . http_build_query($query);
        }
        
        $headers = ['Host' => $endpoint];
        $headers = $this->sign($method, $fullPath, $headers, $body);
        
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_TIMEOUT => 30
        ]);
        
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ['code' => $httpCode, 'body' => $response];
    }
    
    public function connect(): bool {
        $result = $this->request('GET', '', '', ['list-type' => '2', 'max-keys' => '1']);
        return $result['code'] === 200;
    }
    
    public function disconnect(): void {}
    
    public function list(string $path): array {
        $prefix = ltrim($path, '/');
        if (!empty($prefix) && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }
        
        $result = $this->request('GET', '', '', [
            'list-type' => '2',
            'prefix' => $prefix,
            'delimiter' => '/'
        ]);
        
        if ($result['code'] !== 200) return [];
        
        $items = [];
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return [];
        
        // 폴더
        foreach ($xml->CommonPrefixes ?? [] as $cp) {
            $key = (string)$cp->Prefix;
            $name = basename(rtrim($key, '/'));
            $items[] = [
                'name' => $name,
                'path' => rtrim($key, '/'),
                'is_dir' => true,
                'size' => 0,
                'modified' => 0
            ];
        }
        
        // 파일
        foreach ($xml->Contents ?? [] as $content) {
            $key = (string)$content->Key;
            if ($key === $prefix) continue;
            $name = basename($key);
            $items[] = [
                'name' => $name,
                'path' => $key,
                'is_dir' => false,
                'size' => (int)$content->Size,
                'modified' => strtotime((string)$content->LastModified)
            ];
        }
        
        return $items;
    }
    
    public function read(string $path): string {
        $result = $this->request('GET', $path);
        return $result['code'] === 200 ? $result['body'] : '';
    }
    
    public function write(string $path, string $content): bool {
        $result = $this->request('PUT', $path, $content);
        return $result['code'] === 200;
    }
    
    public function delete(string $path): bool {
        $result = $this->request('DELETE', $path);
        return $result['code'] === 204 || $result['code'] === 200;
    }
    
    public function mkdir(string $path): bool {
        // S3에서는 폴더가 없으므로 빈 객체 생성
        $result = $this->request('PUT', rtrim($path, '/') . '/');
        return $result['code'] === 200;
    }
    
    public function rename(string $from, string $to): bool {
        // S3는 rename이 없으므로 copy + delete
        $content = $this->read($from);
        if ($this->write($to, $content)) {
            return $this->delete($from);
        }
        return false;
    }
    
    public function exists(string $path): bool {
        $result = $this->request('HEAD', $path);
        return $result['code'] === 200;
    }
    
    public function isDir(string $path): bool {
        return str_ends_with($path, '/');
    }
    
    public function getSize(string $path): int {
        // HEAD 요청으로 Content-Length 가져오기
        return 0;
    }
    
    public function getMime(string $path): string {
        return (new FtpAdapter([]))->getMime($path);
    }
    
    public function getModified(string $path): int {
        return 0;
    }
}

