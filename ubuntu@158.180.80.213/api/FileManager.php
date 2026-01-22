<?php
/**
 * FileManager - 파일 작업 관리
 */
class FileManager {
    private $db;
    private $auth;
    private $storage;
    private $fileIndex;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->auth = new Auth();
        $this->storage = new Storage();
        $this->fileIndex = FileIndex::getInstance();
    }
    
    // 파일 목록 조회
    public function listFiles(int $storageId, string $relativePath = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => '읽기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        // 베이스 폴더가 없으면 생성
        if (!is_dir($basePath)) {
            @mkdir($basePath, 0755, true);
        }
        
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        // 경로 탐색 공격 방지
        if (!$this->isPathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (!is_dir($fullPath)) {
            // 루트 경로면 생성 시도
            if (empty($relativePath)) {
                @mkdir($fullPath, 0755, true);
            }
            if (!is_dir($fullPath)) {
                return ['success' => false, 'error' => '폴더를 찾을 수 없습니다.'];
            }
        }
        
        $items = [];
        $iterator = new DirectoryIterator($fullPath);
        
        foreach ($iterator as $file) {
            if ($file->isDot()) continue;
            
            $filename = $file->getFilename();
            // 숨김 파일 제외 (.htaccess, .gitignore 등)
            if (substr($filename, 0, 1) === '.') continue;
            
            $item = [
                'name' => $filename,
                'path' => $relativePath ? $relativePath . '/' . $filename : $filename,
                'is_dir' => $file->isDir(),
                'size' => $file->isDir() ? 0 : $file->getSize(),
                'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                'extension' => $file->isDir() ? '' : strtolower($file->getExtension()),
            ];
            
            $item['type'] = $this->getFileType($item['extension'], $item['is_dir']);
            $item['icon'] = $this->getFileIcon($item['type']);
            
            $items[] = $item;
        }
        
        // 정렬: 폴더 먼저, 이름순
        usort($items, function($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $b['is_dir'] - $a['is_dir'];
            }
            return strcasecmp($a['name'], $b['name']);
        });
        
        return [
            'success' => true,
            'path' => $relativePath,
            'items' => $items,
            'breadcrumb' => $this->getBreadcrumb($relativePath)
        ];
    }
    
    // 용량 체크 (업로드 전) - 내부용 (최적화 버전)
    private function checkQuota(int $storageId, int $fileSize): array {
        $storageInfo = $this->storage->getStorageById($storageId);
        if (!$storageInfo) {
            return ['allowed' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        $storageType = $storageInfo['storage_type'] ?? 'local';
        $basePath = $this->storage->getRealPath($storageId);
        
        // home 타입: 사용자별 quota 체크
        if ($storageType === 'home') {
            $userId = $this->auth->getUserId();
            $user = $this->db->find('users', ['id' => $userId]);
            $quota = (int)($user['quota'] ?? 0);
            
            // quota가 0이면 무제한 → 체크 불필요
            if ($quota > 0) {
                // home 폴더는 일반적으로 작으므로 getDirectorySize 사용
                $used = $this->getDirectorySize($basePath);
                $available = $quota - $used;
                if ($fileSize > $available) {
                    return [
                        'allowed' => false, 
                        'error' => '용량이 부족합니다. (여유: ' . $this->formatSize(max(0, $available)) . ', 파일: ' . $this->formatSize($fileSize) . ')'
                    ];
                }
            }
            return ['allowed' => true];
        }
        
        // shared/local 타입: DB 캐싱된 used_size 사용 (빠름!)
        $quota = (int)($storageInfo['quota'] ?? 0);
        $usedSize = (int)($storageInfo['used_size'] ?? 0);
        
        if ($quota > 0) {
            // quota 설정된 경우: 캐싱된 used_size로 빠르게 체크
            $available = $quota - $usedSize;
            if ($fileSize > $available) {
                return [
                    'allowed' => false, 
                    'error' => '스토리지 용량이 부족합니다. (여유: ' . $this->formatSize(max(0, $available)) . ', 파일: ' . $this->formatSize($fileSize) . ')'
                ];
            }
            
            // 추가로 디스크 여유 공간도 체크
            $diskFree = @disk_free_space($basePath);
            if ($diskFree !== false && $fileSize > $diskFree) {
                return [
                    'allowed' => false, 
                    'error' => '디스크 용량이 부족합니다. (여유: ' . $this->formatSize($diskFree) . ', 파일: ' . $this->formatSize($fileSize) . ')'
                ];
            }
        } else {
            // quota 미설정 (무제한): 디스크 여유 공간만 체크
            $diskFree = @disk_free_space($basePath);
            if ($diskFree !== false && $fileSize > $diskFree) {
                return [
                    'allowed' => false, 
                    'error' => '디스크 용량이 부족합니다. (여유: ' . $this->formatSize($diskFree) . ', 파일: ' . $this->formatSize($fileSize) . ')'
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    // 용량 체크 - API용 public 메서드
    public function checkQuotaPublic(int $storageId, int $fileSize): array {
        $result = $this->checkQuota($storageId, $fileSize);
        $result['success'] = true;  // API 응답 형식
        return $result;
    }
    
    // 용량 포맷
    public function formatSize(int $bytes): string {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
    
    // MIME 타입 검증 (확장자와 실제 타입 비교)
    private function validateMimeType(string $tmpFile, string $filename): bool {
        // 허용할 MIME 타입 매핑
        $allowedMimes = [
            // 이미지
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp'],
            'svg' => ['image/svg+xml'],
            'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],
            // 문서
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'html' => ['text/html'],
            'htm' => ['text/html'],
            'css' => ['text/css', 'text/plain'],
            'js' => ['application/javascript', 'text/javascript', 'text/plain'],
            'json' => ['application/json', 'text/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml'],
            'md' => ['text/markdown', 'text/plain'],
            // 오피스
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'odt' => ['application/vnd.oasis.opendocument.text'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
            // 압축
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'rar' => ['application/x-rar-compressed', 'application/vnd.rar'],
            '7z' => ['application/x-7z-compressed'],
            'tar' => ['application/x-tar'],
            'gz' => ['application/gzip', 'application/x-gzip'],
            // 미디어
            'mp3' => ['audio/mpeg', 'audio/mp3'],
            'mp4' => ['video/mp4'],
            'wav' => ['audio/wav', 'audio/x-wav'],
            'ogg' => ['audio/ogg', 'video/ogg'],
            'webm' => ['video/webm', 'audio/webm'],
            'mkv' => ['video/x-matroska'],
            'avi' => ['video/x-msvideo'],
            'mov' => ['video/quicktime'],
            'flac' => ['audio/flac'],
            'm4a' => ['audio/mp4', 'audio/x-m4a'],
            // 기타
            'csv' => ['text/csv', 'text/plain'],
            'sql' => ['application/sql', 'text/plain'],
            'php' => ['text/x-php', 'text/plain', 'application/x-httpd-php'],
            'py' => ['text/x-python', 'text/plain'],
            'java' => ['text/x-java-source', 'text/plain'],
            'c' => ['text/x-c', 'text/plain'],
            'cpp' => ['text/x-c++', 'text/plain'],
            'h' => ['text/x-c', 'text/plain'],
        ];
        
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 확장자가 허용 목록에 없으면 기본 허용 (알 수 없는 파일 타입)
        if (!isset($allowedMimes[$ext])) {
            return true;
        }
        
        // fileinfo 확장이 없으면 검증 스킵
        if (!function_exists('finfo_open')) {
            return true;
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);
        
        // 실제 MIME이 허용 목록에 있는지 확인
        return in_array($realMime, $allowedMimes[$ext], true);
    }
    
    // 파일 업로드
    public function upload(int $storageId, string $relativePath, array $file): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => '쓰기 권한이 없습니다.'];
        }
        
        // 용량 체크
        $fileSize = (int)($file['size'] ?? 0);
        $quotaCheck = $this->checkQuota($storageId, $fileSize);
        if (!$quotaCheck['allowed']) {
            return ['success' => false, 'error' => $quotaCheck['error']];
        }
        
        // MIME 타입 검증 (확장자 위장 방지)
        if (!$this->validateMimeType($file['tmp_name'], $file['name'])) {
            return ['success' => false, 'error' => '파일 형식이 올바르지 않습니다. (확장자와 실제 파일 타입 불일치)'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $targetDir = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $targetDir)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => '대상 폴더가 없습니다.'];
        }
        
        $filename = $this->sanitizeFilename($file['name']);
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        
        // 파일명 중복 처리
        $targetPath = $this->getUniqueFilename($targetPath);
        
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'error' => '파일 저장에 실패했습니다.'];
        }
        
        // 사용량 업데이트
        $uploadedSize = filesize($targetPath);
        $this->storage->updateUsedSize($storageId, $uploadedSize);
        
        return [
            'success' => true,
            'filename' => basename($targetPath),
            'size' => $uploadedSize
        ];
    }
    
    // 청크 업로드 (대용량)
    public function uploadChunk(int $storageId, string $relativePath, array $data): array {
        DebugLog::log('uploadChunk: Before checkPermission');
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => '쓰기 권한이 없습니다.'];
        }
        DebugLog::log('uploadChunk: After checkPermission');
        
        DebugLog::log('uploadChunk: Before getRealPath');
        $basePath = $this->storage->getRealPath($storageId);
        DebugLog::log('uploadChunk: After getRealPath', ['basePath' => $basePath]);
        
        $targetDir = $this->buildPath($basePath, $relativePath);
        
        // 경로 탐색 공격 방지
        DebugLog::log('uploadChunk: Before isPathSafe');
        if (!$this->isPathSafe($basePath, $targetDir)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        DebugLog::log('uploadChunk: After isPathSafe');
        
        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => '대상 폴더가 없습니다.'];
        }
        
        $filename = $this->sanitizeFilename($data['filename']);
        $chunkIndex = (int)$data['chunkIndex'];
        $totalChunks = (int)$data['totalChunks'];
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['uploadId']); // 보안
        $totalSize = (int)($data['totalSize'] ?? 0);
        $lastModified = (int)($data['lastModified'] ?? 0);
        
        // 폴더 업로드인 경우 상대 경로 처리
        $fileRelativePath = $data['relativePath'] ?? null;
        if ($fileRelativePath) {
            // 상대 경로에서 폴더 부분 추출 (예: "MyFolder/SubFolder/file.txt" -> "MyFolder/SubFolder")
            $pathParts = explode('/', str_replace('\\', '/', $fileRelativePath));
            array_pop($pathParts); // 파일명 제거
            
            if (!empty($pathParts)) {
                // 안전한 경로로 변환
                $subPath = implode(DIRECTORY_SEPARATOR, array_map([$this, 'sanitizeFilename'], $pathParts));
                $targetDir = $targetDir . DIRECTORY_SEPARATOR . $subPath;
                
                // 필요한 폴더 생성
                if (!is_dir($targetDir)) {
                    if (!mkdir($targetDir, 0755, true)) {
                        return ['success' => false, 'error' => '폴더 생성 실패: ' . $subPath];
                    }
                }
            }
        }
        
        // 첫 번째 청크일 때 용량 체크
        if ($chunkIndex === 0 && $totalSize > 0) {
            DebugLog::log('uploadChunk: Before checkQuota');
            $quotaCheck = $this->checkQuota($storageId, $totalSize);
            DebugLog::log('uploadChunk: After checkQuota');
            if (!$quotaCheck['allowed']) {
                return ['success' => false, 'error' => $quotaCheck['error']];
            }
        }
        
        // 임시 청크 저장 디렉토리
        $tempDir = DATA_PATH . '/chunks/' . $uploadId;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // duplicateAction 처리
        $duplicateAction = $data['duplicateAction'] ?? 'rename';
        
        // 메타 정보 저장 (첫 청크일 때)
        $metaFile = $tempDir . '/meta.json';
        if ($chunkIndex === 0) {
            file_put_contents($metaFile, json_encode([
                'filename' => $filename,
                'totalChunks' => $totalChunks,
                'totalSize' => $totalSize,
                'targetDir' => $targetDir,
                'lastModified' => $lastModified,
                'duplicateAction' => $duplicateAction,
                'startTime' => time()
            ]));
        }
        
        // 청크 파일 저장
        $chunkPath = $tempDir . '/chunk_' . str_pad($chunkIndex, 8, '0', STR_PAD_LEFT);
        
        DebugLog::log('uploadChunk: Before move_uploaded_file');
        if (isset($data['file']['tmp_name'])) {
            move_uploaded_file($data['file']['tmp_name'], $chunkPath);
        } else {
            return ['success' => false, 'error' => '청크 파일이 없습니다.'];
        }
        DebugLog::log('uploadChunk: After move_uploaded_file');
        
        // 업로드된 청크 수 확인
        DebugLog::log('uploadChunk: Before glob count');
        $uploadedChunks = count(glob($tempDir . '/chunk_*'));
        DebugLog::log('uploadChunk: After glob count', ['uploaded' => $uploadedChunks, 'total' => $totalChunks]);
        
        // 모든 청크가 업로드되었는지 확인
        if ($uploadedChunks >= $totalChunks) {
            DebugLog::log('uploadChunk: Starting file merge');
            // 메타 정보 읽기
            $meta = json_decode(file_get_contents($metaFile), true);
            $dupAction = $meta['duplicateAction'] ?? 'rename';
            
            // 대상 파일 경로
            $originalPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
            
            // duplicateAction에 따른 처리
            if (file_exists($originalPath)) {
                switch ($dupAction) {
                    case 'skip':
                        // 건너뛰기: 청크 정리 후 종료
                        $this->cleanupChunks($tempDir);
                        return [
                            'success' => true,
                            'complete' => true,
                            'skipped' => true,
                            'filename' => $filename
                        ];
                    
                    case 'overwrite':
                        // 덮어쓰기: 기존 파일 삭제
                        @unlink($originalPath);
                        $targetPath = $originalPath;
                        break;
                    
                    case 'rename':
                    default:
                        // 이름 변경
                        $targetPath = $this->getUniqueFilename($originalPath);
                        break;
                }
            } else {
                $targetPath = $originalPath;
            }
            
            DebugLog::log('uploadChunk: Before file open for merge');
            $outFile = fopen($targetPath, 'wb');
            if (!$outFile) {
                return ['success' => false, 'error' => '파일 생성 실패'];
            }
            
            // 순서대로 병합
            DebugLog::log('uploadChunk: Starting chunk merge loop');
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFile = $tempDir . '/chunk_' . str_pad($i, 8, '0', STR_PAD_LEFT);
                
                if (!file_exists($chunkFile)) {
                    fclose($outFile);
                    unlink($targetPath);
                    return ['success' => false, 'error' => "청크 {$i} 누락"];
                }
                
                $inFile = fopen($chunkFile, 'rb');
                while (!feof($inFile)) {
                    fwrite($outFile, fread($inFile, 8192));
                }
                fclose($inFile);
            }
            
            fclose($outFile);
            DebugLog::log('uploadChunk: Chunk merge complete');
            
            // 원본 파일의 수정 날짜 복원 (meta는 이미 위에서 읽음)
            $actualMtime = time(); // 기본값
            if (!empty($meta['lastModified']) && $meta['lastModified'] > 0) {
                DebugLog::log('uploadChunk: Before touch (mtime restore)');
                $mtime = (int)$meta['lastModified'];
                
                // touch로 수정 시간 설정
                $result = @touch($targetPath, $mtime, $mtime);
                
                // 실패 시 재시도 (한 번만)
                if (!$result) {
                    usleep(50000); // 0.05초 대기 (기존 0.1초에서 단축)
                    @touch($targetPath, $mtime, $mtime);
                }
                $actualMtime = $mtime; // touch에서 설정한 값 사용
                DebugLog::log('uploadChunk: After touch');
            }
            
            // 임시 파일 정리
            DebugLog::log('uploadChunk: Before cleanupChunks');
            $this->cleanupChunks($tempDir);
            DebugLog::log('uploadChunk: After cleanupChunks');
            
            // 사용량 업데이트
            $finalSize = filesize($targetPath);
            $this->storage->updateUsedSize($storageId, $finalSize);
            
            // 인덱스 업데이트 (clearstatcache 제거 - 불필요)
            DebugLog::log('uploadChunk: Before fileIndex addFile');
            $indexPath = empty($relativePath) ? basename($targetPath) : $relativePath . '/' . basename($targetPath);
            if ($fileRelativePath) {
                // 폴더 업로드인 경우 전체 상대 경로 사용
                $pathParts = explode('/', str_replace('\\', '/', $fileRelativePath));
                array_pop($pathParts);
                if (!empty($pathParts)) {
                    $indexPath = empty($relativePath) ? $fileRelativePath : $relativePath . '/' . implode('/', $pathParts) . '/' . basename($targetPath);
                }
            }
            $this->fileIndex->addFile($storageId, $indexPath, [
                'is_dir' => 0,
                'size' => filesize($targetPath),
                'modified' => date('Y-m-d H:i:s', $actualMtime)
            ]);
            DebugLog::log('uploadChunk: After fileIndex addFile');
            
            DebugLog::log('uploadChunk: Returning complete result');
            return [
                'success' => true,
                'complete' => true,
                'filename' => basename($targetPath),
                'size' => filesize($targetPath),
                'mtime_set' => $meta['lastModified'] ?? 0,
                'mtime_actual' => $actualMtime
            ];
        }
        
        DebugLog::log('uploadChunk: Returning progress result');
        return [
            'success' => true,
            'complete' => false,
            'uploaded' => $uploadedChunks,
            'total' => $totalChunks,
            'percent' => round(($uploadedChunks / $totalChunks) * 100)
        ];
    }
    
    // 청크 임시 파일 정리
    private function cleanupChunks(string $tempDir): void {
        if (!is_dir($tempDir)) return;
        
        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($tempDir);
    }
    
    // 오래된 청크 정리 (1일 이상)
    public function cleanupOldChunks(): int {
        $chunksDir = DATA_PATH . '/chunks';
        if (!is_dir($chunksDir)) return 0;
        
        $cleaned = 0;
        $dirs = glob($chunksDir . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $metaFile = $dir . '/meta.json';
            $shouldClean = false;
            
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if (time() - ($meta['startTime'] ?? 0) > 86400) {
                    $shouldClean = true;
                }
            } else {
                // 메타 파일 없으면 디렉토리 수정 시간 기준
                if (time() - filemtime($dir) > 86400) {
                    $shouldClean = true;
                }
            }
            
            if ($shouldClean) {
                $this->cleanupChunks($dir);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    // 다운로드
    public function download(int $storageId, string $relativePath, bool $inline = false, int $speedLimit = 0): void {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            http_response_code(403);
            exit('권한이 없습니다.');
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath)) {
            http_response_code(400);
            exit('잘못된 경로입니다.');
        }
        
        if (!is_file($fullPath)) {
            http_response_code(404);
            exit('파일을 찾을 수 없습니다.');
        }
        
        $filename = basename($fullPath);
        $filesize = filesize($fullPath);
        $mimeType = $this->getMimeType($fullPath);
        
        // 범위 요청 처리 (이어받기)
        $start = 0;
        $end = $filesize - 1;
        
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = intval($matches[1]);
                if (!empty($matches[2])) {
                    $end = intval($matches[2]);
                }
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$filesize}");
        }
        
        header('Content-Type: ' . $mimeType);
        
        // RFC 5987 형식으로 파일명 인코딩 (모든 브라우저 지원)
        // ASCII 안전한 파일명 + UTF-8 인코딩된 파일명 모두 제공
        $filenameSafe = preg_replace('/[^\x20-\x7E]/', '_', $filename);  // ASCII만 (비ASCII는 _로)
        $filenameEncoded = rawurlencode($filename);
        
        // inline 모드면 브라우저에서 직접 표시, 아니면 다운로드
        if ($inline) {
            header("Content-Disposition: inline; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        } else {
            header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        }
        
        header('Content-Length: ' . ($end - $start + 1));
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache');
        
        $fp = fopen($fullPath, 'rb');
        fseek($fp, $start);
        
        $remaining = $end - $start + 1;
        
        // 속도 제한 설정 (MB/s 단위, 0 = 무제한)
        if ($speedLimit > 0) {
            $bytesPerSecond = $speedLimit * 1024 * 1024;
            $chunkSize = 262144; // 256KB 청크 (더 큰 청크로 오버헤드 감소)
            $startTime = microtime(true);
            $totalSent = 0;
            
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $chunk = min($chunkSize, $remaining);
                echo fread($fp, $chunk);
                $remaining -= $chunk;
                $totalSent += $chunk;
                flush();
                
                // 누적 전송량 기준으로 예상 시간 계산
                if ($remaining > 0) {
                    $elapsed = microtime(true) - $startTime;
                    $expectedTime = $totalSent / $bytesPerSecond;
                    $sleepTime = $expectedTime - $elapsed;
                    
                    if ($sleepTime > 0) {
                        usleep((int)($sleepTime * 1000000));
                    }
                }
            }
        } else {
            // 무제한 속도
            while ($remaining > 0 && !feof($fp)) {
                $chunk = min(8192, $remaining);
                echo fread($fp, $chunk);
                $remaining -= $chunk;
                flush();
            }
        }
        
        fclose($fp);
        exit;
    }
    
    // 폴더 생성
    public function createFolder(int $storageId, string $relativePath, string $folderName): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => '쓰기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $targetDir = $this->buildPath($basePath, $relativePath);
        $newFolder = $targetDir . DIRECTORY_SEPARATOR . $this->sanitizeFilename($folderName);
        
        if (!$this->isPathSafe($basePath, $newFolder)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (is_dir($newFolder)) {
            return ['success' => false, 'error' => '같은 이름의 폴더가 이미 있습니다.'];
        }
        
        if (!mkdir($newFolder, 0755, true)) {
            return ['success' => false, 'error' => '폴더 생성에 실패했습니다.'];
        }
        
        // 인덱스 추가
        $indexPath = empty($relativePath) ? basename($newFolder) : $relativePath . '/' . basename($newFolder);
        $this->fileIndex->addFile($storageId, $indexPath, [
            'is_dir' => 1,
            'size' => 0,
            'modified' => date('Y-m-d H:i:s')
        ]);
        
        return ['success' => true, 'name' => basename($newFolder)];
    }
    
    // 파일/폴더 삭제
    public function delete(int $storageId, string $relativePath): array {
        if (!$this->storage->checkPermission($storageId, 'can_delete')) {
            return ['success' => false, 'error' => '삭제 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => '파일을 찾을 수 없습니다.'];
        }
        
        $isDir = is_dir($fullPath);
        
        // 삭제 전 파일/폴더 크기 계산
        $deleteSize = $isDir ? $this->getDirectorySize($fullPath) : filesize($fullPath);
        
        // 휴지통으로 이동
        $trashResult = $this->moveToTrash($storageId, $relativePath, $fullPath);
        
        if (!$trashResult['success']) {
            return $trashResult;
        }
        
        // 사용량 감소
        $this->storage->updateUsedSize($storageId, -$deleteSize);
        
        // 인덱스에서 삭제
        if ($isDir) {
            $this->fileIndex->removeFolder($storageId, $relativePath);
        } else {
            $this->fileIndex->removeFile($storageId, $relativePath);
        }
        
        return ['success' => true];
    }
    
    // 휴지통으로 이동
    private function moveToTrash(int $storageId, string $relativePath, string $fullPath): array {
        // 휴지통 폴더 경로
        $trashDir = TRASH_PATH;
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0755, true);
        }
        
        $user = $this->auth->getUser();
        $isDir = is_dir($fullPath);
        $trashId = uniqid('trash_');
        
        // 휴지통 내 고유 경로 (ID 기반)
        $trashPath = $trashDir . DIRECTORY_SEPARATOR . $trashId;
        
        // 파일/폴더를 휴지통으로 이동
        if (!@rename($fullPath, $trashPath)) {
            // rename 실패 시 복사 후 삭제
            if ($isDir) {
                if (!$this->copyDirectory($fullPath, $trashPath)) {
                    return ['success' => false, 'error' => '휴지통 이동 실패'];
                }
                $this->deleteDirectory($fullPath);
            } else {
                if (!@copy($fullPath, $trashPath)) {
                    return ['success' => false, 'error' => '휴지통 이동 실패'];
                }
                @unlink($fullPath);
            }
        }
        
        // 휴지통 DB에 기록
        $trash = $this->db->load('trash');
        $trash[] = [
            'id' => $trashId,
            'name' => basename($fullPath),
            'original_path' => $relativePath,
            'storage_id' => $storageId,
            'deleted_by' => $user['id'] ?? 0,
            'deleted_by_name' => $user['display_name'] ?? $user['username'] ?? '',
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_dir' => $isDir,
            'size' => $isDir ? $this->getDirectorySize($trashPath) : filesize($trashPath),
            'trash_path' => $trashPath
        ];
        $this->db->save('trash', $trash);
        
        return ['success' => true];
    }
    
    // 휴지통에서 복원
    public function restoreFromTrash(string $trashId): array {
        $trash = $this->db->load('trash');
        $item = null;
        $itemIndex = -1;
        
        foreach ($trash as $index => $t) {
            if ($t['id'] === $trashId) {
                $item = $t;
                $itemIndex = $index;
                break;
            }
        }
        
        if (!$item) {
            return ['success' => false, 'error' => '휴지통에서 찾을 수 없습니다.'];
        }
        
        // 이전 방식으로 저장된 항목 (trash_path 없음) - DB에서만 제거
        if (empty($item['trash_path'])) {
            unset($trash[$itemIndex]);
            $this->db->save('trash', array_values($trash));
            return ['success' => false, 'error' => '이전 방식으로 삭제된 항목은 복원할 수 없습니다. 목록에서 제거됩니다.'];
        }
        
        $storageId = $item['storage_id'];
        
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => '쓰기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        $originalPath = $this->buildPath($basePath, $item['original_path']);
        $trashPath = $item['trash_path'];
        
        if (!file_exists($trashPath)) {
            // 휴지통 파일이 없으면 DB에서만 제거
            unset($trash[$itemIndex]);
            $this->db->save('trash', array_values($trash));
            return ['success' => false, 'error' => '휴지통 파일이 없습니다.'];
        }
        
        // 원래 경로의 상위 폴더가 없으면 생성
        $parentDir = dirname($originalPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        
        // 원래 경로에 같은 이름이 있으면 새 이름 생성
        $restorePath = $this->getUniqueFilename($originalPath);
        
        // 복원
        if (!@rename($trashPath, $restorePath)) {
            // rename 실패 시 복사 후 삭제
            if ($item['is_dir']) {
                if (!$this->copyDirectory($trashPath, $restorePath)) {
                    return ['success' => false, 'error' => '복원 실패'];
                }
                $this->deleteDirectory($trashPath);
            } else {
                if (!@copy($trashPath, $restorePath)) {
                    return ['success' => false, 'error' => '복원 실패'];
                }
                @unlink($trashPath);
            }
        }
        
        // 휴지통 DB에서 제거
        unset($trash[$itemIndex]);
        $this->db->save('trash', array_values($trash));
        
        // 사용량 증가 (복원된 파일/폴더 크기)
        $restoredSize = $item['is_dir'] ? $this->getDirectorySize($restorePath) : filesize($restorePath);
        $this->storage->updateUsedSize($storageId, $restoredSize);
        
        // 인덱스에 다시 추가
        $restoredRelPath = substr($restorePath, strlen($basePath) + 1);
        $restoredRelPath = str_replace('\\', '/', $restoredRelPath);
        $this->reindexPath($storageId, $basePath, $restorePath);
        
        return ['success' => true, 'restored_path' => basename($restorePath)];
    }
    
    // 휴지통에서 영구 삭제
    public function deleteFromTrash(string $trashId): array {
        $trash = $this->db->load('trash');
        $item = null;
        $itemIndex = -1;
        
        foreach ($trash as $index => $t) {
            if ($t['id'] === $trashId) {
                $item = $t;
                $itemIndex = $index;
                break;
            }
        }
        
        if (!$item) {
            return ['success' => false, 'error' => '휴지통에서 찾을 수 없습니다.'];
        }
        
        $trashPath = $item['trash_path'] ?? '';
        
        // 파일/폴더 삭제 (trash_path가 있는 경우만)
        if (!empty($trashPath) && file_exists($trashPath)) {
            if (is_dir($trashPath)) {
                $this->deleteDirectory($trashPath);
            } else {
                @unlink($trashPath);
            }
        }
        
        // 휴지통 DB에서 제거
        unset($trash[$itemIndex]);
        $this->db->save('trash', array_values($trash));
        
        return ['success' => true];
    }
    
    // 휴지통 비우기
    public function emptyTrash(int $userId = null): array {
        $trash = $this->db->load('trash');
        $newTrash = [];
        $deletedCount = 0;
        
        foreach ($trash as $item) {
            // 사용자 ID가 지정되면 해당 사용자 것만 삭제
            if ($userId !== null && ($item['deleted_by'] ?? 0) != $userId) {
                $newTrash[] = $item;
                continue;
            }
            
            $trashPath = $item['trash_path'] ?? '';
            if (file_exists($trashPath)) {
                if (is_dir($trashPath)) {
                    $this->deleteDirectory($trashPath);
                } else {
                    @unlink($trashPath);
                }
            }
            $deletedCount++;
        }
        
        $this->db->save('trash', $newTrash);
        
        return ['success' => true, 'deleted_count' => $deletedCount];
    }
    
    // 휴지통 목록 조회
    public function getTrashList(int $userId = null): array {
        $trash = $this->db->load('trash');
        
        if ($userId !== null) {
            $trash = array_filter($trash, function($item) use ($userId) {
                return ($item['deleted_by'] ?? 0) == $userId;
            });
        }
        
        // 최근 삭제순 정렬
        usort($trash, function($a, $b) {
            return strtotime($b['deleted_at']) - strtotime($a['deleted_at']);
        });
        
        // 스토리지 이름 추가
        foreach ($trash as &$item) {
            $storage = $this->storage->getStorageById($item['storage_id']);
            $item['storage_name'] = $storage['name'] ?? '알 수 없음';
        }
        
        return ['success' => true, 'items' => array_values($trash)];
    }
    
    // 폴더 복사 (휴지통 이동용)
    private function copyDirectory(string $src, string $dst): bool {
        $dir = opendir($src);
        if (!$dir) return false;
        
        @mkdir($dst, 0755, true);
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    closedir($dir);
                    return false;
                }
            }
        }
        
        closedir($dir);
        return true;
    }
    
    // 이름 변경
    public function rename(int $storageId, string $relativePath, string $newName): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => '쓰기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        $parentDir = dirname($fullPath);
        $newPath = $parentDir . DIRECTORY_SEPARATOR . $this->sanitizeFilename($newName);
        
        if (!$this->isPathSafe($basePath, $fullPath) || !$this->isPathSafe($basePath, $newPath)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => '파일을 찾을 수 없습니다.'];
        }
        
        if (file_exists($newPath)) {
            return ['success' => false, 'error' => '같은 이름이 이미 있습니다.'];
        }
        
        if (!rename($fullPath, $newPath)) {
            return ['success' => false, 'error' => '이름 변경에 실패했습니다.'];
        }
        
        // 인덱스 업데이트
        $parentRelPath = dirname($relativePath);
        $newRelPath = ($parentRelPath === '.' || $parentRelPath === '') 
            ? basename($newPath) 
            : $parentRelPath . '/' . basename($newPath);
        $this->fileIndex->moveFile($storageId, $relativePath, $newRelPath);
        
        return ['success' => true, 'name' => basename($newPath)];
    }
    
    // 이동
    public function move(int $sourceStorageId, string $sourcePath, int $destStorageId, string $destPath, string $duplicateAction = 'overwrite'): array {
        try {
            // 소스 쓰기/삭제 권한 확인
            if (!$this->storage->checkPermission($sourceStorageId, 'can_write')) {
                return ['success' => false, 'error' => '소스 스토리지 쓰기 권한이 없습니다.'];
            }
            
            // 대상 쓰기 권한 확인
            if (!$this->storage->checkPermission($destStorageId, 'can_write')) {
                return ['success' => false, 'error' => '대상 스토리지 쓰기 권한이 없습니다.'];
            }
            
            $sourceBasePath = $this->storage->getRealPath($sourceStorageId);
            $destBasePath = $this->storage->getRealPath($destStorageId);
            
            if (!$sourceBasePath) {
                return ['success' => false, 'error' => '소스 스토리지를 찾을 수 없습니다.'];
            }
            if (!$destBasePath) {
                return ['success' => false, 'error' => '대상 스토리지를 찾을 수 없습니다.'];
            }
            
            $sourceFullPath = $this->buildPath($sourceBasePath, $sourcePath);
            $destFullPath = $this->buildPath($destBasePath, $destPath);
            
            if (!$this->isPathSafe($sourceBasePath, $sourceFullPath)) {
                return ['success' => false, 'error' => '잘못된 소스 경로입니다.'];
            }
            if (!$this->isPathSafe($destBasePath, $destFullPath)) {
                return ['success' => false, 'error' => '잘못된 대상 경로입니다.'];
            }
            
            // 원본 존재 확인
            if (!file_exists($sourceFullPath)) {
                return ['success' => false, 'error' => '원본 파일/폴더가 존재하지 않습니다: ' . $sourcePath];
            }
            
            // 대상 폴더 존재 확인 및 생성
            if (!is_dir($destFullPath)) {
                if (!@mkdir($destFullPath, 0755, true)) {
                    return ['success' => false, 'error' => '대상 폴더를 생성할 수 없습니다.'];
                }
            }
            
            $filename = basename($sourceFullPath);
            $newPath = $destFullPath . DIRECTORY_SEPARATOR . $filename;
            
            // 자기 자신으로 이동 방지
            if (realpath($sourceFullPath) === realpath($newPath)) {
                return ['success' => true, 'skipped' => true];
            }
            
            // 중복 처리
            if (file_exists($newPath)) {
                switch ($duplicateAction) {
                    case 'skip':
                        return ['success' => true, 'skipped' => true];
                    case 'rename':
                        $newPath = $this->getUniqueFilename($newPath);
                        break;
                    case 'overwrite':
                    default:
                        // 기존 파일/폴더 삭제
                        if (is_dir($newPath)) {
                            $this->deleteDirectory($newPath);
                        } else {
                            @unlink($newPath);
                        }
                        break;
                }
            }
            
            // 같은 스토리지면 rename, 다른 스토리지면 copy + delete
            $isSameStorage = ($sourceStorageId === $destStorageId);
            $sourceSize = is_dir($sourceFullPath) ? $this->getDirectorySize($sourceFullPath) : (filesize($sourceFullPath) ?: 0);
            
            if ($isSameStorage) {
                // 같은 스토리지: rename 사용
                if (!@rename($sourceFullPath, $newPath)) {
                    return ['success' => false, 'error' => '이동에 실패했습니다.'];
                }
            } else {
                // 다른 스토리지: 복사 후 삭제
                if (is_dir($sourceFullPath)) {
                    $this->copyDirectory($sourceFullPath, $newPath);
                    $this->deleteDirectory($sourceFullPath);
                } else {
                    if (!@copy($sourceFullPath, $newPath)) {
                        return ['success' => false, 'error' => '파일 복사에 실패했습니다.'];
                    }
                    @unlink($sourceFullPath);
                }
                
                // 스토리지 사용량 업데이트
                $this->storage->updateUsedSize($sourceStorageId, -$sourceSize);
                $this->storage->updateUsedSize($destStorageId, $sourceSize);
            }
            
            // 인덱스 업데이트
            $newRelPath = empty($destPath) ? basename($newPath) : $destPath . '/' . basename($newPath);
            if ($isSameStorage) {
                $this->fileIndex->moveFile($sourceStorageId, $sourcePath, $newRelPath);
            }
            // 다른 스토리지로 이동 시 인덱스 처리는 api.php에서 수행
            
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => '이동 중 오류: ' . $e->getMessage()];
        }
    }
    
    // 압축 해제
    public function extractZip(int $storageId, string $zipPath, string $destPath = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => '쓰기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullZipPath = $this->buildPath($basePath, $zipPath);
        
        if (!$this->isPathSafe($basePath, $fullZipPath)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (!file_exists($fullZipPath)) {
            return ['success' => false, 'error' => '파일을 찾을 수 없습니다.'];
        }
        
        $ext = strtolower(pathinfo($fullZipPath, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return ['success' => false, 'error' => 'ZIP 파일만 압축 해제할 수 있습니다.'];
        }
        
        // 압축 해제 경로 결정
        if (empty($destPath)) {
            // zip 파일명으로 폴더 생성
            $zipName = pathinfo($fullZipPath, PATHINFO_FILENAME);
            $extractDir = dirname($fullZipPath) . DIRECTORY_SEPARATOR . $zipName;
        } else {
            $extractDir = $this->buildPath($basePath, $destPath);
        }
        
        // 중복 폴더명 처리
        $extractDir = $this->getUniqueFilename($extractDir);
        
        if (!$this->isPathSafe($basePath, $extractDir)) {
            return ['success' => false, 'error' => '잘못된 대상 경로입니다.'];
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($fullZipPath);
        
        if ($result !== true) {
            return ['success' => false, 'error' => 'ZIP 파일을 열 수 없습니다. (에러: ' . $result . ')'];
        }
        
        // 폴더 생성
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        
        // 압축 해제
        $zip->extractTo($extractDir);
        $zip->close();
        
        return [
            'success' => true, 
            'extracted_to' => basename($extractDir),
            'file_count' => $this->countFiles($extractDir)
        ];
    }
    
    // 압축 생성
    public function createZip(int $storageId, array $paths, string $zipName = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => '읽기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        
        if (empty($paths)) {
            return ['success' => false, 'error' => '압축할 파일을 선택하세요.'];
        }
        
        // ZIP 파일명 결정
        if (empty($zipName)) {
            if (count($paths) === 1) {
                $zipName = pathinfo($paths[0], PATHINFO_FILENAME) . '.zip';
            } else {
                $zipName = 'archive_' . date('Ymd_His') . '.zip';
            }
        }
        
        // 첫 번째 파일의 디렉토리에 ZIP 생성
        $firstPath = $this->buildPath($basePath, $paths[0]);
        $zipDir = is_dir($firstPath) ? dirname($firstPath) : dirname($firstPath);
        $zipFullPath = $zipDir . DIRECTORY_SEPARATOR . $zipName;
        
        // 중복 파일명 처리
        $zipFullPath = $this->getUniqueFilename($zipFullPath);
        
        if (!$this->isPathSafe($basePath, $zipFullPath)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== true) {
            return ['success' => false, 'error' => 'ZIP 파일을 생성할 수 없습니다.'];
        }
        
        $addedCount = 0;
        
        foreach ($paths as $relativePath) {
            $fullPath = $this->buildPath($basePath, $relativePath);
            
            if (!$this->isPathSafe($basePath, $fullPath)) {
                continue;
            }
            
            if (!file_exists($fullPath)) {
                continue;
            }
            
            if (is_dir($fullPath)) {
                // 폴더 압축
                $this->addFolderToZip($zip, $fullPath, basename($fullPath));
                $addedCount++;
            } else {
                // 파일 압축
                $zip->addFile($fullPath, basename($fullPath));
                $addedCount++;
            }
        }
        
        $zip->close();
        
        if ($addedCount === 0) {
            @unlink($zipFullPath);
            return ['success' => false, 'error' => '압축할 파일이 없습니다.'];
        }
        
        return [
            'success' => true,
            'zip_name' => basename($zipFullPath),
            'file_count' => $addedCount
        ];
    }
    
    // ZIP에 폴더 추가 (재귀)
    private function addFolderToZip(ZipArchive $zip, string $folderPath, string $zipPath): void {
        $zip->addEmptyDir($zipPath);
        
        $files = scandir($folderPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullPath = $folderPath . DIRECTORY_SEPARATOR . $file;
            $localPath = $zipPath . '/' . $file;
            
            if (is_dir($fullPath)) {
                $this->addFolderToZip($zip, $fullPath, $localPath);
            } else {
                $zip->addFile($fullPath, $localPath);
            }
        }
    }
    
    // 파일 개수 세기
    private function countFiles(string $dir): int {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) $count++;
        }
        return $count;
    }
    
    // 복사
    public function copy(int $sourceStorageId, string $sourcePath, int $destStorageId, string $destPath, string $duplicateAction = 'overwrite'): array {
        try {
            // 소스 읽기 권한 확인
            if (!$this->storage->checkPermission($sourceStorageId, 'can_read')) {
                return ['success' => false, 'error' => '소스 스토리지 읽기 권한이 없습니다.'];
            }
            
            // 대상 쓰기 권한 확인
            if (!$this->storage->checkPermission($destStorageId, 'can_write')) {
                return ['success' => false, 'error' => '대상 스토리지 쓰기 권한이 없습니다.'];
            }
            
            $sourceBasePath = $this->storage->getRealPath($sourceStorageId);
            $destBasePath = $this->storage->getRealPath($destStorageId);
            
            if (!$sourceBasePath) {
                return ['success' => false, 'error' => '소스 스토리지를 찾을 수 없습니다.'];
            }
            if (!$destBasePath) {
                return ['success' => false, 'error' => '대상 스토리지를 찾을 수 없습니다.'];
            }
            
            $sourceFullPath = $this->buildPath($sourceBasePath, $sourcePath);
            $destFullPath = $this->buildPath($destBasePath, $destPath);
            
            if (!$this->isPathSafe($sourceBasePath, $sourceFullPath)) {
                return ['success' => false, 'error' => '잘못된 소스 경로입니다.'];
            }
            if (!$this->isPathSafe($destBasePath, $destFullPath)) {
                return ['success' => false, 'error' => '잘못된 대상 경로입니다.'];
            }
            
            // 원본 존재 확인
            if (!file_exists($sourceFullPath)) {
                return ['success' => false, 'error' => '원본 파일/폴더가 존재하지 않습니다: ' . $sourcePath];
            }
            
            // 대상 폴더 존재 확인 및 생성
            if (!is_dir($destFullPath)) {
                if (!@mkdir($destFullPath, 0755, true)) {
                    return ['success' => false, 'error' => '대상 폴더를 생성할 수 없습니다.'];
                }
            }
            
            $filename = basename($sourceFullPath);
            $newPath = $destFullPath . DIRECTORY_SEPARATOR . $filename;
            
            // 자기 자신으로 복사 방지
            if (realpath($sourceFullPath) === realpath($newPath)) {
                $newPath = $this->getUniqueFilename($newPath);
            }
            
            // 중복 처리
            if (file_exists($newPath)) {
                switch ($duplicateAction) {
                    case 'skip':
                        return ['success' => true, 'skipped' => true];
                    case 'rename':
                        $newPath = $this->getUniqueFilename($newPath);
                        break;
                    case 'overwrite':
                    default:
                        // 기존 파일/폴더 삭제
                        if (is_dir($newPath)) {
                            $this->deleteDirectory($newPath);
                        } else {
                            @unlink($newPath);
                        }
                        break;
                }
            }
            
            if (is_dir($sourceFullPath)) {
                $this->copyDirectory($sourceFullPath, $newPath);
            } else {
                if (!@copy($sourceFullPath, $newPath)) {
                    return ['success' => false, 'error' => '파일 복사에 실패했습니다.'];
                }
            }
            
            // 대상 스토리지 사용량 증가
            $copiedSize = is_dir($newPath) ? $this->getDirectorySize($newPath) : (filesize($newPath) ?: 0);
            $this->storage->updateUsedSize($destStorageId, $copiedSize);
            
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => '복사 중 오류: ' . $e->getMessage()];
        }
    }
    
    // 검색 (인덱스 기반)
    public function search(int $storageId, string $query, string $basePath = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => '읽기 권한이 없습니다.'];
        }
        
        // 인덱스가 있으면 인덱스 검색
        if ($this->fileIndex->hasIndex()) {
            $results = $this->fileIndex->search($query, $storageId, 100);
            return ['success' => true, 'results' => $results, 'indexed' => true];
        }
        
        // 인덱스가 없으면 폴더 순회 (fallback)
        $storagePath = $this->storage->getRealPath($storageId);
        $searchPath = $this->buildPath($storagePath, $basePath);
        
        $results = [];
        $this->searchRecursive($searchPath, $query, $storagePath, $results, 100);
        
        return ['success' => true, 'results' => $results, 'indexed' => false];
    }
    
    // 통합 검색 (모든 접근 가능한 스토리지) - 인덱스 기반
    public function searchAll(string $query): array {
        if (empty(trim($query))) {
            return ['success' => false, 'error' => '검색어를 입력하세요.'];
        }
        
        // 현재 사용자가 접근 가능한 스토리지 목록 가져오기
        $storageData = $this->storage->getStorages();
        $allowedStorages = array_merge(
            $storageData['home'] ?? [],
            $storageData['shared'] ?? [],
            $storageData['public'] ?? []
        );
        
        // 읽기 권한 있는 스토리지 ID만 추출
        $allowedIds = [];
        $storageMap = [];
        foreach ($allowedStorages as $s) {
            if ($s['can_read'] ?? false) {
                $allowedIds[] = $s['id'];
                $storageMap[$s['id']] = $s;
            }
        }
        
        if (empty($allowedIds)) {
            return ['success' => true, 'results' => [], 'indexed' => true];
        }
        
        // 인덱스가 있으면 인덱스 검색 (허용된 스토리지만)
        if ($this->fileIndex->hasIndex()) {
            $results = $this->fileIndex->search($query, $allowedIds, 200);
            
            // 스토리지 정보 추가
            foreach ($results as &$item) {
                $storage = $storageMap[$item['storage_id']] ?? null;
                if ($storage) {
                    $item['storage_name'] = $storage['name'];
                    $item['storage_type'] = $storage['storage_type'] ?? 'unknown';
                }
            }
            
            return ['success' => true, 'results' => $results, 'indexed' => true];
        }
        
        // 인덱스가 없으면 폴더 순회 (fallback)
        $results = [];
        
        foreach ($allowedStorages as $storage) {
            $storageId = $storage['id'];
            $storageName = $storage['name'];
            $storageType = $storage['storage_type'] ?? 'unknown';
            
            if (!($storage['can_read'] ?? false)) {
                continue;
            }
            
            $storagePath = $this->storage->getRealPath($storageId);
            if (!$storagePath || !is_dir($storagePath)) {
                continue;
            }
            
            $storageResults = [];
            $this->searchRecursive($storagePath, $query, $storagePath, $storageResults, 50);
            
            // 각 결과에 스토리지 정보 추가
            foreach ($storageResults as &$item) {
                $item['storage_id'] = $storageId;
                $item['storage_name'] = $storageName;
                $item['storage_type'] = $storageType;
            }
            
            $results = array_merge($results, $storageResults);
            
            // 전체 결과 제한
            if (count($results) >= 200) {
                break;
            }
        }
        
        // 이름순 정렬
        usort($results, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        return ['success' => true, 'results' => array_slice($results, 0, 200), 'indexed' => false];
    }
    
    // 파일 정보
    public function getInfo(int $storageId, string $relativePath): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => '읽기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => '파일을 찾을 수 없습니다.'];
        }
        
        $info = [
            'name' => basename($fullPath),
            'path' => $relativePath,
            'is_dir' => is_dir($fullPath),
            'size' => is_dir($fullPath) ? $this->getDirectorySize($fullPath) : filesize($fullPath),
            'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
            'created' => date('Y-m-d H:i:s', filectime($fullPath)),
            'permissions' => substr(sprintf('%o', fileperms($fullPath)), -4)
        ];
        
        if (!$info['is_dir']) {
            $info['extension'] = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $info['mime_type'] = $this->getMimeType($fullPath);
        }
        
        return ['success' => true, 'info' => $info];
    }
    
    // === 헬퍼 메서드 ===
    
    private function buildPath(string $base, string $relative): string {
        // 슬래시 통일 후 DIRECTORY_SEPARATOR로 변환
        $base = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base);
        $relative = trim($relative, '/\\');
        if (empty($relative)) {
            return $base;
        }
        return $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }
    
    private function isPathSafe(string $basePath, string $targetPath): bool {
        // 경로 정규화 (Windows/Linux 호환)
        $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath);
        $targetPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetPath);
        
        $realBase = realpath($basePath);
        $realTarget = realpath($targetPath);
        
        // basePath가 존재하지 않으면 생성 시도
        if ($realBase === false) {
            if (!is_dir($basePath)) {
                @mkdir($basePath, 0755, true);
            }
            $realBase = realpath($basePath);
            if ($realBase === false) {
                return false;
            }
        }
        
        // 아직 존재하지 않는 경로 (새 파일/폴더)
        if ($realTarget === false) {
            // 부모 디렉토리 확인
            $parent = dirname($targetPath);
            while (!is_dir($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }
            $realParent = realpath($parent);
            if ($realParent === false) {
                return false;
            }
            // Windows 대소문자 무시
            if (DIRECTORY_SEPARATOR === '\\') {
                return stripos($realParent . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0
                       || strcasecmp($realParent, $realBase) === 0;
            }
            return strpos($realParent . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0
                   || $realParent === $realBase;
        }
        
        // Windows 대소문자 무시
        if (DIRECTORY_SEPARATOR === '\\') {
            return stripos($realTarget . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0
                   || strcasecmp($realTarget, $realBase) === 0;
        }
        
        return strpos($realTarget . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0 
               || $realTarget === $realBase;
    }
    
    private function sanitizeFilename(string $filename): string {
        // 위험한 문자 제거
        $filename = preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '', $filename);
        $filename = trim($filename, '. ');
        return $filename ?: 'unnamed';
    }
    
    private function getUniqueFilename(string $path): string {
        if (!file_exists($path)) {
            return $path;
        }
        
        $dir = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        
        // Windows 스타일: "파일명 - 복사본.ext", "파일명 - 복사본 (2).ext", ...
        $copyName = $filename . ' - 복사본';
        $newPath = $dir . DIRECTORY_SEPARATOR . $copyName . ($ext ? '.' . $ext : '');
        
        if (!file_exists($newPath)) {
            return $newPath;
        }
        
        $counter = 2;
        do {
            $newName = $copyName . ' (' . $counter . ')' . ($ext ? '.' . $ext : '');
            $newPath = $dir . DIRECTORY_SEPARATOR . $newName;
            $counter++;
        } while (file_exists($newPath));
        
        return $newPath;
    }
    
    private function getFileType(string $extension, bool $isDir): string {
        if ($isDir) return 'folder';
        if ($extension === 'pdf') return 'pdf';
        if (in_array($extension, PREVIEW_EXTENSIONS['image'])) return 'image';
        if (in_array($extension, PREVIEW_EXTENSIONS['video'])) return 'video';
        if (in_array($extension, PREVIEW_EXTENSIONS['audio'])) return 'audio';
        if (in_array($extension, PREVIEW_EXTENSIONS['document'])) return 'document';
        if (in_array($extension, PREVIEW_EXTENSIONS['code'])) return 'code';
        if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'])) return 'archive';
        return 'default';
    }
    
    private function getFileIcon(string $type): string {
        return FILE_ICONS[$type] ?? FILE_ICONS['default'];
    }
    
    private function getMimeType(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'html' => 'text/html',
            'json' => 'application/json', 'xml' => 'application/xml',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed'
        ];
        return $mimes[$ext] ?? 'application/octet-stream';
    }
    
    private function getBreadcrumb(string $path): array {
        if (empty($path)) return [];
        
        $parts = explode('/', str_replace('\\', '/', $path));
        $breadcrumb = [];
        $current = '';
        
        foreach ($parts as $part) {
            $current .= ($current ? '/' : '') . $part;
            $breadcrumb[] = ['name' => $part, 'path' => $current];
        }
        
        return $breadcrumb;
    }
    
    private function deleteDirectory(string $dir): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
    
    private function searchRecursive(string $dir, string $query, string $basePath, array &$results, int $limit): void {
        if (count($results) >= $limit) return;
        
        try {
            $iterator = new DirectoryIterator($dir);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;
                if (count($results) >= $limit) return;
                
                $filename = $file->getFilename();
                if (stripos($filename, $query) !== false) {
                    $fullPath = $file->getPathname();
                    $relativePath = substr($fullPath, strlen($basePath) + 1);
                    $relativePath = str_replace('\\', '/', $relativePath);
                    
                    $results[] = [
                        'name' => $filename,
                        'path' => $relativePath,
                        'is_dir' => $file->isDir(),
                        'size' => $file->isDir() ? 0 : $file->getSize(),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime())
                    ];
                }
                
                if ($file->isDir()) {
                    $this->searchRecursive($file->getPathname(), $query, $basePath, $results, $limit);
                }
            }
        } catch (Exception $e) {
            // 접근 불가 폴더 무시
        }
    }
    
    public function getDirectorySize(string $dir): int {
        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $size += $file->getSize();
            }
        } catch (Exception $e) {}
        return $size;
    }
    
    // ===== 파일 상세 정보 (EXIF 포함) =====
    public function getDetailedInfo(int $storageId, string $relativePath): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => '읽기 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => '파일을 찾을 수 없습니다.'];
        }
        
        $isDir = is_dir($fullPath);
        
        // 경로: 스토리지명 + 상대경로 (시놀로지 방식)
        $storageInfo = $this->storage->getStorageById($storageId);
        $storageName = $storageInfo['name'] ?? '스토리지';
        
        // 파일이면 상위 폴더 경로, 폴더면 해당 경로
        $folderPath = $isDir ? $relativePath : dirname($relativePath);
        if ($folderPath === '.' || $folderPath === '') {
            $displayPath = '/' . $storageName;
        } else {
            $displayPath = '/' . $storageName . '/' . $folderPath;
        }
        
        $info = [
            'name' => basename($fullPath),
            'path' => $displayPath,
            'is_dir' => $isDir,
            'created' => date('Y-m-d H:i:s', filectime($fullPath)),
            'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
            'accessed' => date('Y-m-d H:i:s', fileatime($fullPath)),
        ];
        
        if ($isDir) {
            $info['size'] = $this->getDirectorySize($fullPath);
            $info['size_formatted'] = $this->formatSize($info['size']);
            $info['item_count'] = $this->countDirectoryItems($fullPath);
        } else {
            $info['size'] = filesize($fullPath);
            $info['size_formatted'] = $this->formatSize($info['size']);
            $info['extension'] = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $info['mime'] = $this->getMimeType($fullPath);
            
            // 이미지 EXIF 정보
            $imageExts = ['jpg', 'jpeg', 'tiff', 'tif'];
            if (in_array($info['extension'], $imageExts) && function_exists('exif_read_data')) {
                $exif = @exif_read_data($fullPath, 'ANY_TAG', true);
                if ($exif) {
                    $info['exif'] = [];
                    
                    if (isset($exif['COMPUTED']['Width'])) {
                        $info['dimensions'] = $exif['COMPUTED']['Width'] . ' x ' . $exif['COMPUTED']['Height'];
                    }
                    if (isset($exif['IFD0']['Make'])) {
                        $info['exif']['make'] = $exif['IFD0']['Make'];
                    }
                    if (isset($exif['IFD0']['Model'])) {
                        $info['exif']['model'] = $exif['IFD0']['Model'];
                    }
                    if (isset($exif['EXIF']['DateTimeOriginal'])) {
                        $info['exif']['taken'] = $exif['EXIF']['DateTimeOriginal'];
                    }
                    if (isset($exif['EXIF']['ExposureTime'])) {
                        $info['exif']['exposure'] = $exif['EXIF']['ExposureTime'];
                    }
                    if (isset($exif['EXIF']['FNumber'])) {
                        $f = $exif['EXIF']['FNumber'];
                        if (is_string($f) && strpos($f, '/') !== false) {
                            list($n, $d) = explode('/', $f);
                            $info['exif']['aperture'] = 'f/' . round($n / $d, 1);
                        }
                    }
                    if (isset($exif['EXIF']['ISOSpeedRatings'])) {
                        $info['exif']['iso'] = $exif['EXIF']['ISOSpeedRatings'];
                    }
                    if (isset($exif['EXIF']['FocalLength'])) {
                        $fl = $exif['EXIF']['FocalLength'];
                        if (is_string($fl) && strpos($fl, '/') !== false) {
                            list($n, $d) = explode('/', $fl);
                            $info['exif']['focal_length'] = round($n / $d) . 'mm';
                        }
                    }
                    
                    // GPS 정보
                    if (isset($exif['GPS'])) {
                        $gps = $this->parseGPS($exif['GPS']);
                        if ($gps) {
                            $info['exif']['gps'] = $gps;
                        }
                    }
                }
            }
            
            // 이미지 크기 (EXIF 없는 경우)
            if (!isset($info['dimensions'])) {
                $imageExtsAll = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                if (in_array($info['extension'], $imageExtsAll)) {
                    $size = @getimagesize($fullPath);
                    if ($size) {
                        $info['dimensions'] = $size[0] . ' x ' . $size[1];
                    }
                }
            }
        }
        
        return ['success' => true, 'info' => $info];
    }
    
    // GPS 좌표 파싱
    private function parseGPS(array $gps): ?array {
        if (!isset($gps['GPSLatitude']) || !isset($gps['GPSLongitude'])) {
            return null;
        }
        
        $lat = $this->gpsToDecimal($gps['GPSLatitude'], $gps['GPSLatitudeRef'] ?? 'N');
        $lon = $this->gpsToDecimal($gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E');
        
        if ($lat === null || $lon === null) return null;
        
        return [
            'latitude' => $lat,
            'longitude' => $lon,
            'formatted' => sprintf('%.6f, %.6f', $lat, $lon)
        ];
    }
    
    private function gpsToDecimal(array $coord, string $ref): ?float {
        if (count($coord) < 3) return null;
        
        $degrees = $this->fractionToFloat($coord[0]);
        $minutes = $this->fractionToFloat($coord[1]);
        $seconds = $this->fractionToFloat($coord[2]);
        
        if ($degrees === null) return null;
        
        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        
        if ($ref === 'S' || $ref === 'W') {
            $decimal = -$decimal;
        }
        
        return $decimal;
    }
    
    private function fractionToFloat($value): ?float {
        if (is_numeric($value)) return (float)$value;
        if (!is_string($value)) return null;
        
        $parts = explode('/', $value);
        if (count($parts) !== 2) return null;
        
        $num = (float)$parts[0];
        $den = (float)$parts[1];
        
        return $den != 0 ? $num / $den : null;
    }
    
    // 폴더 내 항목 수
    private function countDirectoryItems(string $dir): array {
        $files = 0;
        $folders = 0;
        
        try {
            $iterator = new DirectoryIterator($dir);
            foreach ($iterator as $item) {
                if ($item->isDot()) continue;
                if ($item->isDir()) $folders++;
                else $files++;
            }
        } catch (Exception $e) {}
        
        return ['files' => $files, 'folders' => $folders];
    }
    
    // ===== 드래그앤드롭 이동/복사 =====
    public function dragDrop(int $storageId, array $sources, string $destPath, string $action = 'move'): array {
        $permission = $action === 'copy' ? 'can_write' : 'can_delete';
        
        if (!$this->storage->checkPermission($storageId, $permission)) {
            return ['success' => false, 'error' => $action === 'copy' ? '쓰기 권한이 없습니다.' : '삭제 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $destFullPath = $this->buildPath($basePath, $destPath);
        
        if (!$this->isPathSafe($basePath, $destFullPath)) {
            return ['success' => false, 'error' => '잘못된 대상 경로입니다.'];
        }
        
        if (!is_dir($destFullPath)) {
            return ['success' => false, 'error' => '대상 폴더가 없습니다.'];
        }
        
        $results = [];
        $errors = [];
        
        foreach ($sources as $source) {
            $sourceFullPath = $this->buildPath($basePath, $source);
            
            if (!$this->isPathSafe($basePath, $sourceFullPath)) {
                $errors[] = "{$source}: 잘못된 경로";
                continue;
            }
            
            if (!file_exists($sourceFullPath)) {
                $errors[] = "{$source}: 파일 없음";
                continue;
            }
            
            $filename = basename($source);
            $targetPath = $destFullPath . DIRECTORY_SEPARATOR . $filename;
            
            // 자기 자신으로 이동 방지
            if (realpath($sourceFullPath) === realpath($targetPath)) {
                $errors[] = "{$source}: 같은 위치";
                continue;
            }
            
            // 하위 폴더로 이동 방지
            if (is_dir($sourceFullPath)) {
                $realDest = realpath($destFullPath);
                $realSrc = realpath($sourceFullPath);
                if ($realDest && $realSrc && strpos($realDest, $realSrc) === 0) {
                    $errors[] = "{$source}: 하위 폴더로 이동 불가";
                    continue;
                }
            }
            
            // 중복 처리
            $targetPath = $this->getUniqueFilename($targetPath);
            
            try {
                if ($action === 'copy') {
                    if (is_dir($sourceFullPath)) {
                        $this->copyDirectory($sourceFullPath, $targetPath);
                    } else {
                        copy($sourceFullPath, $targetPath);
                    }
                } else {
                    rename($sourceFullPath, $targetPath);
                }
                
                $results[] = [
                    'source' => $source,
                    'dest' => $destPath . '/' . basename($targetPath)
                ];
            } catch (Exception $e) {
                $errors[] = "{$source}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => count($errors) === 0,
            'results' => $results,
            'errors' => $errors
        ];
    }
    
    // ===== 파일 정렬 =====
    public function sortFiles(array $items, string $sortBy = 'name', string $order = 'asc'): array {
        usort($items, function($a, $b) use ($sortBy, $order) {
            // 폴더 먼저
            if ($a['is_dir'] !== $b['is_dir']) {
                return $b['is_dir'] - $a['is_dir'];
            }
            
            $result = 0;
            switch ($sortBy) {
                case 'name':
                    $result = strcasecmp($a['name'], $b['name']);
                    break;
                case 'size':
                    $result = ($a['size'] ?? 0) - ($b['size'] ?? 0);
                    break;
                case 'date':
                    $result = strtotime($a['modified'] ?? '0') - strtotime($b['modified'] ?? '0');
                    break;
                case 'type':
                    $result = strcasecmp($a['extension'] ?? '', $b['extension'] ?? '');
                    if ($result === 0) {
                        $result = strcasecmp($a['name'], $b['name']);
                    }
                    break;
            }
            
            return $order === 'desc' ? -$result : $result;
        });
        
        return $items;
    }
    
    // 조건부 파일 검색 (패턴 매칭)
    public function bulkSearch(int $storageId, string $basePath, array $patterns, string $scope = 'recursive', string $type = 'all'): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => '읽기 권한이 없습니다.'];
        }
        
        $storagePath = $this->storage->getRealPath($storageId);
        if (!$storagePath) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        // 검색 인덱스 사용 시도 (인덱스 있으면 항상 인덱스 사용)
        $fileIndex = FileIndex::getInstance();
        if ($fileIndex->isAvailable()) {
            $results = $fileIndex->searchByPatterns($storageId, $basePath, $patterns, $scope, $type, 1000);
            
            return [
                'success' => true,
                'items' => $results,
                'count' => count($results),
                'method' => 'index'
            ];
        }
        
        // 인덱스 없으면 파일 시스템 직접 검색 (fallback)
        $searchPath = empty($basePath) ? $storagePath : $this->buildPath($storagePath, $basePath);
        
        if (!$this->isPathSafe($storagePath, $searchPath)) {
            return ['success' => false, 'error' => '잘못된 경로입니다.'];
        }
        
        $results = [];
        $this->searchByPatterns($searchPath, $storagePath, $patterns, $scope === 'recursive', $type, $results, 1000);
        
        return [
            'success' => true,
            'items' => $results,
            'count' => count($results),
            'method' => 'filesystem'
        ];
    }
    
    // 패턴 매칭 검색 (재귀)
    private function searchByPatterns(string $dir, string $basePath, array $patterns, bool $recursive, string $type, array &$results, int $limit): void {
        if (count($results) >= $limit) return;
        if (!is_dir($dir)) return;
        
        $items = @scandir($dir);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (count($results) >= $limit) return;
            
            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
            $isDir = is_dir($fullPath);
            
            // 타입 필터
            if ($type === 'file' && $isDir) {
                // 폴더인데 파일만 검색 - 하위는 계속 탐색
                if ($recursive) {
                    $this->searchByPatterns($fullPath, $basePath, $patterns, $recursive, $type, $results, $limit);
                }
                continue;
            }
            if ($type === 'folder' && !$isDir) {
                continue;
            }
            
            // 패턴 매칭
            $matched = false;
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if (empty($pattern)) continue;
                
                if ($this->matchPattern($item, $pattern)) {
                    $matched = true;
                    break;
                }
            }
            
            if ($matched) {
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $fullPath);
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                
                $results[] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : @filesize($fullPath),
                    'modified' => date('Y-m-d H:i:s', @filemtime($fullPath))
                ];
            }
            
            // 재귀 탐색 (폴더가 패턴에 매칭되어도 내부 탐색)
            if ($recursive && $isDir) {
                $this->searchByPatterns($fullPath, $basePath, $patterns, $recursive, $type, $results, $limit);
            }
        }
    }
    
    // 와일드카드 패턴 매칭
    private function matchPattern(string $name, string $pattern): bool {
        // 정확히 일치
        if (strcasecmp($name, $pattern) === 0) return true;
        
        // 와일드카드 패턴 (*, ?)
        if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
            // 순서 중요: 먼저 .을 이스케이프, 그 다음 *와 ? 변환
            $regex = preg_quote($pattern, '/');
            $regex = str_replace(['\*', '\?'], ['.*', '.'], $regex);
            return preg_match('/^' . $regex . '$/i', $name) === 1;
        }
        
        // 부분 일치 (와일드카드 없을 때)
        return stripos($name, $pattern) !== false;
    }
    
    // 조건부 일괄 삭제
    public function bulkDelete(int $storageId, array $paths): array {
        if (!$this->storage->checkPermission($storageId, 'can_delete')) {
            return ['success' => false, 'error' => '삭제 권한이 없습니다.'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        $deleted = 0;
        $failed = 0;
        
        foreach ($paths as $relativePath) {
            $fullPath = $this->buildPath($basePath, $relativePath);
            
            if (!$this->isPathSafe($basePath, $fullPath)) {
                $failed++;
                continue;
            }
            
            if (!file_exists($fullPath)) {
                $failed++;
                continue;
            }
            
            // 휴지통으로 이동
            $result = $this->moveToTrash($storageId, $relativePath, $fullPath);
            
            if ($result['success']) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        
        return [
            'success' => true,
            'deleted' => $deleted,
            'failed' => $failed
        ];
    }
    
    /**
     * 특정 경로를 인덱스에 추가 (복원 시 사용)
     */
    private function reindexPath(int $storageId, string $basePath, string $fullPath): void {
        $relativePath = substr($fullPath, strlen($basePath) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        if (is_dir($fullPath)) {
            // 폴더인 경우 재귀적으로 인덱싱
            $this->fileIndex->addFile($storageId, $relativePath, [
                'is_dir' => 1,
                'size' => 0,
                'modified' => date('Y-m-d H:i:s', filemtime($fullPath))
            ]);
            
            $iterator = new DirectoryIterator($fullPath);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;
                $this->reindexPath($storageId, $basePath, $file->getPathname());
            }
        } else {
            // 파일인 경우
            $this->fileIndex->addFile($storageId, $relativePath, [
                'is_dir' => 0,
                'size' => filesize($fullPath),
                'modified' => date('Y-m-d H:i:s', filemtime($fullPath))
            ]);
        }
    }
}