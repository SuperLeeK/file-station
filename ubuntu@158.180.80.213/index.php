<?php
require_once __DIR__ . '/config.php';

// 보안 헤더
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CSP (Content Security Policy) 헤더
// 'unsafe-inline'은 현재 인라인 스크립트/스타일 사용으로 필요, 추후 제거 권장
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'self';");

$auth = new Auth();

// CSRF 토큰 생성
$csrfToken = getCsrfToken();

// 시스템 설정 로드
$siteSettings = [];
$settingsFile = __DIR__ . '/data/site_settings.json';
if (file_exists($settingsFile)) {
    $siteSettings = json_decode(file_get_contents($settingsFile), true) ?: [];
}
$siteName = !empty($siteSettings['site_name']) ? $siteSettings['site_name'] : 'FileStation';
$logoImage = $siteSettings['logo_image'] ?? '';
$bgImage = $siteSettings['bg_image'] ?? '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName) ?></title>
    <?php
    $faviconPath = null;
    foreach (['favicon.ico', 'favicon.png', 'favicon.svg'] as $f) {
        if (file_exists(__DIR__ . '/' . $f)) {
            $faviconPath = $f;
            break;
        }
    }
    ?>
    <?php if ($faviconPath): ?>
    <link rel="icon" href="<?= $faviconPath ?>">
    <?php else: ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E📁%3C/text%3E%3C/svg%3E">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <?php if ($bgImage): ?>
    <style>
        #login-screen {
            background: linear-gradient(rgba(102, 126, 234, 0.85), rgba(118, 75, 162, 0.85)), url('<?= htmlspecialchars($bgImage) ?>') center/cover no-repeat;
        }
    </style>
    <?php endif; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <div id="app">
        <!-- 로그인 화면 -->
        <div id="login-screen" class="screen active">
            <div class="login-box" id="login-box">
                <?php if ($logoImage): ?>
                <div class="login-logo"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo"></div>
                <?php else: ?>
                <div class="login-logo">📁</div>
                <?php endif; ?>
                <h1><?= htmlspecialchars($siteName) ?></h1>
                
                <!-- 로그인 폼 -->
                <form id="login-form">
                    <input type="text" id="login-username" placeholder="아이디" required>
                    <input type="password" id="login-password" placeholder="비밀번호" required>
                    <label class="remember-me">
                        <input type="checkbox" id="login-remember">
                        <span>로그인 유지</span>
                    </label>
                    <button type="submit" class="btn btn-primary btn-block">로그인</button>
                </form>
                
                <!-- 2FA 입력 폼 -->
                <form id="twofa-form" style="display:none;">
                    <!-- OTP 입력 -->
                    <div id="twofa-otp-section">
                        <p style="text-align:center; margin-bottom:15px; color:#666;">
                            🔐 인증 앱의 6자리 코드를 입력하세요
                        </p>
                        <input type="text" id="twofa-code" placeholder="000000" maxlength="6" 
                               inputmode="numeric" pattern="[0-9]*"
                               style="text-align:center; font-size:24px; letter-spacing:5px;" required
                               oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <p style="text-align:center; margin:15px 0 10px 0;">
                            <a href="#" id="show-backup-code" style="font-size:13px; color:#666;">백업 코드로 로그인</a>
                        </p>
                    </div>
                    
                    <!-- 백업 코드 입력 -->
                    <div id="twofa-backup-section" style="display:none;">
                        <p style="text-align:center; margin-bottom:15px; color:#666;">
                            🔑 백업 코드를 입력하세요
                        </p>
                        <input type="text" id="twofa-backup-code" placeholder="1234-5678" maxlength="9" 
                               inputmode="numeric" pattern="[0-9\-]*"
                               style="text-align:center; font-size:24px; letter-spacing:3px;"
                               oninput="this.value = this.value.replace(/[^0-9\-]/g, '')">
                        <p style="text-align:center; margin:15px 0 10px 0;">
                            <a href="#" id="show-otp-code" style="font-size:13px; color:#666;">OTP 코드로 로그인</a>
                        </p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">확인</button>
                    <button type="button" class="btn btn-block" id="btn-twofa-back" style="margin-top:10px;">← 다시 로그인</button>
                </form>
                
                <div id="login-error" class="error-msg"></div>
                <div id="first-user-notice" class="first-user-notice" style="display:none;">
                    🎉 <strong>처음 오셨군요!</strong><br>
                    회원가입하시면 관리자 계정이 됩니다.
                </div>
                <div class="login-links" id="signup-link-wrap" style="display:none;">
                    <span>계정이 없으신가요?</span> <a href="#" id="show-signup">회원가입</a>
                </div>
            </div>
            
            <!-- 회원가입 폼 -->
            <div class="login-box" id="signup-box" style="display:none;">
                <?php if ($logoImage): ?>
                <div class="login-logo"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo"></div>
                <?php else: ?>
                <div class="login-logo">📝</div>
                <?php endif; ?>
                <h1>회원가입</h1>
                <form id="signup-form">
                    <input type="text" id="signup-username" placeholder="아이디" required>
                    <input type="password" id="signup-password" placeholder="비밀번호" required>
                    <input type="password" id="signup-password2" placeholder="비밀번호 확인" required>
                    <input type="text" id="signup-displayname" placeholder="표시 이름">
                    <input type="email" id="signup-email" placeholder="이메일 (선택)">
                    <button type="submit" class="btn btn-primary btn-block">가입 신청</button>
                </form>
                <div id="signup-error" class="error-msg"></div>
                <div class="login-links">
                    <span>이미 계정이 있으신가요?</span> <a href="#" id="show-login">로그인</a>
                </div>
            </div>
        </div>
        
        <!-- 메인 화면 -->
        <div id="main-screen" class="screen hidden">
            <!-- 헤더 -->
            <header class="header">
                <div class="header-left">
                    <button id="mobile-menu-btn" class="mobile-menu-btn">☰</button>
                    <?php if ($logoImage): ?>
                    <span class="logo"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo" class="header-logo"> <span class="logo-text"><?= htmlspecialchars($siteName) ?></span></span>
                    <?php else: ?>
                    <span class="logo">📁 <span class="logo-text"><?= htmlspecialchars($siteName) ?></span></span>
                    <?php endif; ?>
                </div>
                <div class="header-center">
                    <div class="search-box">
                        <!-- 브라우저 자동완성 방지용 더미 필드 -->
                        <input type="text" style="display:none" aria-hidden="true">
                        <input type="password" style="display:none" aria-hidden="true">
                        <input type="search" id="search-input" placeholder="전체 검색... (예: *.mp4, 문서*)" autocomplete="off" name="q_search_<?= time() ?>" readonly onfocus="this.removeAttribute('readonly')">
                        <button id="search-btn" title="검색">🔍</button>
                        <button id="search-filter-toggle" title="필터 표시/숨김">⚙️</button>
                    </div>
                </div>
                <div class="header-right">
                    <button id="mobile-search-btn" class="mobile-search-btn">🔍</button>
                    <span id="user-name"></span>
                    <button id="btn-settings" class="btn-icon" title="설정">⚙️</button>
                    <button id="btn-logout" class="btn-icon" title="로그아웃">🚪</button>
                </div>
            </header>
            
            <!-- 모바일 검색 바 -->
            <div id="mobile-search-bar" class="mobile-search-bar">
                <!-- 브라우저 자동완성 방지용 더미 필드 -->
                <input type="text" style="display:none" aria-hidden="true">
                <input type="password" style="display:none" aria-hidden="true">
                <input type="search" id="mobile-search-input" placeholder="검색..." autocomplete="off" name="q_mobile_<?= time() ?>" readonly onfocus="this.removeAttribute('readonly')">
                <button id="mobile-search-submit">🔍</button>
                <button id="mobile-search-close">✕</button>
            </div>
            
            <!-- 본문 -->
            <div class="main-content">
                <!-- 사이드바 -->
                <aside class="sidebar">
                    <div class="sidebar-section sidebar-main">
                        <h3>스토리지</h3>
                        <ul id="storage-list" class="storage-list"></ul>
                        
                        <!-- 즐겨찾기 섹션 -->
                        <div class="sidebar-divider"></div>
                        <h3 class="section-toggle" data-target="favorites-list">
                            <span class="toggle-icon">−</span> ⭐ 즐겨찾기
                        </h3>
                        <ul class="menu-list collapsible" id="favorites-list">
                            <li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;">즐겨찾기가 없습니다</li>
                        </ul>
                        
                        <!-- 최근 파일 섹션 -->
                        <div class="sidebar-divider"></div>
                        <h3 class="section-toggle" data-target="recent-files-list">
                            <span class="toggle-icon">−</span> 🕐 최근 파일
                        </h3>
                        <ul class="menu-list collapsible" id="recent-files-list">
                            <li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;">최근 파일이 없습니다</li>
                        </ul>
                        
                        <div class="sidebar-divider"></div>
                        <ul class="menu-list trash-menu">
                            <li><a href="#" id="menu-my-trash">🗑️ 내 휴지통</a></li>
                        </ul>
                        <div id="admin-section" style="display:none;">
                            <div class="sidebar-divider"></div>
                            <h3 class="section-toggle" data-target="admin-menu-list">
                                <span class="toggle-icon">+</span> 관리
                            </h3>
                            <ul class="menu-list collapsible" id="admin-menu-list" style="display:none;">
                                <li class="menu-group-label">사용자/권한</li>
                                <li><a href="#" id="menu-users">👥 사용자 관리</a></li>
                                <li><a href="#" id="menu-roles">🏷️ 역할 관리</a></li>
                                <li class="menu-group-label">스토리지/파일</li>
                                <li><a href="#" id="menu-storages">💾 스토리지 관리</a></li>
                                <li><a href="#" id="menu-shares">🔗 공유 관리</a></li>
                                <li><a href="#" id="menu-trash">🗑️ 전체 휴지통</a></li>
                                <li><a href="#" id="menu-bulk-delete">🧹 조건부 삭제</a></li>
                                <li class="menu-group-label">로그/기록</li>
                                <li><a href="#" id="menu-all-logins">📋 로그인 기록</a></li>
                                <li><a href="#" id="menu-activity-logs">📜 활동 로그</a></li>
                                <li><a href="#" id="menu-search-index">🔍 검색 인덱스</a></li>
                                <li class="menu-group-label">시스템</li>
                                <li><a href="#" id="menu-qos">⚡ 속도 제한</a></li>
                                <li><a href="#" id="menu-security">🔒 보안 설정</a></li>
                                <li><a href="#" id="menu-system-settings">🔧 시스템 설정</a></li>
                                <li><a href="#" id="menu-system-info">📊 시스템 정보</a></li>
                            </ul>
                        </div>
                    </div>
                    <div id="storage-quota" class="storage-quota sidebar-quota" style="display:none;">
                        <div class="quota-bar">
                            <div class="quota-used" id="quota-used-bar"></div>
                        </div>
                        <div class="quota-text" id="quota-text"></div>
                    </div>
                </aside>
                
                <!-- 파일 영역 -->
                <main class="file-area">
                    <!-- 검색 필터 영역 -->
                    <div id="search-filters" class="search-filters" style="display:none;">
                        <div class="filter-row">
                            <div class="filter-item">
                                <label>파일 유형</label>
                                <select id="filter-type">
                                    <option value="all">전체</option>
                                    <option value="image">이미지</option>
                                    <option value="video">동영상</option>
                                    <option value="audio">음악</option>
                                    <option value="document">문서</option>
                                    <option value="archive">압축파일</option>
                                </select>
                            </div>
                            <div class="filter-item">
                                <label>날짜</label>
                                <input type="date" id="filter-date-from" placeholder="시작">
                                <span>~</span>
                                <input type="date" id="filter-date-to" placeholder="끝">
                            </div>
                            <div class="filter-item">
                                <label>크기 (MB)</label>
                                <input type="number" id="filter-size-min" placeholder="최소" min="0" style="width:70px;">
                                <span>~</span>
                                <input type="number" id="filter-size-max" placeholder="최대" min="0" style="width:70px;">
                            </div>
                            <div class="filter-actions">
                                <button id="btn-apply-filter" class="btn btn-sm btn-primary">적용</button>
                                <button id="btn-reset-filter" class="btn btn-sm">초기화</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 검색 결과 헤더 (검색 모드일 때만 표시) -->
                    <div id="search-result-header" class="search-result-header" style="display:none;">
                        <div class="search-info">
                            <span class="search-query"></span>
                            <span class="search-count"></span>
                        </div>
                        <button id="btn-exit-search" class="btn btn-sm">✕ 검색 종료</button>
                    </div>
                    
                    <!-- 툴바 -->
                    <div class="toolbar">
                        <div class="toolbar-left">
                            <button id="btn-back" class="btn-icon" title="뒤로">⬅️</button>
                            <label class="select-all-wrap" title="전체 선택">
                                <input type="checkbox" id="select-all">
                                <span>전체</span>
                            </label>
                            <div id="breadcrumb" class="breadcrumb"></div>
                        </div>
                        <div class="toolbar-center">
                            <button id="btn-paste" class="btn btn-primary" style="display:none;">📋 붙여넣기</button>
                            <button id="btn-delete-selected" class="btn btn-danger" style="display:none;">🗑️ 선택 삭제</button>
                        </div>
                        <div class="toolbar-right">
                            <div class="upload-dropdown">
                                <button id="btn-upload" class="btn btn-primary">📤 업로드 ▾</button>
                                <div id="upload-menu" class="upload-menu">
                                    <div class="upload-option" data-type="file">📄 파일 업로드</div>
                                    <div class="upload-option" data-type="folder">📁 폴더 업로드</div>
                                </div>
                            </div>
                            <button id="btn-new-folder" class="btn">📁 새 폴더</button>
                            <div class="sort-dropdown">
                                <button id="btn-sort" class="btn-icon" title="정렬">🔀</button>
                                <div id="sort-menu" class="sort-menu">
                                    <div class="sort-option active" data-sort="name" data-order="asc">📝 이름 (오름차순)</div>
                                    <div class="sort-option" data-sort="name" data-order="desc">📝 이름 (내림차순)</div>
                                    <div class="sort-option" data-sort="date" data-order="desc">📅 날짜 (최신순)</div>
                                    <div class="sort-option" data-sort="date" data-order="asc">📅 날짜 (오래된순)</div>
                                    <div class="sort-option" data-sort="size" data-order="desc">📊 크기 (큰순)</div>
                                    <div class="sort-option" data-sort="size" data-order="asc">📊 크기 (작은순)</div>
                                    <div class="sort-option" data-sort="type" data-order="asc">📂 유형</div>
                                </div>
                            </div>
                            <button id="btn-view-grid" class="btn-icon active" title="그리드">▦</button>
                            <button id="btn-view-list" class="btn-icon" title="리스트">☰</button>
                        </div>
                    </div>
                    
                    <!-- 파일 목록 -->
                    <div id="file-list" class="file-list grid-view">
                        <div class="empty-msg">스토리지를 선택하세요</div>
                    </div>
                    
                    <!-- 검색 페이지네이션 -->
                    <div id="search-pagination" class="search-pagination" style="display:none;"></div>
                    
                    <!-- 전송 진행 -->
                    <div id="transfer-progress" class="transfer-progress" style="display:none;">
                        <div class="progress-header">
                            <span id="transfer-title">📤 업로드 중...</span>
                            <button id="transfer-cancel" class="btn-icon" title="취소">✕</button>
                        </div>
                        <div class="progress-count" id="transfer-count-wrap" style="display:none;">
                            <span id="transfer-count"></span>
                        </div>
                        <div class="progress-info">
                            <span id="transfer-filename"></span>
                        </div>
                        <div class="progress-bar">
                            <div id="progress-fill" class="progress-fill"></div>
                        </div>
                        <div class="progress-detail">
                            <span id="transfer-percent">0%</span>
                            <span id="transfer-speed"></span>
                        </div>
                        <div class="progress-stats">
                            <span id="transfer-size"></span>
                            <span id="transfer-eta"></span>
                        </div>
                    </div>
                    
                    <!-- 이전 버전 호환 (숨김) -->
                    <div id="upload-progress" style="display:none;"></div>
                </main>
            </div>
        </div>
        
        <!-- 모달들 -->
        <div id="modal-overlay" class="modal-overlay" style="display:none;">
            <!-- 새 폴더 모달 -->
            <div id="modal-new-folder" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>새 폴더</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="text" id="new-folder-name" placeholder="폴더 이름">
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">취소</button>
                    <button class="btn btn-primary" id="btn-create-folder">생성</button>
                </div>
            </div>
            
            <!-- 스토리지 추가 모달 -->
            <div id="modal-add-storage" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2 id="storage-modal-title">스토리지 추가</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="storage-id">
                    <div class="form-group">
                        <label>이름</label>
                        <input type="text" id="storage-name" placeholder="내 드라이브">
                    </div>
                    <div class="form-group">
                        <label>유형</label>
                        <select id="storage-type">
                            <option value="local">📁 로컬/네트워크 경로</option>
                            <option value="smb">🖥️ SMB 공유 (Windows)</option>
                            <option value="ftp">📡 FTP</option>
                            <option value="sftp">🔒 SFTP</option>
                            <option value="webdav">🌐 WebDAV</option>
                            <option value="s3">☁️ Amazon S3 / 호환</option>
                            <option value="shared" style="display:none;">📂 공유 폴더</option>
                        </select>
                    </div>
                    
                    <!-- 로컬 옵션 -->
                    <div id="storage-local-options" class="storage-options">
                        <div class="form-group">
                            <label>경로</label>
                            <input type="text" id="storage-path" placeholder="D:\Files 또는 \\192.168.1.100\share">
                        </div>
                    </div>
                    
                    <!-- SMB 옵션 -->
                    <div id="storage-smb-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label>호스트</label>
                            <input type="text" id="smb-host" placeholder="192.168.1.100">
                        </div>
                        <div class="form-group">
                            <label>공유 이름</label>
                            <input type="text" id="smb-share" placeholder="share">
                        </div>
                        <div class="form-group">
                            <label>사용자명 (선택)</label>
                            <input type="text" id="smb-username">
                        </div>
                        <div class="form-group">
                            <label>비밀번호 (선택)</label>
                            <input type="password" id="smb-password">
                        </div>
                    </div>
                    
                    <!-- FTP 옵션 -->
                    <div id="storage-ftp-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label>호스트</label>
                            <input type="text" id="ftp-host" placeholder="ftp.example.com">
                        </div>
                        <div class="form-group">
                            <label>포트</label>
                            <input type="number" id="ftp-port" value="21" placeholder="21">
                        </div>
                        <div class="form-group">
                            <label>사용자명</label>
                            <input type="text" id="ftp-username">
                        </div>
                        <div class="form-group">
                            <label>비밀번호</label>
                            <input type="password" id="ftp-password">
                        </div>
                        <div class="form-group">
                            <label>루트 경로 (선택)</label>
                            <input type="text" id="ftp-root" placeholder="/">
                        </div>
                        <div class="form-group">
                            <label><input type="checkbox" id="ftp-passive" checked> 패시브 모드</label>
                            <label><input type="checkbox" id="ftp-ssl"> SSL/TLS 사용</label>
                        </div>
                    </div>
                    
                    <!-- SFTP 옵션 -->
                    <div id="storage-sftp-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label>호스트</label>
                            <input type="text" id="sftp-host" placeholder="sftp.example.com">
                        </div>
                        <div class="form-group">
                            <label>포트</label>
                            <input type="number" id="sftp-port" value="22" placeholder="22">
                        </div>
                        <div class="form-group">
                            <label>사용자명</label>
                            <input type="text" id="sftp-username">
                        </div>
                        <div class="form-group">
                            <label>인증 방식</label>
                            <select id="sftp-auth-type">
                                <option value="password">비밀번호</option>
                                <option value="key">SSH 키</option>
                            </select>
                        </div>
                        <div class="form-group" id="sftp-password-group">
                            <label>비밀번호</label>
                            <input type="password" id="sftp-password">
                        </div>
                        <div class="form-group" id="sftp-key-group" style="display:none;">
                            <label>SSH 개인키</label>
                            <textarea id="sftp-private-key" rows="4" placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea>
                        </div>
                        <div class="form-group">
                            <label>루트 경로 (선택)</label>
                            <input type="text" id="sftp-root" placeholder="/home/user">
                        </div>
                    </div>
                    
                    <!-- WebDAV 옵션 -->
                    <div id="storage-webdav-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label>서버 URL</label>
                            <input type="text" id="webdav-url" placeholder="https://cloud.example.com/remote.php/dav/files/user/">
                        </div>
                        <div class="form-group">
                            <label>사용자명</label>
                            <input type="text" id="webdav-username">
                        </div>
                        <div class="form-group">
                            <label>비밀번호</label>
                            <input type="password" id="webdav-password">
                        </div>
                    </div>
                    
                    <!-- S3 옵션 -->
                    <div id="storage-s3-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label>엔드포인트 (S3 호환)</label>
                            <input type="text" id="s3-endpoint" placeholder="s3.amazonaws.com 또는 커스텀 URL">
                        </div>
                        <div class="form-group">
                            <label>리전</label>
                            <input type="text" id="s3-region" placeholder="ap-northeast-2">
                        </div>
                        <div class="form-group">
                            <label>버킷</label>
                            <input type="text" id="s3-bucket" placeholder="my-bucket">
                        </div>
                        <div class="form-group">
                            <label>Access Key ID</label>
                            <input type="text" id="s3-access-key">
                        </div>
                        <div class="form-group">
                            <label>Secret Access Key</label>
                            <input type="password" id="s3-secret-key">
                        </div>
                        <div class="form-group">
                            <label>프리픽스 (선택)</label>
                            <input type="text" id="s3-prefix" placeholder="folder/">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>설명</label>
                        <input type="text" id="storage-desc" placeholder="선택사항">
                    </div>
                    
                    <div class="form-group" id="storage-quota-group">
                        <label>용량 제한 <small style="color:#888">(0 = 무제한)</small></label>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <input type="number" id="storage-quota-value" min="0" value="0" style="width:100px;">
                            <select id="storage-quota-unit" style="width:80px;">
                                <option value="1073741824">GB</option>
                                <option value="1099511627776">TB</option>
                            </select>
                            <span id="storage-used-size" style="color:#666;font-size:0.9em;"></span>
                        </div>
                        <div style="margin-top:12px;padding:10px;background:#f8f9fa;border-radius:6px;">
                            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                                <input type="checkbox" id="storage-calc-usage" style="width:16px;height:16px;">
                                <span>저장 시 현재 사용량 계산</span>
                            </label>
                            <div style="color:#e67e22;font-size:0.85em;margin-top:6px;display:none;" id="calc-usage-warning">
                                ⚠️ 대용량 스토리지는 시간이 오래 걸릴 수 있습니다
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm" id="btn-recalculate" style="margin-top:10px;display:none;">📊 사용량 재계산</button>
                    </div>
                    
                    <!-- 권한 설정 섹션 -->
                    <div class="permission-section">
                        <h4>👥 사용자 권한</h4>
                        <div class="permission-bulk">
                            <span>일괄 적용:</span>
                            <label title="스토리지 목록에 표시"><input type="checkbox" id="bulk-visible"> 표시</label>
                            <label title="파일 열기, 미리보기, 정보"><input type="checkbox" id="bulk-read"> 읽기</label>
                            <label title="파일 다운로드"><input type="checkbox" id="bulk-download"> 다운로드</label>
                            <label title="업로드, 새 폴더, 이름변경, 이동, 복사"><input type="checkbox" id="bulk-write"> 쓰기</label>
                            <label title="파일/폴더 삭제"><input type="checkbox" id="bulk-delete"> 삭제</label>
                            <label title="외부 공유 링크 생성"><input type="checkbox" id="bulk-share"> 공유</label>
                            <button class="btn btn-sm" id="btn-apply-bulk-perm">적용</button>
                        </div>
                        <div class="permission-list" id="permission-list">
                            <!-- 유저별 권한 목록 -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">취소</button>
                    <button class="btn btn-primary" id="btn-save-storage">저장</button>
                </div>
            </div>
            
            <!-- 공유 모달 -->
            <div id="modal-share" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>공유 링크 생성</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>파일</label>
                        <div id="share-filename" class="share-filename"></div>
                    </div>
                    <div class="form-group">
                        <label>만료</label>
                        <select id="share-expire">
                            <option value="">무제한</option>
                            <option value="1">1일</option>
                            <option value="7" selected>7일</option>
                            <option value="30">30일</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>비밀번호 (선택)</label>
                        <input type="text" id="share-password" placeholder="설정 안함">
                    </div>
                    <div class="form-group">
                        <label>최대 다운로드 (선택)</label>
                        <input type="number" id="share-max-downloads" placeholder="무제한">
                    </div>
                    <div id="share-result" class="share-result" style="display:none;">
                        <label>공유 링크</label>
                        <div class="share-url-box">
                            <input type="text" id="share-url" readonly>
                            <button id="btn-copy-url" class="btn">📋 복사</button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">닫기</button>
                    <button class="btn btn-primary" id="btn-create-share">생성</button>
                </div>
            </div>
            
            <!-- 이름 변경 모달 -->
            <div id="modal-rename" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>이름 변경</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="text" id="rename-input" placeholder="새 이름">
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">취소</button>
                    <button class="btn btn-primary" id="btn-rename-confirm">변경</button>
                </div>
            </div>
            
            <!-- 스토리지 관리 모달 -->
            <div id="modal-storages" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2>💾 스토리지 관리</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <button class="btn btn-primary" id="btn-add-storage-new" style="margin-bottom:15px;">➕ 스토리지 추가</button>
                    <div style="overflow-x:auto;">
                        <table class="data-table" id="storages-table" style="min-width:750px;">
                            <thead>
                                <tr>
                                    <th style="width:5%;">ID</th>
                                    <th style="width:13%;">이름</th>
                                    <th style="width:20%;">경로</th>
                                    <th style="width:8%;">유형</th>
                                    <th style="width:18%;">용량</th>
                                    <th style="width:20%;">설명</th>
                                    <th style="width:16%;">관리</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- 사용자 관리 모달 -->
            <div id="modal-users" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>사용자 관리</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 현재 시스템 설정 상태 -->
                    <div id="user-settings-status" class="settings-status-bar">
                        <span id="status-signup" class="status-item">
                            <span class="status-off">🚫 회원가입 비허용</span>
                        </span>
                        <span id="status-approve" class="status-item" style="display:none;">
                            <span class="status-auto">⚡ 자동 승인</span>
                        </span>
                        <span id="status-home-share" class="status-item">
                            <span class="status-on">🔗 개인폴더 외부 공유 허용</span>
                        </span>
                        <a href="#" id="link-change-settings" class="settings-link">⚙️ 설정 변경</a>
                    </div>
                    
                    <div style="margin-bottom:15px; display: flex; gap: 10px;">
                        <button class="btn btn-primary" id="btn-add-user">➕ 사용자 추가</button>
                        <button class="btn" id="btn-bulk-quota">💾 일괄 용량 설정</button>
                    </div>
                    <table class="data-table" id="users-table">
                        <thead>
                            <tr>
                                <th>아이디</th>
                                <th>이름</th>
                                <th>역할</th>
                                <th>용량</th>
                                <th>상태</th>
                                <th>마지막 로그인</th>
                                <th>관리</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            
            <!-- 일괄 용량 설정 모달 -->
            <div id="modal-bulk-quota" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>💾 일괄 용량 설정</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>적용 대상</label>
                        <select id="bulk-quota-target">
                            <option value="all">모든 사용자</option>
                            <option value="user">일반 사용자만</option>
                            <option value="unlimited">현재 무제한인 사용자만</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>용량 설정</label>
                        <div class="quota-input">
                            <input type="number" id="bulk-quota-value" min="0" value="10">
                            <select id="bulk-quota-unit">
                                <option value="0">무제한</option>
                                <option value="1073741824" selected>GB</option>
                                <option value="1048576">MB</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-muted" style="font-size: 12px; color: #666;">
                        ⚠️ 선택한 대상의 모든 사용자에게 동일한 용량이 적용됩니다.
                    </p>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">취소</button>
                    <button class="btn btn-primary" id="btn-apply-bulk-quota">적용</button>
                </div>
            </div>
            
            <!-- 사용자 추가/수정 모달 -->
            <div id="modal-user-form" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2 id="user-form-title">사용자 추가</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="user-id">
                    <div class="form-row-2col">
                        <div class="form-group">
                            <label>아이디</label>
                            <input type="text" id="user-username">
                        </div>
                        <div class="form-group">
                            <label>비밀번호</label>
                            <input type="password" id="user-password" placeholder="변경 시에만 입력">
                        </div>
                    </div>
                    <div class="form-row-2col">
                        <div class="form-group">
                            <label>표시 이름</label>
                            <input type="text" id="user-display-name">
                        </div>
                        <div class="form-group">
                            <label>역할</label>
                            <select id="user-role">
                                <option value="user">일반 사용자</option>
                                <option value="sub_admin">부 관리자</option>
                                <option value="admin">관리자</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-2col">
                        <div class="form-group" id="user-status-group">
                            <label>상태</label>
                            <select id="user-status">
                                <option value="active">활성</option>
                                <option value="suspended">정지</option>
                                <option value="pending">승인 대기</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>저장 용량 제한</label>
                            <div class="quota-input">
                                <input type="number" id="user-quota" min="0" value="0" style="width: 80px;">
                                <select id="user-quota-unit">
                                    <option value="0">무제한</option>
                                    <option value="1073741824">GB</option>
                                    <option value="1048576">MB</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 정지 기간 설정 -->
                    <div id="suspend-period" class="suspend-section" style="display:none;">
                        <h4>🚫 정지 기간 설정</h4>
                        <div class="form-row-2col">
                            <div class="form-group">
                                <label>시작일</label>
                                <input type="date" id="suspend-from">
                            </div>
                            <div class="form-group">
                                <label>종료일</label>
                                <input type="date" id="suspend-until">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>정지 사유</label>
                            <input type="text" id="suspend-reason" placeholder="정지 사유를 입력하세요">
                        </div>
                    </div>
                    
                    <!-- 부 관리자 권한 설정 -->
                    <div id="sub-admin-perms" class="sub-admin-section" style="display:none;">
                        <h4>🔧 부 관리자 접근 권한</h4>
                        <p class="setting-desc">부 관리자가 접근할 수 있는 관리 메뉴를 선택하세요.</p>
                        <div class="admin-menu-checks">
                            <label><input type="checkbox" name="admin_perm" value="storages"> 💾 스토리지 관리</label>
                            <label><input type="checkbox" name="admin_perm" value="users"> 👥 사용자 관리</label>
                            <label><input type="checkbox" name="admin_perm" value="shares"> 🔗 공유 관리</label>
                            <label><input type="checkbox" name="admin_perm" value="logins"> 📋 로그인 기록</label>
                            <label><input type="checkbox" name="admin_perm" value="trash"> 🗑️ 전체 휴지통</label>
                            <label><input type="checkbox" name="admin_perm" value="security"> 🔒 보안 설정</label>
                            <label><input type="checkbox" name="admin_perm" value="system_settings"> 🔧 시스템 설정</label>
                            <label><input type="checkbox" name="admin_perm" value="system_info"> 📊 시스템 정보</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">취소</button>
                    <button class="btn btn-primary" id="btn-save-user">저장</button>
                </div>
            </div>
            
            <!-- 역할 관리 모달 -->
            <div id="modal-roles" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>🏷️ 역할 관리</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-row" style="margin-bottom: 15px; gap: 10px;">
                        <input type="text" id="new-role-name" placeholder="새 역할 이름" style="flex:1; padding: 10px 12px;">
                        <button class="btn btn-primary" id="btn-add-role" style="padding: 10px 20px;">추가</button>
                    </div>
                    <div id="roles-list" class="roles-list"></div>
                    <p class="setting-desc" style="margin-top:15px;">※ 기본 역할(관리자, 부관리자, 사용자)은 삭제할 수 없습니다.</p>
                </div>
            </div>
            
            <!-- QoS 속도 제한 모달 -->
            <div id="modal-qos" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>⚡ 속도 제한 설정</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="qos-tabs">
                        <button class="qos-tab-btn active" data-tab="qos-roles">역할별 설정</button>
                        <button class="qos-tab-btn" data-tab="qos-users">사용자별 설정</button>
                    </div>
                    
                    <!-- 역할별 설정 탭 -->
                    <div id="qos-roles" class="qos-tab-content active">
                        <p class="setting-desc">역할별 기본 속도 제한을 설정합니다. (0 = 무제한)</p>
                        <div id="qos-roles-list" class="qos-list"></div>
                    </div>
                    
                    <!-- 사용자별 설정 탭 -->
                    <div id="qos-users" class="qos-tab-content" style="display:none;">
                        <p class="setting-desc">개별 사용자의 속도 제한을 설정합니다. 역할 설정보다 우선 적용됩니다.</p>
                        <div class="qos-user-search">
                            <input type="text" id="qos-user-search" placeholder="사용자 검색...">
                        </div>
                        <div id="qos-users-list" class="qos-list"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">닫기</button>
                    <button class="btn btn-primary" id="btn-save-qos">저장</button>
                </div>
            </div>
            
            <!-- 공유 목록 모달 -->
            <div id="modal-shares-list" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🔗 공유 관리</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="shares-empty" class="empty-msg" style="display:none;">공유된 파일이 없습니다</div>
                    <div id="shares-list-container"></div>
                </div>
            </div>
            
            <!-- 설정 모달 -->
            <div id="modal-settings" class="modal modal-large" style="display:none;">
                <div class="modal-header">
                    <h2>⚙️ 설정</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 탭 -->
                    <div class="settings-tabs">
                        <button class="tab-btn active" data-tab="tab-profile">👤 내 정보</button>
                        <button class="tab-btn" data-tab="tab-twofa">🔐 2단계 인증</button>
                        <button class="tab-btn" data-tab="tab-theme">🎨 테마</button>
                        <button class="tab-btn" data-tab="tab-sessions">📱 세션 관리</button>
                        <button class="tab-btn" data-tab="tab-login-logs">📋 로그인 기록</button>
                    </div>
                    
                    <!-- 내 정보 탭 -->
                    <div id="tab-profile" class="tab-content active">
                        <h4>내 정보</h4>
                        <div class="form-group">
                            <label>표시 이름</label>
                            <input type="text" id="settings-display-name" placeholder="표시 이름">
                        </div>
                        <div class="form-group">
                            <label>이메일</label>
                            <input type="email" id="settings-email" placeholder="이메일">
                        </div>
                        <button class="btn btn-primary" id="btn-save-settings">정보 저장</button>
                        
                        <hr style="margin: 20px 0;">
                        
                        <h4>비밀번호 변경</h4>
                        <div class="form-group">
                            <label>현재 비밀번호</label>
                            <input type="password" id="current-password">
                        </div>
                        <div class="form-group">
                            <label>새 비밀번호</label>
                            <input type="password" id="new-password">
                        </div>
                        <div class="form-group">
                            <label>새 비밀번호 확인</label>
                            <input type="password" id="confirm-password">
                        </div>
                        <button class="btn btn-primary" id="btn-change-password">비밀번호 변경</button>
                    </div>
                    
                    <!-- 2FA 탭 -->
                    <div id="tab-twofa" class="tab-content" style="display:none;">
                        <h4>🔐 2단계 인증 (TOTP)</h4>
                        <p class="text-muted" style="margin-bottom:20px;">Google Authenticator, Authy 등의 앱을 사용하여 계정을 보호하세요.</p>
                        
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        <div class="alert alert-info" style="font-size:13px; margin-bottom:20px;">
                            <strong>⚙️ 관리자 설정 안내</strong>
                            <p style="margin:10px 0 5px 0;"><code>config.php</code> 파일에서 다음 값을 수정하세요:</p>
                            <ul style="margin:5px 0; padding-left:20px; line-height:1.8;">
                                <li><code>'WebHard'</code> → QR코드에 표시될 사이트 이름으로 변경 (예: <code>'내사이트'</code>)</li>
                                <li><code>'change-this-to-your-secret-key-32chars'</code> → 아무 문자열로 변경<br>
                                    <span style="color:#666; font-size:12px;">예: <code>'aB3xK9mN2pQ7wE5r...'</code> (키보드로 아무거나 입력)</span>
                                </li>
                            </ul>
                            <p style="margin:5px 0 0 0; color:#c00; font-size:12px;">⚠️ 2FA 사용자가 있는 상태에서 두 번째 값을 변경하면 기존 사용자 로그인 불가!</p>
                        </div>
                        <?php endif; ?>
                        
                        <div id="twofa-status-area">
                            <div id="twofa-disabled-section" style="display:none;">
                                <div class="alert alert-warning">
                                    <strong>⚠️ 2단계 인증이 비활성화되어 있습니다.</strong>
                                    <p>2단계 인증을 활성화하면 로그인 시 추가 인증이 필요합니다.</p>
                                </div>
                                <button class="btn btn-primary" id="btn-twofa-setup">2단계 인증 설정</button>
                            </div>
                            
                            <div id="twofa-enabled-section" style="display:none;">
                                <div class="alert alert-success">
                                    <strong>✅ 2단계 인증이 활성화되어 있습니다.</strong>
                                    <p id="twofa-enabled-info"></p>
                                </div>
                                <button class="btn btn-warning" id="btn-twofa-regenerate-backup">백업 코드 재생성</button>
                                <button class="btn btn-danger" id="btn-twofa-disable">2단계 인증 해제</button>
                            </div>
                            
                            <div id="twofa-setup-section" style="display:none;">
                                <h5>1. 인증 앱에서 QR 코드를 스캔하세요</h5>
                                <div class="qr-code-area" style="text-align:center; margin:20px 0;">
                                    <div id="twofa-qr-code" style="display:inline-block; border:1px solid #ddd; padding:10px; background:#fff; border-radius:8px;"></div>
                                </div>
                                <p style="text-align:center; color:#666; font-size:12px;">QR 코드를 스캔할 수 없는 경우 아래 키를 수동으로 입력하세요:</p>
                                <div class="secret-key-area" style="text-align:center; margin:10px 0;">
                                    <code id="twofa-secret-key" style="font-size:16px; letter-spacing:2px; padding:10px; background:#f5f5f5; display:inline-block;"></code>
                                </div>
                                
                                <h5 style="margin-top:20px;">2. 인증 앱에 표시된 6자리 코드를 입력하세요</h5>
                                <div class="form-group" style="max-width:200px; margin:10px auto;">
                                    <input type="text" id="twofa-verify-code" placeholder="000000" maxlength="6" 
                                           inputmode="numeric" pattern="[0-9]*"
                                           style="text-align:center; font-size:24px; letter-spacing:5px;"
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                </div>
                                <div style="text-align:center;">
                                    <button class="btn btn-primary" id="btn-twofa-verify">확인 및 활성화</button>
                                    <button class="btn" id="btn-twofa-cancel">취소</button>
                                </div>
                            </div>
                            
                            <div id="twofa-backup-codes-section" style="display:none;">
                                <div class="alert alert-info">
                                    <strong>📋 백업 코드</strong>
                                    <p>이 코드들을 안전한 곳에 보관하세요. 인증 앱을 사용할 수 없을 때 로그인에 사용할 수 있습니다.</p>
                                    <p style="color:#c00;"><strong>⚠️ 각 코드는 한 번만 사용할 수 있습니다.</strong></p>
                                </div>
                                <div id="twofa-backup-codes-list" class="backup-codes-list" style="display:grid; grid-template-columns:repeat(2, 1fr); gap:10px; max-width:300px; margin:20px auto;"></div>
                                <div style="text-align:center; margin-top:20px;">
                                    <button class="btn btn-primary" id="btn-twofa-backup-done">확인</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 테마 탭 -->
                    <div id="tab-theme" class="tab-content" style="display:none;">
                        <h4>🎨 테마 선택</h4>
                        <div class="theme-grid">
                            <div class="theme-item" data-theme="default">
                                <div class="theme-preview theme-preview-default"></div>
                                <span>기본</span>
                            </div>
                            <div class="theme-item" data-theme="dark">
                                <div class="theme-preview theme-preview-dark"></div>
                                <span>다크</span>
                            </div>
                            <div class="theme-item" data-theme="blue">
                                <div class="theme-preview theme-preview-blue"></div>
                                <span>블루</span>
                            </div>
                            <div class="theme-item" data-theme="mint">
                                <div class="theme-preview theme-preview-mint"></div>
                                <span>민트</span>
                            </div>
                            <div class="theme-item" data-theme="rose">
                                <div class="theme-preview theme-preview-rose"></div>
                                <span>로즈</span>
                            </div>
                            <div class="theme-item" data-theme="blue-full">
                                <div class="theme-preview theme-preview-blue-full"></div>
                                <span>블루 전체</span>
                            </div>
                            <div class="theme-item" data-theme="mint-full">
                                <div class="theme-preview theme-preview-mint-full"></div>
                                <span>민트 전체</span>
                            </div>
                            <div class="theme-item" data-theme="rose-full">
                                <div class="theme-preview theme-preview-rose-full"></div>
                                <span>로즈 전체</span>
                            </div>
                            <div class="theme-item" data-theme="lavender">
                                <div class="theme-preview theme-preview-lavender"></div>
                                <span>라벤더</span>
                            </div>
                            <div class="theme-item" data-theme="peach">
                                <div class="theme-preview theme-preview-peach"></div>
                                <span>피치</span>
                            </div>
                            <div class="theme-item" data-theme="sky">
                                <div class="theme-preview theme-preview-sky"></div>
                                <span>스카이</span>
                            </div>
                            <div class="theme-item" data-theme="lavender-full">
                                <div class="theme-preview theme-preview-lavender-full"></div>
                                <span>라벤더 전체</span>
                            </div>
                            <div class="theme-item" data-theme="peach-full">
                                <div class="theme-preview theme-preview-peach-full"></div>
                                <span>피치 전체</span>
                            </div>
                            <div class="theme-item" data-theme="sky-full">
                                <div class="theme-preview theme-preview-sky-full"></div>
                                <span>스카이 전체</span>
                            </div>
                            <div class="theme-item" data-theme="pink">
                                <div class="theme-preview theme-preview-pink"></div>
                                <span>핑크</span>
                            </div>
                            <div class="theme-item" data-theme="pink-full">
                                <div class="theme-preview theme-preview-pink-full"></div>
                                <span>핑크 전체</span>
                            </div>
                            <div class="theme-item" data-theme="pastel-blue">
                                <div class="theme-preview theme-preview-pastel-blue"></div>
                                <span>파스텔 블루</span>
                            </div>
                            <div class="theme-item" data-theme="pastel-blue-full">
                                <div class="theme-preview theme-preview-pastel-blue-full"></div>
                                <span>파스텔 블루 전체</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 세션 관리 탭 -->
                    <div id="tab-sessions" class="tab-content" style="display:none;">
                        <div class="session-header">
                            <h4>📱 활성 세션</h4>
                            <button class="btn btn-danger btn-sm" id="btn-terminate-all">모든 기기 로그아웃</button>
                        </div>
                        <div id="sessions-list" class="sessions-list">
                            <div class="loading">로딩 중...</div>
                        </div>
                    </div>
                    
                    <!-- 로그인 로그 탭 -->
                    <div id="tab-login-logs" class="tab-content" style="display:none;">
                        <h4>📋 최근 로그인 기록</h4>
                        <div id="login-logs-list" class="login-logs-list">
                            <div class="loading">로딩 중...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 시스템 설정 모달 (관리자) -->
            <div id="modal-system-settings" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🔧 시스템 설정</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="system-settings">
                        <h3>일반 설정</h3>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-signup-enabled">
                                <span>회원가입 허용</span>
                            </label>
                            <p class="setting-desc">활성화하면 로그인 화면에서 회원가입이 가능합니다.</p>
                        </div>
                        <div class="setting-item" id="auto-approve-wrap" style="display:none; margin-left: 20px;">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-auto-approve">
                                <span>자동 승인</span>
                            </label>
                            <p class="setting-desc">활성화하면 가입 즉시 로그인할 수 있습니다. 비활성화하면 관리자 승인이 필요합니다.</p>
                        </div>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-home-share">
                                <span>개인 폴더 외부 공유 허용</span>
                            </label>
                            <p class="setting-desc">비활성화하면 사용자가 개인 폴더의 파일을 외부 링크로 공유할 수 없습니다.</p>
                        </div>
                        
                        <h3>스토리지 경로 설정</h3>
                        <div class="setting-item">
                            <label>개인폴더 루트 경로</label>
                            <input type="text" id="setting-user-files-root" class="form-control" placeholder="비워두면 기본값 사용">
                            <p class="setting-desc">사용자별 개인폴더가 저장되는 위치입니다. (예: E:\WebHard\users 또는 /mnt/data/users)</p>
                            <p class="setting-desc current-path" id="current-user-path"></p>
                        </div>
                        <div class="setting-item">
                            <label>공유폴더 루트 경로</label>
                            <input type="text" id="setting-shared-files-root" class="form-control" placeholder="비워두면 기본값 사용">
                            <p class="setting-desc">공유폴더가 저장되는 위치입니다. (예: E:\WebHard\shared 또는 /mnt/data/shared)</p>
                            <p class="setting-desc current-path" id="current-shared-path"></p>
                        </div>
                        <div class="setting-item">
                            <label>휴지통 경로</label>
                            <input type="text" id="setting-trash-path" class="form-control" placeholder="비워두면 기본값 사용">
                            <p class="setting-desc">삭제된 파일이 저장되는 위치입니다. (예: E:\WebHard\trash 또는 /mnt/data/trash)</p>
                            <p class="setting-desc current-path" id="current-trash-path"></p>
                        </div>
                        <div class="setting-notice">
                            <p>⚠️ <strong>주의사항</strong></p>
                            <ul>
                                <li>경로 변경 시 기존 파일은 자동으로 이동되지 않습니다.</li>
                                <li>변경 전 기존 파일을 새 경로로 직접 이동해주세요.</li>
                                <li>저장 후 페이지를 새로고침해야 적용됩니다.</li>
                            </ul>
                        </div>
                        
                        <h3>외부 접속 설정</h3>
                        <div class="setting-item">
                            <label>외부 접속 URL (공유 링크용)</label>
                            <input type="text" id="setting-external-url" class="form-control" placeholder="https://mynas.example.com">
                            <p class="setting-desc">공유 링크 생성 시 사용할 외부 URL입니다. 내부망(192.168.x.x)에서 접속해도 이 주소로 공유 링크가 생성됩니다. 비워두면 현재 접속 주소를 사용합니다.</p>
                        </div>
                        
                        <h3>로그인 화면 설정</h3>
                        <div class="setting-item">
                            <label>사이트 이름</label>
                            <input type="text" id="setting-site-name" class="form-control" placeholder="Cloud Storage">
                            <p class="setting-desc">로그인 화면과 상단에 표시되는 사이트 이름입니다.</p>
                        </div>
                        
                        <div class="setting-item">
                            <label>로고 이미지</label>
                            <div class="image-upload-wrap">
                                <div id="logo-preview" class="image-preview">
                                    <span class="no-image">📁</span>
                                </div>
                                <div class="image-upload-actions">
                                    <input type="file" id="logo-upload" accept="image/*" style="display:none;">
                                    <button type="button" class="btn btn-sm" onclick="document.getElementById('logo-upload').click()">이미지 선택</button>
                                    <button type="button" class="btn btn-sm btn-danger" id="btn-logo-delete" style="display:none;">삭제</button>
                                </div>
                            </div>
                            <p class="setting-desc">로그인 화면에 표시되는 로고 이미지입니다. (권장: 128x128px)</p>
                        </div>
                        
                        <div class="setting-item">
                            <label>로그인 배경 이미지</label>
                            <div class="image-upload-wrap">
                                <div id="bg-preview" class="image-preview bg-preview">
                                    <span class="no-image">🖼️</span>
                                </div>
                                <div class="image-upload-actions">
                                    <input type="file" id="bg-upload" accept="image/*" style="display:none;">
                                    <button type="button" class="btn btn-sm" onclick="document.getElementById('bg-upload').click()">이미지 선택</button>
                                    <button type="button" class="btn btn-sm btn-danger" id="btn-bg-delete" style="display:none;">삭제</button>
                                </div>
                            </div>
                            <p class="setting-desc">로그인 화면의 배경 이미지입니다. (권장: 1920x1080px)</p>
                        </div>
                        
                        <h3>검색 인덱스 설정</h3>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-auto-index">
                                <span>인덱스 자동 갱신</span>
                            </label>
                            <p class="setting-desc">파일 업로드/삭제/이동/이름변경 시 검색 인덱스를 자동으로 업데이트합니다.</p>
                            <p class="setting-desc">⚠️ 파일이 매우 많은 환경에서는 비활성화하고 주기적으로 수동 재구축을 권장합니다.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()">취소</button>
                    <button class="btn btn-primary" id="btn-save-system-settings">저장</button>
                </div>
            </div>
            
            <!-- 검색 인덱스 모달 -->
            <div id="modal-search-index" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>🔍 검색 인덱스</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="index-info">
                        <p>검색 인덱스를 사용하면 파일 검색 속도가 대폭 향상됩니다.</p>
                        <p>파일이 많은 경우 인덱스 재구축에 시간이 걸릴 수 있습니다.</p>
                        <p class="index-requirement"><strong>⚙️ 요구사항:</strong> PHP sqlite3 확장 필요 (php.ini에서 <code>extension=sqlite3</code> 활성화)</p>
                    </div>
                    
                    <!-- 자동 갱신 상태 표시 -->
                    <div id="index-auto-status" class="index-auto-status">
                        <span id="index-auto-on" style="display:none;">✅ <strong>자동 갱신 활성화</strong> - 파일 변경 시 인덱스가 자동으로 업데이트됩니다.</span>
                        <span id="index-auto-off" style="display:none;">⚠️ <strong>자동 갱신 비활성화</strong> - 파일 변경 후 수동으로 재구축해야 검색에 반영됩니다. <a href="#" id="link-enable-auto-index">[활성화하기]</a></span>
                    </div>
                    
                    <div id="sqlite-warning" class="sqlite-warning" style="display:none;">
                        <p><strong>⚠️ SQLite3 확장이 비활성화되어 있습니다.</strong></p>
                        <p>php.ini에서 <code>extension=sqlite3</code>를 활성화한 후 웹서버를 재시작하세요.</p>
                        <p>SQLite3 없이도 기본 검색은 가능하지만, 파일이 많을 경우 속도가 느립니다.</p>
                    </div>
                    
                    <div class="index-stats">
                        <h3>인덱스 현황</h3>
                        <table class="stats-table">
                            <tr>
                                <th>총 항목</th>
                                <td id="index-total">-</td>
                            </tr>
                            <tr>
                                <th>파일</th>
                                <td id="index-files">-</td>
                            </tr>
                            <tr>
                                <th>폴더</th>
                                <td id="index-folders">-</td>
                            </tr>
                            <tr>
                                <th>마지막 재구축</th>
                                <td id="index-last-rebuild">-</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="index-actions">
                        <button class="btn btn-primary" id="btn-rebuild-index">
                            🔄 전체 인덱스 재구축
                        </button>
                        <button class="btn btn-danger" id="btn-clear-index">
                            🗑️ 인덱스 초기화
                        </button>
                    </div>
                    
                    <div id="index-progress" style="display:none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <p class="progress-text">인덱스 재구축 중...</p>
                    </div>
                    
                    <div id="index-status" style="display:none;"></div>
                </div>
            </div>
            
            <!-- 활동 로그 모달 -->
            <div id="modal-activity-logs" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2>📜 활동 로그</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="activity-filters">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label>사용자</label>
                                <select id="activity-filter-user" class="form-control">
                                    <option value="">전체</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>유형</label>
                                <select id="activity-filter-type" class="form-control">
                                    <option value="">전체</option>
                                    <option value="upload">📤 업로드</option>
                                    <option value="download">📥 다운로드</option>
                                    <option value="delete">🗑️ 삭제</option>
                                    <option value="create_folder">📁 폴더 생성</option>
                                    <option value="rename">✏️ 이름 변경</option>
                                    <option value="move">📦 이동</option>
                                    <option value="copy">📋 복사</option>
                                    <option value="share_create">🔗 공유 생성</option>
                                    <option value="share_access">👁️ 공유 접근</option>
                                    <option value="extract">📦 압축 해제</option>
                                    <option value="compress">🗜️ 압축</option>
                                    <option value="restore">↩️ 복원</option>
                                    <option value="login">🔐 로그인</option>
                                    <option value="logout">🔓 로그아웃</option>
                                    <option value="login_fail">⚠️ 로그인 실패</option>
                                    <option value="hack_attempt">🚨 해킹시도</option>
                                </select>
                            </div>
                            <div class="filter-group filter-date">
                                <label>기간</label>
                                <div class="date-range">
                                    <input type="date" id="activity-filter-from" class="form-control">
                                    <span class="date-separator">~</span>
                                    <input type="date" id="activity-filter-to" class="form-control">
                                </div>
                            </div>
                            <div class="filter-group filter-search">
                                <label>검색</label>
                                <input type="text" id="activity-filter-search" class="form-control" placeholder="파일명, 경로, 사용자...">
                            </div>
                            <div class="filter-group filter-buttons">
                                <label>&nbsp;</label>
                                <div class="btn-group">
                                    <button class="btn btn-primary" id="btn-activity-search">🔍 검색</button>
                                    <button class="btn" id="btn-activity-reset">초기화</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="activity-stats" id="activity-stats"></div>
                    <div class="activity-table-wrap">
                        <table class="data-table" id="activity-table">
                            <thead>
                                <tr>
                                    <th>시간</th>
                                    <th>유형</th>
                                    <th>사용자</th>
                                    <th>파일/경로</th>
                                    <th>크기</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody id="activity-table-body"></tbody>
                        </table>
                    </div>
                    <div class="activity-pagination" id="activity-pagination"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-danger" id="btn-activity-clear">🗑️ 로그 삭제</button>
                    <button class="btn" onclick="closeModal()">닫기</button>
                </div>
            </div>
            
            <!-- 조건부 일괄 삭제 모달 -->
            <div id="modal-bulk-delete" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🧹 조건부 일괄 삭제</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="bulk-delete-info">
                        <p class="info-notice">📍 <strong>현재 폴더</strong>를 기준으로 조건에 맞는 파일/폴더를 검색하여 삭제합니다.</p>
                    </div>
                    <div class="bulk-delete-settings">
                        <div class="form-group">
                            <label>삭제 대상 패턴 (한 줄에 하나씩)</label>
                            <textarea id="bulk-delete-patterns" rows="5" class="form-control" placeholder="@eaDir
*.tmp
Thumbs.db
.DS_Store
desktop.ini"></textarea>
                            <p class="setting-desc">
                                <strong>와일드카드 사용 가능:</strong> 
                                <code>*</code> = 모든 문자, <code>?</code> = 한 문자<br>
                                예: <code>*.zip</code> (모든 ZIP 파일), <code>test?.txt</code> (test1.txt, testA.txt 등)
                            </p>
                        </div>
                        <div class="form-group">
                            <label>검색 범위</label>
                            <select id="bulk-delete-scope" class="form-control">
                                <option value="current">현재 폴더만</option>
                                <option value="recursive" selected>현재 폴더 및 하위 폴더</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>대상 유형</label>
                            <select id="bulk-delete-type" class="form-control">
                                <option value="all">파일 및 폴더</option>
                                <option value="file">파일만</option>
                                <option value="folder">폴더만</option>
                            </select>
                        </div>
                        <div class="bulk-delete-actions">
                            <button class="btn btn-primary" id="btn-bulk-delete-search">🔍 검색</button>
                        </div>
                        <div id="bulk-delete-results" class="bulk-delete-results" style="display:none;">
                            <h4>검색 결과 <span id="bulk-delete-count"></span></h4>
                            <div id="bulk-delete-list" class="bulk-delete-list"></div>
                            <div class="bulk-delete-actions">
                                <button class="btn btn-danger" id="btn-bulk-delete-execute">🗑️ 선택 항목 삭제</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 권한 설정 모달 -->
            <div id="modal-permissions" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>권한 설정</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="perm-storage-id">
                    <div class="form-group">
                        <label>스토리지</label>
                        <div id="perm-storage-name" class="perm-storage-name"></div>
                    </div>
                    <div id="perm-list" class="perm-list"></div>
                    <hr>
                    <h4>권한 추가</h4>
                    <div class="form-row">
                        <select id="perm-user-select"></select>
                        <label><input type="checkbox" id="perm-read" checked> 읽기</label>
                        <label><input type="checkbox" id="perm-write"> 쓰기</label>
                        <label><input type="checkbox" id="perm-delete"> 삭제</label>
                        <label><input type="checkbox" id="perm-share"> 공유</label>
                        <button class="btn btn-sm btn-primary" id="btn-add-perm">추가</button>
                    </div>
                </div>
            </div>
            
            <!-- 상세 정보 모달 -->
            <div id="modal-detailed-info" class="modal modal-md" style="display:none;">
                <div class="modal-header">
                    <h2>📄 상세 정보</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="detailed-info-content"></div>
                </div>
            </div>
            
            <!-- 파일 정보 모달 -->
            <div id="modal-info" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>파일 정보</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <table class="info-table" id="file-info-table"></table>
                </div>
            </div>
            
            <!-- 전체 로그인 기록 모달 (관리자) -->
            <div id="modal-all-logins" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>📋 전체 로그인 기록</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="log-actions" style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <button id="btn-log-delete-selected" class="btn btn-danger btn-sm">🗑️ 선택 삭제</button>
                        <button id="btn-log-delete-all" class="btn btn-danger btn-sm">🗑️ 전체 삭제</button>
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <input type="number" id="log-delete-days" value="30" min="1" style="width: 60px; padding: 5px;">
                            <span>일 이전</span>
                            <button id="btn-log-delete-old" class="btn btn-sm">🗑️ 삭제</button>
                        </div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 30px;"><input type="checkbox" id="log-select-all"></th>
                                <th>사용자</th>
                                <th>시간</th>
                                <th>IP</th>
                                <th>국가</th>
                                <th>디바이스</th>
                                <th>결과</th>
                            </tr>
                        </thead>
                        <tbody id="all-logins-tbody"></tbody>
                    </table>
                    <div id="all-logins-pagination"></div>
                </div>
            </div>
            
            <!-- 휴지통 관리 모달 (관리자) -->
            <!-- 전체 휴지통 모달 (관리자) -->
            <div id="modal-trash" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🗑️ 전체 휴지통</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="trash-toolbar">
                        <div class="trash-info">
                            <span id="trash-count">0개 항목</span>
                            <span id="trash-size">0 B</span>
                        </div>
                        <button id="btn-trash-empty" class="btn btn-danger btn-sm">🗑️ 전체 비우기</button>
                    </div>
                    <div class="trash-list" id="trash-list"></div>
                    <div class="trash-empty-msg" id="trash-empty-msg" style="display:none;">
                        <div class="empty-icon">🗑️</div>
                        <p>휴지통이 비어있습니다</p>
                    </div>
                </div>
            </div>
            
            <!-- 내 휴지통 모달 (개인) -->
            <div id="modal-my-trash" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🗑️ 내 휴지통</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="trash-toolbar">
                        <div class="trash-info">
                            <span id="my-trash-count">0개 항목</span>
                            <span id="my-trash-size">0 B</span>
                        </div>
                        <button id="btn-my-trash-empty" class="btn btn-danger btn-sm">🗑️ 비우기</button>
                    </div>
                    <div class="trash-list" id="my-trash-list"></div>
                    <div class="trash-empty-msg" id="my-trash-empty-msg" style="display:none;">
                        <div class="empty-icon">🗑️</div>
                        <p>휴지통이 비어있습니다</p>
                    </div>
                </div>
            </div>
            
            <!-- 보안 설정 모달 (관리자) -->
            <div id="modal-security" class="modal modal-xl" style="display:none;">
                <div class="modal-header security-header">
                    <h2>🛡️ IP/국가 차단 설정</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 현재 접속 정보 -->
                    <div class="security-info-bar">
                        📍 현재 접속 정보: IP: <code id="current-ip">-</code> | 국가: <code id="current-country">-</code>
                    </div>
                    
                    <!-- 차단 기능 활성화 -->
                    <div class="security-toggle-section">
                        <label class="toggle-switch">
                            <input type="checkbox" id="security-enabled">
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">차단 기능 활성화</span>
                        <div class="security-warning">⚠️ 활성화만으로는 차단되지 않습니다! 아래 차단 모드를 반드시 1개 이상 선택하세요.</div>
                    </div>
                    
                    <!-- 차단 모드 선택 -->
                    <div class="security-mode-section">
                        <h4>차단 모드 <span class="required">※ 필수 선택</span></h4>
                        <div class="security-mode-grid">
                            <div class="mode-column">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-block-country">
                                    <span class="checkbox-icon">🚫</span> 특정 국가 차단
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-allow-country-only">
                                    <span class="checkbox-icon">✅</span> 특정 국가만 허용
                                </label>
                            </div>
                            <div class="mode-column">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-block-ip">
                                    <span class="checkbox-icon">🚫</span> 특정 IP 차단
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-allow-ip-only">
                                    <span class="checkbox-icon">✅</span> 특정 IP만 허용
                                </label>
                            </div>
                        </div>
                        <div class="mode-hint">💡 국가 차단 + IP 차단을 함께 사용하면 두 조건 모두 적용됩니다.</div>
                    </div>
                    
                    <!-- 국가/IP 입력 -->
                    <div class="security-input-section">
                        <div class="input-row">
                            <div class="input-column">
                                <label><span class="label-icon">🚫</span> 차단할 국가 <small>(국가 코드, 쉼표 구분)</small></label>
                                <input type="text" id="security-blocked-countries" placeholder="CN,RU,KP" disabled>
                                <div class="input-example">예: CN(중국), RU(러시아), KP(북한), VN(베트남)</div>
                            </div>
                            <div class="input-column">
                                <label><span class="label-icon">✅</span> 허용할 국가 <small>(국가 코드, 쉼표 구분)</small></label>
                                <input type="text" id="security-allowed-countries" placeholder="KR,US" disabled>
                                <div class="input-example">예: KR(한국), US(미국), JP(일본)</div>
                            </div>
                        </div>
                        <div class="input-row">
                            <div class="input-column">
                                <label><span class="label-icon">🚫</span> 차단할 IP <small>(줄바꿈 또는 쉼마 구분, CIDR 지원)</small></label>
                                <textarea id="security-blocked-ips" rows="4" placeholder="1.2.3.4&#10;5.6.7.0/24" disabled></textarea>
                            </div>
                            <div class="input-column">
                                <label><span class="label-icon">✅</span> 허용할 IP <small>(줄바꿈 또는 쉼마 구분, CIDR 지원)</small></label>
                                <textarea id="security-allowed-ips" rows="4" placeholder="192.168.1.0/24&#10;10.0.0.0/8" disabled></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 관리자 IP (화이트리스트) -->
                    <div class="security-admin-section">
                        <h4>⭐ 관리자 IP (화이트리스트)</h4>
                        <div class="admin-ip-warning">
                            <strong>⚠️ 차단 설정 전 반드시 관리자 IP를 입력하세요!</strong><br>
                            이 IP는 모든 차단 규칙을 무시하고 항상 접근이 허용됩니다.<br>
                            <span class="current-ip-hint">현재 접속 IP: <code id="current-ip-hint">-</code> ← 이 IP를 아래에 추가하세요!</span>
                        </div>
                        <textarea id="security-admin-ips" rows="3" placeholder="127.0.0.1&#10;192.168.1.100"></textarea>
                        <div class="input-example">줄바꿈 또는 쉼마로 구분, CIDR 지원 (예: 192.168.1.0/24)</div>
                    </div>
                    
                    <!-- 추가 설정 -->
                    <div class="security-extra-section">
                        <div class="extra-row">
                            <div class="extra-item">
                                <label>차단 메시지</label>
                                <input type="text" id="security-block-message" placeholder="접근이 차단되었습니다.">
                            </div>
                            <div class="extra-item">
                                <label>IP→국가 캐시 시간</label>
                                <div class="input-with-unit">
                                    <input type="number" id="security-cache-hours" value="24" min="1" max="168">
                                    <span class="unit">시간</span>
                                </div>
                            </div>
                            <div class="extra-item">
                                <label>차단 로그</label>
                                <label class="toggle-switch small">
                                    <input type="checkbox" id="security-log-enabled">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="toggle-text">로그 기록</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 국가 코드 목록 -->
                    <div class="security-section">
                        <div class="country-codes-toggle">
                            <a href="#" id="toggle-country-codes">📋 국가 코드 목록 보기</a>
                            <div id="country-codes-list" style="display:none;">
                                <div class="country-codes-grid">
                                    <div class="country-group">
                                        <strong>동아시아</strong>
                                        <span>KR 한국</span>
                                        <span>KP 북한</span>
                                        <span>JP 일본</span>
                                        <span>CN 중국</span>
                                        <span>TW 대만</span>
                                        <span>HK 홍콩</span>
                                        <span>MO 마카오</span>
                                        <span>MN 몽골</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>동남아시아</strong>
                                        <span>SG 싱가포르</span>
                                        <span>TH 태국</span>
                                        <span>VN 베트남</span>
                                        <span>PH 필리핀</span>
                                        <span>MY 말레이시아</span>
                                        <span>ID 인도네시아</span>
                                        <span>MM 미얀마</span>
                                        <span>KH 캄보디아</span>
                                        <span>LA 라오스</span>
                                        <span>BN 브루나이</span>
                                        <span>TL 동티모르</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>남아시아</strong>
                                        <span>IN 인도</span>
                                        <span>PK 파키스탄</span>
                                        <span>BD 방글라데시</span>
                                        <span>LK 스리랑카</span>
                                        <span>NP 네팔</span>
                                        <span>BT 부탄</span>
                                        <span>MV 몰디브</span>
                                        <span>AF 아프가니스탄</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>중앙아시아</strong>
                                        <span>KZ 카자흐스탄</span>
                                        <span>UZ 우즈베키스탄</span>
                                        <span>TM 투르크메니스탄</span>
                                        <span>KG 키르기스스탄</span>
                                        <span>TJ 타지키스탄</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>중동</strong>
                                        <span>AE 아랍에미리트</span>
                                        <span>SA 사우디아라비아</span>
                                        <span>IL 이스라엘</span>
                                        <span>TR 터키</span>
                                        <span>IR 이란</span>
                                        <span>IQ 이라크</span>
                                        <span>SY 시리아</span>
                                        <span>JO 요르단</span>
                                        <span>LB 레바논</span>
                                        <span>KW 쿠웨이트</span>
                                        <span>QA 카타르</span>
                                        <span>BH 바레인</span>
                                        <span>OM 오만</span>
                                        <span>YE 예멘</span>
                                        <span>PS 팔레스타인</span>
                                        <span>CY 키프로스</span>
                                        <span>GE 조지아</span>
                                        <span>AM 아르메니아</span>
                                        <span>AZ 아제르바이잔</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>서유럽</strong>
                                        <span>GB 영국</span>
                                        <span>IE 아일랜드</span>
                                        <span>FR 프랑스</span>
                                        <span>DE 독일</span>
                                        <span>NL 네덜란드</span>
                                        <span>BE 벨기에</span>
                                        <span>LU 룩셈부르크</span>
                                        <span>CH 스위스</span>
                                        <span>AT 오스트리아</span>
                                        <span>LI 리히텐슈타인</span>
                                        <span>MC 모나코</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>북유럽</strong>
                                        <span>SE 스웨덴</span>
                                        <span>NO 노르웨이</span>
                                        <span>DK 덴마크</span>
                                        <span>FI 핀란드</span>
                                        <span>IS 아이슬란드</span>
                                        <span>EE 에스토니아</span>
                                        <span>LV 라트비아</span>
                                        <span>LT 리투아니아</span>
                                        <span>AX 올란드제도</span>
                                        <span>FO 페로제도</span>
                                        <span>SJ 스발바르</span>
                                        <span>GL 그린란드</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>남유럽</strong>
                                        <span>IT 이탈리아</span>
                                        <span>ES 스페인</span>
                                        <span>PT 포르투갈</span>
                                        <span>GR 그리스</span>
                                        <span>MT 몰타</span>
                                        <span>SM 산마리노</span>
                                        <span>VA 바티칸</span>
                                        <span>AD 안도라</span>
                                        <span>GI 지브롤터</span>
                                        <span>SI 슬로베니아</span>
                                        <span>HR 크로아티아</span>
                                        <span>BA 보스니아</span>
                                        <span>RS 세르비아</span>
                                        <span>ME 몬테네그로</span>
                                        <span>MK 북마케도니아</span>
                                        <span>AL 알바니아</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>동유럽</strong>
                                        <span>RU 러시아</span>
                                        <span>UA 우크라이나</span>
                                        <span>BY 벨라루스</span>
                                        <span>PL 폴란드</span>
                                        <span>CZ 체코</span>
                                        <span>SK 슬로바키아</span>
                                        <span>HU 헝가리</span>
                                        <span>RO 루마니아</span>
                                        <span>BG 불가리아</span>
                                        <span>MD 몰도바</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>북미</strong>
                                        <span>US 미국</span>
                                        <span>CA 캐나다</span>
                                        <span>MX 멕시코</span>
                                        <span>GT 과테말라</span>
                                        <span>BZ 벨리즈</span>
                                        <span>HN 온두라스</span>
                                        <span>SV 엘살바도르</span>
                                        <span>NI 니카라과</span>
                                        <span>CR 코스타리카</span>
                                        <span>PA 파나마</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>카리브해</strong>
                                        <span>CU 쿠바</span>
                                        <span>JM 자메이카</span>
                                        <span>HT 아이티</span>
                                        <span>DO 도미니카공화국</span>
                                        <span>PR 푸에르토리코</span>
                                        <span>TT 트리니다드토바고</span>
                                        <span>BS 바하마</span>
                                        <span>BB 바베이도스</span>
                                        <span>BM 버뮤다</span>
                                        <span>LC 세인트루시아</span>
                                        <span>GD 그레나다</span>
                                        <span>VC 세인트빈센트</span>
                                        <span>AG 앤티가바부다</span>
                                        <span>DM 도미니카</span>
                                        <span>KN 세인트키츠네비스</span>
                                        <span>AI 앵귈라</span>
                                        <span>AW 아루바</span>
                                        <span>BL 생바르텔레미</span>
                                        <span>BQ 보네르</span>
                                        <span>CW 퀴라소</span>
                                        <span>GP 과들루프</span>
                                        <span>KY 케이맨제도</span>
                                        <span>MF 생마르탱</span>
                                        <span>MQ 마르티니크</span>
                                        <span>MS 몬트세랫</span>
                                        <span>SX 신트마르턴</span>
                                        <span>TC 터크스케이커스</span>
                                        <span>VG 영국령버진아일랜드</span>
                                        <span>VI 미국령버진아일랜드</span>
                                        <span>AN 네덜란드령안틸레스</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>남미</strong>
                                        <span>BR 브라질</span>
                                        <span>AR 아르헨티나</span>
                                        <span>CL 칠레</span>
                                        <span>CO 콜롬비아</span>
                                        <span>PE 페루</span>
                                        <span>VE 베네수엘라</span>
                                        <span>EC 에콰도르</span>
                                        <span>BO 볼리비아</span>
                                        <span>PY 파라과이</span>
                                        <span>UY 우루과이</span>
                                        <span>GY 가이아나</span>
                                        <span>SR 수리남</span>
                                        <span>GF 프랑스령기아나</span>
                                        <span>FK 포클랜드제도</span>
                                        <span>GS 사우스조지아</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>북아프리카</strong>
                                        <span>EG 이집트</span>
                                        <span>LY 리비아</span>
                                        <span>TN 튀니지</span>
                                        <span>DZ 알제리</span>
                                        <span>MA 모로코</span>
                                        <span>SD 수단</span>
                                        <span>SS 남수단</span>
                                        <span>EH 서사하라</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>서아프리카</strong>
                                        <span>NG 나이지리아</span>
                                        <span>GH 가나</span>
                                        <span>CI 코트디부아르</span>
                                        <span>SN 세네갈</span>
                                        <span>ML 말리</span>
                                        <span>BF 부르키나파소</span>
                                        <span>NE 니제르</span>
                                        <span>GN 기니</span>
                                        <span>BJ 베냉</span>
                                        <span>TG 토고</span>
                                        <span>SL 시에라리온</span>
                                        <span>LR 라이베리아</span>
                                        <span>MR 모리타니</span>
                                        <span>GM 감비아</span>
                                        <span>GW 기니비사우</span>
                                        <span>CV 카보베르데</span>
                                        <span>SH 세인트헬레나</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>동아프리카</strong>
                                        <span>KE 케냐</span>
                                        <span>ET 에티오피아</span>
                                        <span>TZ 탄자니아</span>
                                        <span>UG 우간다</span>
                                        <span>RW 르완다</span>
                                        <span>BI 부룬디</span>
                                        <span>SO 소말리아</span>
                                        <span>DJ 지부티</span>
                                        <span>ER 에리트레아</span>
                                        <span>MG 마다가스카르</span>
                                        <span>MU 모리셔스</span>
                                        <span>SC 세이셸</span>
                                        <span>KM 코모로</span>
                                        <span>RE 레위니옹</span>
                                        <span>YT 마요트</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>중앙아프리카</strong>
                                        <span>CD 콩고민주공화국</span>
                                        <span>CG 콩고공화국</span>
                                        <span>CM 카메룬</span>
                                        <span>CF 중앙아프리카공화국</span>
                                        <span>TD 차드</span>
                                        <span>GA 가봉</span>
                                        <span>GQ 적도기니</span>
                                        <span>ST 상투메프린시페</span>
                                        <span>AO 앙골라</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>남아프리카</strong>
                                        <span>ZA 남아프리카공화국</span>
                                        <span>ZW 짐바브웨</span>
                                        <span>ZM 잠비아</span>
                                        <span>MW 말라위</span>
                                        <span>MZ 모잠비크</span>
                                        <span>BW 보츠와나</span>
                                        <span>NA 나미비아</span>
                                        <span>SZ 에스와티니</span>
                                        <span>LS 레소토</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>오세아니아</strong>
                                        <span>AU 호주</span>
                                        <span>NZ 뉴질랜드</span>
                                        <span>PG 파푸아뉴기니</span>
                                        <span>FJ 피지</span>
                                        <span>SB 솔로몬제도</span>
                                        <span>VU 바누아투</span>
                                        <span>NC 뉴칼레도니아</span>
                                        <span>PF 프랑스령폴리네시아</span>
                                        <span>WS 사모아</span>
                                        <span>TO 통가</span>
                                        <span>KI 키리바시</span>
                                        <span>FM 미크로네시아</span>
                                        <span>MH 마셜제도</span>
                                        <span>PW 팔라우</span>
                                        <span>NR 나우루</span>
                                        <span>TV 투발루</span>
                                        <span>GU 괌</span>
                                        <span>AS 아메리칸사모아</span>
                                        <span>MP 북마리아나제도</span>
                                        <span>CK 쿡제도</span>
                                        <span>NU 니우에</span>
                                        <span>TK 토켈라우</span>
                                        <span>WF 왈리스푸투나</span>
                                        <span>PN 핏케언제도</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>기타 지역</strong>
                                        <span>AQ 남극</span>
                                        <span>BV 부베섬</span>
                                        <span>CC 코코스제도</span>
                                        <span>CX 크리스마스섬</span>
                                        <span>GG 건지</span>
                                        <span>HM 허드맥도널드제도</span>
                                        <span>IM 맨섬</span>
                                        <span>IO 영국령인도양</span>
                                        <span>JE 저지</span>
                                        <span>NF 노퍽섬</span>
                                        <span>PM 생피에르미클롱</span>
                                        <span>TF 프랑스령남부지역</span>
                                        <span>UM 미국령군소제도</span>
                                    </div>
                                    <div class="country-group">
                                        <strong>특수 코드</strong>
                                        <span>AP 아시아태평양</span>
                                        <span>EU 유럽연합</span>
                                        <span>HW 하와이</span>
                                        <span>YU 구유고슬라비아</span>
                                        <span>ZZ 알수없음</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 브루트포스 방지 -->
                    <div class="security-section">
                        <h4>🔐 브루트포스 방지</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>최대 로그인 시도</label>
                                <input type="number" id="security-max-attempts" min="0" value="5">
                            </div>
                            <div class="form-group">
                                <label>잠금 시간 (분)</label>
                                <input type="number" id="security-lockout-minutes" min="1" value="15">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" id="btn-test-security">🧪 현재 IP 테스트</button>
                    <button class="btn btn-primary" id="btn-save-security">💾 저장</button>
                </div>
            </div>
            
            <!-- 시스템 정보 모달 (관리자) -->
            <div id="modal-system-info" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>📊 시스템 정보</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="system-info-content"></div>
                </div>
            </div>
            
            <!-- 파일 미리보기 모달 -->
            <div id="modal-preview" class="modal modal-preview resizable" style="display:none;">
                <div class="modal-header">
                    <h2 id="preview-title">미리보기</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="preview-content"></div>
                </div>
                <div class="modal-footer preview-footer">
                    <button class="btn" id="btn-preview-download">⬇️ 다운로드</button>
                </div>
                <div class="resize-handle resize-handle-se"></div>
                <div class="resize-handle resize-handle-e"></div>
                <div class="resize-handle resize-handle-s"></div>
            </div>
            
            <!-- 중복 파일 처리 모달 -->
            <div id="modal-duplicate" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>⚠️ 중복 파일 발견</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="duplicate-message">다음 파일이 이미 존재합니다:</p>
                    <div id="duplicate-list" class="duplicate-list"></div>
                    <p class="duplicate-hint">어떻게 처리하시겠습니까?</p>
                </div>
                <div class="modal-footer duplicate-footer">
                    <button class="btn" id="btn-dup-skip-all">건너뛰기</button>
                    <button class="btn btn-warning" id="btn-dup-overwrite-all">덮어쓰기</button>
                    <button class="btn btn-primary" id="btn-dup-rename-all">이름 변경 후 복사</button>
                </div>
            </div>
        </div>
        
        <!-- 컨텍스트 메뉴 -->
        <div id="context-menu" class="context-menu" style="display:none;">
            <ul>
                <!-- 파일/폴더 선택 시 -->
                <li data-action="open">📂 열기</li>
                <li data-action="preview">👁️ 미리보기</li>
                <li data-action="download">⬇️ 다운로드</li>
                <li data-action="save-as">💾 다른 이름으로 저장</li>
                <li data-action="share">🔗 공유</li>
                <li class="divider"></li>
                <li data-action="favorite-add">⭐ 즐겨찾기 추가</li>
                <li data-action="favorite-remove">☆ 즐겨찾기 제거</li>
                <li data-action="file-lock">🔒 잠금</li>
                <li data-action="file-unlock">🔓 잠금 해제</li>
                <li class="divider"></li>
                <li data-action="copy">📋 복사</li>
                <li data-action="move">✂️ 잘라내기</li>
                <li data-action="paste">📥 여기에 붙여넣기</li>
                <li class="divider"></li>
                <li data-action="extract">📦 압축 해제</li>
                <li data-action="compress">🗜️ 압축</li>
                <li class="divider"></li>
                <li data-action="rename">✏️ 이름 변경</li>
                <li data-action="info">ℹ️ 정보</li>
                <li class="divider"></li>
                <li data-action="delete" class="danger">🗑️ 삭제</li>
                <!-- 빈 공간 우클릭 시 -->
                <li data-action="new-folder">📁 새 폴더</li>
                <li data-action="upload-file">📄 파일 업로드</li>
                <li data-action="upload-folder">📂 폴더 업로드</li>
                <li data-action="refresh">🔄 새로고침</li>
            </ul>
        </div>
        
        <!-- 알림 토스트 -->
        <div id="toast" class="toast"></div>
        
        <!-- 숨김 파일 업로드 -->
        <input type="file" id="file-input" multiple style="display:none;">
        <input type="file" id="folder-input" webkitdirectory directory multiple style="display:none;">
    </div>
    
    <!-- CSRF 토큰 -->
    <script>
        window.CSRF_TOKEN = '<?= htmlspecialchars($csrfToken) ?>';
    </script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>