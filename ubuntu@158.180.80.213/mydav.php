<?php
/**
 * WebDAV 엔드포인트
 * 
 * Windows 네트워크 드라이브 연결:
 *   net use Z: http://서버주소/webdav.php
 * 또는 탐색기에서:
 *   \\서버주소@SSL\webdav.php (HTTPS)
 *   \\서버주소\webdav.php (HTTP)
 */

// 디버그 모드 (문제 해결 후 false로)
$DEBUG = false;

if ($DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/data/webdav_error.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// 설정 파일 로드 (세션은 config.php에서 시작)
require_once __DIR__ . '/config.php';

// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/api/JsonDB.php';
require_once __DIR__ . '/api/Auth.php';
require_once __DIR__ . '/api/Storage.php';
require_once __DIR__ . '/api/WebDAV.php';

// 인스턴스 생성
$db = JsonDB::getInstance();
$auth = new Auth();
$storage = new Storage();

// WebDAV 처리
$webdav = new WebDAV($db, $auth, $storage);
$webdav->handleRequest();
