<?php
/**
 * API Router - REST API 엔드포인트
 */

// 오류를 JSON으로 반환하도록 설정
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 운영 환경 여부 (개발 시 true로 변경)
define('DEBUG_MODE', false);

set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    if (DEBUG_MODE) {
        echo json_encode([
            'success' => false, 
            'error' => 'Server Error: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]);
    } else {
        // 운영 환경: 상세 정보 숨김
        error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode([
            'success' => false, 
            'error' => '서버 오류가 발생했습니다.'
        ]);
    }
    exit;
});

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// 보안 헤더
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
// CSP 헤더 (frame-ancestors 'self'로 PDF/이미지 미리보기 허용)
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self';");

// CORS 설정 (같은 도메인 또는 지정된 도메인만 허용)
$allowedOrigins = []; // 필요시 허용할 도메인 추가: ['https://yourdomain.com']
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (empty($allowedOrigins)) {
    // 같은 도메인만 허용 (Origin 헤더 없는 경우 = 같은 도메인)
    if (!empty($origin)) {
        $serverHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                      . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        if ($origin === $serverHost) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
    }
} elseif (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 클래스 로드
$db = JsonDB::getInstance();
$auth = new Auth();
$storage = new Storage();
$fileManager = new FileManager();
$shareManager = new ShareManager();

// 활동 로그
require_once __DIR__ . '/api/ActivityLog.php';
$activityLog = new ActivityLog($db, $auth);

// ===== API Rate Limiting =====
/**
 * 간단한 파일 기반 Rate Limiting
 * 분당 요청 수 제한 (기본: 120회/분)
 */
function checkRateLimit(string $ip, int $maxRequests = 120, int $windowSeconds = 60): bool {
    $rateLimitDir = DATA_PATH . '/rate_limits';
    if (!is_dir($rateLimitDir)) {
        @mkdir($rateLimitDir, 0755, true);
    }
    
    // IP 기반 파일명 (해시로 안전하게)
    $file = $rateLimitDir . '/' . md5($ip) . '.json';
    $now = time();
    
    // 기존 데이터 로드
    $data = [];
    if (file_exists($file)) {
        $data = @json_decode(@file_get_contents($file), true) ?: [];
    }
    
    // 윈도우 시간 초과된 요청 제거
    $data = array_filter($data, fn($t) => ($now - $t) < $windowSeconds);
    
    // 제한 초과 체크
    if (count($data) >= $maxRequests) {
        return false;  // Rate limit exceeded
    }
    
    // 현재 요청 기록
    $data[] = $now;
    @file_put_contents($file, json_encode($data));
    
    return true;
}

// Rate Limiting 적용 (업로드/다운로드 제외)
$action = $_GET['action'] ?? '';
$rateLimitExclude = ['download', 'upload', 'upload_chunk', 'share_download', 'server_config'];
if (!in_array($action, $rateLimitExclude)) {
    $clientIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (strpos($clientIP, ',') !== false) {
        $clientIP = trim(explode(',', $clientIP)[0]);
    }
    
    if (!checkRateLimit($clientIP)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => '요청이 너무 많습니다. 잠시 후 다시 시도하세요.']);
        exit;
    }
}

// ===== CSRF 토큰 검증 =====
// CSRF 검증이 필요없는 액션 목록
$csrfExclude = [
    // 인증 관련
    'server_config', 'login', 'logout', 'csrf_token', 'signup', 'signup_status',
    // 2FA 관련
    '2fa_status', '2fa_setup', '2fa_enable', '2fa_disable', '2fa_verify', '2fa_regenerate_backup',
    // 공유 링크
    'share_access', 'share_download',
    // 파일 작업
    'upload', 'upload_chunk', 'download',
    // 읽기 전용 API (세션 인증으로 보호됨)
    'list', 'files', 'search', 'search_all', 'search_advanced',
    'storages', 'storages_all', 'storage_permissions',
    'users', 'user', 'me', 'pending_users_count',
    'sessions', 'login_logs', 'roles',
    'settings', 'security_settings',
    'index_stats', 'index_lookup',
    'activity_logs', 'activity_stats',
    'shares', 'share_check',
    'check_quota', 'size', 'info', 'detailed_info',
    'trash_list',
    'server_stats', 'system_info'
];

// _get으로 끝나는 API는 자동 제외 (읽기 전용)
$isCsrfExcluded = in_array($action, $csrfExclude) || str_ends_with($action, '_get');

// POST 요청이고 CSRF 검증 필요한 액션인 경우
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isCsrfExcluded) {
    // 헤더 또는 POST 데이터에서 토큰 확인
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
    
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => '보안 토큰이 유효하지 않습니다. 페이지를 새로고침 해주세요.',
            'csrf_error' => true
        ]);
        exit;
    }
}

// ===== QoS 헬퍼 함수 =====
/**
 * QoS 설정 파일 로드
 */
function loadQosSettings(): array {
    $qosFile = __DIR__ . '/data/qos_settings.json';
    return file_exists($qosFile) 
        ? json_decode(file_get_contents($qosFile), true) ?: []
        : [];
}

/**
 * 사용자의 QoS 속도 제한 계산
 * @param array $user 사용자 정보
 * @param string $type 'download' 또는 'upload'
 * @return int 속도 제한 (0 = 무제한)
 */
function getUserQosLimit(array $user, string $type = 'download'): int {
    $qosSettings = loadQosSettings();
    $limit = 0;
    
    // 역할 기본값
    $role = $user['role'] ?? 'user';
    if (isset($qosSettings['roles'][$role][$type])) {
        $limit = (int)$qosSettings['roles'][$role][$type];
    }
    
    // 사용자 개별 설정 (우선 적용)
    $userId = $user['id'];
    if (isset($qosSettings['users'][$userId][$type]) && $qosSettings['users'][$userId][$type] !== null) {
        $limit = (int)$qosSettings['users'][$userId][$type];
    }
    
    return $limit;
}

/**
 * 사이트 설정 파일 로드
 */
function loadSiteSettings(): array {
    $settingsFile = __DIR__ . '/data/site_settings.json';
    return file_exists($settingsFile) 
        ? json_decode(file_get_contents($settingsFile), true) ?: []
        : [];
}

/**
 * 사이트 설정 파일 저장
 */
function saveSiteSettings(array $settings): bool {
    $settingsFile = __DIR__ . '/data/site_settings.json';
    return file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// 요청 파싱
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// POST 데이터 병합
if ($method === 'POST' && empty($input)) {
    $input = $_POST;
}

// GET 데이터 병합
if ($method === 'GET') {
    $input = array_merge($input, $_GET);
}

try {
    $result = ['success' => false, 'error' => '잘못된 요청입니다.'];
    
    // 해킹시도 감지 (경로 조작 시도) - 강화 버전
    $inputString = json_encode($input);
    $inputStringLower = strtolower(urldecode($inputString));  // URL 디코딩 + 소문자 변환
    
    $suspiciousPatterns = [
        '../', '..\\',                                  // 경로 탐색
        '<script', '</script', 'javascript:',           // XSS 기본
        'onclick', 'onerror', 'onload', 'onmouseover',  // 이벤트 핸들러
        'onfocus', 'onblur', 'onsubmit', 'onchange',
        'expression(', 'eval(', 'alert(',               // JS 실행
        'data:text/html', 'data:application',           // 데이터 URI
        'vbscript:',                                    // VBScript
        '<?php', '<?=',                                 // PHP 인젝션 시도
        'base64_decode', 'exec(', 'system(',            // 위험 함수
    ];
    
    $hackDetected = false;
    $hackReason = '';
    
    foreach ($suspiciousPatterns as $pattern) {
        if (strpos($inputStringLower, strtolower($pattern)) !== false) {
            $hackDetected = true;
            $hackReason = "패턴 감지: {$pattern}";
            break;
        }
    }
    
    // 경로 파라미터에서 상위 디렉토리 접근 시도 감지 (이중 인코딩 대응)
    $pathParams = ['path', 'source', 'dest', 'file_path', 'paths', 'zip_name', 'new_name'];
    foreach ($pathParams as $param) {
        $value = $input[$param] ?? null;
        if ($value === null) continue;
        
        // 배열인 경우 처리
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $v) {
            if (!is_string($v)) continue;
            
            // URL 디코딩 (이중 인코딩 대응)
            $decoded = urldecode(urldecode($v));
            
            if (preg_match('/\.\.[\\/\\\\]|%2e%2e/i', $decoded)) {
                $hackDetected = true;
                $hackReason = "경로 탐색 시도: {$param}";
                break 2;
            }
        }
    }
    
    if ($hackDetected) {
        $activityLog->log(ActivityLog::TYPE_HACK_ATTEMPT, [
            'filename' => $action,
            'details' => $hackReason . ' | Input: ' . substr($inputString, 0, 200)
        ]);
        // 해킹 시도 시 즉시 차단
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '잘못된 요청입니다.']);
        exit;
    }
    
    switch ($action) {
        // ===== CSRF 토큰 =====
        case 'csrf_token':
            // 현재 토큰 반환 (없으면 새로 생성)
            $result = [
                'success' => true,
                'token' => getCsrfToken()
            ];
            break;
        
        // ===== 서버 설정 =====
        case 'server_config':
            // 로그인 불필요 - 업로드 전 청크 크기 결정에 필요
            $uploadMax = ini_get('upload_max_filesize');
            $postMax = ini_get('post_max_size');
            
            // 바이트로 변환하는 함수
            $toBytes = function($val) {
                $val = trim($val);
                $last = strtolower($val[strlen($val)-1]);
                $val = (int)$val;
                switch($last) {
                    case 'g': $val *= 1024;
                    case 'm': $val *= 1024;
                    case 'k': $val *= 1024;
                }
                return $val;
            };
            
            $uploadMaxBytes = $toBytes($uploadMax);
            $postMaxBytes = $toBytes($postMax);
            
            // 더 작은 값 사용, 안전하게 80%로 설정
            $maxChunkSize = (int)(min($uploadMaxBytes, $postMaxBytes) * 0.8);
            
            // 최소 1MB, 최대 50MB
            $maxChunkSize = max(1 * 1024 * 1024, min($maxChunkSize, 50 * 1024 * 1024));
            
            $result = [
                'success' => true,
                'upload_max_filesize' => $uploadMax,
                'post_max_size' => $postMax,
                'max_chunk_size' => $maxChunkSize
            ];
            break;
        
        // ===== 인증 =====
        case 'login':
            $remember = isset($input['remember']) && $input['remember'];
            $username = $input['username'] ?? '';
            $result = $auth->login($username, $input['password'] ?? '', $remember);
            
            // 로그인 결과에 따른 활동 로그
            if ($result['success'] ?? false) {
                // CSRF 토큰 재생성 (세션 고정 공격 방지)
                $result['csrf_token'] = regenerateCsrfToken();
                
                $activityLog->log(ActivityLog::TYPE_LOGIN, [
                    'filename' => $username,
                    'details' => 'User-Agent: ' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100)
                ]);
            } else {
                // 로그인 실패 기록 (해킹 시도 감지용)
                $activityLog->log(ActivityLog::TYPE_LOGIN_FAIL, [
                    'filename' => $username,
                    'details' => $result['error'] ?? '로그인 실패'
                ]);
            }
            break;
            
        case 'logout':
            // 로그아웃 전에 사용자 정보 저장
            $logoutUser = $auth->getUser();
            $auth->logout();
            
            // 로그아웃 로그
            if ($logoutUser) {
                $activityLog->log(ActivityLog::TYPE_LOGOUT, [
                    'filename' => $logoutUser['username'] ?? ''
                ]);
            }
            $result = ['success' => true];
            break;
            
        case 'me':
            // Remember Me 토큰 체크
            if (!$auth->isLoggedIn() && method_exists($auth, 'checkRememberToken')) {
                $auth->checkRememberToken();
            }
            $user = $auth->getUser();
            $result = $user ? ['success' => true, 'user' => $user] : ['success' => false, 'error' => '로그인 필요'];
            break;
            
        case 'change_password':
            $auth->requireLogin();
            $result = $auth->changePassword($input['current_password'] ?? '', $input['new_password'] ?? '');
            break;
        
        // ===== 2FA (TOTP) =====
        case '2fa_status':
            $auth->requireLogin();
            $result = $auth->get2FAStatus();
            break;
            
        case '2fa_setup':
            $auth->requireLogin();
            $result = $auth->setup2FA();
            break;
            
        case '2fa_enable':
            $auth->requireLogin();
            $result = $auth->enable2FA($input['code'] ?? '');
            break;
            
        case '2fa_disable':
            $auth->requireLogin();
            $result = $auth->disable2FA($input['password'] ?? '', $input['code'] ?? '');
            break;
            
        case '2fa_verify':
            // 로그인 2단계 - 로그인 안 된 상태
            $result = $auth->verify2FA($input['code'] ?? '');
            break;
            
        case '2fa_regenerate_backup':
            $auth->requireLogin();
            $result = $auth->regenerateBackupCodes($input['password'] ?? '');
            break;
        
        // ===== 세션 관리 =====
        case 'sessions':
            $auth->requireLogin();
            $result = ['success' => true, 'sessions' => $auth->getSessions()];
            break;
            
        case 'terminate_session':
            $auth->requireLogin();
            $result = $auth->terminateSession($input['session_id'] ?? '');
            break;
            
        case 'terminate_all_sessions':
            $auth->requireLogin();
            $result = $auth->terminateAllOtherSessions();
            break;
        
        // ===== 로그인 로그 =====
        case 'login_logs':
            $auth->requireLogin();
            $all = isset($_GET['all']) && $_GET['all'] === 'true';
            $page = (int)($_GET['page'] ?? 1);
            $perPage = (int)($_GET['per_page'] ?? 20);
            $data = $auth->getLoginLogs($page, $perPage, $all);
            $result = ['success' => true, ...$data];
            break;
        
        case 'login_logs_delete':
            $auth->requireAdmin();
            $ids = $input['ids'] ?? [];
            $result = $auth->deleteLoginLogs($ids);
            break;
        
        case 'login_logs_delete_all':
            $auth->requireAdmin();
            $result = $auth->deleteAllLoginLogs();
            break;
        
        case 'login_logs_delete_old':
            $auth->requireAdmin();
            $days = (int)($input['days'] ?? 30);
            $result = $auth->deleteOldLoginLogs($days);
            break;
        
        // ===== 사용자 관리 (관리자) =====
        case 'pending_users_count':
            $auth->requireAdmin();
            $users = $db->load('users');
            $count = count(array_filter($users, fn($u) => ($u['status'] ?? 'active') === 'pending'));
            $result = ['success' => true, 'count' => $count];
            break;
        
        case 'users':
            $auth->requireAdmin();
            $result = ['success' => true, 'users' => $auth->getUsers()];
            break;
            
        case 'user_create':
            $auth->requireAdmin();
            $result = $auth->createUser($input);
            break;
            
        case 'user_update':
            $auth->requireAdmin();
            $result = $auth->updateUser((int)($input['id'] ?? 0), $input);
            break;
            
        case 'user_delete':
            $auth->requireAdmin();
            $result = $auth->deleteUser((int)($input['id'] ?? 0));
            break;
        
        // ===== 역할 =====
        case 'roles':
            $auth->requireAdmin();
            $customRoles = $db->load('roles') ?: [];
            // 기본 역할 + 커스텀 역할
            $defaultRoles = [
                ['id' => 0, 'value' => 'admin', 'name' => '관리자', 'is_default' => true],
                ['id' => 0, 'value' => 'sub_admin', 'name' => '부 관리자', 'is_default' => true],
                ['id' => 0, 'value' => 'user', 'name' => '일반 사용자', 'is_default' => true]
            ];
            $result = ['success' => true, 'roles' => $customRoles, 'default_roles' => $defaultRoles];
            break;
        
        case 'role_create':
            $auth->requireAdmin();
            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                $result = ['success' => false, 'error' => '역할 이름을 입력하세요.'];
                break;
            }
            // 기본 역할 이름과 중복 체크
            $reservedNames = ['관리자', '부 관리자', '부관리자', '일반 사용자', '사용자', 'admin', 'sub_admin', 'user'];
            if (in_array($name, $reservedNames)) {
                $result = ['success' => false, 'error' => '기본 역할 이름은 사용할 수 없습니다.'];
                break;
            }
            $roles = $db->load('roles') ?: [];
            // 중복 체크
            foreach ($roles as $r) {
                if ($r['name'] === $name) {
                    $result = ['success' => false, 'error' => '이미 존재하는 역할입니다.'];
                    break 2;
                }
            }
            // value는 이름을 소문자+언더스코어로 변환
            $value = 'custom_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
            $id = $db->insert('roles', [
                'name' => $name,
                'value' => $value,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $result = ['success' => true, 'id' => $id, 'value' => $value];
            break;
        
        case 'role_delete':
            $auth->requireAdmin();
            $roleId = (int)($input['id'] ?? 0);
            // 삭제할 역할 정보 가져오기
            $roles = $db->load('roles') ?: [];
            $roleToDelete = null;
            foreach ($roles as $r) {
                if ($r['id'] == $roleId) {
                    $roleToDelete = $r;
                    break;
                }
            }
            if (!$roleToDelete) {
                $result = ['success' => false, 'error' => '역할을 찾을 수 없습니다.'];
                break;
            }
            $db->delete('roles', ['id' => $roleId]);
            // 해당 역할의 사용자들을 일반 사용자로 변경
            $users = $db->load('users');
            foreach ($users as &$u) {
                if (($u['role'] ?? '') === $roleToDelete['value']) {
                    $u['role'] = 'user';
                }
            }
            $db->save('users', $users);
            $result = ['success' => true];
            break;
        
        // ===== QoS 속도 제한 =====
        case 'qos_get':
            $auth->requireAdmin();
            $result = ['success' => true, 'settings' => loadQosSettings()];
            break;
        
        case 'qos_save':
            $auth->requireAdmin();
            $qosFile = __DIR__ . '/data/qos_settings.json';
            $qosSettings = [
                'roles' => $input['roles'] ?? [],
                'users' => $input['users'] ?? []
            ];
            file_put_contents($qosFile, json_encode($qosSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $result = ['success' => true];
            break;
        
        case 'qos_user':
            // 현재 사용자의 QoS 설정 가져오기 (로그인 시 호출)
            $auth->requireLogin();
            $user = $auth->getUser();
            $result = [
                'success' => true, 
                'download' => getUserQosLimit($user, 'download'), 
                'upload' => getUserQosLimit($user, 'upload')
            ];
            break;
        
        case 'user_bulk_quota':
            $auth->requireAdmin();
            $result = $auth->bulkUpdateQuota(
                $input['target'] ?? 'all',
                (int)($input['quota'] ?? 0)
            );
            break;
        
        // ===== 스토리지 관리 =====
        case 'storages':
            $auth->requireLogin();
            $result = ['success' => true, 'storages' => $storage->getStorages()];
            break;
            
        case 'storages_all':
            $auth->requireAdmin();
            $result = ['success' => true, 'storages' => $storage->getAllStorages()];
            break;
            
        case 'storage_get':
            $auth->requireAdmin();
            $result = $storage->getStorage((int)($_GET['id'] ?? 0));
            break;
            
        case 'storage_add':
            $auth->requireAdmin();
            $result = $storage->addStorage($input);
            break;
            
        case 'storage_update':
            $auth->requireAdmin();
            $result = $storage->updateStorage((int)($input['id'] ?? 0), $input);
            break;
            
        case 'storage_delete':
            $auth->requireAdmin();
            $result = $storage->deleteStorage((int)($input['id'] ?? 0));
            break;
            
        case 'storage_permissions':
            $auth->requireAdmin();
            $result = ['success' => true, 'permissions' => $storage->getPermissions((int)($_GET['storage_id'] ?? 0))];
            break;
            
        case 'storage_permission_set':
            $auth->requireAdmin();
            $result = $storage->setPermission(
                (int)($input['storage_id'] ?? 0),
                (int)($input['user_id'] ?? 0),
                $input
            );
            break;
            
        case 'storage_permission_remove':
            $auth->requireAdmin();
            $result = $storage->removePermission(
                (int)($input['storage_id'] ?? 0),
                (int)($input['user_id'] ?? 0)
            );
            break;
        
        // 스토리지 용량 정보 조회
        case 'storage_quota_info':
            $auth->requireLogin();
            $result = $storage->getQuotaInfo((int)($_GET['storage_id'] ?? 0));
            break;
        
        // 스토리지 사용량 재계산 (관리자)
        case 'storage_recalculate':
            $auth->requireAdmin();
            $result = $storage->recalculateUsedSize((int)($input['storage_id'] ?? 0));
            break;
        
        // ===== 파일 관리 =====
        case 'files':
            $auth->requireLogin();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $result = $fileManager->listFiles(
                $storageId,
                $_GET['path'] ?? ''
            );
            // 정렬 적용
            if ($result['success'] && isset($result['items'])) {
                $sortBy = $_GET['sort'] ?? 'name';
                $sortOrder = $_GET['order'] ?? 'asc';
                $result['items'] = $fileManager->sortFiles($result['items'], $sortBy, $sortOrder);
                
                // 공유 정보 추가
                $shares = $db->load('shares');
                $sharedPaths = [];
                foreach ($shares as $share) {
                    if (($share['storage_id'] ?? 0) == $storageId && ($share['is_active'] ?? 0)) {
                        $sharedPaths[$share['file_path']] = $share['token'];
                    }
                }
                
                foreach ($result['items'] as &$item) {
                    $relativePath = ltrim($item['path'] ?? '', '/');
                    if (isset($sharedPaths[$relativePath])) {
                        $item['shared'] = true;
                        $item['share_token'] = $sharedPaths[$relativePath];
                    }
                }
                unset($item);
            }
            break;
        
        // ===== 용량 체크 =====
        case 'check_quota':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $size = (int)($input['size'] ?? 0);
            $result = $fileManager->checkQuotaPublic($storageId, $size);
            break;
            
        case 'upload':
            $auth->requireLogin();
            if (empty($_FILES['file'])) {
                $result = ['success' => false, 'error' => '파일이 없습니다.'];
            } else {
                $result = $fileManager->upload(
                    (int)($_POST['storage_id'] ?? 0),
                    $_POST['path'] ?? '',
                    $_FILES['file']
                );
            }
            break;
            
        case 'upload_chunk':
            DebugLog::start('upload_chunk', [
                'storage_id' => $_POST['storage_id'] ?? 0,
                'filename' => $_POST['filename'] ?? '',
                'chunkIndex' => $_POST['chunkIndex'] ?? 0,
                'totalChunks' => $_POST['totalChunks'] ?? 0
            ]);
            
            DebugLog::log('Before requireLogin');
            $auth->requireLogin();
            DebugLog::log('After requireLogin');
            
            if (empty($_FILES['chunk'])) {
                $result = ['success' => false, 'error' => '청크가 없습니다.'];
            } else {
                DebugLog::log('Before uploadChunk call');
                $result = $fileManager->uploadChunk(
                    (int)($_POST['storage_id'] ?? 0),
                    $_POST['path'] ?? '',
                    [
                        'filename' => $_POST['filename'] ?? '',
                        'chunkIndex' => $_POST['chunkIndex'] ?? 0,
                        'totalChunks' => $_POST['totalChunks'] ?? 1,
                        'totalSize' => $_POST['totalSize'] ?? 0,
                        'uploadId' => $_POST['uploadId'] ?? '',
                        'lastModified' => $_POST['lastModified'] ?? 0,
                        'relativePath' => $_POST['relativePath'] ?? null,
                        'duplicateAction' => $_POST['duplicateAction'] ?? 'rename',
                        'file' => $_FILES['chunk']
                    ]
                );
                DebugLog::log('After uploadChunk call');
                
                // 업로드 완료 시 로그 및 인덱스 갱신
                if (($result['success'] ?? false) && ($result['complete'] ?? false)) {
                    DebugLog::log('Before completion processing');
                    $storageInfo = $storage->getStorageById((int)($_POST['storage_id'] ?? 0));
                    DebugLog::log('After getStorageById');
                    
                    $activityLog->log(ActivityLog::TYPE_UPLOAD, [
                        'storage_id' => (int)($_POST['storage_id'] ?? 0),
                        'storage_name' => $storageInfo['name'] ?? '',
                        'path' => ($_POST['path'] ?? '') . '/' . ($_POST['filename'] ?? ''),
                        'filename' => $_POST['filename'] ?? '',
                        'size' => (int)($_POST['totalSize'] ?? 0)
                    ]);
                    DebugLog::log('After activityLog');
                    
                    // 자동 인덱스 갱신
                    $appSettings = $db->load('settings');
                    DebugLog::log('After load settings');
                    
                    if (!empty($appSettings['auto_index'])) {
                        DebugLog::log('Before auto_index');
                        $fileIndex = FileIndex::getInstance();
                        if ($fileIndex->isAvailable()) {
                            $storageId = (int)($_POST['storage_id'] ?? 0);
                            $filepath = trim(($_POST['path'] ?? '') . '/' . ($_POST['filename'] ?? ''), '/');
                            $fileIndex->addFile($storageId, $filepath, [
                                'name' => $_POST['filename'] ?? '',
                                'size' => (int)($_POST['totalSize'] ?? 0),
                                'modified' => time(),
                                'is_dir' => false
                            ]);
                        }
                        DebugLog::log('After auto_index');
                    }
                    DebugLog::log('After completion processing');
                }
            }
            DebugLog::end('upload_chunk', $result);
            break;
            
        case 'download':
            $auth->requireLogin();
            $inline = isset($_GET['inline']) && $_GET['inline'] === '1';
            
            // 다운로드 로그 기록 (미리보기 제외)
            if (!$inline) {
                $storageId = (int)($_GET['storage_id'] ?? 0);
                $path = $_GET['path'] ?? '';
                $storageInfo = $storage->getStorageById($storageId);
                $realPath = $storage->getRealPath($storageId);
                $fullPath = $realPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
                $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
                
                $activityLog->log(ActivityLog::TYPE_DOWNLOAD, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path),
                    'size' => $fileSize
                ]);
            }
            
            // QoS 다운로드 속도 제한 가져오기
            $user = $auth->getUser();
            $downloadLimit = getUserQosLimit($user, 'download');
            
            $fileManager->download(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['path'] ?? '',
                $inline,
                $downloadLimit
            );
            break;
            
        case 'mkdir':
            $auth->requireLogin();
            $result = $fileManager->createFolder(
                (int)($input['storage_id'] ?? 0),
                $input['path'] ?? '',
                $input['name'] ?? ''
            );
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById((int)($input['storage_id'] ?? 0));
                $activityLog->log(ActivityLog::TYPE_CREATE_FOLDER, [
                    'storage_id' => (int)($input['storage_id'] ?? 0),
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => ($input['path'] ?? '') . '/' . ($input['name'] ?? ''),
                    'filename' => $input['name'] ?? ''
                ]);
                
                // 자동 인덱스 갱신
                $appSettings = $db->load('settings');
                if (!empty($appSettings['auto_index'])) {
                    $fileIndex = FileIndex::getInstance();
                    if ($fileIndex->isAvailable()) {
                        $storageId = (int)($input['storage_id'] ?? 0);
                        $filepath = trim(($input['path'] ?? '') . '/' . ($input['name'] ?? ''), '/');
                        $fileIndex->addFile($storageId, $filepath, [
                            'name' => $input['name'] ?? '',
                            'size' => 0,
                            'modified' => time(),
                            'is_dir' => true
                        ]);
                    }
                }
            }
            break;
            
        case 'delete':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $storageInfo = $storage->getStorageById($storageId);
            
            // 삭제 전에 폴더인지 확인
            $realPath = $storage->getRealPath($storageId);
            $fullPath = $realPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $wasDir = is_dir($fullPath);
            
            $result = $fileManager->delete($storageId, $path);
            
            if ($result['success'] ?? false) {
                $activityLog->log(ActivityLog::TYPE_DELETE, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path)
                ]);
                
                // 자동 인덱스 갱신
                $appSettings = $db->load('settings');
                if (!empty($appSettings['auto_index'])) {
                    $fileIndex = FileIndex::getInstance();
                    if ($fileIndex->isAvailable()) {
                        if ($wasDir) {
                            $fileIndex->removeFolder($storageId, $path);
                        } else {
                            $fileIndex->removeFile($storageId, $path);
                        }
                    }
                }
            }
            break;
            
        case 'rename':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $oldPath = $input['path'] ?? '';
            $newName = $input['new_name'] ?? '';
            
            $result = $fileManager->rename($storageId, $oldPath, $newName);
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_RENAME, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $oldPath,
                    'filename' => basename($oldPath),
                    'details' => '→ ' . $newName
                ]);
                
                // 자동 인덱스 갱신
                $appSettings = $db->load('settings');
                if (!empty($appSettings['auto_index'])) {
                    $fileIndex = FileIndex::getInstance();
                    if ($fileIndex->isAvailable()) {
                        $parentPath = dirname($oldPath);
                        $newPath = ($parentPath === '.' ? '' : $parentPath . '/') . $newName;
                        $fileIndex->moveFile($storageId, $oldPath, $newPath);
                    }
                }
            }
            break;
            
        case 'move':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $destStorageId = (int)($input['dest_storage_id'] ?? $storageId);  // 대상 스토리지 (없으면 소스와 동일)
            $source = $input['source'] ?? '';
            $dest = $input['dest'] ?? '';
            
            $result = $fileManager->move($storageId, $source, $destStorageId, $dest, $input['duplicate_action'] ?? 'overwrite');
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $destStorageInfo = $storage->getStorageById($destStorageId);
                $activityLog->log(ActivityLog::TYPE_MOVE, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $source,
                    'filename' => basename($source),
                    'details' => '→ ' . ($destStorageInfo['name'] ?? '') . ':' . $dest
                ]);
                
                // 자동 인덱스 갱신
                $appSettings = $db->load('settings');
                if (!empty($appSettings['auto_index'])) {
                    $fileIndex = FileIndex::getInstance();
                    if ($fileIndex->isAvailable()) {
                        $newPath = trim($dest . '/' . basename($source), '/');
                        // 다른 스토리지로 이동 시 인덱스 처리
                        if ($storageId !== $destStorageId) {
                            $fileIndex->removeFile($storageId, $source);
                            // 파일 정보 수집
                            $destBasePath = $storage->getRealPath($destStorageId);
                            $fullNewPath = $destBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
                            $fileInfo = [
                                'is_dir' => is_dir($fullNewPath) ? 1 : 0,
                                'size' => is_file($fullNewPath) ? filesize($fullNewPath) : 0,
                                'modified' => date('Y-m-d H:i:s', filemtime($fullNewPath) ?: time())
                            ];
                            $fileIndex->addFile($destStorageId, $newPath, $fileInfo);
                        } else {
                            $fileIndex->moveFile($storageId, $source, $newPath);
                        }
                    }
                }
            }
            break;
        
        // ===== 압축/압축해제 =====
        case 'extract':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $result = $fileManager->extractZip($storageId, $path, $input['dest'] ?? '');
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_EXTRACT, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path),
                    'details' => '→ ' . ($result['extracted_to'] ?? '')
                ]);
            }
            break;
            
        case 'compress':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $paths = $input['paths'] ?? [];
            
            $result = $fileManager->createZip($storageId, $paths, $input['zip_name'] ?? '');
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_COMPRESS, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => implode(', ', array_map('basename', $paths)),
                    'filename' => $result['zip_name'] ?? '',
                    'details' => count($paths) . '개 항목'
                ]);
            }
            break;
            
        case 'copy':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $destStorageId = (int)($input['dest_storage_id'] ?? $storageId);  // 대상 스토리지 (없으면 소스와 동일)
            $source = $input['source'] ?? '';
            $dest = $input['dest'] ?? '';
            
            $result = $fileManager->copy($storageId, $source, $destStorageId, $dest, $input['duplicate_action'] ?? 'overwrite');
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $destStorageInfo = $storage->getStorageById($destStorageId);
                $activityLog->log(ActivityLog::TYPE_COPY, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $source,
                    'filename' => basename($source),
                    'details' => '→ ' . ($destStorageInfo['name'] ?? '') . ':' . $dest
                ]);
            }
            break;
            
        case 'search':
            $auth->requireLogin();
            $result = $fileManager->search(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['query'] ?? '',
                $_GET['path'] ?? ''
            );
            break;
        
        case 'search_all':
            $auth->requireLogin();
            $result = $fileManager->searchAll($_GET['query'] ?? '');
            break;
        
        // ===== 검색 인덱스 =====
        case 'index_stats':
            $auth->requireLogin();
            $fileIndex = FileIndex::getInstance();
            $result = [
                'success' => true,
                'stats' => $fileIndex->getStats()
            ];
            break;
        
        case 'index_rebuild':
            $auth->requireRealAdmin();
            $fileIndex = FileIndex::getInstance();
            
            // 모든 스토리지 정보 가져오기 (개인폴더, 공유폴더, 추가 스토리지 모두 포함)
            $allStorages = $db->load('storages');
            $storagesWithPath = [];
            
            foreach ($allStorages as $s) {
                $path = $storage->getRealPath($s['id']);
                if ($path && is_dir($path)) {
                    $storagesWithPath[] = [
                        'id' => $s['id'],
                        'name' => $s['name'],
                        'path' => $path
                    ];
                }
            }
            
            $results = $fileIndex->rebuildAll($storagesWithPath);
            $totalCount = array_sum(array_column($results, 'count'));
            
            $result = [
                'success' => true,
                'message' => "인덱스 재구축 완료: {$totalCount}개 항목",
                'details' => $results,
                'stats' => $fileIndex->getStats()
            ];
            break;
        
        case 'index_rebuild_storage':
            $auth->requireRealAdmin();
            $storageId = (int)($input['storage_id'] ?? 0);
            
            if (!$storageId) {
                $result = ['success' => false, 'error' => '스토리지 ID가 필요합니다.'];
                break;
            }
            
            $path = $storage->getRealPath($storageId);
            if (!$path || !is_dir($path)) {
                $result = ['success' => false, 'error' => '스토리지 경로를 찾을 수 없습니다.'];
                break;
            }
            
            $fileIndex = FileIndex::getInstance();
            $count = $fileIndex->rebuildStorage($storageId, $path);
            
            $result = [
                'success' => true,
                'message' => "인덱스 재구축 완료: {$count}개 항목",
                'count' => $count
            ];
            break;
        
        case 'index_clear':
            $auth->requireRealAdmin();
            $fileIndex = FileIndex::getInstance();
            $fileIndex->clearAll();
            $result = ['success' => true, 'message' => '인덱스가 초기화되었습니다.'];
            break;
        
        // 인덱스 데이터 조회 (디버깅용)
        case 'index_lookup':
            $auth->requireRealAdmin();
            $filepath = $input['filepath'] ?? '';
            $fileIndex = FileIndex::getInstance();
            
            if (!$fileIndex->isAvailable()) {
                $result = ['success' => false, 'error' => '인덱스 사용 불가'];
                break;
            }
            
            // 파일명으로 검색하여 인덱스 데이터 확인
            $filename = basename($filepath);
            $stmt = $fileIndex->getDb()->prepare('SELECT * FROM files WHERE filename = :filename');
            $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
            $res = $stmt->execute();
            
            $records = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $records[] = $row;
            }
            
            $result = ['success' => true, 'records' => $records, 'searched_filename' => $filename];
            break;
            
        case 'info':
            $auth->requireLogin();
            $result = $fileManager->getInfo(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['path'] ?? ''
            );
            break;
        
        // ===== 상세 정보 (EXIF 포함) =====
        case 'detailed_info':
            $auth->requireLogin();
            $result = $fileManager->getDetailedInfo(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['path'] ?? ''
            );
            break;
        
        // ===== 드래그앤드롭 =====
        case 'drag_drop':
            $auth->requireLogin();
            $result = $fileManager->dragDrop(
                (int)($input['storage_id'] ?? 0),
                $input['sources'] ?? [],
                $input['dest'] ?? '',
                $input['action'] ?? 'move'
            );
            break;
        
        // ===== 공유 =====
        case 'shares':
            $auth->requireLogin();
            $result = ['success' => true, 'shares' => $shareManager->getShares()];
            break;
            
        case 'share_check':
            $auth->requireLogin();
            $result = $shareManager->checkShare(
                (int)($input['storage_id'] ?? 0),
                $input['path'] ?? ''
            );
            break;
            
        case 'share_create':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $result = $shareManager->createShare($storageId, $path, $input);
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_SHARE_CREATE, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path),
                    'details' => $result['token'] ?? ''
                ]);
            }
            break;
            
        case 'share_delete':
            $auth->requireLogin();
            $shareId = (int)($input['id'] ?? 0);
            
            // 삭제 전 정보 가져오기
            $shares = $db->load('shares');
            $shareInfo = null;
            foreach ($shares as $s) {
                if (($s['id'] ?? 0) == $shareId) {
                    $shareInfo = $s;
                    break;
                }
            }
            
            $result = $shareManager->deleteShare($shareId);
            
            if (($result['success'] ?? false) && $shareInfo) {
                $activityLog->log(ActivityLog::TYPE_SHARE_DELETE, [
                    'storage_id' => $shareInfo['storage_id'] ?? 0,
                    'path' => $shareInfo['file_path'] ?? '',
                    'filename' => basename($shareInfo['file_path'] ?? ''),
                    'details' => $shareInfo['token'] ?? ''
                ]);
            }
            break;
            
        case 'share_update':
            $auth->requireLogin();
            $result = $shareManager->updateShare((int)($input['id'] ?? 0), $input);
            break;
            
        case 'share_access':
            // 로그인 불필요
            $token = $_GET['token'] ?? '';
            $result = $shareManager->accessShare($token, $input['password'] ?? null);
            
            // 공유 접근 로그
            if ($result['success'] ?? false) {
                $activityLog->log(ActivityLog::TYPE_SHARE_ACCESS, [
                    'storage_id' => $result['share']['storage_id'] ?? 0,
                    'path' => $result['share']['file_path'] ?? '',
                    'filename' => $result['share']['file_name'] ?? basename($result['share']['file_path'] ?? ''),
                    'details' => 'Token: ' . $token
                ]);
            }
            break;
            
        case 'share_download':
            // 로그인 불필요
            $shareManager->downloadShare(
                $_GET['token'] ?? '',
                $_GET['password'] ?? null
            );
            break;
        
        // ===== 활동 로그 =====
        case 'activity_logs':
            $auth->requireAdmin();
            $filters = [
                'user_id' => $input['user_id'] ?? null,
                'type' => $input['type'] ?? null,
                'storage_id' => $input['storage_id'] ?? null,
                'date_from' => $input['date_from'] ?? null,
                'date_to' => $input['date_to'] ?? null,
                'search' => $input['search'] ?? null
            ];
            $page = (int)($input['page'] ?? 1);
            $limit = (int)($input['limit'] ?? 50);
            $result = $activityLog->getLogs($filters, $page, $limit);
            break;
            
        case 'activity_logs_clear':
            $auth->requireAdmin();
            $beforeDate = $input['before_date'] ?? null;
            $result = $activityLog->clearLogs($beforeDate);
            break;
            
        case 'activity_stats':
            $auth->requireLogin();
            $user = $auth->getUser();
            $targetUserId = ($user['role'] ?? '') === 'admin' && isset($input['user_id']) 
                ? (int)$input['user_id'] 
                : $user['id'];
            $result = ['success' => true, 'stats' => $activityLog->getUserStats($targetUserId)];
            break;
        
        // ===== 시스템 =====
        case 'system_info':
            $auth->requireAdmin();
            
            // 사용자 통계
            $users = $db->load('users');
            $totalUsers = count($users);
            
            // 세션 통계
            $sessions = $db->load('sessions');
            $activeSessions = count(array_filter($sessions, function($s) {
                return ($s['expires_at'] ?? 0) > time();
            }));
            
            // 스토리지 통계
            $storages = $db->load('storages');
            $totalStorages = count($storages);
            
            // 공유 통계
            $shares = $db->load('shares');
            $totalShares = count(array_filter($shares, function($s) {
                return empty($s['expires_at']) || strtotime($s['expires_at']) > time();
            }));
            
            // PHP 확장 모듈 체크
            $extensions = [
                'sqlite3' => [
                    'loaded' => extension_loaded('sqlite3'),
                    'required' => false,
                    'desc' => '빠른 검색 기능 (선택)'
                ],
                'zip' => [
                    'loaded' => extension_loaded('zip'),
                    'required' => true,
                    'desc' => '압축/해제 기능'
                ],
                'gd' => [
                    'loaded' => extension_loaded('gd'),
                    'required' => false,
                    'desc' => '이미지 처리 (썸네일)'
                ],
                'exif' => [
                    'loaded' => extension_loaded('exif'),
                    'required' => false,
                    'desc' => '이미지 EXIF 정보'
                ],
                'mbstring' => [
                    'loaded' => extension_loaded('mbstring'),
                    'required' => true,
                    'desc' => '다국어 문자열 처리'
                ],
                'json' => [
                    'loaded' => extension_loaded('json'),
                    'required' => true,
                    'desc' => 'JSON 처리'
                ],
                'curl' => [
                    'loaded' => extension_loaded('curl'),
                    'required' => false,
                    'desc' => '외부 API 호출'
                ],
                'fileinfo' => [
                    'loaded' => extension_loaded('fileinfo'),
                    'required' => false,
                    'desc' => '파일 MIME 타입 감지'
                ]
            ];
            
            // 폴더 권한 체크
            $folders = [
                'data' => [
                    'path' => DATA_PATH,
                    'writable' => is_writable(DATA_PATH),
                    'desc' => '데이터 저장'
                ],
                'users' => [
                    'path' => USER_FILES_ROOT,
                    'writable' => is_dir(USER_FILES_ROOT) && is_writable(USER_FILES_ROOT),
                    'desc' => '사용자 파일'
                ],
                'shared' => [
                    'path' => SHARED_FILES_ROOT,
                    'writable' => is_dir(SHARED_FILES_ROOT) && is_writable(SHARED_FILES_ROOT),
                    'desc' => '공유 폴더'
                ],
                'trash' => [
                    'path' => TRASH_PATH,
                    'writable' => is_dir(TRASH_PATH) && is_writable(TRASH_PATH),
                    'desc' => '휴지통'
                ],
                'chunks' => [
                    'path' => DATA_PATH . '/chunks',
                    'writable' => is_dir(DATA_PATH . '/chunks') && is_writable(DATA_PATH . '/chunks'),
                    'desc' => '업로드 임시파일'
                ]
            ];
            
            // 디스크 공간 (data 폴더 기준)
            $diskFree = @disk_free_space(DATA_PATH);
            $diskTotal = @disk_total_space(DATA_PATH);
            
            // 검색 인덱스 상태
            $fileIndex = FileIndex::getInstance();
            $indexStats = $fileIndex->getStats();
            
            // 검색 인덱스 상세 정보 추가
            $indexDbPath = DATA_PATH . '/file_index.db';
            $indexStats['db_path'] = $indexDbPath;
            $indexStats['db_exists'] = file_exists($indexDbPath);
            $indexStats['db_size'] = file_exists($indexDbPath) ? filesize($indexDbPath) : 0;
            $indexStats['db_modified'] = file_exists($indexDbPath) ? date('Y-m-d H:i:s', filemtime($indexDbPath)) : null;
            
            // 스토리지별 인덱스 통계
            $storageStats = [];
            foreach ($storages as $sid => $storage) {
                $stats = $fileIndex->getStorageStats((int)$sid);
                $storageStats[$sid] = [
                    'name' => $storage['name'] ?? "스토리지 #{$sid}",
                    'type' => $storage['type'] ?? 'unknown',
                    'total' => $stats['total'],
                    'files' => $stats['files'],
                    'folders' => $stats['folders']
                ];
            }
            $indexStats['storage_stats'] = $storageStats;
            
            // 세션 정보
            $sessionInfo = [
                'save_handler' => ini_get('session.save_handler'),
                'save_path' => ini_get('session.save_path') ?: '기본값',
                'gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
                'cookie_lifetime' => ini_get('session.cookie_lifetime'),
                'cookie_secure' => (bool)ini_get('session.cookie_secure'),
                'cookie_httponly' => (bool)ini_get('session.cookie_httponly'),
                'cookie_samesite' => ini_get('session.cookie_samesite') ?: 'None'
            ];
            
            // OPcache 상태
            $opcacheInfo = ['enabled' => false];
            if (function_exists('opcache_get_status')) {
                $opcStatus = @opcache_get_status(false);
                if ($opcStatus) {
                    $memory = $opcStatus['memory_usage'] ?? [];
                    $stats = $opcStatus['opcache_statistics'] ?? [];
                    $used = ($memory['used_memory'] ?? 0) + ($memory['wasted_memory'] ?? 0);
                    $total = $used + ($memory['free_memory'] ?? 0);
                    $hitRate = isset($stats['hits'], $stats['misses']) && ($stats['hits'] + $stats['misses']) > 0
                        ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 1) : 0;
                    
                    $opcacheInfo = [
                        'enabled' => $opcStatus['opcache_enabled'] ?? false,
                        'memory_total' => $total,
                        'memory_used' => $used,
                        'memory_free' => $memory['free_memory'] ?? 0,
                        'hit_rate' => $hitRate,
                        'cached_scripts' => $stats['num_cached_scripts'] ?? 0,
                        'hits' => $stats['hits'] ?? 0,
                        'misses' => $stats['misses'] ?? 0
                    ];
                }
            }
            
            // APCu 상태
            $apcuInfo = ['enabled' => false];
            if (function_exists('apcu_cache_info')) {
                $apcuCacheInfo = @apcu_cache_info(true);
                $apcuSma = @apcu_sma_info(true);
                if ($apcuCacheInfo) {
                    $hitRate = isset($apcuCacheInfo['num_hits'], $apcuCacheInfo['num_misses']) 
                        && ($apcuCacheInfo['num_hits'] + $apcuCacheInfo['num_misses']) > 0
                        ? round($apcuCacheInfo['num_hits'] / ($apcuCacheInfo['num_hits'] + $apcuCacheInfo['num_misses']) * 100, 1) : 0;
                    
                    $apcuInfo = [
                        'enabled' => true,
                        'memory_total' => $apcuSma['seg_size'] ?? 0,
                        'memory_used' => ($apcuSma['seg_size'] ?? 0) - ($apcuSma['avail_mem'] ?? 0),
                        'memory_free' => $apcuSma['avail_mem'] ?? 0,
                        'hit_rate' => $hitRate,
                        'entries' => $apcuCacheInfo['num_entries'] ?? 0
                    ];
                }
            }
            
            // 보안 체크리스트
            $securityChecks = [
                'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'display_errors' => !ini_get('display_errors'),
                'cookie_httponly' => (bool)ini_get('session.cookie_httponly'),
                'cookie_secure' => (bool)ini_get('session.cookie_secure'),
                'expose_php' => !ini_get('expose_php'),
                'allow_url_include' => !ini_get('allow_url_include')
            ];
            
            // PHP 상세 정보
            $phpInfo = [
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'timezone' => date_default_timezone_get(),
                'current_time' => date('Y-m-d H:i:s'),
                'sapi' => php_sapi_name(),
                'zend_version' => zend_version()
            ];
            
            // ========== 서버 리소스 모니터 ==========
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $serverResources = [
                'is_windows' => $isWindows,
                'hostname' => @gethostname() ?: 'Unknown',
                'cpu' => ['model' => 'Unknown', 'cores' => 0, 'threads' => 0, 'usage' => 0],
                'memory' => ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0],
                'network' => ['interfaces' => []],
                'traffic' => ['total_rx' => 0, 'total_tx' => 0, 'interfaces' => []],
                'webserver' => ['processes' => []],
                'uptime' => '',
                'disk_io' => ['read' => 0, 'write' => 0],
                'private_ip' => '',
                'public_ip' => '확인 불가'
            ];
            
            // Private IP
            $serverResources['private_ip'] = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : @gethostbyname(@gethostname());
            
            if ($isWindows) {
                // Windows CPU
                $cpuInfo = @shell_exec('wmic cpu get name,numberofcores,numberoflogicalprocessors /format:csv 2>nul');
                if ($cpuInfo) {
                    $lines = array_filter(explode("\n", trim($cpuInfo)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 4) {
                            $serverResources['cpu']['model'] = trim($parts[1]);
                            $serverResources['cpu']['cores'] = (int)$parts[2];
                            $serverResources['cpu']['threads'] = (int)$parts[3];
                        }
                    }
                }
                
                // Windows CPU Usage
                $cpuLoad = @shell_exec('wmic cpu get loadpercentage /format:csv 2>nul');
                if ($cpuLoad) {
                    $lines = array_filter(explode("\n", trim($cpuLoad)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 2) {
                            $serverResources['cpu']['usage'] = (int)$parts[1];
                        }
                    }
                }
                
                // Windows Memory
                $memInfo = @shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /format:csv 2>nul');
                if ($memInfo) {
                    $lines = array_filter(explode("\n", trim($memInfo)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 3) {
                            $freeKB = (int)$parts[1];
                            $totalKB = (int)$parts[2];
                            $serverResources['memory']['total'] = $totalKB * 1024;
                            $serverResources['memory']['free'] = $freeKB * 1024;
                            $serverResources['memory']['used'] = ($totalKB - $freeKB) * 1024;
                            if ($totalKB > 0) {
                                $serverResources['memory']['percent'] = round((($totalKB - $freeKB) / $totalKB) * 100, 1);
                            }
                        }
                    }
                }
                
                // Windows Network Traffic
                $trafficInfo = @shell_exec('wmic path Win32_PerfRawData_Tcpip_NetworkInterface get Name,BytesReceivedPersec,BytesSentPersec /format:csv 2>nul');
                if ($trafficInfo) {
                    $lines = array_filter(explode("\n", trim($trafficInfo)));
                    if (count($lines) > 1) {
                        array_shift($lines);
                        $totalRx = 0;
                        $totalTx = 0;
                        foreach ($lines as $line) {
                            $parts = str_getcsv($line);
                            if (count($parts) >= 4 && !empty(trim($parts[3]))) {
                                $rx = (int)$parts[1];
                                $tx = (int)$parts[2];
                                $totalRx += $rx;
                                $totalTx += $tx;
                                $serverResources['traffic']['interfaces'][] = [
                                    'name' => trim($parts[3]),
                                    'rx' => $rx,
                                    'tx' => $tx
                                ];
                            }
                        }
                        $serverResources['traffic']['total_rx'] = $totalRx;
                        $serverResources['traffic']['total_tx'] = $totalTx;
                    }
                }
                
                // Windows Disk I/O
                $diskIO = @shell_exec('wmic path Win32_PerfRawData_PerfDisk_PhysicalDisk where "Name=\'_Total\'" get DiskReadBytesPersec,DiskWriteBytesPersec /format:csv 2>nul');
                if ($diskIO) {
                    $lines = array_filter(explode("\n", trim($diskIO)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 3) {
                            $serverResources['disk_io']['read'] = (int)$parts[1];
                            $serverResources['disk_io']['write'] = (int)$parts[2];
                        }
                    }
                }
                
                // Windows Webserver Processes
                $webProcesses = [];
                $procs = ['httpd.exe' => 'Apache', 'nginx.exe' => 'Nginx', 'w3wp.exe' => 'IIS'];
                foreach ($procs as $proc => $name) {
                    $info = @shell_exec("wmic process where \"name='{$proc}'\" get processid,workingsetsize /format:csv 2>nul");
                    if ($info) {
                        $lines = array_filter(explode("\n", trim($info)));
                        if (count($lines) > 1) {
                            array_shift($lines);
                            $count = 0;
                            $totalMem = 0;
                            foreach ($lines as $line) {
                                $parts = str_getcsv($line);
                                if (count($parts) >= 3) {
                                    $count++;
                                    $totalMem += (int)$parts[2];
                                }
                            }
                            if ($count > 0) {
                                $webProcesses[] = ['name' => $name, 'count' => $count, 'memory' => $totalMem, 'icon' => '🌐'];
                            }
                        }
                    }
                }
                $serverResources['webserver']['processes'] = $webProcesses;
                
                // Windows Uptime
                $uptimeInfo = @shell_exec('wmic os get lastbootuptime /format:csv 2>nul');
                if ($uptimeInfo) {
                    $lines = array_filter(explode("\n", trim($uptimeInfo)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 2) {
                            $bootTime = $parts[1];
                            if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $bootTime, $m)) {
                                $bootTimestamp = mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
                                $uptimeSecs = time() - $bootTimestamp;
                                $days = floor($uptimeSecs / 86400);
                                $hours = floor(($uptimeSecs % 86400) / 3600);
                                $mins = floor(($uptimeSecs % 3600) / 60);
                                $serverResources['uptime'] = "{$days}일 {$hours}시간 {$mins}분";
                            }
                        }
                    }
                }
            } else {
                // Linux CPU
                if (is_readable('/proc/cpuinfo')) {
                    $cpuinfo = @file_get_contents('/proc/cpuinfo');
                    if ($cpuinfo) {
                        if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m)) {
                            $serverResources['cpu']['model'] = trim($m[1]);
                        }
                        $serverResources['cpu']['threads'] = substr_count($cpuinfo, 'processor');
                        $serverResources['cpu']['cores'] = $serverResources['cpu']['threads'];
                    }
                }
                
                // Linux CPU Usage
                $load = @sys_getloadavg();
                if ($load !== false && $serverResources['cpu']['threads'] > 0) {
                    $serverResources['cpu']['usage'] = min(100, round(($load[0] / $serverResources['cpu']['threads']) * 100, 1));
                }
                
                // Linux Memory
                if (is_readable('/proc/meminfo')) {
                    $meminfo = @file_get_contents('/proc/meminfo');
                    if ($meminfo) {
                        preg_match('/MemTotal:\s*(\d+)/i', $meminfo, $total);
                        preg_match('/MemAvailable:\s*(\d+)/i', $meminfo, $available);
                        if (!empty($total[1])) {
                            $totalKB = (int)$total[1];
                            $availKB = isset($available[1]) ? (int)$available[1] : 0;
                            $serverResources['memory']['total'] = $totalKB * 1024;
                            $serverResources['memory']['free'] = $availKB * 1024;
                            $serverResources['memory']['used'] = ($totalKB - $availKB) * 1024;
                            if ($totalKB > 0) {
                                $serverResources['memory']['percent'] = round((($totalKB - $availKB) / $totalKB) * 100, 1);
                            }
                        }
                    }
                }
                
                // Linux Network Traffic
                if (is_readable('/proc/net/dev')) {
                    $netdev = @file_get_contents('/proc/net/dev');
                    if ($netdev) {
                        $lines = explode("\n", $netdev);
                        $totalRx = 0;
                        $totalTx = 0;
                        foreach ($lines as $line) {
                            if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                                $iface = $m[1];
                                $rx = (int)$m[2];
                                $tx = (int)$m[3];
                                if ($iface !== 'lo') {
                                    $totalRx += $rx;
                                    $totalTx += $tx;
                                    $serverResources['traffic']['interfaces'][] = [
                                        'name' => $iface,
                                        'rx' => $rx,
                                        'tx' => $tx
                                    ];
                                }
                            }
                        }
                        $serverResources['traffic']['total_rx'] = $totalRx;
                        $serverResources['traffic']['total_tx'] = $totalTx;
                    }
                }
                
                // Linux Disk I/O
                if (is_readable('/proc/diskstats')) {
                    $diskstats = @file_get_contents('/proc/diskstats');
                    if ($diskstats) {
                        $totalRead = 0;
                        $totalWrite = 0;
                        foreach (explode("\n", $diskstats) as $line) {
                            if (preg_match('/^\s*\d+\s+\d+\s+(sd[a-z]|nvme\d+n\d+|vd[a-z])\s+\d+\s+\d+\s+(\d+)\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                                $totalRead += (int)$m[2] * 512;
                                $totalWrite += (int)$m[3] * 512;
                            }
                        }
                        $serverResources['disk_io']['read'] = $totalRead;
                        $serverResources['disk_io']['write'] = $totalWrite;
                    }
                }
                
                // Linux Uptime
                if (is_readable('/proc/uptime')) {
                    $uptime = @file_get_contents('/proc/uptime');
                    if ($uptime) {
                        $uptimeSecs = (int)floatval($uptime);
                        $days = floor($uptimeSecs / 86400);
                        $hours = floor(($uptimeSecs % 86400) / 3600);
                        $mins = floor(($uptimeSecs % 3600) / 60);
                        $serverResources['uptime'] = "{$days}일 {$hours}시간 {$mins}분";
                    }
                }
            }
            
            // Public IP (캐시 사용)
            $publicIpCache = DATA_PATH . '/public_ip_cache.json';
            $publicIp = null;
            
            if (file_exists($publicIpCache)) {
                $cache = @json_decode(@file_get_contents($publicIpCache), true);
                if ($cache && isset($cache['ip']) && isset($cache['time']) && (time() - $cache['time']) < 3600) {
                    $publicIp = $cache['ip'];
                }
            }
            
            if (!$publicIp && function_exists('curl_init')) {
                $ipServices = ['http://ip-api.com/line/?fields=query', 'http://checkip.amazonaws.com'];
                foreach ($ipServices as $service) {
                    $ch = @curl_init();
                    if ($ch) {
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $service,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 3,
                            CURLOPT_CONNECTTIMEOUT => 2
                        ]);
                        $ip = @curl_exec($ch);
                        @curl_close($ch);
                        if ($ip) {
                            $ip = trim($ip);
                            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                                $publicIp = $ip;
                                @file_put_contents($publicIpCache, json_encode(['ip' => $publicIp, 'time' => time()]));
                                break;
                            }
                        }
                    }
                }
            }
            
            if ($publicIp) {
                $serverResources['public_ip'] = $publicIp;
            }
            
            
            $result = [
                'success' => true,
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'upload_max' => ini_get('upload_max_filesize'),
                'post_max' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'total_users' => $totalUsers,
                'active_sessions' => $activeSessions,
                'total_storages' => $totalStorages,
                'total_shares' => $totalShares,
                'extensions' => $extensions,
                'folders' => $folders,
                'disk_free' => $diskFree,
                'disk_total' => $diskTotal,
                'index_stats' => $indexStats,
                'session_info' => $sessionInfo,
                'opcache_info' => $opcacheInfo,
                'apcu_info' => $apcuInfo,
                'security_checks' => $securityChecks,
                'php_info' => $phpInfo,
                'server_resources' => $serverResources
            ];
            break;
        
        case 'server_stats':
            // 실시간 모니터용 - 가벼운 데이터만 반환
            $auth->requireAdmin();
            
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $stats = [
                'time' => date('Y-m-d H:i:s'),
                'cpu' => 0,
                'memory' => ['used' => 0, 'total' => 0, 'percent' => 0],
                'network' => ['rx' => 0, 'tx' => 0],
                'disk' => ['read' => 0, 'write' => 0]
            ];
            
            // CPU 사용률
            if ($isWindows) {
                $cpuUsage = @shell_exec('wmic cpu get loadpercentage 2>nul');
                if ($cpuUsage && preg_match('/(\d+)/', $cpuUsage, $m)) {
                    $stats['cpu'] = (int)$m[1];
                }
            } else {
                $load = @sys_getloadavg();
                $cores = 1;
                if (file_exists('/proc/cpuinfo')) {
                    $cpuinfo = @file_get_contents('/proc/cpuinfo');
                    $cores = max(1, substr_count($cpuinfo, 'processor'));
                }
                if ($load) {
                    $stats['cpu'] = min(100, round($load[0] / $cores * 100));
                }
            }
            
            // 메모리
            if ($isWindows) {
                $memInfo = @shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value 2>nul');
                if ($memInfo) {
                    $total = 0; $free = 0;
                    if (preg_match('/TotalVisibleMemorySize=(\d+)/i', $memInfo, $m)) $total = (int)$m[1] * 1024;
                    if (preg_match('/FreePhysicalMemory=(\d+)/i', $memInfo, $m)) $free = (int)$m[1] * 1024;
                    $stats['memory'] = [
                        'total' => $total,
                        'used' => $total - $free,
                        'percent' => $total > 0 ? round(($total - $free) / $total * 100, 1) : 0
                    ];
                }
            } else {
                if (file_exists('/proc/meminfo')) {
                    $meminfo = @file_get_contents('/proc/meminfo');
                    $total = 0; $free = 0;
                    if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) $total = (int)$m[1] * 1024;
                    if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $m)) $free = (int)$m[1] * 1024;
                    elseif (preg_match('/MemFree:\s+(\d+)\s+kB/i', $meminfo, $m)) $free = (int)$m[1] * 1024;
                    $stats['memory'] = [
                        'total' => $total,
                        'used' => $total - $free,
                        'percent' => $total > 0 ? round(($total - $free) / $total * 100, 1) : 0
                    ];
                }
            }
            
            // 네트워크 트래픽
            if ($isWindows) {
                // Windows wmic 사용 (admin.php ajax_resources 방식)
                $trafficInfo = @shell_exec('wmic path Win32_PerfRawData_Tcpip_NetworkInterface get Name,BytesReceivedPersec,BytesSentPersec /format:csv 2>nul');
                if ($trafficInfo) {
                    $lines = array_filter(explode("\n", trim($trafficInfo)));
                    if (count($lines) > 1) {
                        array_shift($lines);
                        $totalRx = 0; $totalTx = 0;
                        $interfaces = [];
                        foreach ($lines as $line) {
                            $parts = str_getcsv($line);
                            // CSV: Node, BytesReceivedPersec, BytesSentPersec, Name
                            if (count($parts) >= 4 && !empty(trim($parts[3]))) {
                                $rx = (int)$parts[1];
                                $tx = (int)$parts[2];
                                $totalRx += $rx;
                                $totalTx += $tx;
                                $interfaces[] = ['name' => trim($parts[3]), 'rx' => $rx, 'tx' => $tx];
                            }
                        }
                        $stats['network'] = ['rx' => $totalRx, 'tx' => $totalTx, 'interfaces' => $interfaces];
                    }
                }
            } else {
                if (file_exists('/proc/net/dev')) {
                    $netDev = @file_get_contents('/proc/net/dev');
                    $totalRx = 0; $totalTx = 0;
                    $interfaces = [];
                    foreach (explode("\n", $netDev) as $line) {
                        if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                            if ($m[1] !== 'lo') {
                                $rx = (int)$m[2];
                                $tx = (int)$m[3];
                                $totalRx += $rx;
                                $totalTx += $tx;
                                $interfaces[] = ['name' => $m[1], 'rx' => $rx, 'tx' => $tx];
                            }
                        }
                    }
                    $stats['network'] = ['rx' => $totalRx, 'tx' => $totalTx, 'interfaces' => $interfaces];
                }
            }
            
            // 디스크 I/O
            if ($isWindows) {
                // Windows wmic 사용
                $diskIO = @shell_exec('wmic path Win32_PerfRawData_PerfDisk_PhysicalDisk where "Name=\'_Total\'" get DiskReadBytesPersec,DiskWriteBytesPersec /format:csv 2>nul');
                if ($diskIO) {
                    $lines = array_filter(explode("\n", trim($diskIO)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 3) {
                            $stats['disk'] = ['read' => (int)$parts[1], 'write' => (int)$parts[2]];
                        }
                    }
                }
            } else {
                if (file_exists('/proc/diskstats')) {
                    $diskstats = @file_get_contents('/proc/diskstats');
                    $totalRead = 0; $totalWrite = 0;
                    foreach (explode("\n", $diskstats) as $line) {
                        // sda, nvme0n1 등 주요 디스크만 (파티션 제외)
                        if (preg_match('/^\s*\d+\s+\d+\s+(sd[a-z]|nvme\d+n\d+|vd[a-z])\s+\d+\s+\d+\s+(\d+)\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                            $totalRead += (int)$m[2] * 512;
                            $totalWrite += (int)$m[3] * 512;
                        }
                    }
                    $stats['disk'] = ['read' => $totalRead, 'write' => $totalWrite];
                }
            }
            
            $result = ['success' => true, 'stats' => $stats];
            break;
        
        case 'settings':
            $auth->requireLogin();  // 모든 로그인 사용자가 설정 읽기 가능
            $settings = $db->load('settings');
            // 기본값 설정
            if (empty($settings)) {
                $settings = ['home_share_enabled' => true];
            }
            $result = ['success' => true, 'settings' => $settings];
            break;
        
        case 'settings_update':
            $auth->requireRealAdmin();  // 실제 관리자만 시스템 설정 변경 가능
            $settings = $db->load('settings');
            if (empty($settings)) {
                $settings = [];
            }
            // 설정 업데이트
            if (isset($input['home_share_enabled'])) {
                $settings['home_share_enabled'] = (bool)$input['home_share_enabled'];
            }
            if (isset($input['signup_enabled'])) {
                $settings['signup_enabled'] = (bool)$input['signup_enabled'];
            }
            if (isset($input['auto_approve'])) {
                $settings['auto_approve'] = (bool)$input['auto_approve'];
            }
            if (isset($input['external_url'])) {
                $settings['external_url'] = trim($input['external_url']);
            }
            if (isset($input['auto_index'])) {
                $settings['auto_index'] = (bool)$input['auto_index'];
            }
            $db->save('settings', $settings);
            $result = ['success' => true];
            break;
        
        // ===== 사이트 설정 (로고, 배경 등) =====
        case 'site_settings_get':
            $result = ['success' => true, 'settings' => loadSiteSettings()];
            break;
        
        case 'site_settings_update':
            $auth->requireRealAdmin();
            $siteSettings = loadSiteSettings();
            
            // 사이트 이름 업데이트
            if (isset($input['site_name'])) {
                $siteSettings['site_name'] = trim($input['site_name']);
            }
            
            saveSiteSettings($siteSettings);
            $result = ['success' => true];
            break;
        
        case 'site_image_upload':
            $auth->requireRealAdmin();
            
            $type = $input['type'] ?? $_POST['type'] ?? '';  // 'logo' or 'bg'
            if (!in_array($type, ['logo', 'bg'])) {
                $result = ['success' => false, 'error' => '잘못된 이미지 타입'];
                break;
            }
            
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $result = ['success' => false, 'error' => '파일 업로드 실패'];
                break;
            }
            
            $file = $_FILES['image'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                $result = ['success' => false, 'error' => '지원하지 않는 이미지 형식'];
                break;
            }
            
            // 이미지 저장 폴더
            $uploadDir = __DIR__ . '/data/site_images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // 기존 이미지 삭제
            $siteSettings = loadSiteSettings();
            
            $oldImageKey = $type === 'logo' ? 'logo_image' : 'bg_image';
            if (!empty($siteSettings[$oldImageKey])) {
                $oldPath = __DIR__ . '/' . $siteSettings[$oldImageKey];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            
            // 새 파일명 생성
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFilename = $type . '_' . time() . '.' . $ext;
            $newPath = $uploadDir . $newFilename;
            
            if (!move_uploaded_file($file['tmp_name'], $newPath)) {
                $result = ['success' => false, 'error' => '파일 저장 실패'];
                break;
            }
            
            // 설정 업데이트
            $relativePath = 'data/site_images/' . $newFilename;
            $siteSettings[$oldImageKey] = $relativePath;
            saveSiteSettings($siteSettings);
            
            $result = ['success' => true, 'path' => $relativePath];
            break;
        
        case 'site_image_delete':
            $auth->requireRealAdmin();
            
            $type = $input['type'] ?? '';  // 'logo' or 'bg'
            if (!in_array($type, ['logo', 'bg'])) {
                $result = ['success' => false, 'error' => '잘못된 이미지 타입'];
                break;
            }
            
            $siteSettings = loadSiteSettings();
            
            $imageKey = $type === 'logo' ? 'logo_image' : 'bg_image';
            if (!empty($siteSettings[$imageKey])) {
                $imagePath = __DIR__ . '/' . $siteSettings[$imageKey];
                if (file_exists($imagePath)) {
                    @unlink($imagePath);
                }
                unset($siteSettings[$imageKey]);
                saveSiteSettings($siteSettings);
            }
            
            $result = ['success' => true];
            break;
        
        // ===== 스토리지 경로 설정 =====
        case 'storage_paths_get':
            $auth->requireRealAdmin();
            $pathsFile = __DIR__ . '/data/storage_paths.json';
            $paths = file_exists($pathsFile) ? json_decode(file_get_contents($pathsFile), true) : [];
            
            // 현재 적용된 경로 (상수값)
            $result = [
                'success' => true,
                'paths' => [
                    'user_files_root' => $paths['user_files_root'] ?? '',
                    'shared_files_root' => $paths['shared_files_root'] ?? '',
                    'trash_path' => $paths['trash_path'] ?? ''
                ],
                'current' => [
                    'user_files_root' => USER_FILES_ROOT,
                    'shared_files_root' => SHARED_FILES_ROOT,
                    'trash_path' => TRASH_PATH
                ],
                'defaults' => [
                    'user_files_root' => __DIR__ . '/users',
                    'shared_files_root' => __DIR__ . '/shared',
                    'trash_path' => __DIR__ . '/data/trash_files'
                ]
            ];
            break;
        
        case 'storage_paths_update':
            $auth->requireRealAdmin();
            
            $userPath = trim($input['user_files_root'] ?? '');
            $sharedPath = trim($input['shared_files_root'] ?? '');
            $trashPath = trim($input['trash_path'] ?? '');
            
            // 경로 유효성 검사 (비어있으면 기본값 사용)
            $errors = [];
            
            if (!empty($userPath)) {
                // 경로 정규화
                $userPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $userPath);
                $userPath = rtrim($userPath, DIRECTORY_SEPARATOR);
                
                // 폴더 존재 확인 또는 생성 시도
                if (!is_dir($userPath)) {
                    if (!@mkdir($userPath, 0755, true)) {
                        $errors[] = '개인폴더 경로를 생성할 수 없습니다: ' . $userPath;
                    }
                }
                if (is_dir($userPath) && !is_writable($userPath)) {
                    $errors[] = '개인폴더 경로에 쓰기 권한이 없습니다: ' . $userPath;
                }
            }
            
            if (!empty($sharedPath)) {
                // 경로 정규화
                $sharedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sharedPath);
                $sharedPath = rtrim($sharedPath, DIRECTORY_SEPARATOR);
                
                // 폴더 존재 확인 또는 생성 시도
                if (!is_dir($sharedPath)) {
                    if (!@mkdir($sharedPath, 0755, true)) {
                        $errors[] = '공유폴더 경로를 생성할 수 없습니다: ' . $sharedPath;
                    }
                }
                if (is_dir($sharedPath) && !is_writable($sharedPath)) {
                    $errors[] = '공유폴더 경로에 쓰기 권한이 없습니다: ' . $sharedPath;
                }
            }
            
            if (!empty($trashPath)) {
                // 경로 정규화
                $trashPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trashPath);
                $trashPath = rtrim($trashPath, DIRECTORY_SEPARATOR);
                
                // 폴더 존재 확인 또는 생성 시도
                if (!is_dir($trashPath)) {
                    if (!@mkdir($trashPath, 0755, true)) {
                        $errors[] = '휴지통 경로를 생성할 수 없습니다: ' . $trashPath;
                    }
                }
                if (is_dir($trashPath) && !is_writable($trashPath)) {
                    $errors[] = '휴지통 경로에 쓰기 권한이 없습니다: ' . $trashPath;
                }
            }
            
            if (!empty($errors)) {
                $result = ['success' => false, 'error' => implode("\n", $errors)];
                break;
            }
            
            // 설정 저장
            $pathsFile = __DIR__ . '/data/storage_paths.json';
            $paths = [
                'user_files_root' => $userPath,
                'shared_files_root' => $sharedPath,
                'trash_path' => $trashPath,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            file_put_contents($pathsFile, json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $result = ['success' => true, 'message' => '경로 설정이 저장되었습니다. 새로고침 후 적용됩니다.'];
            break;
        
        // ===== 회원가입 설정 확인 (공개) =====
        case 'signup_status':
            $settings = $db->load('settings');
            $users = $db->load('users') ?: [];
            
            // 사용자가 없으면 첫 관리자 가입을 위해 자동 허용
            $isFirstUser = empty($users);
            $signupEnabled = $isFirstUser || ($settings['signup_enabled'] ?? false);
            
            $result = [
                'success' => true, 
                'signup_enabled' => $signupEnabled,
                'is_first_user' => $isFirstUser
            ];
            break;
        
        // ===== 회원가입 =====
        case 'signup':
            $settings = $db->load('settings');
            $users = $db->load('users') ?: [];
            
            // 사용자가 없으면 첫 관리자 가입 허용
            $isFirstUser = empty($users);
            
            // 회원가입 허용 여부 확인 (첫 사용자는 항상 허용)
            if (!$isFirstUser && !($settings['signup_enabled'] ?? false)) {
                $result = ['success' => false, 'error' => '회원가입이 비활성화되어 있습니다.'];
                break;
            }
            
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $displayName = trim($input['display_name'] ?? '');
            $email = trim($input['email'] ?? '');
            
            // 유효성 검사
            if (empty($username) || empty($password)) {
                $result = ['success' => false, 'error' => '아이디와 비밀번호는 필수입니다.'];
                break;
            }
            if (strlen($username) < 3) {
                $result = ['success' => false, 'error' => '아이디는 3자 이상이어야 합니다.'];
                break;
            }
            if (strlen($password) < 8) {
                $result = ['success' => false, 'error' => '비밀번호는 8자 이상이어야 합니다.'];
                break;
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                $result = ['success' => false, 'error' => '아이디는 영문, 숫자, 밑줄만 사용 가능합니다.'];
                break;
            }
            
            // 중복 체크
            $existing = $db->find('users', ['username' => $username]);
            if ($existing) {
                $result = ['success' => false, 'error' => '이미 존재하는 아이디입니다.'];
                break;
            }
            
            // 첫 번째 사용자는 관리자로, 이후는 설정에 따라
            if ($isFirstUser) {
                $role = 'admin';
                $status = 'active';
            } else {
                $role = 'user';
                $autoApprove = $settings['auto_approve'] ?? false;
                $status = $autoApprove ? 'active' : 'pending';
            }
            
            // 사용자 생성
            $id = $db->insert('users', [
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => $displayName ?: $username,
                'email' => $email,
                'role' => $role,
                'status' => $status,
                'quota' => 0,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'last_login' => null
            ]);
            
            if ($isFirstUser) {
                $result = ['success' => true, 'message' => '🎉 관리자 계정이 생성되었습니다. 로그인해주세요.'];
            } elseif ($status === 'active') {
                $result = ['success' => true, 'message' => '회원가입이 완료되었습니다. 로그인해주세요.'];
            } else {
                $result = ['success' => true, 'message' => '가입 신청이 완료되었습니다. 관리자 승인 후 로그인할 수 있습니다.'];
            }
            break;
        
        // ===== 용량 =====
        case 'storage_quota':
            $auth->requireLogin();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $basePath = $storage->getRealPath($storageId);
            
            if ($basePath) {
                // 스토리지 정보 가져오기
                $storageInfo = $storage->getStorageById($storageId);
                $storageType = $storageInfo['storage_type'] ?? 'local';
                
                // home 타입이면 사용자 quota 사용
                if ($storageType === 'home') {
                    // 폴더 사용량 계산 (home은 작으므로 직접 계산)
                    $used = $fileManager->getDirectorySize($basePath);
                    
                    // 캐시 우회하여 DB에서 직접 quota 조회
                    $userId = $auth->getUserId();
                    $freshUser = $db->find('users', ['id' => $userId]);
                    $userQuota = (int)($freshUser['quota'] ?? 0);
                    
                    if ($userQuota > 0) {
                        $total = $userQuota;
                        $free = max(0, $total - $used);
                    } else {
                        // 무제한인 경우
                        $total = 0;
                        $free = 0;
                    }
                } else {
                    // shared/local 타입: DB 캐싱된 used_size 사용 (빠름!)
                    $used = (int)($storageInfo['used_size'] ?? 0);
                    $quota = (int)($storageInfo['quota'] ?? 0);
                    
                    if ($quota > 0) {
                        $total = $quota;
                        $free = max(0, $quota - $used);
                    } else {
                        // quota 미설정: 무제한
                        $total = 0;
                        $free = 0;
                    }
                }
                
                $result = [
                    'success' => true,
                    'used' => $used,
                    'total' => $total,
                    'free' => $free,
                    'used_formatted' => $fileManager->formatSize($used),
                    'total_formatted' => $total > 0 ? $fileManager->formatSize($total) : '무제한',
                    'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0
                ];
            } else {
                $result = ['success' => false, 'error' => '스토리지 없음'];
            }
            break;
        
        // ===== 휴지통 =====
        case 'trash_list':
            $auth->requireLogin();
            $user = $auth->getUser();
            $all = isset($_GET['all']) && $_GET['all'] === 'true' && ($user['role'] ?? '') === 'admin';
            
            $userId = $all ? null : $user['id'];
            $result = $fileManager->getTrashList($userId);
            break;
            
        case 'trash_restore':
            $auth->requireLogin();
            $user = $auth->getUser();
            $id = $input['id'] ?? '';
            
            // 권한 확인
            $trash = $db->load('trash');
            $item = null;
            foreach ($trash as $t) {
                if ($t['id'] === $id) {
                    $item = $t;
                    break;
                }
            }
            
            if (!$item) {
                $result = ['success' => false, 'error' => '항목을 찾을 수 없습니다'];
                break;
            }
            
            // 관리자이거나 본인이 삭제한 것만 복원 가능
            if (($user['role'] ?? '') !== 'admin' && ($item['deleted_by'] ?? 0) !== $user['id']) {
                $result = ['success' => false, 'error' => '권한이 없습니다'];
                break;
            }
            
            $result = $fileManager->restoreFromTrash($id);
            
            // 복원 로그
            if ($result['success'] ?? false) {
                $activityLog->log(ActivityLog::TYPE_RESTORE, [
                    'storage_id' => $item['storage_id'] ?? 0,
                    'storage_name' => $item['storage_name'] ?? '',
                    'path' => $item['original_path'] ?? '',
                    'filename' => $item['name'] ?? ''
                ]);
            }
            break;
            
        case 'trash_delete':
            $auth->requireLogin();
            $user = $auth->getUser();
            $id = $input['id'] ?? '';
            
            // 권한 확인
            $trash = $db->load('trash');
            $item = null;
            foreach ($trash as $t) {
                if ($t['id'] === $id) {
                    $item = $t;
                    break;
                }
            }
            
            if (!$item) {
                $result = ['success' => false, 'error' => '항목을 찾을 수 없습니다'];
                break;
            }
            
            // 권한 확인
            if (($user['role'] ?? '') !== 'admin' && ($item['deleted_by'] ?? 0) !== $user['id']) {
                $result = ['success' => false, 'error' => '권한이 없습니다'];
                break;
            }
            
            $result = $fileManager->deleteFromTrash($id);
            break;
            
        case 'trash_empty':
            $auth->requireLogin();
            $user = $auth->getUser();
            $all = isset($input['all']) && $input['all'] === true && ($user['role'] ?? '') === 'admin';
            
            $userId = $all ? null : $user['id'];
            $result = $fileManager->emptyTrash($userId);
            break;
        
        // ===== 조건부 일괄 삭제 =====
        case 'bulk_search':
            $auth->requireAdmin();
            $patterns = $input['patterns'] ?? [];
            if (is_string($patterns)) {
                $patterns = array_filter(array_map('trim', explode("\n", $patterns)));
            }
            $result = $fileManager->bulkSearch(
                (int)($input['storage_id'] ?? 0),
                $input['path'] ?? '',
                $patterns,
                $input['scope'] ?? 'recursive',
                $input['type'] ?? 'all'
            );
            break;
            
        case 'bulk_delete':
            $auth->requireAdmin();
            $result = $fileManager->bulkDelete(
                (int)($input['storage_id'] ?? 0),
                $input['paths'] ?? []
            );
            break;
            
        // ===== 보안 설정 (관리자) =====
        case 'security_settings':
            $auth->requireRealAdmin();  // 실제 관리자만
            $settings = $db->load('security_settings');
            if (empty($settings)) {
                $settings = [
                    'enabled' => false,
                    'block_country' => false,
                    'allow_country_only' => false,
                    'block_ip' => false,
                    'allow_ip_only' => false,
                    'allowed_ips' => [],
                    'blocked_ips' => [],
                    'allowed_countries' => [],
                    'blocked_countries' => [],
                    'admin_ips' => [],
                    'block_message' => '접근이 차단되었습니다.',
                    'cache_hours' => 24,
                    'log_enabled' => false,
                    'max_attempts' => 5,
                    'lockout_minutes' => 15
                ];
            }
            $result = [
                'success' => true, 
                'settings' => $settings,
                'current_ip' => $auth->getCurrentIP(),
                'current_country' => $auth->getCurrentCountry()
            ];
            break;
            
        case 'security_settings_save':
            $auth->requireRealAdmin();  // 실제 관리자만
            
            $settings = [
                'enabled' => !empty($input['enabled']),
                'block_country' => !empty($input['block_country']),
                'allow_country_only' => !empty($input['allow_country_only']),
                'block_ip' => !empty($input['block_ip']),
                'allow_ip_only' => !empty($input['allow_ip_only']),
                'allowed_ips' => array_filter(array_map('trim', $input['allowed_ips'] ?? [])),
                'blocked_ips' => array_filter(array_map('trim', $input['blocked_ips'] ?? [])),
                'allowed_countries' => array_filter(array_map('trim', $input['allowed_countries'] ?? [])),
                'blocked_countries' => array_filter(array_map('trim', $input['blocked_countries'] ?? [])),
                'admin_ips' => array_filter(array_map('trim', $input['admin_ips'] ?? [])),
                'block_message' => trim($input['block_message'] ?? '접근이 차단되었습니다.'),
                'cache_hours' => max(1, min(168, (int)($input['cache_hours'] ?? 24))),
                'log_enabled' => !empty($input['log_enabled']),
                'max_attempts' => max(0, (int)($input['max_attempts'] ?? 5)),
                'lockout_minutes' => max(1, (int)($input['lockout_minutes'] ?? 15))
            ];
            
            $db->save('security_settings', $settings);
            $result = ['success' => true];
            break;
            
        case 'security_test':
            $auth->requireRealAdmin();  // 실제 관리자만
            $result = array_merge(['success' => true], $auth->testIPRestriction());
            break;
        
        // ===== 즐겨찾기 =====
        case 'favorites_get':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $favorites = $db->findAll('favorites', ['user_id' => $userId]);
            $result = ['success' => true, 'favorites' => array_values($favorites)];
            break;
            
        case 'favorites_add':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $name = $input['name'] ?? basename($path);
            $isDir = (bool)($input['is_dir'] ?? false);
            
            // 이미 존재하는지 확인
            $existing = $db->find('favorites', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            if ($existing) {
                $result = ['success' => false, 'error' => '이미 즐겨찾기에 추가되어 있습니다.'];
            } else {
                $db->insert('favorites', [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'path' => $path,
                    'name' => $name,
                    'is_dir' => $isDir ? 1 : 0,
                    'added_at' => date('Y-m-d H:i:s')
                ]);
                
                // 오래된 기록 정리 (100개 초과 시)
                $allFavorites = $db->findAll('favorites', ['user_id' => $userId]);
                if (count($allFavorites) > 100) {
                    usort($allFavorites, function($a, $b) {
                        return strtotime($b['added_at'] ?? 0) - strtotime($a['added_at'] ?? 0);
                    });
                    $toDelete = array_slice($allFavorites, 100);
                    foreach ($toDelete as $item) {
                        $db->delete('favorites', ['id' => $item['id']]);
                    }
                }
                
                $result = ['success' => true, 'message' => '즐겨찾기에 추가되었습니다.'];
            }
            break;
            
        case 'favorites_remove':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $count = $db->delete('favorites', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            $result = $count > 0 
                ? ['success' => true, 'message' => '즐겨찾기에서 제거되었습니다.']
                : ['success' => false, 'error' => '즐겨찾기를 찾을 수 없습니다.'];
            break;
        
        // 즐겨찾기 전체 삭제
        case 'favorites_clear':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            
            $allFavorites = $db->findAll('favorites', ['user_id' => $userId]);
            foreach ($allFavorites as $item) {
                $db->delete('favorites', ['id' => $item['id']]);
            }
            
            $result = ['success' => true, 'message' => '즐겨찾기가 모두 삭제되었습니다.'];
            break;
        
        // ===== 최근 파일 =====
        case 'recent_files_get':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $limit = min((int)($input['limit'] ?? 50), 100);
            
            $recentFiles = $db->findAll('recent_files', ['user_id' => $userId]);
            // 최신순 정렬
            usort($recentFiles, function($a, $b) {
                return strtotime($b['accessed_at'] ?? 0) - strtotime($a['accessed_at'] ?? 0);
            });
            // 제한
            $recentFiles = array_slice($recentFiles, 0, $limit);
            
            $result = ['success' => true, 'files' => array_values($recentFiles)];
            break;
            
        case 'recent_files_add':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $name = $input['name'] ?? basename($path);
            $action = $input['action'] ?? 'view'; // view, download, upload
            
            // 기존 기록 삭제 (중복 방지)
            $db->delete('recent_files', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            // 새로 추가
            $db->insert('recent_files', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path,
                'name' => $name,
                'action' => $action,
                'accessed_at' => date('Y-m-d H:i:s')
            ]);
            
            // 오래된 기록 정리 (100개 초과 시)
            $allRecent = $db->findAll('recent_files', ['user_id' => $userId]);
            if (count($allRecent) > 100) {
                usort($allRecent, function($a, $b) {
                    return strtotime($b['accessed_at'] ?? 0) - strtotime($a['accessed_at'] ?? 0);
                });
                $toDelete = array_slice($allRecent, 100);
                foreach ($toDelete as $item) {
                    $db->delete('recent_files', ['id' => $item['id']]);
                }
            }
            
            $result = ['success' => true];
            break;
            
        case 'recent_files_clear':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            
            $allRecent = $db->findAll('recent_files', ['user_id' => $userId]);
            foreach ($allRecent as $item) {
                $db->delete('recent_files', ['id' => $item['id']]);
            }
            
            $result = ['success' => true, 'message' => '최근 파일 기록이 삭제되었습니다.'];
            break;
        
        // 최근 파일 개별 삭제
        case 'recent_files_remove':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $db->delete('recent_files', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            $result = ['success' => true];
            break;
        
        // ===== 파일 잠금 =====
        case 'file_lock':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            // 이미 잠겨있는지 확인
            $existing = $db->find('locked_files', [
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            if ($existing) {
                $result = ['success' => false, 'error' => '이미 잠긴 파일입니다.'];
            } else {
                $db->insert('locked_files', [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'path' => $path,
                    'locked_at' => date('Y-m-d H:i:s')
                ]);
                $result = ['success' => true, 'message' => '파일이 잠겼습니다.'];
            }
            break;
            
        case 'file_unlock':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            // 본인이 잠근 파일인지 또는 관리자인지 확인
            $locked = $db->find('locked_files', [
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            if (!$locked) {
                $result = ['success' => false, 'error' => '잠긴 파일이 아닙니다.'];
            } else if ($locked['user_id'] != $userId && !$auth->isAdmin()) {
                $result = ['success' => false, 'error' => '본인이 잠근 파일만 해제할 수 있습니다.'];
            } else {
                $db->delete('locked_files', ['id' => $locked['id']]);
                $result = ['success' => true, 'message' => '파일 잠금이 해제되었습니다.'];
            }
            break;
            
        case 'locked_files_get':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            
            $lockedFiles = $db->findAll('locked_files', ['storage_id' => $storageId]);
            // path만 배열로 반환 (빠른 조회용)
            $lockedPaths = array_column($lockedFiles, 'path');
            
            $result = ['success' => true, 'locked_paths' => $lockedPaths, 'locked_files' => array_values($lockedFiles)];
            break;
        
        // ===== 통합 검색 =====
        case 'search_advanced':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $query = trim($input['query'] ?? '');
            $filters = $input['filters'] ?? [];
            $page = max(1, (int)($input['page'] ?? 1));
            $perPage = min(100, max(20, (int)($input['per_page'] ?? 50)));
            
            // 검색어가 비어있으면 빈 결과 반환
            if (empty($query)) {
                $result = [
                    'success' => true,
                    'results' => [],
                    'total' => 0,
                    'page' => 1,
                    'per_page' => $perPage,
                    'total_pages' => 0
                ];
                break;
            }
            
            // FileIndex 초기화
            $fileIndex = FileIndex::getInstance();
            if (!$fileIndex->isAvailable()) {
                $result = ['success' => false, 'error' => '검색 인덱스를 사용할 수 없습니다. 관리자에게 문의하세요.'];
                break;
            }
            
            // 필터 옵션
            $fileType = $filters['type'] ?? ''; // image, video, audio, document, archive, all
            $dateFrom = $filters['date_from'] ?? '';
            $dateTo = $filters['date_to'] ?? '';
            $sizeMin = (int)($filters['size_min'] ?? 0); // bytes
            $sizeMax = (int)($filters['size_max'] ?? 0); // 0 = 무제한
            $searchPath = $filters['path'] ?? ''; // 특정 폴더 내 검색
            
            // 스토리지 접근 권한 확인
            if ($storageId > 0) {
                $storageInfo = $storage->getStorage($storageId);
                if (!$storageInfo) {
                    $result = ['success' => false, 'error' => '스토리지를 찾을 수 없습니다.'];
                    break;
                }
            }
            
            // FileIndex 사용 - 제한 없이 전체 검색
            $searchResults = [];
            
            if ($storageId > 0) {
                // 특정 스토리지 검색 (무제한)
                $results = $fileIndex->search($query, $storageId, 0);
                foreach ($results as $item) {
                    $item['storage_id'] = $storageId;
                    $searchResults[] = $item;
                }
            } else {
                // 전체 스토리지 검색 (사용자 접근 가능한 스토리지만, 무제한)
                $userStorages = $storage->getStorages();
                
                // 스토리지 ID -> 이름 매핑 생성
                $storageNames = [];
                $allowedStorageIds = [];
                foreach ($userStorages as $category => $storageList) {
                    // getStorages()는 카테고리별로 반환 (home, public, shared)
                    if (is_array($storageList)) {
                        foreach ($storageList as $st) {
                            $sid = (int)($st['id'] ?? 0);
                            if ($sid > 0) {
                                $storageNames[$sid] = $st['name'] ?? '';
                                $allowedStorageIds[] = $sid;
                            }
                        }
                    }
                }
                
                // 허용된 스토리지에서만 검색 (FileIndex가 storage_id 반환)
                if (!empty($allowedStorageIds)) {
                    $results = $fileIndex->search($query, $allowedStorageIds, 0);
                    foreach ($results as $item) {
                        // FileIndex가 반환한 storage_id 사용
                        $sid = (int)($item['storage_id'] ?? 0);
                        $item['storage_name'] = $storageNames[$sid] ?? '';
                        $searchResults[] = $item;
                    }
                }
            }
            
            // 필터 적용
            if (!empty($fileType) && $fileType !== 'all') {
                $typeExtensions = [
                    'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'],
                    'video' => ['mp4', 'webm', 'avi', 'mkv', 'mov', 'wmv', 'flv'],
                    'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'],
                    'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt'],
                    'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2']
                ];
                
                if (isset($typeExtensions[$fileType])) {
                    $allowedExts = $typeExtensions[$fileType];
                    $searchResults = array_filter($searchResults, function($item) use ($allowedExts) {
                        $ext = strtolower(pathinfo($item['filepath'] ?? '', PATHINFO_EXTENSION));
                        return in_array($ext, $allowedExts);
                    });
                }
            }
            
            // 날짜 필터
            if (!empty($dateFrom)) {
                $fromTime = strtotime($dateFrom);
                $searchResults = array_filter($searchResults, function($item) use ($fromTime) {
                    return strtotime($item['modified'] ?? 0) >= $fromTime;
                });
            }
            if (!empty($dateTo)) {
                $toTime = strtotime($dateTo . ' 23:59:59');
                $searchResults = array_filter($searchResults, function($item) use ($toTime) {
                    return strtotime($item['modified'] ?? 0) <= $toTime;
                });
            }
            
            // 크기 필터
            if ($sizeMin > 0) {
                $searchResults = array_filter($searchResults, function($item) use ($sizeMin) {
                    return ($item['size'] ?? 0) >= $sizeMin;
                });
            }
            if ($sizeMax > 0) {
                $searchResults = array_filter($searchResults, function($item) use ($sizeMax) {
                    return ($item['size'] ?? 0) <= $sizeMax;
                });
            }
            
            // 경로 필터
            if (!empty($searchPath)) {
                $searchResults = array_filter($searchResults, function($item) use ($searchPath) {
                    return strpos($item['filepath'] ?? '', $searchPath) === 0;
                });
            }
            
            // 정렬 적용
            $sortBy = $input['sort_by'] ?? 'name';
            $sortOrder = $input['sort_order'] ?? 'asc';
            
            usort($searchResults, function($a, $b) use ($sortBy, $sortOrder) {
                // 폴더 우선
                $aIsDir = $a['is_dir'] ?? 0;
                $bIsDir = $b['is_dir'] ?? 0;
                if ($aIsDir != $bIsDir) {
                    return $bIsDir - $aIsDir; // 폴더가 먼저
                }
                
                // 정렬 기준에 따라
                switch ($sortBy) {
                    case 'size':
                        $cmp = ($a['size'] ?? 0) - ($b['size'] ?? 0);
                        break;
                    case 'date':
                        $cmp = strtotime($a['modified'] ?? '0') - strtotime($b['modified'] ?? '0');
                        break;
                    case 'type':
                        $extA = strtolower(pathinfo($a['filepath'] ?? '', PATHINFO_EXTENSION));
                        $extB = strtolower(pathinfo($b['filepath'] ?? '', PATHINFO_EXTENSION));
                        $cmp = strcmp($extA, $extB);
                        break;
                    case 'name':
                    default:
                        $nameA = basename($a['filepath'] ?? '');
                        $nameB = basename($b['filepath'] ?? '');
                        $cmp = strcasecmp($nameA, $nameB);
                        break;
                }
                
                return $sortOrder === 'desc' ? -$cmp : $cmp;
            });
            
            // 페이지네이션 적용
            $searchResults = array_values($searchResults);
            $total = count($searchResults);
            $totalPages = ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $pagedResults = array_slice($searchResults, $offset, $perPage);
            
            $result = [
                'success' => true, 
                'results' => $pagedResults,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages
            ];
            break;
            
        default:
            http_response_code(400);
            $result = ['success' => false, 'error' => '알 수 없는 액션입니다.'];
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}