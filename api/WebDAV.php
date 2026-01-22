<?php
/**
 * WebDAV Server for g5 File Manager
 * Windows 네트워크 드라이브 연결 지원
 */

class WebDAV {
    private $db;
    private $auth;
    private $storage;
    private $baseUri;
    private $currentUser = null;  // 인증된 사용자 정보
    
    public function __construct($db, $auth, $storage) {
        $this->db = $db;
        $this->auth = $auth;
        $this->storage = $storage;
        // 동적으로 현재 스크립트 경로 사용
        $this->baseUri = $_SERVER['SCRIPT_NAME'] ?? '/webdav.php';
    }
    
    /**
     * 요청 처리
     */
    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        
        // Basic Auth 인증
        if (!$this->authenticate()) {
            $this->requireAuth();
            return;
        }
        
        // URI에서 경로 추출
        $path = $this->getPathFromUri($uri);
        
        switch ($method) {
            case 'OPTIONS':
                $this->handleOptions();
                break;
            case 'PROPFIND':
                $this->handlePropfind($path);
                break;
            case 'GET':
            case 'HEAD':
                $this->handleGet($path, $method === 'HEAD');
                break;
            case 'PUT':
                $this->handlePut($path);
                break;
            case 'DELETE':
                $this->handleDelete($path);
                break;
            case 'MKCOL':
                $this->handleMkcol($path);
                break;
            case 'MOVE':
                $this->handleMove($path);
                break;
            case 'COPY':
                $this->handleCopy($path);
                break;
            case 'PROPPATCH':
                $this->handleProppatch($path);
                break;
            case 'LOCK':
                $this->handleLock($path);
                break;
            case 'UNLOCK':
                $this->handleUnlock($path);
                break;
            default:
                http_response_code(405);
                header('Allow: OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE, COPY');
                break;
        }
    }
    
    /**
     * Basic Auth 인증
     */
    private function authenticate(): bool {
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            return false;
        }
        
        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        // g5 로그인 확인
        $users = $this->db->load('users');
        foreach ($users as $user) {
            if ($user['username'] === $username && password_verify($password, $user['password'])) {
                if (($user['status'] ?? '') !== 'active') {
                    return false;
                }
                // 사용자 정보 저장 (세션 + 클래스 멤버)
                $this->currentUser = $user;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user'] = $user;
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 인증 요청
     */
    private function requireAuth(): void {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="g5 WebDAV"');
        echo 'Authentication required';
    }
    
    /**
     * WebDAV 전용 권한 체크 (세션 대신 currentUser 사용)
     */
    private function checkPermission(int $storageId, string $permission): bool {
        if (!$this->currentUser) {
            return false;
        }
        
        // 관리자는 모든 권한
        if (($this->currentUser['role'] ?? '') === 'admin') {
            return true;
        }
        
        $userId = $this->currentUser['id'] ?? 0;
        
        // 홈 스토리지인지 확인 (소유자면 모든 권한)
        $storages = $this->db->load('storages');
        foreach ($storages as $storage) {
            if (($storage['id'] ?? 0) == $storageId) {
                if (($storage['storage_type'] ?? '') === 'home' && ($storage['owner_id'] ?? 0) == $userId) {
                    return true;
                }
                break;
            }
        }
        
        // 권한 테이블에서 확인
        $permissions = $this->db->load('permissions');
        foreach ($permissions as $perm) {
            if (($perm['storage_id'] ?? 0) == $storageId && ($perm['user_id'] ?? 0) == $userId) {
                return (bool)($perm[$permission] ?? false);
            }
        }
        
        return false;
    }
    
    /**
     * URI에서 경로 추출
     */
    private function getPathFromUri(string $uri): string {
        // 쿼리 스트링 제거
        $path = parse_url($uri, PHP_URL_PATH);
        
        // 현재 스크립트명 (예: /g5/mydav.php → mydav.php)
        $scriptName = $_SERVER['SCRIPT_NAME'];
        
        // 스크립트 경로 제거 (Windows/Linux 모두 호환)
        // /g5/mydav.php/1/folder → 1/folder
        if (strpos($path, $scriptName) === 0) {
            $path = substr($path, strlen($scriptName));
        }
        
        // URL 디코딩
        $path = rawurldecode($path);
        
        // 앞뒤 슬래시 정리
        $path = trim($path, '/');
        
        return $path;
    }
    
    /**
     * 경로를 스토리지와 상대경로로 분리
     * 형식: /스토리지ID/상대경로
     */
    private function parsePath(string $path): ?array {
        if (empty($path)) {
            return ['storage_id' => 0, 'relative_path' => '', 'is_root' => true];
        }
        
        $parts = explode('/', $path, 2);
        $storageId = (int)$parts[0];
        $relativePath = $parts[1] ?? '';
        
        // 스토리지 접근 권한 확인
        if ($storageId > 0 && !$this->checkPermission($storageId, 'can_read')) {
            return null;
        }
        
        return [
            'storage_id' => $storageId,
            'relative_path' => $relativePath,
            'is_root' => false
        ];
    }
    
    /**
     * 실제 파일 경로 가져오기
     */
    private function getRealPath(int $storageId, string $relativePath): ?string {
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return null;
        }
        
        if (empty($relativePath)) {
            return $basePath;
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        
        // 경로 검증 (상위 디렉토리 접근 방지)
        $realBase = realpath($basePath);
        $realFull = realpath(dirname($fullPath)) . DIRECTORY_SEPARATOR . basename($fullPath);
        
        if ($realBase && strpos($realFull, $realBase) !== 0) {
            return null;
        }
        
        return $fullPath;
    }
    
    /**
     * OPTIONS 처리
     */
    private function handleOptions(): void {
        http_response_code(200);
        header('Allow: OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE, COPY, PROPPATCH, LOCK, UNLOCK');
        header('DAV: 1, 2');
        header('MS-Author-Via: DAV');
    }
    
    /**
     * PROPFIND 처리 (파일/폴더 목록)
     */
    private function handlePropfind(string $path): void {
        $depth = $_SERVER['HTTP_DEPTH'] ?? 'infinity';
        if ($depth === 'infinity') {
            $depth = 1; // 무한 깊이는 1로 제한
        }
        
        $parsed = $this->parsePath($path);
        if ($parsed === null) {
            http_response_code(403);
            return;
        }
        
        $responses = [];
        
        if ($parsed['is_root']) {
            // 루트: 스토리지 목록 표시
            $responses[] = $this->buildResponse('/', true, time(), 0);
            
            if ($depth > 0) {
                $storages = $this->storage->getStorages();
                $allStorages = array_merge($storages['home'] ?? [], $storages['shared'] ?? []);
                
                foreach ($allStorages as $storage) {
                    if (!($storage['can_read'] ?? false)) continue;
                    $name = $storage['id'] . '_' . preg_replace('/[^a-zA-Z0-9가-힣_-]/', '_', $storage['name']);
                    $responses[] = $this->buildResponse('/' . $storage['id'], true, time(), 0, $storage['name']);
                }
            }
        } else {
            $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
            
            if (!$realPath || !file_exists($realPath)) {
                http_response_code(404);
                return;
            }
            
            $isDir = is_dir($realPath);
            $href = '/' . $path;
            $responses[] = $this->buildResponse($href, $isDir, filemtime($realPath), $isDir ? 0 : filesize($realPath));
            
            if ($isDir && $depth > 0) {
                $items = scandir($realPath);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    
                    $itemPath = $realPath . DIRECTORY_SEPARATOR . $item;
                    $itemIsDir = is_dir($itemPath);
                    $itemHref = '/' . $path . '/' . rawurlencode($item);
                    
                    $responses[] = $this->buildResponse(
                        $itemHref,
                        $itemIsDir,
                        filemtime($itemPath),
                        $itemIsDir ? 0 : filesize($itemPath)
                    );
                }
            }
        }
        
        $this->sendMultiStatus($responses);
    }
    
    /**
     * PROPFIND 응답 빌드
     */
    private function buildResponse(string $href, bool $isDir, int $mtime, int $size, ?string $displayName = null): array {
        $props = [
            'displayname' => $displayName ?? basename($href) ?: '/',
            'getlastmodified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'resourcetype' => $isDir ? '<D:collection/>' : '',
        ];
        
        if (!$isDir) {
            $props['getcontentlength'] = $size;
            $props['getcontenttype'] = $this->getMimeType(basename($href));
        }
        
        // ETag 추가
        $props['getetag'] = '"' . md5($href . $mtime . $size) . '"';
        
        return [
            'href' => $this->baseUri . $href,
            'props' => $props
        ];
    }
    
    /**
     * MultiStatus XML 응답
     */
    private function sendMultiStatus(array $responses): void {
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<D:multistatus xmlns:D="DAV:">' . "\n";
        
        foreach ($responses as $response) {
            $xml .= '<D:response>' . "\n";
            $xml .= '<D:href>' . htmlspecialchars($response['href']) . '</D:href>' . "\n";
            $xml .= '<D:propstat>' . "\n";
            $xml .= '<D:prop>' . "\n";
            
            foreach ($response['props'] as $name => $value) {
                if ($name === 'resourcetype') {
                    $xml .= "<D:resourcetype>{$value}</D:resourcetype>\n";
                } else {
                    $xml .= "<D:{$name}>" . htmlspecialchars($value) . "</D:{$name}>\n";
                }
            }
            
            $xml .= '</D:prop>' . "\n";
            $xml .= '<D:status>HTTP/1.1 200 OK</D:status>' . "\n";
            $xml .= '</D:propstat>' . "\n";
            $xml .= '</D:response>' . "\n";
        }
        
        $xml .= '</D:multistatus>';
        
        echo $xml;
    }
    
    /**
     * GET 처리 (파일 다운로드)
     */
    private function handleGet(string $path, bool $headOnly = false): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            // 브라우저에서 접근 시 안내 메시지
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><title>WebDAV Server</title></head><body>';
            echo '<h1>WebDAV Server</h1>';
            echo '<p>이 주소는 WebDAV 서버입니다.</p>';
            echo '<p><strong>Windows에서 연결하는 방법:</strong></p>';
            echo '<ol>';
            echo '<li>파일 탐색기 → "내 PC" 우클릭 → "네트워크 드라이브 연결"</li>';
            echo '<li>폴더에 이 URL 입력</li>';
            echo '<li>사용자명과 비밀번호 입력</li>';
            echo '</ol>';
            echo '</body></html>';
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        
        if (!$realPath || !file_exists($realPath)) {
            http_response_code(404);
            return;
        }
        
        if (is_dir($realPath)) {
            http_response_code(403);
            return;
        }
        
        $size = filesize($realPath);
        $mtime = filemtime($realPath);
        $mime = $this->getMimeType($realPath);
        
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: "' . md5($path . $mtime . $size) . '"');
        
        if (!$headOnly) {
            readfile($realPath);
        }
    }
    
    /**
     * PUT 처리 (파일 업로드)
     */
    private function handlePut(string $path): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 쓰기 권한 확인
        if (!$this->checkPermission($parsed['storage_id'], 'can_write')) {
            http_response_code(403);
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        if (!$realPath) {
            http_response_code(403);
            return;
        }
        
        // 상위 디렉토리 존재 확인
        $dir = dirname($realPath);
        if (!is_dir($dir)) {
            http_response_code(409); // Conflict - 상위 폴더 없음
            return;
        }
        
        $isNew = !file_exists($realPath);
        
        // 파일 저장
        $input = fopen('php://input', 'r');
        $output = fopen($realPath, 'w');
        
        if (!$output) {
            http_response_code(500);
            return;
        }
        
        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        
        http_response_code($isNew ? 201 : 204);
    }
    
    /**
     * DELETE 처리
     */
    private function handleDelete(string $path): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 삭제 권한 확인
        if (!$this->checkPermission($parsed['storage_id'], 'can_delete')) {
            http_response_code(403);
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        
        if (!$realPath || !file_exists($realPath)) {
            http_response_code(404);
            return;
        }
        
        if (is_dir($realPath)) {
            $this->deleteDirectory($realPath);
        } else {
            unlink($realPath);
        }
        
        http_response_code(204);
    }
    
    /**
     * MKCOL 처리 (폴더 생성)
     */
    private function handleMkcol(string $path): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 쓰기 권한 확인
        if (!$this->checkPermission($parsed['storage_id'], 'can_write')) {
            http_response_code(403);
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        if (!$realPath) {
            http_response_code(403);
            return;
        }
        
        if (file_exists($realPath)) {
            http_response_code(405); // 이미 존재
            return;
        }
        
        // 상위 디렉토리 존재 확인
        $parent = dirname($realPath);
        if (!is_dir($parent)) {
            http_response_code(409); // Conflict
            return;
        }
        
        if (mkdir($realPath, 0755)) {
            http_response_code(201);
        } else {
            http_response_code(500);
        }
    }
    
    /**
     * MOVE 처리 (이동/이름변경)
     */
    private function handleMove(string $path): void {
        $this->handleMoveOrCopy($path, true);
    }
    
    /**
     * COPY 처리
     */
    private function handleCopy(string $path): void {
        $this->handleMoveOrCopy($path, false);
    }
    
    /**
     * MOVE/COPY 공통 처리
     */
    private function handleMoveOrCopy(string $srcPath, bool $isMove): void {
        $destination = $_SERVER['HTTP_DESTINATION'] ?? '';
        if (empty($destination)) {
            http_response_code(400);
            return;
        }
        
        // Destination 헤더에서 경로 추출
        $destUri = parse_url($destination, PHP_URL_PATH);
        $destPath = $this->getPathFromUri($destUri);
        
        $srcParsed = $this->parsePath($srcPath);
        $destParsed = $this->parsePath($destPath);
        
        if ($srcParsed === null || $destParsed === null || $srcParsed['is_root'] || $destParsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 권한 확인
        if (!$this->checkPermission($destParsed['storage_id'], 'can_write')) {
            http_response_code(403);
            return;
        }
        
        if ($isMove && !$this->checkPermission($srcParsed['storage_id'], 'can_delete')) {
            http_response_code(403);
            return;
        }
        
        $srcRealPath = $this->getRealPath($srcParsed['storage_id'], $srcParsed['relative_path']);
        $destRealPath = $this->getRealPath($destParsed['storage_id'], $destParsed['relative_path']);
        
        if (!$srcRealPath || !file_exists($srcRealPath)) {
            http_response_code(404);
            return;
        }
        
        if (!$destRealPath) {
            http_response_code(403);
            return;
        }
        
        $overwrite = ($_SERVER['HTTP_OVERWRITE'] ?? 'T') === 'T';
        $destExists = file_exists($destRealPath);
        
        if ($destExists && !$overwrite) {
            http_response_code(412); // Precondition Failed
            return;
        }
        
        // 대상 상위 폴더 확인
        $destDir = dirname($destRealPath);
        if (!is_dir($destDir)) {
            http_response_code(409);
            return;
        }
        
        if ($isMove) {
            // 이동
            if ($destExists) {
                if (is_dir($destRealPath)) {
                    $this->deleteDirectory($destRealPath);
                } else {
                    unlink($destRealPath);
                }
            }
            $success = rename($srcRealPath, $destRealPath);
        } else {
            // 복사
            if (is_dir($srcRealPath)) {
                $success = $this->copyDirectory($srcRealPath, $destRealPath);
            } else {
                $success = copy($srcRealPath, $destRealPath);
            }
        }
        
        if ($success) {
            http_response_code($destExists ? 204 : 201);
        } else {
            http_response_code(500);
        }
    }
    
    /**
     * PROPPATCH 처리 (속성 변경 - 최소 지원)
     */
    private function handleProppatch(string $path): void {
        // 대부분의 클라이언트에서 필요하지만 실제로는 무시해도 됨
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<D:multistatus xmlns:D="DAV:">' . "\n";
        $xml .= '<D:response>' . "\n";
        $xml .= '<D:href>' . htmlspecialchars($this->baseUri . '/' . $path) . '</D:href>' . "\n";
        $xml .= '<D:propstat>' . "\n";
        $xml .= '<D:prop/>' . "\n";
        $xml .= '<D:status>HTTP/1.1 200 OK</D:status>' . "\n";
        $xml .= '</D:propstat>' . "\n";
        $xml .= '</D:response>' . "\n";
        $xml .= '</D:multistatus>';
        
        echo $xml;
    }
    
    /**
     * LOCK 처리 (잠금 - 최소 지원)
     */
    private function handleLock(string $path): void {
        // Windows에서 Office 파일 편집 시 필요
        $token = 'opaquelocktoken:' . uniqid();
        
        http_response_code(200);
        header('Content-Type: application/xml; charset=utf-8');
        header('Lock-Token: <' . $token . '>');
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<D:prop xmlns:D="DAV:">' . "\n";
        $xml .= '<D:lockdiscovery>' . "\n";
        $xml .= '<D:activelock>' . "\n";
        $xml .= '<D:locktype><D:write/></D:locktype>' . "\n";
        $xml .= '<D:lockscope><D:exclusive/></D:lockscope>' . "\n";
        $xml .= '<D:depth>infinity</D:depth>' . "\n";
        $xml .= '<D:owner/>' . "\n";
        $xml .= '<D:timeout>Second-3600</D:timeout>' . "\n";
        $xml .= '<D:locktoken><D:href>' . $token . '</D:href></D:locktoken>' . "\n";
        $xml .= '</D:activelock>' . "\n";
        $xml .= '</D:lockdiscovery>' . "\n";
        $xml .= '</D:prop>';
        
        echo $xml;
    }
    
    /**
     * UNLOCK 처리
     */
    private function handleUnlock(string $path): void {
        http_response_code(204);
    }
    
    /**
     * MIME 타입 가져오기
     */
    private function getMimeType(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
        ];
        
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * 디렉토리 삭제 (재귀)
     */
    private function deleteDirectory(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * 디렉토리 복사 (재귀)
     */
    private function copyDirectory(string $src, string $dst): bool {
        if (!is_dir($src)) {
            return false;
        }
        
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        
        return true;
    }
}
