<?php
/**
 * FileStation - 설정 파일
 * 시놀로지 파일스테이션 대체용 웹 파일 관리자
 */

// 에러 리포팅 (운영 시 0으로)
error_reporting(0);
ini_set('display_errors', 0);

// 타임존
date_default_timezone_set('Asia/Seoul');

// 세션 설정 (세션 시작 전에만 설정)
if (session_status() === PHP_SESSION_NONE) {
    // 세션 파일 저장 경로를 persistent volume인 data 폴더로 변경
    $sessionPath = DATA_PATH . '/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0755, true);
    }
    ini_set('session.save_path', $sessionPath);

    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    // IP 접속 환경 호환성을 위해 Lax로 변경
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', 86400); // 24시간
    
    // HTTPS 환경 확인 (리버스 프록시 지원)
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
               
    if ($isHttps) {
        ini_set('session.cookie_secure', 1);
    }
}

// ===== CSRF 토큰 관리 =====
/**
 * CSRF 토큰 생성 또는 기존 토큰 반환
 */
function getCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * CSRF 토큰 검증
 */
function validateCsrfToken(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF 토큰 재생성 (로그인 후 등)
 */
function regenerateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

// 기본 설정
define('APP_NAME', 'FileStation');
define('APP_VERSION', '1.0.0');
define('BASE_PATH', __DIR__);
define('DATA_PATH', BASE_PATH . '/data');

// 사용자 폴더 설정 (동적 로드)
// home 타입 스토리지는 USER_FILES_ROOT/계정명 으로 자동 계산됨
// 
// ★★★ 보안 권고 ★★★
// 기본값은 웹 루트 내 폴더(/users, /shared)입니다.
// .htaccess로 직접 접근을 차단하지만, 더 안전하게 사용하려면
// 웹 루트 밖의 경로로 설정하세요:
//   예: C:\webhard_files\users 또는 /var/webhard/users
// storage_paths.json 파일에서 설정 가능합니다.
//
$_storageSettings = [];
$_storageSettingsFile = __DIR__ . '/data/storage_paths.json';
if (file_exists($_storageSettingsFile)) {
    $_storageSettings = json_decode(file_get_contents($_storageSettingsFile), true) ?: [];
}

// 개인 폴더 루트 (기본값: ./users)
$_userFilesRoot = $_storageSettings['user_files_root'] ?? '';
if (empty($_userFilesRoot)) {
    $_userFilesRoot = __DIR__ . '/users';
}
define('USER_FILES_ROOT', rtrim($_userFilesRoot, '/\\'));

// 공유 폴더 루트 (기본값: ./shared)
$_sharedFilesRoot = $_storageSettings['shared_files_root'] ?? '';
if (empty($_sharedFilesRoot)) {
    $_sharedFilesRoot = __DIR__ . '/shared';
}
define('SHARED_FILES_ROOT', rtrim($_sharedFilesRoot, '/\\'));

// 휴지통 경로 (기본값: ./data/trash_files)
$_trashPath = $_storageSettings['trash_path'] ?? '';
if (empty($_trashPath)) {
    $_trashPath = __DIR__ . '/data/trash_files';
}
define('TRASH_PATH', rtrim($_trashPath, '/\\'));

define('AUTO_CREATE_USER_FOLDER', true);  // 로그인 시 자동 생성

// 업로드 설정 (0 = 무제한)
define('MAX_UPLOAD_SIZE', 0); // 무제한
define('CHUNK_SIZE', 10 * 1024 * 1024); // 10MB 청크

// 공유 링크 설정
define('SHARE_LINK_LENGTH', 16);
define('SHARE_DEFAULT_EXPIRE_DAYS', 7);

// ===== 로그인 보안 설정 =====
// 로그인 유지 (Remember Me)
define('REMEMBER_ME_ENABLED', true);
define('REMEMBER_ME_DAYS', 3650);  // 쿠키 유효 기간 (10년 = 무제한)
define('REMEMBER_ME_TOKEN_LENGTH', 64);

// 브루트포스 방지
define('LOGIN_MAX_ATTEMPTS', 5);  // 최대 시도 횟수
define('LOGIN_LOCKOUT_MINUTES', 15);  // 잠금 시간

// 세션 관리
define('SESSION_TRACKING_ENABLED', true);
define('SESSION_MAX_CONCURRENT', 5);  // 동시 세션 최대 수

// 로그인 로그
define('LOGIN_LOG_ENABLED', true);
define('LOGIN_LOG_RETENTION_DAYS', 90);  // 로그 보관 기간

// 2FA (TOTP) 설정
define('TOTP_ENABLED', true);  // 사용자별 2FA 활성화 허용
define('TOTP_ISSUER', 'WebHard');  // QR 코드에 표시될 발급자명
define('TOTP_ENCRYPTION_KEY', 'change-this-to-your-secret-key-32chars');  // 시크릿 암호화 키 (반드시 변경!)

// IP/국가 제한 (빈 배열 = 제한 없음)
define('ALLOWED_IPS', []);  // 예: ['192.168.1.0/24', '10.0.0.1']
define('BLOCKED_IPS', []);
define('ALLOWED_COUNTRIES', []);  // 예: ['KR', 'US']
define('BLOCKED_COUNTRIES', []);

// 썸네일 설정
define('THUMB_SIZE', 200);
define('THUMB_QUALITY', 80);

// 허용 미리보기 확장자
define('PREVIEW_EXTENSIONS', [
    'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'],
    'video' => ['mp4', 'webm', 'mkv', 'avi', 'mov'],
    'audio' => ['mp3', 'wav', 'ogg', 'flac', 'm4a'],
    'document' => ['pdf', 'txt', 'md', 'html', 'htm'],
    'code' => ['php', 'js', 'css', 'json', 'xml', 'sql', 'py', 'java', 'c', 'cpp', 'h']
]);

// 아이콘 매핑
define('FILE_ICONS', [
    'folder' => '📁',
    'image' => '🖼️',
    'video' => '🎬',
    'audio' => '🎵',
    'document' => '📄',
    'pdf' => '📕',
    'archive' => '📦',
    'code' => '💻',
    'default' => '📄'
]);

// PHP 버전 호환성 함수
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return 0 === strncmp($haystack, $needle, strlen($needle));
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return '' === $needle || ('' !== $haystack && 0 === substr_compare($haystack, $needle, -strlen($needle)));
    }
}

// 자동 로더
spl_autoload_register(function ($class) {
    $file = BASE_PATH . '/api/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// StorageAdapter 클래스들 로드
// 현재 미사용 (FTP/SFTP/S3 등 원격 스토리지 지원 시 활성화)
// require_once BASE_PATH . '/api/StorageAdapter.php';

// ===== 디버그 설정 =====
// 업로드 성능 디버그 (true: 로그 기록, false: 비활성화)
define('DEBUG_UPLOAD', false);
// 디버그 로그 파일 경로 (기본값: data/debug_upload.log)
// define('DEBUG_LOG_FILE', DATA_PATH . '/debug_upload.log');
// 디버그 로그 최대 크기 (기본값: 5MB)
// define('DEBUG_LOG_MAX_SIZE', 5 * 1024 * 1024);

// ===== API Rate Limiting 설정 =====
// 분당 최대 요청 수 (0 = 무제한)
define('API_RATE_LIMIT', 120);
// Rate Limit 윈도우 (초)
define('API_RATE_WINDOW', 60);

// 데이터 폴더 생성
if (!is_dir(DATA_PATH)) {
    mkdir(DATA_PATH, 0755, true);
}

// ===== 보안: 스토리지 폴더 보호 =====
// .htaccess 자동 생성 함수
function createStorageProtection(string $path): void {
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    
    $htaccessPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccessPath)) {
        $content = "# FileStation Storage Protection\n";
        $content .= "# URL 직접 접근 차단\n\n";
        $content .= "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\n";
        $content .= "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n\n";
        $content .= "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule .* - [F,L]\n</IfModule>\n";
        @file_put_contents($htaccessPath, $content);
    }
}

// 기본 스토리지 폴더 보호
createStorageProtection(USER_FILES_ROOT);
createStorageProtection(SHARED_FILES_ROOT);
createStorageProtection(TRASH_PATH);