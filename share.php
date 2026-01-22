<?php
/**
 * 공유 링크 접근 페이지
 */
require_once __DIR__ . '/config.php';

// 세션 시작 (비밀번호 인증 유지용)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 보안 헤더
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
// CSP 헤더
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self';");

$token = $_GET['t'] ?? '';
$password = $_POST['password'] ?? null;
$download = isset($_GET['download']);

if (empty($token)) {
    http_response_code(400);
    exit('잘못된 접근입니다.');
}

// 세션에서 이전 인증된 비밀번호 확인
if (!$password && isset($_SESSION['share_passwords'][$token])) {
    $password = $_SESSION['share_passwords'][$token];
}

$shareManager = new ShareManager();

// 다운로드 요청
if ($download) {
    $shareManager->downloadShare($token, $password);
    exit;
}

// 공유 정보 확인
$result = $shareManager->accessShare($token, $password);
$needsPassword = ($result['error'] ?? '') === 'password_required';
$error = (!$result['success'] && !$needsPassword) ? $result['error'] : null;
$share = $result['share'] ?? null;

// 비밀번호 인증 성공 시 세션에 저장
if ($result['success'] && $password) {
    $_SESSION['share_passwords'][$token] = $password;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>파일 공유 - <?= APP_NAME ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 450px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
        }
        .filename {
            font-size: 18px;
            color: #666;
            word-break: break-all;
            margin-bottom: 20px;
        }
        .info {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
        .info div { margin: 5px 0; }
        .btn {
            display: inline-block;
            padding: 14px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .error {
            background: #fee;
            color: #c00;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        input[type="password"] {
            width: 100%;
            padding: 14px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 15px;
            transition: border-color 0.2s;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        .password-form { margin-bottom: 20px; }
        
        /* 모바일 */
        @media (max-width: 480px) {
            body { padding: 15px; }
            .container { padding: 25px 20px; border-radius: 12px; }
            .icon { font-size: 48px; margin-bottom: 15px; }
            h1 { font-size: 20px; }
            .filename { font-size: 15px; }
            .info { font-size: 13px; padding: 12px; }
            .btn { padding: 12px 30px; font-size: 15px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div class="icon">❌</div>
            <h1>접근 불가</h1>
            <div class="error"><?= htmlspecialchars($error) ?></div>
            <a href="/" class="btn">홈으로</a>
            
        <?php elseif ($needsPassword): ?>
            <div class="icon">🔒</div>
            <h1>비밀번호 필요</h1>
            <p style="color:#666;margin-bottom:20px;">이 파일은 비밀번호로 보호되어 있습니다.</p>
            <form method="post" class="password-form">
                <input type="password" name="password" placeholder="비밀번호 입력" required autofocus>
                <button type="submit" class="btn">확인</button>
            </form>
            
        <?php elseif ($share): ?>
            <div class="icon"><?= $share['is_dir'] ? '📁' : '📄' ?></div>
            <h1>파일 공유</h1>
            <div class="filename"><?= htmlspecialchars($share['filename']) ?></div>
            
            <div class="info">
                <?php if (!$share['is_dir']): ?>
                <div>📦 크기: <?= formatSize($share['size'] ?? 0) ?></div>
                <?php endif; ?>
                <div>📅 공유일: <?= date('Y-m-d H:i', strtotime($share['created_at'])) ?></div>
                <?php if ($share['expire_at']): ?>
                <div>⏰ 만료: <?= date('Y-m-d H:i', strtotime($share['expire_at'])) ?></div>
                <?php endif; ?>
                <?php if ($share['max_downloads']): ?>
                <div>📥 다운로드: <?= $share['download_count'] ?> / <?= $share['max_downloads'] ?></div>
                <?php endif; ?>
            </div>
            
            <?php 
            // 비밀번호는 세션에 저장되므로 URL에 포함하지 않음
            $downloadUrl = "share.php?t=" . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . "&download=1";
            ?>
            <a href="<?= $downloadUrl ?>" class="btn">
                <?= $share['is_dir'] ? '📦 ZIP 다운로드' : '⬇️ 다운로드' ?>
            </a>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}