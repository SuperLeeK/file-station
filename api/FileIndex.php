<?php
/**
 * FileIndex - SQLite 기반 파일 인덱스
 * 빠른 검색을 위한 파일 목록 캐시
 */
class FileIndex {
    private static ?FileIndex $instance = null;
    private $db = null;  // SQLite3 또는 null
    private string $dbPath;
    private bool $available = false;
    
    private function __construct() {
        // SQLite3 확장 사용 가능 여부 확인
        if (!class_exists('SQLite3')) {
            $this->available = false;
            return;
        }
        
        $this->dbPath = DATA_PATH . '/file_index.db';
        $this->initDatabase();
        $this->available = true;
    }
    
    public static function getInstance(): FileIndex {
        if (self::$instance === null) {
            self::$instance = new FileIndex();
        }
        return self::$instance;
    }
    
    /**
     * SQLite3 사용 가능 여부
     */
    public function isAvailable(): bool {
        return $this->available;
    }
    
    /**
     * DB 인스턴스 반환 (디버깅용)
     */
    public function getDb() {
        return $this->db;
    }
    
    private function initDatabase(): void {
        $isNew = !file_exists($this->dbPath);
        $this->db = new SQLite3($this->dbPath);
        $this->db->busyTimeout(5000);
        
        // WAL 모드 (동시 접근 성능 향상)
        $this->db->exec('PRAGMA journal_mode=WAL');
        
        if ($isNew) {
            $this->createTables();
        }
    }
    
    private function createTables(): void {
        // 파일 인덱스 테이블
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                storage_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                filepath TEXT NOT NULL,
                is_dir INTEGER NOT NULL DEFAULT 0,
                size INTEGER NOT NULL DEFAULT 0,
                modified TEXT,
                extension TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(storage_id, filepath)
            )
        ');
        
        // 인덱스 생성 (검색 속도 향상)
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_storage ON files(storage_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_filename ON files(filename COLLATE NOCASE)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_filepath ON files(filepath)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_extension ON files(extension)');
        
        // 메타 정보 테이블
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ');
    }
    
    /**
     * 파일 추가/업데이트
     */
    public function addFile(int $storageId, string $filepath, array $info = []): bool {
        if (!$this->available) return false;
        
        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO files (storage_id, filename, filepath, is_dir, size, modified, extension)
            VALUES (:storage_id, :filename, :filepath, :is_dir, :size, :modified, :extension)
        ');
        
        $filename = basename($filepath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
        $stmt->bindValue(':filepath', $filepath, SQLITE3_TEXT);
        $stmt->bindValue(':is_dir', $info['is_dir'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':size', $info['size'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':modified', $info['modified'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':extension', strtolower($extension), SQLITE3_TEXT);
        
        return $stmt->execute() !== false;
    }
    
    /**
     * 파일 삭제
     */
    public function removeFile(int $storageId, string $filepath): bool {
        if (!$this->available) return false;
        
        $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id AND filepath = :filepath');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':filepath', $filepath, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }
    
    /**
     * 폴더 삭제 (하위 항목 포함)
     */
    public function removeFolder(int $storageId, string $folderPath): bool {
        if (!$this->available) return false;
        
        // 폴더 자체 삭제
        $this->removeFile($storageId, $folderPath);
        
        // 하위 항목 삭제
        $pattern = $folderPath . '/%';
        $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id AND filepath LIKE :pattern');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':pattern', $pattern, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }
    
    /**
     * 파일/폴더 이동 (경로 업데이트)
     */
    public function moveFile(int $storageId, string $oldPath, string $newPath): bool {
        if (!$this->available) return false;
        
        // 파일 자체 이동
        $stmt = $this->db->prepare('
            UPDATE files 
            SET filepath = :new_path, filename = :filename 
            WHERE storage_id = :storage_id AND filepath = :old_path
        ');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':old_path', $oldPath, SQLITE3_TEXT);
        $stmt->bindValue(':new_path', $newPath, SQLITE3_TEXT);
        $stmt->bindValue(':filename', basename($newPath), SQLITE3_TEXT);
        $stmt->execute();
        
        // 하위 항목 이동 (폴더인 경우)
        $oldPattern = $oldPath . '/%';
        $oldLen = strlen($oldPath);
        
        $selectStmt = $this->db->prepare('SELECT id, filepath FROM files WHERE storage_id = :storage_id AND filepath LIKE :pattern');
        $selectStmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $selectStmt->bindValue(':pattern', $oldPattern, SQLITE3_TEXT);
        $result = $selectStmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $newFilePath = $newPath . substr($row['filepath'], $oldLen);
            $updateStmt = $this->db->prepare('UPDATE files SET filepath = :new_path WHERE id = :id');
            $updateStmt->bindValue(':new_path', $newFilePath, SQLITE3_TEXT);
            $updateStmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $updateStmt->execute();
        }
        
        return true;
    }
    
    /**
     * 검색
     * @param string $query 검색어
     * @param int|array|null $storageIds 단일 ID, ID 배열, 또는 null(전체)
     * @param int $limit 결과 제한 (0 = 무제한)
     */
    public function search(string $query, $storageIds = null, int $limit = 500): array {
        if (!$this->available) return [];
        
        // 와일드카드 변환: * → %, ? → _
        // 사용자가 이미 *나 ?를 사용했으면 변환
        if (strpos($query, '*') !== false || strpos($query, '?') !== false) {
            // SQL 특수문자 이스케이프 (%, _, \)
            $query = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            // 와일드카드 변환
            $query = str_replace(['*', '?'], ['%', '_'], $query);
            $useEscape = true;
        } else {
            // 일반 검색: 양쪽에 % 추가
            $query = '%' . $query . '%';
            $useEscape = false;
        }
        
        $escapeSql = $useEscape ? " ESCAPE '\\'" : "";
        $limitSql = $limit > 0 ? " LIMIT " . (int)$limit : ""; // 0이면 무제한
        
        if ($storageIds !== null) {
            // 배열이 아니면 배열로 변환
            if (!is_array($storageIds)) {
                $storageIds = [$storageIds];
            }
            
            if (empty($storageIds)) {
                return []; // 허용된 스토리지 없음
            }
            
            // IN 절 생성
            $placeholders = implode(',', array_fill(0, count($storageIds), '?'));
            $stmt = $this->db->prepare("
                SELECT * FROM files 
                WHERE storage_id IN ($placeholders) AND filename LIKE ? COLLATE NOCASE{$escapeSql}
                ORDER BY is_dir DESC, filename ASC
                {$limitSql}
            ");
            
            $paramIndex = 1;
            foreach ($storageIds as $id) {
                $stmt->bindValue($paramIndex++, (int)$id, SQLITE3_INTEGER);
            }
            $stmt->bindValue($paramIndex, $query, SQLITE3_TEXT);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM files 
                WHERE filename LIKE :query COLLATE NOCASE{$escapeSql}
                ORDER BY is_dir DESC, filename ASC
                {$limitSql}
            ");
            $stmt->bindValue(':query', $query, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $files = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = [
                'name' => $row['filename'],
                'path' => $row['filepath'],
                'is_dir' => (bool)$row['is_dir'],
                'size' => (int)$row['size'],
                'modified' => $row['modified'],
                'extension' => $row['extension'],
                'storage_id' => (int)$row['storage_id']
            ];
        }
        
        return $files;
    }
    
    /**
     * 와일드카드 패턴으로 검색 (조건부 일괄삭제용)
     * @param int $storageId 스토리지 ID
     * @param string $basePath 검색 기준 경로 (빈 문자열이면 루트)
     * @param array $patterns 검색 패턴 배열 (*.zip, test*.txt 등)
     * @param string $scope 'recursive' 또는 'current'
     * @param string $type 'all', 'file', 'folder'
     * @param int $limit 최대 결과 수
     */
    public function searchByPatterns(int $storageId, string $basePath, array $patterns, string $scope = 'recursive', string $type = 'all', int $limit = 1000): array {
        if (!$this->available) return [];
        
        // 패턴을 SQL LIKE 패턴으로 변환
        $likePatterns = [];
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) continue;
            
            // 와일드카드를 SQL LIKE로 변환: * → %, ? → _
            $likePattern = str_replace(['*', '?'], ['%', '_'], $pattern);
            // 특수문자 이스케이프 (% _ 제외)
            $likePattern = addcslashes($likePattern, '%_\\');
            $likePattern = str_replace(['\\%', '\\_'], ['%', '_'], $likePattern);
            $likePatterns[] = $likePattern;
        }
        
        if (empty($likePatterns)) return [];
        
        // SQL 쿼리 조건 생성
        $conditions = ['storage_id = :storage_id'];
        $bindings = [];
        
        // 경로 조건
        $normalizedPath = empty($basePath) ? '' : rtrim(str_replace('\\', '/', $basePath), '/');
        
        if ($scope === 'current') {
            // 현재 폴더만 - 직접 자식만 검색
            if (empty($normalizedPath)) {
                // 루트 폴더: filepath에 /가 없는 것 (직접 자식)
                $conditions[] = "filepath NOT LIKE '%/%'";
            } else {
                // 하위 폴더: 정확히 basePath/filename 형태인 것만
                // filepath가 basePath/로 시작하고, 그 이후에 /가 없는 것
                $conditions[] = "(filepath LIKE :path_direct AND filepath NOT LIKE :path_subdir)";
                $bindings[':path_direct'] = $normalizedPath . '/%';
                $bindings[':path_subdir'] = $normalizedPath . '/%/%';
            }
        } else {
            // 재귀 검색
            if (!empty($normalizedPath)) {
                // basePath 하위 전체
                $conditions[] = "(filepath LIKE :path_prefix OR filepath = :path_exact)";
                $bindings[':path_prefix'] = $normalizedPath . '/%';
                $bindings[':path_exact'] = $normalizedPath;
            }
            // 루트에서 재귀는 조건 없음 (전체 검색)
        }
        
        // 타입 조건
        if ($type === 'file') {
            $conditions[] = 'is_dir = 0';
        } elseif ($type === 'folder') {
            $conditions[] = 'is_dir = 1';
        }
        
        // 패턴 조건 (OR로 연결)
        $patternConditions = [];
        foreach ($likePatterns as $i => $lp) {
            $patternConditions[] = "filename LIKE :pattern{$i} ESCAPE '\\'";
            $bindings[":pattern{$i}"] = $lp;
        }
        $conditions[] = '(' . implode(' OR ', $patternConditions) . ')';
        
        $sql = "SELECT * FROM files WHERE " . implode(' AND ', $conditions) . 
               " ORDER BY is_dir DESC, filename ASC LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        
        // 동적 바인딩
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $files = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = [
                'name' => $row['filename'],
                'path' => $row['filepath'],
                'is_dir' => (bool)$row['is_dir'],
                'size' => (int)$row['size'],
                'modified' => $row['modified'],
                'storage_id' => (int)$row['storage_id']
            ];
        }
        
        return $files;
    }
    
    /**
     * 스토리지 전체 재인덱싱
     */
    public function rebuildStorage(int $storageId, string $basePath): int {
        if (!$this->available) return 0;
        
        // 트랜잭션 시작 (대량 INSERT 성능 개선)
        $this->db->exec('BEGIN TRANSACTION');
        
        try {
            // 기존 인덱스 삭제
            $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id');
            $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
            $stmt->execute();
            
            // 새로 인덱싱
            $count = 0;
            $this->indexDirectory($storageId, $basePath, $basePath, $count);
            
            // 트랜잭션 커밋
            $this->db->exec('COMMIT');
            
            return $count;
        } catch (Exception $e) {
            // 오류 시 롤백
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
    
    /**
     * 디렉토리 재귀 인덱싱
     */
    private function indexDirectory(int $storageId, string $dir, string $basePath, int &$count): void {
        if (!$this->available) return;
        if (!is_dir($dir)) return;
        
        try {
            $iterator = new DirectoryIterator($dir);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;
                
                $filename = $file->getFilename();
                // 숨김 파일 제외
                if (substr($filename, 0, 1) === '.') continue;
                
                $fullPath = $file->getPathname();
                $relativePath = substr($fullPath, strlen($basePath) + 1);
                $relativePath = str_replace('\\', '/', $relativePath);
                
                $this->addFile($storageId, $relativePath, [
                    'is_dir' => $file->isDir() ? 1 : 0,
                    'size' => $file->isDir() ? 0 : $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime())
                ]);
                
                $count++;
                
                if ($file->isDir()) {
                    $this->indexDirectory($storageId, $fullPath, $basePath, $count);
                }
            }
        } catch (Exception $e) {
            // 접근 불가 폴더 무시
        }
    }
    
    /**
     * 전체 재인덱싱
     */
    public function rebuildAll(array $storages): array {
        if (!$this->available) return [];
        
        $results = [];
        foreach ($storages as $storage) {
            if (empty($storage['path'])) continue;
            $count = $this->rebuildStorage($storage['id'], $storage['path']);
            $results[$storage['id']] = [
                'name' => $storage['name'],
                'count' => $count
            ];
        }
        
        // 메타 정보 업데이트
        $this->setMeta('last_rebuild', date('Y-m-d H:i:s'));
        
        return $results;
    }
    
    /**
     * 인덱스 통계
     */
    public function getStats(): array {
        if (!$this->available) {
            return [
                'total' => 0,
                'folders' => 0,
                'files' => 0,
                'last_rebuild' => null,
                'available' => false
            ];
        }
        
        $total = $this->db->querySingle('SELECT COUNT(*) FROM files');
        $folders = $this->db->querySingle('SELECT COUNT(*) FROM files WHERE is_dir = 1');
        $files = $this->db->querySingle('SELECT COUNT(*) FROM files WHERE is_dir = 0');
        $lastRebuild = $this->getMeta('last_rebuild');
        
        return [
            'total' => (int)$total,
            'folders' => (int)$folders,
            'files' => (int)$files,
            'last_rebuild' => $lastRebuild,
            'available' => true
        ];
    }
    
    /**
     * 스토리지별 통계
     */
    public function getStorageStats(int $storageId): array {
        if (!$this->available) {
            return ['total' => 0, 'files' => 0, 'folders' => 0];
        }
        
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM files WHERE storage_id = :id');
        $stmt->bindValue(':id', $storageId, SQLITE3_INTEGER);
        $total = $stmt->execute()->fetchArray()[0];
        
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM files WHERE storage_id = :id AND is_dir = 0');
        $stmt->bindValue(':id', $storageId, SQLITE3_INTEGER);
        $files = $stmt->execute()->fetchArray()[0];
        
        return [
            'total' => (int)$total,
            'files' => (int)$files,
            'folders' => (int)$total - (int)$files
        ];
    }
    
    /**
     * 메타 정보 저장
     */
    private function setMeta(string $key, string $value): void {
        if (!$this->available) return;
        
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    /**
     * 메타 정보 조회
     */
    private function getMeta(string $key): ?string {
        if (!$this->available) return null;
        
        $stmt = $this->db->prepare('SELECT value FROM meta WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray();
        return $result ? $result[0] : null;
    }
    
    /**
     * 인덱스 존재 여부 (SQLite3 사용 가능 && 인덱스 있음)
     */
    public function hasIndex(): bool {
        if (!$this->available) return false;
        
        $count = $this->db->querySingle('SELECT COUNT(*) FROM files');
        return $count > 0;
    }
    
    /**
     * 인덱스 초기화
     */
    public function clearAll(): bool {
        if (!$this->available) return false;
        
        // DB 연결 닫기
        if ($this->db) {
            $this->db->close();
            $this->db = null;
        }
        
        // DB 파일 삭제
        $deleted = false;
        if (file_exists($this->dbPath)) {
            $deleted = @unlink($this->dbPath);
        }
        
        // WAL 관련 파일도 삭제
        $walFile = $this->dbPath . '-wal';
        $shmFile = $this->dbPath . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
        
        // 인스턴스 초기화 (다음 호출 시 새로 생성)
        $this->available = false;
        self::$instance = null;
        
        return $deleted || !file_exists($this->dbPath);
    }
}
