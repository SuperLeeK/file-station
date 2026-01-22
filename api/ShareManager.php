<?php
/**
 * ShareManager - 공유 링크 관리 (JSON 기반)
 */
class ShareManager {
    private $db;
    private $auth;
    private $storage;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->auth = new Auth();
        $this->storage = new Storage();
    }
    
    // 파일에 대한 기존 공유 확인
    public function checkShare(int $storageId, string $filePath): array {
        $userId = $this->auth->getUserId();
        
        $shares = $this->db->load('shares');
        
        // 경로 정규화
        $normalizedPath = str_replace(['\\', '\/'], '/', $filePath);
        
        foreach ($shares as $share) {
            $sharePath = str_replace(['\\', '\/'], '/', $share['file_path']);
            
            if ($share['storage_id'] == $storageId && 
                $sharePath == $normalizedPath && 
                $share['created_by'] == $userId) {
                
                // 만료 확인 - 만료된 공유는 삭제
                if (!empty($share['expire_at']) && strtotime($share['expire_at']) < time()) {
                    $this->db->delete('shares', ['id' => $share['id']]);
                    continue;
                }
                
                // 다운로드 횟수 초과 확인 - 초과한 공유는 삭제
                if (!empty($share['max_downloads']) && 
                    ($share['download_count'] ?? 0) >= $share['max_downloads']) {
                    $this->db->delete('shares', ['id' => $share['id']]);
                    continue;
                }
                
                return ['success' => true, 'share' => $share];
            }
        }
        
        return ['success' => true, 'share' => null];
    }
    
    // 공유 링크 생성
    public function createShare(int $storageId, string $filePath, array $options = []): array {
        // 개인 폴더 공유 허용 여부 체크
        $storageInfo = $this->storage->getStorageById($storageId);
        if ($storageInfo && ($storageInfo['storage_type'] ?? '') === 'home') {
            $settings = $this->db->load('settings');
            $homeShareEnabled = $settings['home_share_enabled'] ?? true;
            if (!$homeShareEnabled) {
                return ['success' => false, 'error' => '개인 폴더 외부 공유가 비활성화되어 있습니다.'];
            }
        }
        
        if (!$this->storage->checkPermission($storageId, 'can_share')) {
            return ['success' => false, 'error' => '공유 권한이 없습니다.'];
        }
        
        // 경로 정규화
        $filePath = str_replace(['\\', '\/'], '/', $filePath);
        
        // 경로 탐색 공격 방지
        if (strpos($filePath, '..') !== false) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        // 파일 존재 확인
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        
        // 경로 안전성 검증 (realpath 기반)
        $realBase = realpath($basePath);
        $realFull = realpath($fullPath);
        
        if ($realBase === false || $realFull === false || strpos($realFull, $realBase) !== 0) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => '파일을 찾을 수 없습니다.'];
        }
        
        $token = $this->generateToken();
        
        $expireAt = null;
        if (!empty($options['expire_days'])) {
            $expireAt = date('Y-m-d H:i:s', strtotime('+' . (int)$options['expire_days'] . ' days'));
        } elseif (!empty($options['expire_at'])) {
            $expireAt = $options['expire_at'];
        }
        
        $password = null;
        if (!empty($options['password'])) {
            $password = password_hash($options['password'], PASSWORD_DEFAULT);
        }
        
        $id = $this->db->insert('shares', [
            'token' => $token,
            'storage_id' => $storageId,
            'file_path' => $filePath,
            'created_by' => $this->auth->getUserId(),
            'password' => $password,
            'expire_at' => $expireAt,
            'max_downloads' => $options['max_downloads'] ?? null,
            'download_count' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // 디버깅: 저장 후 다시 로드해서 확인
        $savedShares = $this->db->load('shares');
        
        $shareUrl = $this->getShareUrl($token);
        
        return [
            'success' => true,
            'id' => $id,
            'token' => $token,
            'url' => $shareUrl
        ];
    }
    
    // 공유 링크 목록
    public function getShares(): array {
        // 만료된 공유 자동 정리
        $this->cleanupExpiredShares();
        
        $userId = $this->auth->getUserId();
        $isAdmin = $this->auth->isAdmin();
        
        $shares = $this->db->load('shares');
        $users = $this->db->load('users');
        $storages = $this->db->load('storages');
        
        // 디버깅
        
        $result = [];
        foreach ($shares as $share) {
            
            if (!$isAdmin && $share['created_by'] != $userId) {
                continue;
            }
            
            // 생성자 이름 추가
            foreach ($users as $user) {
                if ($user['id'] == $share['created_by']) {
                    $share['creator_name'] = $user['username'];
                    break;
                }
            }
            
            // 스토리지 이름 추가
            foreach ($storages as $storage) {
                if ($storage['id'] == $share['storage_id']) {
                    $share['storage_name'] = $storage['name'];
                    break;
                }
            }
            
            $result[] = $share;
        }
        
        // 최신순 정렬
        usort($result, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return $result;
    }
    
    // 만료된 공유 자동 정리
    public function cleanupExpiredShares(): int {
        $shares = $this->db->load('shares');
        $deletedCount = 0;
        $now = time();
        
        
        foreach ($shares as $share) {
            $shouldDelete = false;
            $deleteReason = '';
            
            // 1. 만료일이 지난 경우
            if (!empty($share['expire_at']) && strtotime($share['expire_at']) < $now) {
                $shouldDelete = true;
                $deleteReason = 'expired';
            }
            
            // 2. 다운로드 횟수 초과
            if (!empty($share['max_downloads']) && 
                ($share['download_count'] ?? 0) >= $share['max_downloads']) {
                $shouldDelete = true;
                $deleteReason = 'max_downloads';
            }
            
            // 3. 실제 파일이 존재하지 않는 경우
            if (!$shouldDelete) {
                $basePath = $this->storage->getRealPath($share['storage_id']);
                if ($basePath) {
                    $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                    
                    
                    if (!file_exists($fullPath)) {
                        $shouldDelete = true;
                        $deleteReason = 'file_not_exists';
                    }
                } else {
                    // 스토리지가 삭제된 경우
                    $shouldDelete = true;
                    $deleteReason = 'storage_deleted';
                }
            }
            
            if ($shouldDelete) {
                $this->db->delete('shares', ['id' => $share['id']]);
                $deletedCount++;
            }
        }
        
        return $deletedCount;
    }
    
    // 공유 링크 삭제
    public function deleteShare(int $id): array {
        $share = $this->db->find('shares', ['id' => $id]);
        
        if (!$share) {
            return ['success' => false, 'error' => '공유를 찾을 수 없습니다.'];
        }
        
        if (!$this->auth->isAdmin() && $share['created_by'] != $this->auth->getUserId()) {
            return ['success' => false, 'error' => '권한이 없습니다.'];
        }
        
        $this->db->delete('shares', ['id' => $id]);
        return ['success' => true];
    }
    
    // 공유 링크로 접근
    public function accessShare(string $token, string $password = null): array {
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        
        if (!$share) {
            return ['success' => false, 'error' => '공유 링크를 찾을 수 없습니다.'];
        }
        
        $storage = $this->db->find('storages', ['id' => $share['storage_id']]);
        if (!$storage) {
            // 스토리지가 삭제된 경우 공유도 삭제
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        // 만료 확인
        if ($share['expire_at'] && strtotime($share['expire_at']) < time()) {
            // 만료된 공유 삭제
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => '만료된 공유 링크입니다.'];
        }
        
        // 다운로드 횟수 확인
        if ($share['max_downloads'] && $share['download_count'] >= $share['max_downloads']) {
            // 횟수 초과 공유 삭제
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => '다운로드 횟수를 초과했습니다.'];
        }
        
        // 비밀번호 확인
        if ($share['password']) {
            if (!$password) {
                return ['success' => false, 'error' => 'password_required', 'needs_password' => true];
            }
            if (!password_verify($password, $share['password'])) {
                return ['success' => false, 'error' => '비밀번호가 올바르지 않습니다.'];
            }
        }
        
        // 파일 경로 확인 - Storage 클래스 사용
        $basePath = $this->storage->getRealPath($share['storage_id']);
        
        if (!$basePath) {
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => '스토리지 경로를 찾을 수 없습니다.'];
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        
        if (!file_exists($fullPath)) {
            // 파일이 삭제된 경우 공유도 삭제
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => '파일이 존재하지 않습니다.'];
        }
        
        $isDir = is_dir($fullPath);
        $filename = basename($share['file_path']);
        
        return [
            'success' => true,
            'share' => [
                'token' => $share['token'],
                'filename' => $filename,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : filesize($fullPath),
                'created_at' => $share['created_at'],
                'expire_at' => $share['expire_at'],
                'download_count' => $share['download_count'],
                'max_downloads' => $share['max_downloads']
            ]
        ];
    }
    
    // 공유 파일 다운로드
    public function downloadShare(string $token, string $password = null): void {
        $access = $this->accessShare($token, $password);
        
        if (!$access['success']) {
            if (($access['error'] ?? '') === 'password_required') {
                http_response_code(401);
                echo json_encode(['error' => 'password_required']);
            } else {
                http_response_code(403);
                echo $access['error'] ?? '접근 불가';
            }
            exit;
        }
        
        $share = $this->db->find('shares', ['token' => $token]);
        
        // Storage 클래스 사용하여 실제 경로 가져오기
        $basePath = $this->storage->getRealPath($share['storage_id']);
        
        if (!$basePath) {
            http_response_code(500);
            exit('스토리지 경로를 찾을 수 없습니다.');
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        // 경로 안전성 검증 (realpath 기반)
        $realBase = realpath($basePath);
        $realFull = realpath($fullPath);
        if ($realBase === false || $realFull === false || strpos($realFull, $realBase) !== 0) {
            http_response_code(403);
            exit('잘못된 경로입니다.');
        }
        
        // 다운로드 횟수 증가
        $this->db->update('shares', ['token' => $token], [
            'download_count' => ($share['download_count'] ?? 0) + 1
        ]);
        
        // 폴더인 경우 ZIP으로 압축
        if (is_dir($fullPath)) {
            $this->downloadAsZip($fullPath);
            return;
        }
        
        // 파일 다운로드
        $filename = basename($fullPath);
        $filesize = filesize($fullPath);
        
        // RFC 5987 형식으로 파일명 인코딩
        $filenameSafe = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $filenameEncoded = rawurlencode($filename);
        
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        header('Content-Length: ' . $filesize);
        header('Cache-Control: no-cache');
        
        readfile($fullPath);
        exit;
    }
    
    // ZIP 압축 다운로드
    private function downloadAsZip(string $dir): void {
        $zipName = basename($dir) . '.zip';
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('share_') . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit('ZIP 생성 실패');
        }
        
        $baseName = basename($dir);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            $relativePath = $baseName . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($file->getPathname(), $relativePath);
            }
        }
        
        $zip->close();
        
        // RFC 5987 형식으로 파일명 인코딩
        $zipNameSafe = preg_replace('/[^\x20-\x7E]/', '_', $zipName);
        $zipNameEncoded = rawurlencode($zipName);
        
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"{$zipNameSafe}\"; filename*=UTF-8''{$zipNameEncoded}");
        header('Content-Length: ' . filesize($zipPath));
        
        readfile($zipPath);
        unlink($zipPath);
        exit;
    }
    
    // 토큰 생성
    private function generateToken(): string {
        return bin2hex(random_bytes(SHARE_LINK_LENGTH / 2));
    }
    
    // 공유 URL 생성
    private function getShareUrl(string $token): string {
        // 외부 URL 설정 확인
        $settings = $this->db->load('settings');
        $externalUrl = $settings['external_url'] ?? '';
        
        if (!empty($externalUrl)) {
            // 외부 URL 설정이 있으면 사용
            $externalUrl = rtrim($externalUrl, '/');
            return "{$externalUrl}/share.php?t={$token}";
        }
        
        // 기본: 현재 접속 URL 사용
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME']);
        $path = str_replace('\\', '/', $path); // Windows 역슬래시 제거
        $path = rtrim($path, '/');
        return "{$protocol}://{$host}{$path}/share.php?t={$token}";
    }
    
    // 공유 링크 수정
    public function updateShare(int $id, array $options): array {
        $share = $this->db->find('shares', ['id' => $id]);
        
        if (!$share) {
            return ['success' => false, 'error' => '공유를 찾을 수 없습니다.'];
        }
        
        if (!$this->auth->isAdmin() && $share['created_by'] != $this->auth->getUserId()) {
            return ['success' => false, 'error' => '권한이 없습니다.'];
        }
        
        $updateData = [];
        
        if (isset($options['expire_days'])) {
            $updateData['expire_at'] = $options['expire_days'] 
                ? date('Y-m-d H:i:s', strtotime('+' . (int)$options['expire_days'] . ' days'))
                : null;
        }
        
        if (isset($options['max_downloads'])) {
            $updateData['max_downloads'] = $options['max_downloads'] ?: null;
        }
        
        if (isset($options['password'])) {
            $updateData['password'] = $options['password'] 
                ? password_hash($options['password'], PASSWORD_DEFAULT)
                : null;
        }
        
        if (isset($options['is_active'])) {
            $updateData['is_active'] = $options['is_active'];
        }
        
        if (empty($updateData)) {
            return ['success' => false, 'error' => '변경할 내용이 없습니다.'];
        }
        
        $this->db->update('shares', ['id' => $id], $updateData);
        return ['success' => true];
    }
}