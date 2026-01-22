<?php
/**
 * Auth - 사용자 인증 관리 (JSON 기반) + 보안 기능
 */
class Auth {
    private $db;
    private static $user = null;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public function login(string $username, string $password, bool $remember = false): array {
        try {
            $ip = $this->getClientIP();
            
            // IP/국가 제한 체크
            $ipCheck = $this->checkIPRestriction($ip);
            if (!$ipCheck['allowed']) {
                $this->logLogin($username, false, $ip, $ipCheck['reason']);
                return ['success' => false, 'error' => $ipCheck['reason']];
            }
            
            // 브루트포스 체크
            if ($this->isLockedOut($username, $ip)) {
                $this->logLogin($username, false, $ip, '계정 잠금');
                return ['success' => false, 'error' => '로그인 시도 횟수 초과. 잠시 후 다시 시도하세요.'];
            }
            
            // 먼저 사용자 조회 (is_active 체크 없이)
            $user = $this->db->find('users', ['username' => $username]);
            
            if (!$user || !password_verify($password, $user['password'])) {
                $this->recordFailedAttempt($username, $ip);
                $this->logLogin($username, false, $ip, '잘못된 인증정보');
                return ['success' => false, 'error' => '아이디 또는 비밀번호가 올바르지 않습니다.'];
            }
            
            // 계정 상태 체크
            $status = $user['status'] ?? 'active';
            if ($status !== 'active') {
                // 정지 상태인 경우 기간 체크
                if ($status === 'suspended') {
                    $suspendUntil = $user['suspend_until'] ?? null;
                    $suspendFrom = $user['suspend_from'] ?? null;
                    $suspendReason = $user['suspend_reason'] ?? '';
                    
                    // 종료일이 지났으면 자동 활성화
                    if ($suspendUntil && strtotime($suspendUntil) < strtotime('today')) {
                        $this->db->update('users', ['id' => $user['id']], [
                            'status' => 'active',
                            'suspend_from' => null,
                            'suspend_until' => null,
                            'suspend_reason' => null
                        ]);
                        // 활성화되었으니 계속 진행
                    } else {
                        // 아직 정지 기간
                        $periodMsg = '';
                        if ($suspendFrom && $suspendUntil) {
                            $periodMsg = "\n정지 기간: {$suspendFrom} ~ {$suspendUntil}";
                        } elseif ($suspendUntil) {
                            $periodMsg = "\n정지 종료일: {$suspendUntil}";
                        }
                        $reasonMsg = $suspendReason ? "\n사유: {$suspendReason}" : '';
                        
                        $this->logLogin($username, false, $ip, '계정 정지');
                        return ['success' => false, 'error' => "정지된 계정입니다.{$periodMsg}{$reasonMsg}"];
                    }
                } elseif ($status === 'pending') {
                    $this->logLogin($username, false, $ip, '승인 대기');
                    return ['success' => false, 'error' => "승인 대기 중인 계정입니다.\n관리자의 승인 후 로그인할 수 있습니다."];
                } else {
                    $this->logLogin($username, false, $ip, '계정 상태: ' . $status);
                    return ['success' => false, 'error' => '로그인할 수 없는 계정입니다.'];
                }
            }
            
            // is_active 체크 (하위 호환)
            if (!($user['is_active'] ?? 1)) {
                $this->logLogin($username, false, $ip, '비활성 계정');
                return ['success' => false, 'error' => '비활성화된 계정입니다.'];
            }
            
            // 2FA 활성화 체크
            if (!empty($user['2fa_enabled'])) {
                // 2FA 인증 대기 상태로 설정
                $_SESSION['2fa_pending_user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'remember' => $remember
                ];
                
                return [
                    'success' => true,
                    '2fa_required' => true,
                    'message' => '2단계 인증이 필요합니다.'
                ];
            }
            
            // 성공 - 실패 기록 초기화
            $this->clearFailedAttempts($username, $ip);
            
            // Session Fixation 방지: 세션 ID 재생성
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            $this->db->update('users', ['id' => $user['id']], ['last_login' => date('Y-m-d H:i:s')]);
            
            // Remember Me 처리
            if ($remember && defined('REMEMBER_ME_ENABLED') && REMEMBER_ME_ENABLED) {
                $this->createRememberToken($user['id']);
            }
            
            // 세션 기록
            $this->recordSession($user['id'], $ip);
            
            // 로그인 로그
            $this->logLogin($username, true, $ip, '성공');
            
            // 사용자 폴더 자동 생성
            if (defined('AUTO_CREATE_USER_FOLDER') && AUTO_CREATE_USER_FOLDER) {
                $this->ensureUserFolder($user);
            }
            
            unset($user['password']);
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => '로그인 처리 중 오류가 발생했습니다.'];
        }
    }
    
    // 사용자 개인 폴더 생성 및 스토리지 등록
    private function ensureUserFolder(array $user): void {
        if (!defined('USER_FILES_ROOT')) return;
        
        $baseRoot = str_replace('\\', '/', rtrim(USER_FILES_ROOT, '/\\'));
        $userPath = $baseRoot . '/' . $user['username'];
        $realPath = str_replace('/', DIRECTORY_SEPARATOR, $userPath);
        
        if (!is_dir($realPath)) {
            @mkdir($realPath, 0755, true);
        }
        
        $storages = $this->db->load('storages');
        $homeStorage = null;
        
        foreach ($storages as $s) {
            if (($s['storage_type'] ?? '') === 'home' && ($s['owner_id'] ?? 0) == $user['id']) {
                $homeStorage = $s;
                break;
            }
        }
        
        if (!$homeStorage) {
            // home 타입은 path를 저장하지 않음 (동적 계산)
            $storageId = $this->db->insert('storages', [
                'name' => '내 파일',
                'path' => '',  // Storage::getHomeStoragePath()에서 동적 계산
                'storage_type' => 'home',
                'owner_id' => $user['id'],
                'description' => $user['username'] . '의 개인 폴더',
                'icon' => '🏠',
                'is_active' => 1,
                'created_by' => $user['id'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->db->insert('permissions', [
                'storage_id' => $storageId,
                'user_id' => $user['id'],
                'can_read' => 1,
                'can_write' => 1,
                'can_delete' => 1,
                'can_share' => 1
            ]);
        }
    }
    
    public function logout(): void {
        $userId = $this->getUserId();
        
        if ($userId && isset($_COOKIE['remember_token'])) {
            $this->deleteRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
        
        if ($userId) {
            $this->removeSession($userId, session_id());
        }
        
        session_destroy();
        self::$user = null;
    }
    
    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }
    
    public function getUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        
        if (self::$user === null) {
            $user = $this->db->find('users', ['id' => $_SESSION['user_id']]);
            if ($user) {
                unset($user['password']);
                self::$user = $user;
            }
        }
        return self::$user;
    }
    
    public function getUserId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
    
    public function isAdmin(): bool {
        return ($this->getUser()['role'] ?? '') === 'admin';
    }
    
    public function isSubAdmin(): bool {
        return ($this->getUser()['role'] ?? '') === 'sub_admin';
    }
    
    public function isAdminOrSubAdmin(): bool {
        $role = $this->getUser()['role'] ?? '';
        return $role === 'admin' || $role === 'sub_admin';
    }
    
    // 부관리자가 특정 메뉴 권한을 가지고 있는지 확인
    public function hasAdminPerm(string $perm): bool {
        if ($this->isAdmin()) return true;
        if (!$this->isSubAdmin()) return false;
        
        $user = $this->getUser();
        $perms = $user['admin_perms'] ?? [];
        return is_array($perms) && in_array($perm, $perms);
    }
    
    public function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['error' => '로그인이 필요합니다.']);
            exit;
        }
    }
    
    public function requireAdmin(): void {
        $this->requireLogin();
        if (!$this->isAdminOrSubAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => '관리자 권한이 필요합니다.']);
            exit;
        }
    }
    
    // 실제 관리자만 필요한 경우
    public function requireRealAdmin(): void {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => '관리자 권한이 필요합니다.']);
            exit;
        }
    }
    
    // 사용자 관리
    public function createUser(array $data): array {
        if (empty($data['username']) || empty($data['password'])) {
            return ['success' => false, 'error' => '아이디와 비밀번호는 필수입니다.'];
        }
        
        $existing = $this->db->find('users', ['username' => $data['username']]);
        if ($existing) {
            return ['success' => false, 'error' => '이미 존재하는 아이디입니다.'];
        }
        
        $role = $data['role'] ?? 'user';
        // 관리자는 무조건 활성 상태
        $status = ($role === 'admin') ? 'active' : ($data['status'] ?? 'active');
        
        $userData = [
            'username' => $data['username'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'display_name' => $data['display_name'] ?? $data['username'],
            'email' => $data['email'] ?? '',
            'role' => $role,
            'status' => $status,
            'admin_perms' => ($role === 'sub_admin' && !empty($data['admin_perms'])) ? $data['admin_perms'] : null,
            'quota' => (int)($data['quota'] ?? 0),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'last_login' => null
        ];
        
        // 정지 상태인 경우 기간 정보 추가
        if ($status === 'suspended') {
            $userData['suspend_from'] = !empty($data['suspend_from']) ? $data['suspend_from'] : null;
            $userData['suspend_until'] = !empty($data['suspend_until']) ? $data['suspend_until'] : null;
            $userData['suspend_reason'] = !empty($data['suspend_reason']) ? $data['suspend_reason'] : null;
        }
        
        $id = $this->db->insert('users', $userData);
        
        return ['success' => true, 'id' => $id];
    }
    
    public function updateUser(int $id, array $data): array {
        // 대상 사용자 정보 조회
        $targetUser = $this->db->find('users', ['id' => $id]);
        if (!$targetUser) {
            return ['success' => false, 'error' => '사용자를 찾을 수 없습니다.'];
        }
        
        // 관리자 역할 변경 불가
        if (($targetUser['role'] ?? '') === 'admin' && isset($data['role']) && $data['role'] !== 'admin') {
            return ['success' => false, 'error' => '관리자의 역할은 변경할 수 없습니다.'];
        }
        
        $updateData = [];
        if (isset($data['display_name'])) $updateData['display_name'] = $data['display_name'];
        if (isset($data['email'])) $updateData['email'] = $data['email'];
        if (isset($data['role'])) $updateData['role'] = $data['role'];
        if (isset($data['admin_perms'])) $updateData['admin_perms'] = $data['admin_perms'];
        if (isset($data['is_active'])) $updateData['is_active'] = $data['is_active'];
        if (isset($data['quota'])) $updateData['quota'] = (int)$data['quota'];
        if (!empty($data['password'])) {
            $updateData['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        // 역할에 따른 상태 처리
        $newRole = $data['role'] ?? $targetUser['role'];
        if ($newRole === 'admin') {
            // 관리자는 무조건 활성 상태
            $updateData['status'] = 'active';
            // 정지 정보 초기화
            $updateData['suspend_from'] = null;
            $updateData['suspend_until'] = null;
            $updateData['suspend_reason'] = null;
        } elseif (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            
            // 정지 상태인 경우 기간 정보 설정
            if ($data['status'] === 'suspended') {
                $updateData['suspend_from'] = !empty($data['suspend_from']) ? $data['suspend_from'] : null;
                $updateData['suspend_until'] = !empty($data['suspend_until']) ? $data['suspend_until'] : null;
                $updateData['suspend_reason'] = !empty($data['suspend_reason']) ? $data['suspend_reason'] : null;
            } else {
                // 정지 아닌 상태면 정지 정보 초기화
                $updateData['suspend_from'] = null;
                $updateData['suspend_until'] = null;
                $updateData['suspend_reason'] = null;
            }
        }
        
        // 부관리자가 아니면 admin_perms 제거
        if ($newRole !== 'sub_admin') {
            $updateData['admin_perms'] = null;
        }
        
        if (empty($updateData)) {
            return ['success' => false, 'error' => '변경할 내용이 없습니다.'];
        }
        
        $this->db->update('users', ['id' => $id], $updateData);
        return ['success' => true];
    }
    
    public function deleteUser(int $id): array {
        if ($id === $this->getUserId()) {
            return ['success' => false, 'error' => '자신의 계정은 삭제할 수 없습니다.'];
        }
        
        // 삭제 대상 사용자 조회
        $targetUser = $this->db->find('users', ['id' => $id]);
        if (!$targetUser) {
            return ['success' => false, 'error' => '사용자를 찾을 수 없습니다.'];
        }
        
        // 관리자 계정은 삭제 불가
        if (($targetUser['role'] ?? '') === 'admin') {
            return ['success' => false, 'error' => '관리자 계정은 삭제할 수 없습니다.'];
        }
        
        $this->db->delete('users', ['id' => $id]);
        return ['success' => true];
    }
    
    public function bulkUpdateQuota(string $target, int $quota): array {
        $users = $this->db->load('users');
        $updated = 0;
        
        foreach ($users as &$user) {
            $shouldUpdate = false;
            
            switch ($target) {
                case 'all':
                    $shouldUpdate = true;
                    break;
                case 'user':
                    $shouldUpdate = ($user['role'] ?? 'user') !== 'admin';
                    break;
                case 'unlimited':
                    $shouldUpdate = empty($user['quota']) || $user['quota'] == 0;
                    break;
            }
            
            if ($shouldUpdate) {
                $user['quota'] = $quota;
                $updated++;
            }
        }
        unset($user);
        
        $this->db->save('users', $users);
        
        return ['success' => true, 'updated' => $updated];
    }
    
    public function getUsers(): array {
        $users = $this->db->load('users');
        return array_map(function($u) {
            unset($u['password']);
            return $u;
        }, $users);
    }
    
    public function changePassword(string $currentPassword, string $newPassword): array {
        $user = $this->db->find('users', ['id' => $this->getUserId()]);
        
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => '현재 비밀번호가 올바르지 않습니다.'];
        }
        
        $this->db->update('users', ['id' => $this->getUserId()], [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT)
        ]);
        
        return ['success' => true];
    }
    
    // ===== IP/국가 제한 =====
    private function getClientIP(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    private function getSecuritySettings(): array {
        $settings = $this->db->load('security_settings');
        if (empty($settings)) {
            return [
                'enabled' => false,
                'block_country' => false,
                'allow_country_only' => false,
                'block_ip' => false,
                'allow_ip_only' => false,
                'allowed_ips' => defined('ALLOWED_IPS') ? ALLOWED_IPS : [],
                'blocked_ips' => defined('BLOCKED_IPS') ? BLOCKED_IPS : [],
                'allowed_countries' => defined('ALLOWED_COUNTRIES') ? ALLOWED_COUNTRIES : [],
                'blocked_countries' => defined('BLOCKED_COUNTRIES') ? BLOCKED_COUNTRIES : [],
                'admin_ips' => [],
                'block_message' => '접근이 차단되었습니다.',
                'cache_hours' => 24,
                'log_enabled' => false,
                'max_attempts' => defined('LOGIN_MAX_ATTEMPTS') ? LOGIN_MAX_ATTEMPTS : 5,
                'lockout_minutes' => defined('LOGIN_LOCKOUT_MINUTES') ? LOGIN_LOCKOUT_MINUTES : 15
            ];
        }
        return $settings;
    }
    
    public function getCurrentIP(): string {
        return $this->getClientIP();
    }
    
    public function getCurrentCountry(): string {
        return $this->getCountryFromIP($this->getClientIP());
    }
    
    private function checkIPRestriction(string $ip): array {
        $settings = $this->getSecuritySettings();
        
        // 차단 기능이 비활성화되어 있으면 허용
        if (empty($settings['enabled'])) {
            return ['allowed' => true, 'reason' => ''];
        }
        
        // 관리자 IP 화이트리스트 체크 (최우선)
        $adminIps = $settings['admin_ips'] ?? [];
        if (!empty($adminIps)) {
            foreach ($adminIps as $adminIp) {
                if ($this->ipInRange($ip, trim($adminIp))) {
                    return ['allowed' => true, 'reason' => '관리자 IP'];
                }
            }
        }
        
        $blockMessage = $settings['block_message'] ?? '접근이 차단되었습니다.';
        
        // IP 차단 모드
        $blockIp = $settings['block_ip'] ?? false;
        $allowIpOnly = $settings['allow_ip_only'] ?? false;
        $blockedIps = $settings['blocked_ips'] ?? [];
        $allowedIps = $settings['allowed_ips'] ?? [];
        
        // 특정 IP 차단
        if ($blockIp && !empty($blockedIps)) {
            foreach ($blockedIps as $blocked) {
                if ($this->ipInRange($ip, trim($blocked))) {
                    $this->logBlockedAccess($ip, 'IP 차단');
                    return ['allowed' => false, 'reason' => $blockMessage];
                }
            }
        }
        
        // 특정 IP만 허용
        if ($allowIpOnly && !empty($allowedIps)) {
            $allowed = false;
            foreach ($allowedIps as $allowedIp) {
                if ($this->ipInRange($ip, trim($allowedIp))) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $this->logBlockedAccess($ip, 'IP 허용 목록에 없음');
                return ['allowed' => false, 'reason' => $blockMessage];
            }
        }
        
        // 국가 차단 모드
        $blockCountry = $settings['block_country'] ?? false;
        $allowCountryOnly = $settings['allow_country_only'] ?? false;
        $blockedCountries = $settings['blocked_countries'] ?? [];
        $allowedCountries = $settings['allowed_countries'] ?? [];
        
        $checkCountry = ($blockCountry && !empty($blockedCountries)) || ($allowCountryOnly && !empty($allowedCountries));
        
        if ($checkCountry) {
            $country = $this->getCountryFromIP($ip);
            
            // 로컬 IP는 국가 제한 건너뛰기
            if ($country === 'LOCAL') {
                return ['allowed' => true, 'reason' => ''];
            }
            
            // 특정 국가 차단
            if ($blockCountry && !empty($blockedCountries) && in_array($country, $blockedCountries)) {
                $this->logBlockedAccess($ip, "국가 차단: {$country}");
                return ['allowed' => false, 'reason' => $blockMessage];
            }
            
            // 특정 국가만 허용
            if ($allowCountryOnly && !empty($allowedCountries)) {
                if (!in_array($country, $allowedCountries)) {
                    $this->logBlockedAccess($ip, "국가 허용 목록에 없음: {$country}");
                    return ['allowed' => false, 'reason' => $blockMessage];
                }
            }
        }
        
        return ['allowed' => true, 'reason' => ''];
    }
    
    private function logBlockedAccess(string $ip, string $reason): void {
        $settings = $this->getSecuritySettings();
        if (empty($settings['log_enabled'])) {
            return;
        }
        
        $logs = $this->db->load('security_block_logs');
        $logs[] = [
            'ip' => $ip,
            'reason' => $reason,
            'country' => $this->getCountryFromIP($ip),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // 최대 1000개 로그 유지
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -500);
        }
        
        $this->db->save('security_block_logs', array_values($logs));
    }
    
    public function testIPRestriction(): array {
        $ip = $this->getClientIP();
        $country = $this->getCountryFromIP($ip);
        $check = $this->checkIPRestriction($ip);
        
        return [
            'ip' => $ip,
            'country' => $country,
            'blocked' => !$check['allowed'],
            'reason' => $check['reason']
        ];
    }
    
    private function ipInRange(string $ip, string $range): bool {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        if ($ip === false || $subnet === false) return false;
        
        $mask = -1 << (32 - (int)$bits);
        return ($ip & $mask) === ($subnet & $mask);
    }
    
    private function getCountryFromIP(string $ip): string {
        // 로컬 IP는 건너뛰기
        if (in_array($ip, ['127.0.0.1', '::1']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0 || strpos($ip, '172.') === 0) {
            return 'LOCAL';
        }
        
        $settings = $this->getSecuritySettings();
        $cacheHours = $settings['cache_hours'] ?? 24;
        
        // 캐시 확인
        $cache = $this->db->load('ip_country_cache');
        foreach ($cache as $entry) {
            if (($entry['ip'] ?? '') === $ip && strtotime($entry['cached_at'] ?? '0') > strtotime("-{$cacheHours} hours")) {
                return $entry['country'] ?? 'XX';
            }
        }
        
        // ip-api.com 무료 API
        $country = 'XX';
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=countryCode";
            $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['countryCode'])) {
                    $country = $data['countryCode'];
                }
            }
        } catch (Exception $e) {
            // 무시
        }
        
        // 캐시 저장
        $cache[] = ['ip' => $ip, 'country' => $country, 'cached_at' => date('Y-m-d H:i:s')];
        $cache = array_filter($cache, fn($e) => strtotime($e['cached_at'] ?? '0') > strtotime("-{$cacheHours} hours"));
        if (count($cache) > 1000) $cache = array_slice($cache, -500);
        $this->db->save('ip_country_cache', array_values($cache));
        
        return $country;
    }
    
    // ===== 브루트포스 방지 =====
    private function isLockedOut(string $username, string $ip): bool {
        $settings = $this->getSecuritySettings();
        $maxAttempts = $settings['max_attempts'] ?? 5;
        $lockoutMinutes = $settings['lockout_minutes'] ?? 15;
        
        if ($maxAttempts <= 0) return false;
        
        $attempts = $this->db->load('login_attempts');
        $key = md5($username . $ip);
        
        foreach ($attempts as $attempt) {
            if (($attempt['key'] ?? '') === $key && ($attempt['count'] ?? 0) >= $maxAttempts) {
                $lastAttempt = strtotime($attempt['last_attempt'] ?? '0');
                if (time() - $lastAttempt < $lockoutMinutes * 60) {
                    return true;
                }
            }
        }
        return false;
    }
    
    private function recordFailedAttempt(string $username, string $ip): void {
        $settings = $this->getSecuritySettings();
        $maxAttempts = $settings['max_attempts'] ?? 5;
        
        if ($maxAttempts <= 0) return;
        
        $attempts = $this->db->load('login_attempts');
        $key = md5($username . $ip);
        $found = false;
        
        foreach ($attempts as &$attempt) {
            if (($attempt['key'] ?? '') === $key) {
                $attempt['count'] = ($attempt['count'] ?? 0) + 1;
                $attempt['last_attempt'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($attempt);
        
        if (!$found) {
            $attempts[] = [
                'key' => $key,
                'username' => $username,
                'ip' => $ip,
                'count' => 1,
                'last_attempt' => date('Y-m-d H:i:s')
            ];
        }
        
        $this->db->save('login_attempts', $attempts);
    }
    
    private function clearFailedAttempts(string $username, string $ip): void {
        $attempts = $this->db->load('login_attempts');
        $key = md5($username . $ip);
        $attempts = array_filter($attempts, fn($a) => ($a['key'] ?? '') !== $key);
        $this->db->save('login_attempts', array_values($attempts));
    }
    
    // ===== Remember Me =====
    private function createRememberToken(int $userId): void {
        $tokenLength = defined('REMEMBER_ME_TOKEN_LENGTH') ? REMEMBER_ME_TOKEN_LENGTH : 64;
        $days = defined('REMEMBER_ME_DAYS') ? REMEMBER_ME_DAYS : 30;
        
        $token = bin2hex(random_bytes($tokenLength / 2));
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        
        $tokens = $this->db->load('remember_tokens');
        $tokens[] = [
            'user_id' => $userId,
            'token' => hash('sha256', $token),
            'expires' => $expires,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // 만료된 토큰 정리
        $tokens = array_filter($tokens, fn($t) => strtotime($t['expires'] ?? '0') > time());
        $this->db->save('remember_tokens', array_values($tokens));
        
        setcookie('remember_token', $token, time() + ($days * 86400), '/', '', false, true);
    }
    
    public function checkRememberToken(): bool {
        if (!isset($_COOKIE['remember_token'])) return false;
        
        $token = $_COOKIE['remember_token'];
        $hashedToken = hash('sha256', $token);
        
        $tokens = $this->db->load('remember_tokens');
        foreach ($tokens as $t) {
            if (($t['token'] ?? '') === $hashedToken && strtotime($t['expires'] ?? '0') > time()) {
                $user = $this->db->find('users', ['id' => $t['user_id'], 'is_active' => 1]);
                if ($user) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    return true;
                }
            }
        }
        
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        return false;
    }
    
    private function deleteRememberToken(string $token): void {
        $hashedToken = hash('sha256', $token);
        $tokens = $this->db->load('remember_tokens');
        $tokens = array_filter($tokens, fn($t) => ($t['token'] ?? '') !== $hashedToken);
        $this->db->save('remember_tokens', array_values($tokens));
    }
    
    // ===== 세션 관리 =====
    private function recordSession(int $userId, string $ip): void {
        if (!defined('SESSION_TRACKING_ENABLED') || !SESSION_TRACKING_ENABLED) return;
        
        $sessions = $this->db->load('sessions');
        $sessionId = session_id();
        
        $found = false;
        foreach ($sessions as &$s) {
            if (($s['session_id'] ?? '') === $sessionId) {
                $s['last_activity'] = date('Y-m-d H:i:s');
                $s['ip'] = $ip;
                $found = true;
                break;
            }
        }
        unset($s);
        
        if (!$found) {
            $sessions[] = [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'ip' => $ip,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => date('Y-m-d H:i:s'),
                'last_activity' => date('Y-m-d H:i:s')
            ];
        }
        
        // 동시 세션 제한
        if (defined('SESSION_MAX_CONCURRENT') && SESSION_MAX_CONCURRENT > 0) {
            $userSessions = array_filter($sessions, fn($s) => ($s['user_id'] ?? 0) === $userId);
            if (count($userSessions) > SESSION_MAX_CONCURRENT) {
                usort($userSessions, fn($a, $b) => strtotime($a['last_activity'] ?? '0') - strtotime($b['last_activity'] ?? '0'));
                $toRemove = array_slice($userSessions, 0, count($userSessions) - SESSION_MAX_CONCURRENT);
                foreach ($toRemove as $r) {
                    $sessions = array_filter($sessions, fn($s) => ($s['session_id'] ?? '') !== ($r['session_id'] ?? ''));
                }
            }
        }
        
        // 24시간 이상 비활성 세션 정리
        $sessions = array_filter($sessions, fn($s) => strtotime($s['last_activity'] ?? '0') > strtotime('-24 hours'));
        
        $this->db->save('sessions', array_values($sessions));
    }
    
    private function removeSession(int $userId, string $sessionId): void {
        $sessions = $this->db->load('sessions');
        $sessions = array_filter($sessions, fn($s) => !(($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') === $sessionId));
        $this->db->save('sessions', array_values($sessions));
    }
    
    public function getSessions(): array {
        $userId = $this->getUserId();
        if (!$userId) return [];
        
        $sessions = $this->db->load('sessions');
        $userSessions = array_filter($sessions, fn($s) => ($s['user_id'] ?? 0) === $userId);
        
        $currentSessionId = session_id();
        return array_map(function($s) use ($currentSessionId) {
            return [
                'session_id' => substr($s['session_id'] ?? '', 0, 8) . '...',
                'ip' => $s['ip'] ?? '',
                'user_agent' => $this->parseUserAgent($s['user_agent'] ?? ''),
                'created_at' => $s['created_at'] ?? '',
                'last_activity' => $s['last_activity'] ?? '',
                'is_current' => ($s['session_id'] ?? '') === $currentSessionId
            ];
        }, array_values($userSessions));
    }
    
    public function terminateSession(string $sessionIdPrefix): array {
        $userId = $this->getUserId();
        if (!$userId) return ['success' => false, 'error' => '로그인이 필요합니다.'];
        
        $sessions = $this->db->load('sessions');
        $found = false;
        $prefix = rtrim($sessionIdPrefix, '.');
        
        foreach ($sessions as $key => $s) {
            if (($s['user_id'] ?? 0) === $userId && strpos($s['session_id'] ?? '', $prefix) === 0) {
                unset($sessions[$key]);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['success' => false, 'error' => '세션을 찾을 수 없습니다.'];
        }
        
        $this->db->save('sessions', array_values($sessions));
        return ['success' => true];
    }
    
    public function terminateAllOtherSessions(): array {
        $userId = $this->getUserId();
        if (!$userId) return ['success' => false, 'error' => '로그인이 필요합니다.'];
        
        $currentSessionId = session_id();
        $sessions = $this->db->load('sessions');
        $sessions = array_filter($sessions, fn($s) => !(($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') !== $currentSessionId));
        $this->db->save('sessions', array_values($sessions));
        
        return ['success' => true];
    }
    
    private function parseUserAgent(string $ua): string {
        if (empty($ua)) return '알 수 없음';
        
        $browser = '알 수 없음';
        $os = '알 수 없음';
        
        if (preg_match('/Edg/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
        elseif (preg_match('/MSIE|Trident/i', $ua)) $browser = 'IE';
        
        if (preg_match('/Windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Mac/i', $ua)) $os = 'Mac';
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
        elseif (preg_match('/Android/i', $ua)) $os = 'Android';
        elseif (preg_match('/iPhone|iPad/i', $ua)) $os = 'iOS';
        
        return "{$browser} / {$os}";
    }
    
    // ===== 로그인 로그 =====
    private function logLogin(string $username, bool $success, string $ip, string $reason): void {
        if (!defined('LOGIN_LOG_ENABLED') || !LOGIN_LOG_ENABLED) return;
        
        try {
            $logs = $this->db->load('login_logs');
            
            // 국가 코드 가져오기
            $country = '';
            try {
                $country = $this->getCountryFromIP($ip);
            } catch (Exception $e) {
                $country = '';
            }
            
            $logs[] = [
                'id' => uniqid('log_'),
                'username' => $username,
                'success' => $success,
                'ip' => $ip,
                'country' => $country,
                'reason' => $reason,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $this->db->save('login_logs', array_values($logs));
        } catch (Exception $e) {
            // 로그 실패는 무시
        }
    }
    
    // 로그인 로그 삭제 (관리자)
    public function deleteLoginLogs(array $ids): array {
        $user = $this->getUser();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => '권한이 없습니다'];
        }
        
        $logs = $this->db->load('login_logs');
        $logs = array_filter($logs, fn($l) => !in_array($l['id'] ?? '', $ids));
        $this->db->save('login_logs', array_values($logs));
        
        return ['success' => true, 'deleted' => count($ids)];
    }
    
    // 전체 로그인 로그 삭제 (관리자)
    public function deleteAllLoginLogs(): array {
        $user = $this->getUser();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => '권한이 없습니다'];
        }
        
        $this->db->save('login_logs', []);
        return ['success' => true];
    }
    
    // 오래된 로그인 로그 삭제 (관리자)
    public function deleteOldLoginLogs(int $days): array {
        $user = $this->getUser();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => '권한이 없습니다'];
        }
        
        $logs = $this->db->load('login_logs');
        $cutoff = strtotime("-{$days} days");
        $before = count($logs);
        $logs = array_filter($logs, fn($l) => strtotime($l['created_at'] ?? '0') > $cutoff);
        $this->db->save('login_logs', array_values($logs));
        
        return ['success' => true, 'deleted' => $before - count($logs)];
    }
    
    public function getLoginLogs(int $page = 1, int $perPage = 20, bool $all = false): array {
        $user = $this->getUser();
        if (!$user) return ['logs' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        
        $logs = $this->db->load('login_logs');
        
        // 관리자가 아니거나, all=false면 자신의 로그만
        if (($user['role'] ?? '') !== 'admin' || !$all) {
            $logs = array_filter($logs, fn($l) => ($l['username'] ?? '') === $user['username']);
            $logs = array_values($logs);
        }
        
        // 최신순 정렬
        usort($logs, fn($a, $b) => strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'));
        
        $total = count($logs);
        $totalPages = ceil($total / $perPage);
        $page = max(1, min($page, $totalPages ?: 1));
        $offset = ($page - 1) * $perPage;
        
        $pagedLogs = array_slice($logs, $offset, $perPage);
        
        return [
            'logs' => $pagedLogs,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }
    
    // ===== 2FA (TOTP) =====
    
    /**
     * 2FA 설정 시작 - 시크릿 키 생성
     */
    public function setup2FA(): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => '로그인이 필요합니다.'];
        }
        
        // 이미 활성화되어 있으면 거부
        if (!empty($user['2fa_enabled'])) {
            return ['success' => false, 'error' => '2FA가 이미 활성화되어 있습니다.'];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        // 새 시크릿 생성
        $secret = TOTP::generateSecret();
        
        // 임시로 세션에 저장 (활성화 전까지)
        $_SESSION['2fa_setup_secret'] = $secret;
        
        // QR 코드 URI 생성
        $issuer = defined('TOTP_ISSUER') ? TOTP_ISSUER : (defined('SITE_NAME') ? SITE_NAME : 'WebHard');
        $uri = TOTP::getUri($secret, $user['username'], $issuer);
        $qrUrl = TOTP::getQRCodeUrl($uri, 200);
        
        return [
            'success' => true,
            'secret' => $secret,
            'qr_url' => $qrUrl,
            'uri' => $uri
        ];
    }
    
    /**
     * 2FA 활성화 확인 - OTP 코드로 검증 후 활성화
     */
    public function enable2FA(string $code): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => '로그인이 필요합니다.'];
        }
        
        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        if (empty($secret)) {
            return ['success' => false, 'error' => '2FA 설정을 먼저 시작하세요.'];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        // 코드 검증
        if (!TOTP::verify($secret, $code)) {
            return ['success' => false, 'error' => '인증 코드가 올바르지 않습니다.'];
        }
        
        // 백업 코드 생성
        $backupCodes = TOTP::generateBackupCodes(10);
        $hashedCodes = array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $backupCodes);
        
        // 사용자 정보 업데이트
        $this->db->update('users', ['id' => $user['id']], [
            '2fa_enabled' => true,
            '2fa_secret' => $this->encrypt2FASecret($secret),
            '2fa_backup_codes' => $hashedCodes,
            '2fa_enabled_at' => date('Y-m-d H:i:s')
        ]);
        
        // 세션 정리
        unset($_SESSION['2fa_setup_secret']);
        
        // 캐시 갱신
        self::$user = null;
        
        return [
            'success' => true,
            'message' => '2FA가 활성화되었습니다.',
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * 2FA 비활성화
     */
    public function disable2FA(string $password, string $code = ''): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => '로그인이 필요합니다.'];
        }
        
        // DB에서 비밀번호 포함해서 다시 조회
        $fullUser = $this->db->find('users', ['id' => $user['id']]);
        if (!$fullUser) {
            return ['success' => false, 'error' => '사용자를 찾을 수 없습니다.'];
        }
        
        // 비밀번호 확인
        if (!password_verify($password, $fullUser['password'])) {
            return ['success' => false, 'error' => '비밀번호가 올바르지 않습니다.'];
        }
        
        // 2FA 활성화 상태면 OTP 검증
        if (!empty($user['2fa_enabled']) && !empty($code)) {
            require_once __DIR__ . '/TOTP.php';
            $secret = $this->decrypt2FASecret($fullUser['2fa_secret'] ?? '');
            
            if (!TOTP::verify($secret, $code) && !$this->verifyBackupCode($user['id'], $code)) {
                return ['success' => false, 'error' => '인증 코드가 올바르지 않습니다.'];
            }
        }
        
        // 2FA 정보 제거
        $this->db->update('users', ['id' => $user['id']], [
            '2fa_enabled' => false,
            '2fa_secret' => null,
            '2fa_backup_codes' => null,
            '2fa_enabled_at' => null
        ]);
        
        // 캐시 갱신
        self::$user = null;
        
        return ['success' => true, 'message' => '2FA가 비활성화되었습니다.'];
    }
    
    /**
     * 2FA 검증 (로그인 2단계)
     */
    public function verify2FA(string $code): array {
        $pendingUser = $_SESSION['2fa_pending_user'] ?? null;
        if (!$pendingUser) {
            return ['success' => false, 'error' => '2FA 인증 대기 상태가 아닙니다.'];
        }
        
        $user = $this->db->find('users', ['id' => $pendingUser['id']]);
        if (!$user) {
            unset($_SESSION['2fa_pending_user']);
            return ['success' => false, 'error' => '사용자를 찾을 수 없습니다.'];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        $secret = $this->decrypt2FASecret($user['2fa_secret'] ?? '');
        $isValid = false;
        $usedBackup = false;
        
        // TOTP 코드 검증
        if (TOTP::verify($secret, $code)) {
            $isValid = true;
        }
        // 백업 코드 검증
        elseif ($this->verifyBackupCode($user['id'], $code)) {
            $isValid = true;
            $usedBackup = true;
        }
        
        if (!$isValid) {
            return ['success' => false, 'error' => '인증 코드가 올바르지 않습니다.'];
        }
        
        // 로그인 완료
        $_SESSION['user_id'] = $user['id'];
        self::$user = $user;
        
        // Remember Me 처리
        if ($pendingUser['remember'] ?? false) {
            $this->createRememberToken($user['id']);
        }
        
        // 마지막 로그인 시간 업데이트
        $this->db->update('users', ['id' => $user['id']], [
            'last_login' => date('Y-m-d H:i:s')
        ]);
        
        // 세션 정리
        unset($_SESSION['2fa_pending_user']);
        
        // 로그인 로그
        $this->logLogin($user['username'], true, $this->getClientIP(), '2FA 인증 완료' . ($usedBackup ? ' (백업 코드 사용)' : ''));
        
        return [
            'success' => true,
            'user' => $this->sanitizeUser($user),
            'used_backup' => $usedBackup
        ];
    }
    
    /**
     * 백업 코드 검증 및 사용 처리
     */
    private function verifyBackupCode(int $userId, string $code): bool {
        $user = $this->db->find('users', ['id' => $userId]);
        if (!$user || empty($user['2fa_backup_codes'])) {
            return false;
        }
        
        $codes = $user['2fa_backup_codes'];
        $cleanCode = str_replace('-', '', $code);
        
        foreach ($codes as $index => $hashedCode) {
            if (password_verify($cleanCode, $hashedCode)) {
                // 사용한 코드 제거
                unset($codes[$index]);
                $this->db->update('users', ['id' => $userId], [
                    '2fa_backup_codes' => array_values($codes)
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 백업 코드 재생성
     */
    public function regenerateBackupCodes(string $password): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => '로그인이 필요합니다.'];
        }
        
        // DB에서 비밀번호 포함해서 다시 조회
        $fullUser = $this->db->find('users', ['id' => $user['id']]);
        if (!$fullUser) {
            return ['success' => false, 'error' => '사용자를 찾을 수 없습니다.'];
        }
        
        // 비밀번호 확인
        if (!password_verify($password, $fullUser['password'])) {
            return ['success' => false, 'error' => '비밀번호가 올바르지 않습니다.'];
        }
        
        if (empty($user['2fa_enabled'])) {
            return ['success' => false, 'error' => '2FA가 활성화되어 있지 않습니다.'];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        // 새 백업 코드 생성
        $backupCodes = TOTP::generateBackupCodes(10);
        $hashedCodes = array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $backupCodes);
        
        $this->db->update('users', ['id' => $user['id']], [
            '2fa_backup_codes' => $hashedCodes
        ]);
        
        return [
            'success' => true,
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * 2FA 상태 확인
     */
    public function get2FAStatus(): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => '로그인이 필요합니다.'];
        }
        
        $backupCodesCount = 0;
        if (!empty($user['2fa_backup_codes']) && is_array($user['2fa_backup_codes'])) {
            $backupCodesCount = count($user['2fa_backup_codes']);
        }
        
        return [
            'success' => true,
            'enabled' => !empty($user['2fa_enabled']),
            'enabled_at' => $user['2fa_enabled_at'] ?? null,
            'backup_codes_remaining' => $backupCodesCount
        ];
    }
    
    /**
     * 2FA 시크릿 암호화
     */
    private function encrypt2FASecret(string $secret): string {
        $key = $this->get2FAEncryptionKey();
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * 2FA 시크릿 복호화
     */
    private function decrypt2FASecret(string $encrypted): string {
        if (empty($encrypted)) return '';
        
        $key = $this->get2FAEncryptionKey();
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv) ?: '';
    }
    
    /**
     * 2FA 암호화 키 가져오기
     */
    private function get2FAEncryptionKey(): string {
        // 설정에서 키를 가져오거나 기본값 사용
        $key = defined('TOTP_ENCRYPTION_KEY') ? TOTP_ENCRYPTION_KEY : 'webhard-2fa-default-key-change-me';
        return hash('sha256', $key, true);
    }
    
    /**
     * 사용자 정보 정제 (민감 정보 제거)
     */
    private function sanitizeUser(array $user): array {
        unset($user['password']);
        unset($user['2fa_secret']);
        unset($user['2fa_backup_codes']);
        return $user;
    }
}
