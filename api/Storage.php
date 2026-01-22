<?php
/**
 * Storage - 스토리지(네트워크/로컬 드라이브) 관리 (JSON 기반)
 * 
 * 수정 이력:
 * - 2026-01-19: checkPermission 개선 - 스토리지 타입별 권한 처리 추가
 *               외부 공유폴더 업로드 문제 해결
 */
class Storage {
    private $db;
    private $auth;
    
    // 성능 최적화를 위한 캐시 (같은 요청 내에서 재사용)
    private static $storageCache = [];
    private static $permissionCache = [];
    private static $isAdminCache = null;
    private static $userIdCache = null;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->auth = new Auth();
    }
    
    /**
     * 캐시 초기화 (필요 시 호출)
     */
    public static function clearCache(): void {
        self::$storageCache = [];
        self::$permissionCache = [];
        self::$isAdminCache = null;
        self::$userIdCache = null;
    }
    
    // 스토리지 목록 (사용자 권한 기반)
    public function getStorages(): array {
        $userId = $this->auth->getUserId();
        $isAdmin = $this->auth->isAdmin();
        
        // 공용 폴더(shared) 자동 생성 확인
        $this->ensureSharedStorage();
        
        $storages = $this->db->findAll('storages', ['is_active' => 1]);
        $permissions = $this->db->findAll('permissions', ['user_id' => $userId]);
        $allowedIds = [];
        
        // can_visible이 true인 스토리지만 허용
        foreach ($permissions as $perm) {
            if ($perm['can_visible'] ?? 1) {
                $allowedIds[] = $perm['storage_id'];
            }
        }
        
        $home = [];
        $public = [];  // 공용 폴더 (shared 타입)
        $shared = [];  // 외부 스토리지 (local, smb, ftp 등)
        
        foreach ($storages as $storage) {
            $type = $storage['storage_type'] ?? 'local';
            
            // 홈 스토리지: 본인 것만 (모든 권한)
            if ($type === 'home') {
                if (($storage['owner_id'] ?? 0) == $userId) {
                    // 홈 스토리지는 소유자이므로 모든 권한
                    $storage['can_read'] = 1;
                    $storage['can_write'] = 1;
                    $storage['can_delete'] = 1;
                    $storage['can_share'] = 1;
                    $storage['can_download'] = 1;
                    // path 동적 계산
                    $storage['path'] = $this->getHomeStoragePath($storage['owner_id']);
                    $home[] = $storage;
                }
                continue;
            }
            
            // 공용 폴더(shared 타입): 권한 기반으로 접근 제어
            if ($type === 'shared') {
                $storage['path'] = $this->getSharedStoragePath();
                
                // 관리자는 모든 권한
                if ($isAdmin) {
                    $storage['can_read'] = 1;
                    $storage['can_write'] = 1;
                    $storage['can_download'] = 1;
                    $storage['can_share'] = 1;
                    $storage['can_delete'] = 1;
                    $public[] = $storage;
                    continue;
                }
                
                // 일반 사용자: 권한 확인
                $hasPerm = false;
                foreach ($permissions as $perm) {
                    if ($perm['storage_id'] == $storage['id']) {
                        if ($perm['can_visible'] ?? 1) {
                            $storage['can_read'] = $perm['can_read'] ?? 1;
                            $storage['can_write'] = $perm['can_write'] ?? 0;
                            $storage['can_download'] = $perm['can_download'] ?? 1;
                            $storage['can_share'] = $perm['can_share'] ?? 0;
                            $storage['can_delete'] = $perm['can_delete'] ?? 0;
                            $public[] = $storage;
                            $hasPerm = true;
                        }
                        break;
                    }
                }
                
                // 권한 없으면 기본값으로 표시 (읽기/다운로드만)
                if (!$hasPerm) {
                    $storage['can_read'] = 1;
                    $storage['can_write'] = 0;
                    $storage['can_download'] = 1;
                    $storage['can_share'] = 0;
                    $storage['can_delete'] = 0;
                    $public[] = $storage;
                }
                continue;
            }
            
            // 외부 스토리지 (local, smb, ftp 등): 관리자는 모두, 일반 사용자는 권한 있는 것만
            if ($isAdmin || in_array($storage['id'], $allowedIds)) {
                // 기본 권한 설정 (권한이 없을 경우를 대비)
                $storage['can_read'] = 0;
                $storage['can_write'] = 0;
                $storage['can_delete'] = 0;
                $storage['can_share'] = 0;
                $storage['can_download'] = 0;
                
                // 권한 정보 추가
                foreach ($permissions as $perm) {
                    if ($perm['storage_id'] == $storage['id']) {
                        $storage['can_read'] = $perm['can_read'] ?? 0;
                        $storage['can_write'] = $perm['can_write'] ?? 0;
                        $storage['can_delete'] = $perm['can_delete'] ?? 0;
                        $storage['can_share'] = $perm['can_share'] ?? 0;
                        $storage['can_download'] = $perm['can_download'] ?? 1;
                        break;
                    }
                }
                
                // 관리자는 모든 권한
                if ($isAdmin) {
                    $storage['can_read'] = 1;
                    $storage['can_write'] = 1;
                    $storage['can_delete'] = 1;
                    $storage['can_share'] = 1;
                    $storage['can_download'] = 1;
                }
                
                $shared[] = $storage;
            }
        }
        
        return [
            'home' => $home,
            'public' => $public,
            'shared' => $shared
        ];
    }
    
    // 모든 스토리지 조회 (관리자용) - 홈 스토리지만 제외
    public function getAllStorages(): array {
        $storages = $this->db->load('storages');
        $result = [];
        
        foreach ($storages as $s) {
            // 홈 스토리지만 제외 (개인 폴더)
            $type = $s['storage_type'] ?? 'local';
            if ($type === 'home') {
                continue;
            }
            unset($s['smb_password']);
            
            // 기본값 보장
            if (!isset($s['quota'])) $s['quota'] = 0;
            if (!isset($s['used_size'])) $s['used_size'] = 0;
            
            $result[] = $s;
        }
        
        return $result;
    }
    
    // 스토리지 추가
    public function addStorage(array $data): array {
        if (empty($data['name'])) {
            return ['success' => false, 'error' => '이름은 필수입니다.'];
        }
        
        $storageType = $data['storage_type'] ?? 'local';
        $path = '';
        
        // local 타입만 경로 검사
        if ($storageType === 'local') {
            if (empty($data['path'])) {
                return ['success' => false, 'error' => '경로는 필수입니다.'];
            }
            $path = $this->normalizePath($data['path']);
            
            if (!$this->isPathAccessible($path, $data)) {
                return ['success' => false, 'error' => '경로에 접근할 수 없습니다: ' . $path];
            }
            
            // ★ 보안: .htaccess 자동 생성 (URL 직접 접근 차단)
            $this->createProtectionFile($path);
        }
        
        // config 암호화 저장
        $config = $data['config'] ?? [];
        if (!empty($config)) {
            // 민감한 정보 암호화 (간단히 base64 사용, 실제로는 암호화 권장)
            $config = base64_encode(json_encode($config));
        } else {
            $config = '';
        }
        
        // quota 처리 (바이트 단위)
        $quota = 0;
        if (isset($data['quota']) && $data['quota'] > 0) {
            $quota = (int)$data['quota'];
        }
        
        $storageData = [
            'name' => $data['name'],
            'path' => $path,
            'storage_type' => $storageType,
            'description' => $data['description'] ?? '',
            'icon' => $this->getStorageIcon($storageType),
            'is_active' => 1,
            'created_by' => $this->auth->getUserId(),
            'created_at' => date('Y-m-d H:i:s'),
            'config' => $config,
            'quota' => $quota,
            'used_size' => 0  // 초기값, 필요시 recalculate로 계산
        ];
        
        $id = $this->db->insert('storages', $storageData);
        
        // 권한 설정
        if (!empty($data['permissions'])) {
            foreach ($data['permissions'] as $perm) {
                $this->db->insert('permissions', [
                    'storage_id' => $id,
                    'user_id' => $perm['user_id'],
                    'can_visible' => $perm['can_visible'] ?? 1,
                    'can_read' => $perm['can_read'] ?? 1,
                    'can_download' => $perm['can_download'] ?? 1,
                    'can_write' => $perm['can_write'] ?? 0,
                    'can_delete' => $perm['can_delete'] ?? 0,
                    'can_share' => $perm['can_share'] ?? 0
                ]);
            }
        } else {
            // 권한 설정이 없으면 생성자에게만 모든 권한 부여
            $this->db->insert('permissions', [
                'storage_id' => $id,
                'user_id' => $this->auth->getUserId(),
                'can_visible' => 1,
                'can_read' => 1,
                'can_download' => 1,
                'can_write' => 1,
                'can_delete' => 1,
                'can_share' => 1
            ]);
        }
        
        // 캐시 무효화
        self::$storageCache = [];
        
        // 사용량 계산 요청 시
        $result = ['success' => true, 'id' => $id];
        if (!empty($data['recalculate_usage'])) {
            $recalcResult = $this->recalculateUsedSize($id);
            if ($recalcResult['success']) {
                $result['used_size'] = $recalcResult['used_size'];
                $result['used_size_formatted'] = $recalcResult['used_size_formatted'];
            }
        }
        
        return $result;
    }
    
    // 스토리지 단일 조회 (민감 정보 제외)
    public function getStorage(int $id): array {
        $storage = $this->getStorageById($id);
        if (!$storage) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        // config 복호화 (비밀번호 제외)
        if (!empty($storage['config'])) {
            $config = json_decode(base64_decode($storage['config']), true);
            if ($config) {
                // 민감한 정보 마스킹
                foreach (['password', 'secret_key', 'client_secret', 'app_secret', 'private_key'] as $key) {
                    if (isset($config[$key]) && !empty($config[$key])) {
                        $config[$key] = ''; // 빈 값으로 (프론트에서 입력 안 하면 유지)
                    }
                }
                $storage['config'] = $config;
            }
        }
        
        // 레거시 필드 제거
        unset($storage['smb_password']);
        
        return ['success' => true, 'storage' => $storage];
    }
    
    // 스토리지 수정
    public function updateStorage(int $id, array $data): array {
        $storage = $this->getStorageById($id);
        if (!$storage) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['storage_type'])) {
            $updateData['storage_type'] = $data['storage_type'];
            $updateData['icon'] = $this->getStorageIcon($data['storage_type']);
        }
        if (isset($data['is_active'])) $updateData['is_active'] = $data['is_active'];
        
        // quota 업데이트
        if (isset($data['quota'])) {
            $updateData['quota'] = max(0, (int)$data['quota']);
        }
        
        // local 타입 경로 업데이트
        if (isset($data['path']) && ($data['storage_type'] ?? $storage['storage_type']) === 'local') {
            $path = $this->normalizePath($data['path']);
            if (!$this->isPathAccessible($path, $data)) {
                return ['success' => false, 'error' => '경로에 접근할 수 없습니다.'];
            }
            $updateData['path'] = $path;
            
            // ★ 보안: .htaccess 자동 생성 (URL 직접 접근 차단)
            $this->createProtectionFile($path);
        }
        
        // config 업데이트
        if (isset($data['config'])) {
            $newConfig = $data['config'];
            
            // 기존 config 로드
            $existingConfig = [];
            if (!empty($storage['config'])) {
                $existingConfig = json_decode(base64_decode($storage['config']), true) ?: [];
            }
            
            // 비밀번호 등 빈 값이면 기존 값 유지
            foreach (['password', 'secret_key', 'client_secret', 'app_secret', 'private_key'] as $key) {
                if (isset($newConfig[$key]) && empty($newConfig[$key]) && !empty($existingConfig[$key])) {
                    $newConfig[$key] = $existingConfig[$key];
                }
            }
            
            $updateData['config'] = base64_encode(json_encode($newConfig));
        }
        
        if (!empty($updateData)) {
            $this->db->update('storages', ['id' => $id], $updateData);
            // 캐시 무효화
            self::$storageCache = [];
        }
        
        // 권한 업데이트
        if (!empty($data['permissions'])) {
            // 기존 권한 삭제 후 새로 추가
            $this->db->delete('permissions', ['storage_id' => $id]);
            
            foreach ($data['permissions'] as $perm) {
                $this->db->insert('permissions', [
                    'storage_id' => $id,
                    'user_id' => $perm['user_id'],
                    'can_visible' => $perm['can_visible'] ?? 1,
                    'can_read' => $perm['can_read'] ?? 1,
                    'can_download' => $perm['can_download'] ?? 1,
                    'can_write' => $perm['can_write'] ?? 0,
                    'can_delete' => $perm['can_delete'] ?? 0,
                    'can_share' => $perm['can_share'] ?? 0
                ]);
            }
        }
        
        // 사용량 계산 요청 시
        $result = ['success' => true];
        if (!empty($data['recalculate_usage'])) {
            $recalcResult = $this->recalculateUsedSize($id);
            if ($recalcResult['success']) {
                $result['used_size'] = $recalcResult['used_size'];
                $result['used_size_formatted'] = $recalcResult['used_size_formatted'];
            }
        }
        
        return $result;
    }
    
    // 스토리지 삭제
    public function deleteStorage(int $id): array {
        $storage = $this->getStorageById($id);
        
        // shared 타입은 삭제 불가
        if ($storage && ($storage['storage_type'] ?? '') === 'shared') {
            return ['success' => false, 'error' => '공용 폴더는 삭제할 수 없습니다.'];
        }
        
        $this->db->delete('storages', ['id' => $id]);
        $this->db->delete('permissions', ['storage_id' => $id]);
        $this->db->delete('shares', ['storage_id' => $id]);
        
        // 캐시 무효화
        self::$storageCache = [];
        
        return ['success' => true];
    }
    
    // 스토리지 정보 조회
    public function getStorageById(int $id): ?array {
        $storage = $this->db->find('storages', ['id' => $id]);
        
        if (!$storage) return null;
        
        // 기본값 보장
        if (!isset($storage['quota'])) $storage['quota'] = 0;
        if (!isset($storage['used_size'])) $storage['used_size'] = 0;
        
        // home 타입이면 동적으로 경로 계산
        if (($storage['storage_type'] ?? '') === 'home') {
            $storage['path'] = $this->getHomeStoragePath($storage['owner_id'] ?? 0);
        }
        
        // shared 타입이면 동적으로 경로 계산
        if (($storage['storage_type'] ?? '') === 'shared') {
            $storage['path'] = $this->getSharedStoragePath();
        }
        
        return $storage;
    }
    
    /**
     * home 타입 스토리지의 실제 경로 계산
     * USER_FILES_ROOT + username
     */
    private function getHomeStoragePath(int $ownerId): string {
        $user = $this->db->find('users', ['id' => $ownerId]);
        $username = $user['username'] ?? 'unknown';
        return USER_FILES_ROOT . DIRECTORY_SEPARATOR . $username;
    }
    
    /**
     * shared 타입 스토리지의 실제 경로 계산
     * SHARED_FILES_ROOT
     */
    private function getSharedStoragePath(): string {
        return SHARED_FILES_ROOT;
    }
    
    /**
     * 공용 폴더(shared) 스토리지 자동 생성 및 중복 정리
     */
    private function ensureSharedStorage(): void {
        if (!defined('SHARED_FILES_ROOT')) return;
        
        // 폴더 생성
        $sharedPath = SHARED_FILES_ROOT;
        if (!is_dir($sharedPath)) {
            @mkdir($sharedPath, 0755, true);
        }
        
        // 모든 스토리지 로드
        $allStorages = $this->db->load('storages');
        $sharedStorages = [];
        $duplicates = [];
        $needsUpdate = false;
        
        foreach ($allStorages as $index => &$s) {
            $type = $s['storage_type'] ?? '';
            $name = $s['name'] ?? '';
            
            // 정상 shared 타입
            if ($type === 'shared') {
                $sharedStorages[] = $s;
                
                // 기존 공유 폴더에 누락된 필드 추가
                if (!isset($s['quota'])) {
                    $allStorages[$index]['quota'] = 0;
                    $needsUpdate = true;
                }
                if (!isset($s['used_size'])) {
                    $allStorages[$index]['used_size'] = 0;
                    $needsUpdate = true;
                }
            }
            // 중복/잘못된 공유 폴더 (storage_type이 없거나 다른데 이름이 "공유 폴더")
            elseif ($name === '공유 폴더' && $type !== 'shared') {
                $duplicates[] = $index;
            }
        }
        unset($s);
        
        // 중복 항목 삭제 또는 필드 업데이트
        if (!empty($duplicates) || $needsUpdate) {
            foreach (array_reverse($duplicates) as $index) {
                unset($allStorages[$index]);
            }
            $this->db->save('storages', array_values($allStorages));
        }
        
        // 정상 shared 스토리지가 있으면 종료
        if (!empty($sharedStorages)) return;
        
        // shared 스토리지 생성
        $this->db->insert('storages', [
            'name' => '공유 폴더',
            'path' => '',  // 동적 계산
            'storage_type' => 'shared',
            'description' => '모든 사용자가 접근 가능한 공용 폴더',
            'icon' => '📂',
            'is_active' => 1,
            'created_by' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'quota' => 0,
            'used_size' => 0
        ]);
    }
    
    /**
     * 권한 확인 (캐싱 적용 버전)
     * 
     * 스토리지 타입별 처리:
     * - 관리자: 항상 모든 권한
     * - home: 소유자면 모든 권한
     * - shared: permissions 테이블 확인
     * - 외부 스토리지 (local, smb 등): permissions 테이블 확인, 생성자도 확인
     * 
     * @param int $storageId 스토리지 ID
     * @param string $permission 확인할 권한 (can_read, can_write, can_delete, can_share, can_download)
     * @return bool
     */
    public function checkPermission(int $storageId, string $permission): bool {
        // 관리자 캐시 확인
        if (self::$isAdminCache === null) {
            self::$isAdminCache = $this->auth->isAdmin();
        }
        if (self::$isAdminCache) {
            return true;
        }
        
        // 사용자 ID 캐시
        if (self::$userIdCache === null) {
            self::$userIdCache = $this->auth->getUserId();
        }
        $userId = self::$userIdCache;
        
        // 스토리지 정보 캐시
        if (!isset(self::$storageCache[$storageId])) {
            self::$storageCache[$storageId] = $this->getStorageById($storageId);
        }
        $storage = self::$storageCache[$storageId];
        
        if (!$storage) {
            return false;
        }
        
        $storageType = $storage['storage_type'] ?? 'local';
        
        // 1. home 타입: 소유자면 모든 권한
        if ($storageType === 'home') {
            if (($storage['owner_id'] ?? 0) == $userId) {
                return true;
            }
            return false;
        }
        
        // 권한 정보 캐시 키
        $permCacheKey = "{$storageId}_{$userId}";
        
        // 권한 정보 캐시 확인
        if (!isset(self::$permissionCache[$permCacheKey])) {
            self::$permissionCache[$permCacheKey] = $this->db->find('permissions', [
                'storage_id' => $storageId,
                'user_id' => $userId
            ]);
        }
        $perm = self::$permissionCache[$permCacheKey];
        
        // 2. shared 타입
        if ($storageType === 'shared') {
            if ($perm) {
                return (bool)($perm[$permission] ?? false);
            }
            // permissions에 없으면 기본값 (읽기/다운로드만 허용)
            if ($permission === 'can_read' || $permission === 'can_download') {
                return true;
            }
            return false;
        }
        
        // 3. 외부 스토리지 (local, smb, ftp 등)
        if ($perm) {
            return (bool)($perm[$permission] ?? false);
        }
        
        // 스토리지 생성자인 경우 모든 권한
        if (($storage['created_by'] ?? 0) == $userId) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 사용자의 특정 스토리지에 대한 전체 권한 정보 반환 (캐싱 적용)
     * 
     * @param int $storageId 스토리지 ID
     * @return array 권한 배열
     */
    public function getEffectivePermissions(int $storageId): array {
        $defaultPerms = [
            'can_visible' => 0,
            'can_read' => 0,
            'can_write' => 0,
            'can_delete' => 0,
            'can_share' => 0,
            'can_download' => 0
        ];
        
        $allPerms = [
            'can_visible' => 1,
            'can_read' => 1,
            'can_write' => 1,
            'can_delete' => 1,
            'can_share' => 1,
            'can_download' => 1
        ];
        
        // 관리자 캐시 확인
        if (self::$isAdminCache === null) {
            self::$isAdminCache = $this->auth->isAdmin();
        }
        if (self::$isAdminCache) {
            return $allPerms;
        }
        
        // 사용자 ID 캐시
        if (self::$userIdCache === null) {
            self::$userIdCache = $this->auth->getUserId();
        }
        $userId = self::$userIdCache;
        
        // 스토리지 정보 캐시
        if (!isset(self::$storageCache[$storageId])) {
            self::$storageCache[$storageId] = $this->getStorageById($storageId);
        }
        $storage = self::$storageCache[$storageId];
        
        if (!$storage) {
            return $defaultPerms;
        }
        
        $storageType = $storage['storage_type'] ?? 'local';
        
        // home 타입: 소유자면 모든 권한
        if ($storageType === 'home') {
            if (($storage['owner_id'] ?? 0) == $userId) {
                return $allPerms;
            }
            return $defaultPerms;
        }
        
        // 권한 정보 캐시
        $permCacheKey = "{$storageId}_{$userId}";
        if (!isset(self::$permissionCache[$permCacheKey])) {
            self::$permissionCache[$permCacheKey] = $this->db->find('permissions', [
                'storage_id' => $storageId,
                'user_id' => $userId
            ]);
        }
        $perm = self::$permissionCache[$permCacheKey];
        
        if ($perm) {
            return [
                'can_visible' => (int)($perm['can_visible'] ?? 1),
                'can_read' => (int)($perm['can_read'] ?? 0),
                'can_write' => (int)($perm['can_write'] ?? 0),
                'can_delete' => (int)($perm['can_delete'] ?? 0),
                'can_share' => (int)($perm['can_share'] ?? 0),
                'can_download' => (int)($perm['can_download'] ?? 0)
            ];
        }
        
        // shared 타입 기본값 (읽기/다운로드만)
        if ($storageType === 'shared') {
            return [
                'can_visible' => 1,
                'can_read' => 1,
                'can_write' => 0,
                'can_delete' => 0,
                'can_share' => 0,
                'can_download' => 1
            ];
        }
        
        // 외부 스토리지 생성자인 경우
        if (($storage['created_by'] ?? 0) == $userId) {
            return $allPerms;
        }
        
        return $defaultPerms;
    }
    
    // 권한 설정 (캐시 무효화 포함)
    public function setPermission(int $storageId, int $userId, array $permissions): array {
        $existing = $this->db->find('permissions', [
            'storage_id' => $storageId,
            'user_id' => $userId
        ]);
        
        $data = [
            'can_visible' => $permissions['can_visible'] ?? 1,
            'can_read' => $permissions['can_read'] ?? 1,
            'can_download' => $permissions['can_download'] ?? 1,
            'can_write' => $permissions['can_write'] ?? 0,
            'can_delete' => $permissions['can_delete'] ?? 0,
            'can_share' => $permissions['can_share'] ?? 0
        ];
        
        if ($existing) {
            $this->db->update('permissions', [
                'storage_id' => $storageId,
                'user_id' => $userId
            ], $data);
        } else {
            $data['storage_id'] = $storageId;
            $data['user_id'] = $userId;
            $this->db->insert('permissions', $data);
        }
        
        // 해당 권한 캐시 무효화
        $permCacheKey = "{$storageId}_{$userId}";
        unset(self::$permissionCache[$permCacheKey]);
        
        return ['success' => true];
    }
    
    // 스토리지별 권한 목록
    public function getPermissions(int $storageId): array {
        $permissions = $this->db->findAll('permissions', ['storage_id' => $storageId]);
        $users = $this->db->load('users');
        
        // 사용자 정보 추가
        foreach ($permissions as &$perm) {
            foreach ($users as $user) {
                if ($user['id'] == $perm['user_id']) {
                    $perm['username'] = $user['username'];
                    $perm['display_name'] = $user['display_name'];
                    break;
                }
            }
        }
        
        return $permissions;
    }
    
    // 권한 삭제
    public function removePermission(int $storageId, int $userId): array {
        $this->db->delete('permissions', [
            'storage_id' => $storageId,
            'user_id' => $userId
        ]);
        return ['success' => true];
    }
    
    // 스토리지 타입별 아이콘
    private function getStorageIcon(string $type): string {
        $icons = [
            'local' => '📁',
            'smb' => '🖥️',
            'ftp' => '📡',
            'sftp' => '🔒',
            'webdav' => '🌐',
            's3' => '☁️',
            'home' => '🏠',
            'shared' => '📂'
        ];
        return $icons[$type] ?? '📁';
    }
    
    // 경로 정규화
    private function normalizePath(string $path): string {
        // Windows UNC 경로 처리
        if (preg_match('/^\\\\\\\\/', $path) || preg_match('/^\/\//', $path)) {
            return str_replace('/', '\\', $path);
        }
        // Windows 드라이브 경로
        if (preg_match('/^[A-Za-z]:/', $path)) {
            return rtrim(str_replace('/', '\\', $path), '\\');
        }
        // Linux 경로
        return rtrim($path, '/');
    }
    
    // 경로 접근 가능 여부 확인
    private function isPathAccessible(string $path, array $data = []): bool {
        // SMB 연결 시도 (Windows)
        if (($data['storage_type'] ?? '') === 'smb' && $this->isWindows()) {
            return $this->connectSmb($data);
        }
        
        return is_dir($path) && is_readable($path);
    }
    
    // Windows 환경 확인
    private function isWindows(): bool {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
    
    // SMB 연결 (Windows)
    private function connectSmb(array $data): bool {
        if (empty($data['smb_host']) || empty($data['smb_share'])) {
            return false;
        }
        
        // 입력값 검증 (호스트명, 공유명에 허용되지 않는 문자 차단)
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $data['smb_host'])) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9$._-]+$/', $data['smb_share'])) {
            return false;
        }
        
        $uncPath = "\\\\{$data['smb_host']}\\{$data['smb_share']}";
        
        // 이미 연결되어 있으면 성공
        if (is_dir($uncPath)) {
            return true;
        }
        
        // net use로 연결 시도
        if (!empty($data['smb_username']) && !empty($data['smb_password'])) {
            // Command Injection 방지: escapeshellarg 사용
            $cmd = sprintf(
                'net use %s /user:%s %s 2>&1',
                escapeshellarg($uncPath),
                escapeshellarg($data['smb_username']),
                escapeshellarg($data['smb_password'])
            );
            exec($cmd, $output, $returnCode);
            return $returnCode === 0 || is_dir($uncPath);
        }
        
        return is_dir($uncPath);
    }
    
    // 실제 경로 반환 (SMB 포함)
    public function getRealPath(int $storageId): ?string {
        $storage = $this->getStorageById($storageId);
        if (!$storage) return null;
        
        if ($storage['storage_type'] === 'smb' && $this->isWindows()) {
            // SMB 재연결 시도
            $this->connectSmb([
                'smb_host' => $storage['smb_host'],
                'smb_share' => $storage['smb_share'],
                'smb_username' => $storage['smb_username'],
                'smb_password' => $storage['smb_password']
            ]);
            return "\\\\{$storage['smb_host']}\\{$storage['smb_share']}";
        }
        
        return $storage['path'];
    }
    
    /**
     * 스토리지 사용량 업데이트 (파일 업로드/삭제 시 호출)
     * @param int $storageId 스토리지 ID
     * @param int $sizeDelta 변경량 (양수: 증가, 음수: 감소)
     */
    public function updateUsedSize(int $storageId, int $sizeDelta): void {
        $storage = $this->getStorageById($storageId);
        if (!$storage) return;
        
        // home 타입은 사용자별 quota 사용 (used_size 사용 안함)
        if (($storage['storage_type'] ?? '') === 'home') return;
        
        $currentUsed = (int)($storage['used_size'] ?? 0);
        $newUsed = max(0, $currentUsed + $sizeDelta);
        
        $this->db->update('storages', ['id' => $storageId], ['used_size' => $newUsed]);
        
        // 캐시 무효화
        self::$storageCache = [];
    }
    
    /**
     * 스토리지 사용량 재계산 (관리자용)
     * @param int $storageId 스토리지 ID
     * @return array 결과
     */
    public function recalculateUsedSize(int $storageId): array {
        $storage = $this->getStorageById($storageId);
        if (!$storage) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        // home 타입은 제외
        if (($storage['storage_type'] ?? '') === 'home') {
            return ['success' => false, 'error' => '개인폴더는 사용자별 용량을 사용합니다.'];
        }
        
        $path = $this->getRealPath($storageId);
        if (!$path || !is_dir($path)) {
            return ['success' => false, 'error' => '스토리지 경로에 접근할 수 없습니다.'];
        }
        
        // 폴더 크기 계산 (시간이 걸릴 수 있음)
        $usedSize = $this->calculateDirectorySize($path);
        
        $this->db->update('storages', ['id' => $storageId], ['used_size' => $usedSize]);
        
        // 캐시 무효화
        self::$storageCache = [];
        
        return [
            'success' => true, 
            'used_size' => $usedSize,
            'used_size_formatted' => $this->formatSize($usedSize)
        ];
    }
    
    /**
     * 디렉토리 크기 계산
     */
    private function calculateDirectorySize(string $path): int {
        $size = 0;
        
        if (!is_dir($path)) return 0;
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
    
    /**
     * 파일 크기 포맷팅
     */
    private function formatSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * 스토리지 용량 정보 조회
     */
    public function getQuotaInfo(int $storageId): array {
        $storage = $this->getStorageById($storageId);
        if (!$storage) {
            return ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
        }
        
        $quota = (int)($storage['quota'] ?? 0);
        $usedSize = (int)($storage['used_size'] ?? 0);
        
        return [
            'success' => true,
            'quota' => $quota,
            'used_size' => $usedSize,
            'available' => $quota > 0 ? max(0, $quota - $usedSize) : -1,
            'quota_formatted' => $quota > 0 ? $this->formatSize($quota) : '무제한',
            'used_size_formatted' => $this->formatSize($usedSize)
        ];
    }
    
    /**
     * 스토리지 폴더에 .htaccess 보호 파일 생성
     * URL 직접 접근 차단용
     */
    private function createProtectionFile(string $path): bool {
        if (!is_dir($path)) {
            return false;
        }
        
        $htaccessPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
        
        // 이미 .htaccess가 있으면 건드리지 않음
        if (file_exists($htaccessPath)) {
            return true;
        }
        
        $content = <<<'HTACCESS'
# FileStation 스토리지 보호
# 모든 파일은 api.php를 통해서만 접근 가능

# Apache 2.4+
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

# Apache 2.2
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

# Fallback
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule .* - [F,L]
</IfModule>
HTACCESS;
        
        return @file_put_contents($htaccessPath, $content) !== false;
    }
}