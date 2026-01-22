# 즉시 수정 권장 패치 코드

## 1. MIME 타입 검증 추가 (FileManager.php)

`upload()` 메서드에 다음 검증 로직 추가:

```php
// FileManager.php - upload() 메서드 시작 부분에 추가

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
        'bmp' => ['image/bmp'],
        'svg' => ['image/svg+xml'],
        // 문서
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'html' => ['text/html'],
        'htm' => ['text/html'],
        'css' => ['text/css'],
        'js' => ['application/javascript', 'text/javascript'],
        'json' => ['application/json', 'text/json'],
        'xml' => ['application/xml', 'text/xml'],
        // 오피스
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        // 압축
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar-compressed', 'application/vnd.rar'],
        '7z' => ['application/x-7z-compressed'],
        'tar' => ['application/x-tar'],
        'gz' => ['application/gzip'],
        // 미디어
        'mp3' => ['audio/mpeg'],
        'mp4' => ['video/mp4'],
        'wav' => ['audio/wav'],
        'ogg' => ['audio/ogg'],
        'webm' => ['video/webm'],
        'mkv' => ['video/x-matroska'],
        'avi' => ['video/x-msvideo'],
        'mov' => ['video/quicktime'],
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

// upload() 메서드 내부에서 호출
// (용량 체크 이후, move_uploaded_file 이전에 추가)

// MIME 타입 검증
if (!$this->validateMimeType($file['tmp_name'], $file['name'])) {
    return ['success' => false, 'error' => '파일 형식이 올바르지 않습니다.'];
}
```

---

## 2. 해킹 패턴 감지 강화 (api.php)

153-176줄 부분을 다음으로 교체:

```php
// 해킹시도 감지 (경로 조작 시도) - 강화 버전
$inputString = json_encode($input);
$inputStringLower = strtolower(urldecode($inputString));  // URL 디코딩 + 소문자 변환

$suspiciousPatterns = [
    '../', '..\\',                          // 경로 탐색
    '<script', '</script', 'javascript:',   // XSS
    'onclick', 'onerror', 'onload', 'onmouseover', 'onfocus',  // 이벤트 핸들러
    'expression(', 'eval(', 'alert(',       // JS 실행
    'data:text/html', 'data:application',   // 데이터 URI
    'vbscript:',                            // VBScript
    '<?php', '<?=',                         // PHP 인젝션 시도
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
$pathParams = ['path', 'source', 'dest', 'file_path', 'paths'];
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
    
    // 해킹 시도 시 즉시 차단 (선택적)
    // http_response_code(403);
    // echo json_encode(['success' => false, 'error' => '잘못된 요청입니다.']);
    // exit;
}
```

---

## 3. 다운로드 파일명 인코딩 (FileManager.php)

`download()` 메서드의 Content-Disposition 헤더 부분을 수정:

```php
// FileManager.php - download() 메서드 내부

// 기존 코드 (544-549줄 근처)
// header('Content-Disposition: attachment; filename="' . $filename . '"');

// 수정된 코드
$filename = basename($fullPath);

// RFC 5987 형식으로 파일명 인코딩 (모든 브라우저 지원)
$filenameSafe = preg_replace('/[^\x20-\x7E]/', '', $filename);  // ASCII만 추출
$filenameEncoded = rawurlencode($filename);

if ($inline) {
    // inline 모드 (브라우저에서 직접 표시)
    header("Content-Disposition: inline; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
} else {
    // attachment 모드 (다운로드)
    header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
}
```

---

## 4. /data/ 폴더 접근 차단 (.htaccess)

프로젝트 루트의 `.htaccess`에 다음 규칙이 있는지 확인하고, 없으면 추가:

```apache
# /data/ 폴더 직접 접근 차단
<Directory "data">
    Require all denied
</Directory>

# 또는 RewriteRule 사용
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^data/ - [F,L]
</IfModule>
```

`/data/` 폴더 내에 별도 `.htaccess` 파일 생성:

```apache
# data/.htaccess
Require all denied

# Apache 2.2 호환
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

---

## 적용 순서

1. **즉시**: 위 1~4번 패치 적용
2. **테스트**: 파일 업로드/다운로드 기능 정상 동작 확인
3. **모니터링**: 에러 로그 확인하며 안정성 검증

---

*패치 코드 작성: Claude | 2026-01-20*
