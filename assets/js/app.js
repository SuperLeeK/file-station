/**
 * FileStation - 프론트엔드 애플리케이션
 */

const App = {
    currentStorage: null,
    currentPath: '',
    selectedItems: [],
    viewMode: 'grid',
    user: null,
    homeStorageId: null,
    sortBy: 'name',
    sortOrder: 'asc',
    draggedItems: [],
    loadFilesController: null,
    storages: [],
    isSearchMode: false,
    searchQuery: '',
    currentPermissions: {
        can_read: 1,
        can_download: 1,
        can_write: 1,
        can_delete: 1,
        can_share: 1
    },
    systemSettings: {
        home_share_enabled: true
    },
    // 서버 설정 (청크 크기 등)
    serverConfig: {
        maxChunkSize: 10 * 1024 * 1024  // 기본값 10MB
    },
    // 전송 상태
    transfer: {
        type: '',           // 'upload', 'download', 'copy', 'move'
        startTime: 0,
        lastTime: 0,
        lastBytes: 0,
        speed: 0,
        cancelled: false,
        totalFiles: 0,
        completedFiles: 0,
        currentFile: '',
        totalSize: 0,
        transferredSize: 0
    },
    // 클립보드 (복사/잘라내기)
    clipboard: {
        items: [],
        mode: null,  // 'copy' or 'cut'
        storageId: null
    },
    
    init() {
        this.bindEvents();
        this.initTheme();
        this.loadServerConfig();  // 서버 설정 로드
        this.checkAuth();
        
        // 브라우저 뒤로가기/앞으로가기 처리
        window.addEventListener('popstate', (e) => {
            if (e.state) {
                const { storageId, path } = e.state;
                if (storageId) {
                    // 스토리지가 다르면 스토리지 UI도 업데이트
                    if (storageId !== this.currentStorage) {
                        this.currentStorage = storageId;
                        $('#storage-list a').removeClass('active');
                        $(`#storage-list a[data-id="${storageId}"]`).addClass('active');
                    }
                    this.currentPath = path || '';
                    this.loadFiles(false); // 히스토리 추가 안 함
                }
            }
        });
    },
    
    // CSRF 토큰 관리
    csrfToken: window.CSRF_TOKEN || '',
    
    // CSRF 토큰 갱신
    async refreshCsrfToken() {
        try {
            const res = await fetch('api.php?action=csrf_token');
            const json = await res.json();
            if (json.success && json.token) {
                this.csrfToken = json.token;
                window.CSRF_TOKEN = json.token;
                return true;
            }
        } catch (e) {
            console.error('CSRF token refresh failed:', e);
        }
        return false;
    },
    
    // API 호출
    async api(action, data = {}, method = 'POST', signal = null, _retryCount = 0) {
        const isGet = method === 'GET';
        let url = `api.php?action=${action}`;
        
        if (isGet && Object.keys(data).length) {
            url += '&' + new URLSearchParams(data).toString();
        }
        
        const options = {
            method,
            credentials: 'same-origin'
        };
        
        if (signal) {
            options.signal = signal;
        }
        
        // POST 요청에 CSRF 토큰 추가
        if (!isGet) {
            if (!(data instanceof FormData)) {
                options.headers = { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                };
                options.body = JSON.stringify(data);
            } else {
                // FormData에 CSRF 토큰 추가
                data.append('csrf_token', this.csrfToken);
                options.body = data;
            }
        }
        
        try {
            const res = await fetch(url, options);
            const json = await res.json();
            
            // CSRF 토큰 오류 시 토큰 갱신 후 재시도 (최대 1회)
            if (json.csrf_error && _retryCount < 1) {
                const refreshed = await this.refreshCsrfToken();
                // 재시도 (FormData가 아닌 경우만, 갱신 성공 시에만)
                if (refreshed && !(data instanceof FormData)) {
                    return this.api(action, data, method, signal, _retryCount + 1);
                }
                return json;
            }
            
            if (res.status === 401) {
                this.showLogin();
            }
            
            return json;
        } catch (e) {
            // AbortError는 무시 (요청 취소)
            if (e.name === 'AbortError') {
                return null;
            }
            console.error('API Error:', e);
            return { error: '서버 연결 실패' };
        }
    },
    
    // 이벤트 바인딩
    bindEvents() {
        // ===== 모바일 메뉴 =====
        // 사이드바 토글
        $('#mobile-menu-btn').on('click', () => {
            $('.sidebar').toggleClass('open');
            this.toggleSidebarOverlay();
        });
        
        // 오버레이 클릭 시 사이드바 닫기
        $(document).on('click', '.sidebar-overlay', () => {
            $('.sidebar').removeClass('open');
            this.toggleSidebarOverlay();
        });
        
        // 모바일 검색 버튼
        $('#mobile-search-btn').on('click', () => {
            $('.mobile-search-bar').addClass('active');
            $('#mobile-search-input').focus();
        });
        
        // 모바일 검색 닫기
        $('#mobile-search-close').on('click', () => {
            $('.mobile-search-bar').removeClass('active');
        });
        
        // 모바일 검색 실행
        $('#mobile-search-submit').on('click', () => {
            const query = $('#mobile-search-input').val();
            $('#search-input').val(query);
            this.doSearch();
            $('.mobile-search-bar').removeClass('active');
        });
        
        $('#mobile-search-input').on('keypress', (e) => {
            if (e.key === 'Enter') {
                const query = $('#mobile-search-input').val();
                $('#search-input').val(query);
                this.doSearch();
                $('.mobile-search-bar').removeClass('active');
            }
        });
        
        // 사이드바 메뉴 클릭 시 모바일에서 자동 닫기
        $('.sidebar').on('click', 'a, .storage-item', () => {
            if (window.innerWidth <= 768) {
                setTimeout(() => {
                    $('.sidebar').removeClass('open');
                    this.toggleSidebarOverlay();
                }, 100);
            }
        });
        
        // 화면 크기 변경 시 사이드바 상태 초기화
        $(window).on('resize', () => {
            if (window.innerWidth > 768) {
                $('.sidebar').removeClass('open');
                $('.sidebar-overlay').removeClass('active');
            }
        });
        
        // ===== 기존 이벤트 =====
        // 로그인
        $('#login-form').on('submit', e => {
            e.preventDefault();
            this.login();
        });
        
        // 2FA 폼 제출
        $('#twofa-form').on('submit', e => {
            e.preventDefault();
            this.verify2FA();
        });
        
        // 2FA 취소
        $('#btn-twofa-back').on('click', () => {
            this.cancel2FA();
        });
        
        // OTP ↔ 백업 코드 전환
        $('#show-backup-code').on('click', e => {
            e.preventDefault();
            $('#twofa-otp-section').hide();
            $('#twofa-backup-section').show();
            $('#twofa-backup-code').val('').focus();
            $('#login-error').text('');
        });
        
        $('#show-otp-code').on('click', e => {
            e.preventDefault();
            $('#twofa-backup-section').hide();
            $('#twofa-otp-section').show();
            $('#twofa-code').val('').focus();
            $('#login-error').text('');
        });
        
        // 회원가입 폼 전환
        $('#show-signup').on('click', e => {
            e.preventDefault();
            $('#login-box').hide();
            $('#signup-box').show();
            $('#signup-username').focus();
        });
        
        $('#show-login').on('click', e => {
            e.preventDefault();
            $('#signup-box').hide();
            $('#login-box').show();
            $('#login-username').focus();
        });
        
        // 회원가입 폼 제출
        $('#signup-form').on('submit', e => {
            e.preventDefault();
            this.signup();
        });
        
        // 로그아웃
        $('#btn-logout').on('click', () => this.logout());
        
        // 관리 메뉴 토글
        $('.section-toggle').on('click', function() {
            const targetId = $(this).data('target');
            const $target = $('#' + targetId);
            const $icon = $(this).find('.toggle-icon');
            
            if ($target.is(':visible')) {
                $target.hide();
                $icon.text('+');
            } else {
                $target.show();
                $icon.text('−');
            }
        });
        
        // 전송 취소 버튼
        document.getElementById('transfer-cancel')?.addEventListener('click', () => {
            this.transfer.cancelled = true;
        });
        
        // 붙여넣기 버튼
        document.getElementById('btn-paste')?.addEventListener('click', () => {
            
            
            this.clipboardPaste();
        });
        
        // 키보드 단축키 (Ctrl+C, Ctrl+X, Ctrl+V)
        document.addEventListener('keydown', (e) => {
            // 입력 필드에서는 무시
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            if (!this.user) return;
            
            if (e.ctrlKey || e.metaKey) {
                if (e.key === 'c' && this.selectedItems.length > 0) {
                    e.preventDefault();
                    this.clipboardCopy();
                } else if (e.key === 'x' && this.selectedItems.length > 0) {
                    e.preventDefault();
                    this.clipboardCut();
                } else if (e.key === 'v' && this.clipboard.items.length > 0) {
                    e.preventDefault();
                    this.clipboardPaste();
                }
            }
        });
        
        // 로고 클릭 (홈으로)
        $('.logo').on('click', () => this.goHome());
        
        // 설정
        $('#btn-settings').on('click', () => this.showSettingsModal());
        $('#btn-save-settings').on('click', () => this.saveSettings());
        $('#btn-change-password').on('click', () => this.changePassword());
        
        // 2FA 이벤트 핸들러
        $('#btn-twofa-setup').on('click', () => this.setup2FA());
        $('#btn-twofa-verify').on('click', () => this.enable2FA());
        $('#btn-twofa-cancel').on('click', () => this.load2FAStatus());
        $('#btn-twofa-disable').on('click', () => this.disable2FA());
        $('#btn-twofa-regenerate-backup').on('click', () => this.regenerateBackupCodes());
        $('#btn-twofa-backup-done').on('click', () => this.load2FAStatus());
        
        $('#btn-save-system-settings').on('click', () => this.saveSystemSettings());
        
        // 회원가입 허용 체크 시 자동 승인 옵션 표시
        $('#setting-signup-enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#auto-approve-wrap').show();
            } else {
                $('#auto-approve-wrap').hide();
            }
        });
        
        // 사이트 이미지 업로드
        $('#logo-upload').on('change', (e) => {
            if (e.target.files[0]) {
                this.uploadSiteImage('logo', e.target.files[0]);
                e.target.value = '';
            }
        });
        $('#bg-upload').on('change', (e) => {
            if (e.target.files[0]) {
                this.uploadSiteImage('bg', e.target.files[0]);
                e.target.value = '';
            }
        });
        $('#btn-logo-delete').on('click', () => this.deleteSiteImage('logo'));
        $('#btn-bg-delete').on('click', () => this.deleteSiteImage('bg'));
        
        // 스토리지 선택
        $('#storage-list').on('click', 'a', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            App.selectStorage(id);
        });
        
        // 브레드크럼 클릭 (이벤트 위임)
        $('#breadcrumb').on('click', 'a', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const path = $(this).data('path') || '';
            App.navigate(path);
        });
        
        // 모바일 감지 (화면 크기 또는 터치 지원)
        const isMobile = () => window.innerWidth <= 768 || 'ontouchstart' in window;
        
        // 파일 클릭 (선택) - 모바일에서는 선택된 항목 다시 탭하면 열기
        $('#file-list').on('click', '.file-item', function(e) {
            // 체크박스 클릭은 별도 처리
            if (e.target.classList.contains('item-checkbox') || 
                e.target.closest('.file-checkbox')) {
                return;
            }
            
            const item = $(this);
            
            if (e.ctrlKey || e.metaKey) {
                // Ctrl+클릭: 다중 선택 토글
                item.toggleClass('selected');
                App.updateSelection();
            } else if (isMobile()) {
                // 모바일: 이미 선택된 항목 탭하면 열기
                if (item.hasClass('selected')) {
                    const isDir = item.data('is-dir');
                    const path = item.data('path');
                    const name = item.data('name');
                    
                    if (isDir) {
                        App.navigate(path);
                    } else {
                        // 검색 결과 아이템이면 해당 위치로 이동
                        if (item.hasClass('search-result-item')) {
                            const storageId = item.data('storage-id');
                            App.navigateToSearchResult({
                                storage_id: storageId,
                                path: path,
                                name: name,
                                is_dir: false
                            });
                        } else {
                            const fileItem = { path, name, isDir: false };
                            if (App.getFileType(name)) {
                                App.showPreview(fileItem);
                            } else {
                                App.downloadFile(path);
                            }
                        }
                    }
                } else {
                    // 선택 안 된 항목: 선택
                    $('.file-item').removeClass('selected');
                    item.addClass('selected');
                    App.updateSelection();
                }
            } else {
                // PC: 일반 클릭은 선택만
                App.handleFileClick(item);
            }
        });
        
        // 파일 체크박스 변경
        $('#file-list').on('change', '.file-checkbox', function(e) {
            e.stopPropagation();
            App.updateCheckboxSelection();
        });
        
        // 전체 선택 체크박스
        document.getElementById('select-all').addEventListener('change', function() {
            const isChecked = this.checked;
            document.querySelectorAll('.file-checkbox').forEach(function(cb) {
                cb.checked = isChecked;
            });
            App.updateCheckboxSelection();
        });
        
        // 선택 삭제 버튼
        $('#btn-delete-selected').on('click', () => this.deleteCheckedFiles());
        
        // 파일/폴더 더블클릭 (폴더 열기, 파일 미리보기/다운로드)
        $('#file-list').on('dblclick', '.file-item', function(e) {
            // 검색 결과 아이템은 별도 핸들러에서 처리
            if ($(this).hasClass('search-result-item')) {
                return;
            }
            
            const item = $(this);
            const isDir = item.data('is-dir');
            const path = item.data('path');
            const name = item.data('name');
            
            if (isDir) {
                // 폴더 더블클릭: 열기
                App.navigate(path);
            } else {
                const fileItem = {
                    path: path,
                    name: name,
                    isDir: false
                };
                // 미리보기 지원 파일이면 미리보기, 아니면 다운로드
                if (App.getFileType(name)) {
                    App.showPreview(fileItem);
                } else {
                    App.downloadFile(path);
                }
            }
        });
        
        // 컨텍스트 메뉴 (파일/폴더 선택 시)
        $('#file-list').on('contextmenu', '.file-item', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const item = $(this);
            if (!item.hasClass('selected')) {
                $('.file-item').removeClass('selected');
                item.addClass('selected');
                // 검색 모드면 검색용 선택 업데이트
                if (App.isSearchMode) {
                    App.updateSearchSelection();
                } else {
                    App.updateSelection();
                }
            }
            App.showContextMenu(e.pageX, e.pageY, false);
            return false;
        });
        
        // 모바일 길게 누르기 (터치)로 컨텍스트 메뉴 열기
        let longPressTimer = null;
        let longPressPos = { x: 0, y: 0 };
        
        // touchstart
        $('#file-list').on('touchstart', '.file-item', function(e) {
            const item = $(this);
            
            // 기존 타이머 클리어
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
            
            // 터치 좌표 저장
            const touch = e.originalEvent?.touches?.[0];
            if (touch) {
                longPressPos = { x: touch.pageX, y: touch.pageY };
            }
            
            // 1000ms(1초) 후 컨텍스트 메뉴 표시
            longPressTimer = setTimeout(() => {
                longPressTimer = null;
                
                // 선택
                $('.file-item').removeClass('selected');
                item.addClass('selected');
                App.updateSelection();
                
                // 컨텍스트 메뉴 표시
                App.showContextMenu(longPressPos.x, longPressPos.y, false);
            }, 1000);
        });
        
        // touchend - 타이머 취소 (짧은 탭)
        $('#file-list').on('touchend', '.file-item', function(e) {
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
        });
        
        // touchcancel - 타이머 취소
        $('#file-list').on('touchcancel', '.file-item', function(e) {
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
        });
        
        // touchmove - 10px 이상 이동시 취소
        $('#file-list').on('touchmove', '.file-item', function(e) {
            if (longPressTimer) {
                const touch = e.originalEvent?.touches?.[0];
                if (touch) {
                    const dx = Math.abs(touch.pageX - longPressPos.x);
                    const dy = Math.abs(touch.pageY - longPressPos.y);
                    if (dx > 10 || dy > 10) {
                        clearTimeout(longPressTimer);
                        longPressTimer = null;
                    }
                }
            }
        });
        
        // 빈 공간 우클릭 (붙여넣기만)
        $('#file-list').on('contextmenu', function(e) {
            // 파일 아이템이 아닌 빈 공간에서만
            if (!e.target.closest('.file-item') && App.currentStorage) {
                e.preventDefault();
                $('.file-item').removeClass('selected');
                App.selectedItems = [];
                App.showContextMenu(e.pageX, e.pageY, true);
            }
        });
        
        // 컨텍스트 메뉴 항목 - 바닐라 JS로 직접 바인딩
        document.getElementById('context-menu').addEventListener('click', function(e) {
            e.stopPropagation();
            const li = e.target.closest('li');
            if (li) {
                const action = li.dataset.action;
                
                
                
                if (action) {
                    App.handleContextAction(action);
                }
                App.hideContextMenu();
            }
        });
        
        // 클릭하면 컨텍스트 메뉴 닫기
        $(document).on('click', (e) => {
            if (!e.target.closest('#context-menu')) {
                this.hideContextMenu();
            }
        });
        
        // 뷰 모드 전환
        $('#btn-view-grid').on('click', () => this.setViewMode('grid'));
        $('#btn-view-list').on('click', () => this.setViewMode('list'));
        
        // 뒤로가기
        $('#btn-back').on('click', () => this.goBack());
        
        // 업로드 드롭다운 (바닐라 JS)
        const uploadBtn = document.getElementById('btn-upload');
        const uploadMenu = document.getElementById('upload-menu');
        
        if (uploadBtn && uploadMenu) {
            uploadBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isVisible = uploadMenu.style.display === 'block';
                uploadMenu.style.display = isVisible ? 'none' : 'block';
            });
        }
        
        // 업로드 옵션 클릭
        document.querySelectorAll('.upload-option').forEach(function(opt) {
            opt.addEventListener('click', function(e) {
                e.stopPropagation();
                const type = this.getAttribute('data-type');
                uploadMenu.style.display = 'none';
                if (type === 'file') {
                    document.getElementById('file-input').click();
                } else if (type === 'folder') {
                    document.getElementById('folder-input').click();
                }
            });
        });
        
        // 외부 클릭 시 업로드 메뉴 닫기
        document.addEventListener('click', function() {
            if (uploadMenu) uploadMenu.style.display = 'none';
        });
        
        $('#file-input').on('change', e => this.handleUpload(e.target.files));
        $('#folder-input').on('change', e => this.handleFolderUpload(e.target.files));
        
        // 새 폴더
        $('#btn-new-folder').on('click', () => this.showModal('modal-new-folder'));
        $('#btn-create-folder').on('click', () => this.createFolder());
        
        // 통합 검색
        $('#search-btn').on('click', () => this.doSearch());
        $('#search-input').on('keypress', e => {
            if (e.key === 'Enter') this.doSearch();
        });
        
        // 검색 필터 토글
        $('#search-filter-toggle').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const filters = $('#search-filters');
            const btn = $(this);
            if (filters.is(':visible')) {
                filters.hide();
                btn.removeClass('active');
            } else {
                filters.show();
                btn.addClass('active');
            }
        });
        
        // 필터 적용/초기화
        $('#btn-apply-filter').on('click', () => this.doSearch(1));
        $('#btn-reset-filter').on('click', () => this.resetSearchFilters());
        
        // 검색 종료
        $('#btn-exit-search').on('click', () => this.exitSearchMode());
        
        // 관리 메뉴
        $('#menu-storages').on('click', e => {
            e.preventDefault();
            this.showStoragesModal();
        });
        
        $('#btn-add-storage-new').on('click', () => this.showStorageModal());
        $('#btn-apply-bulk-perm').on('click', () => this.applyBulkPermission());
        
        $('#menu-users').on('click', e => {
            e.preventDefault();
            this.showUsersModal();
        });
        
        // 사용자 관리에서 설정 변경 링크 클릭
        $(document).on('click', '#link-change-settings', (e) => {
            e.preventDefault();
            closeModal();
            this.showSystemSettingsModal();
        });
        
        $('#menu-shares').on('click', e => {
            e.preventDefault();
            this.showSharesModal();
        });
        
        $('#menu-all-logins').on('click', e => {
            e.preventDefault();
            this.showAllLoginsModal();
        });
        
        // 로그인 기록 삭제 이벤트
        $('#btn-log-delete-selected').on('click', () => this.deleteSelectedLogs());
        $('#btn-log-delete-all').on('click', () => this.deleteAllLogs());
        $('#btn-log-delete-old').on('click', () => this.deleteOldLogs());
        $('#log-select-all').on('change', function() {
            $('.log-checkbox').prop('checked', $(this).is(':checked'));
        });
        
        // 페이지네이션 클릭
        $(document).on('click', '.page-link', e => {
            e.preventDefault();
            const page = $(e.target).data('page');
            const callback = $(e.target).data('callback');
            if (callback && this[callback]) {
                this[callback](page);
            }
        });
        
        $('#menu-trash').on('click', e => {
            e.preventDefault();
            this.showTrashModal();
        });
        
        $('#menu-bulk-delete').on('click', e => {
            e.preventDefault();
            this.showBulkDeleteModal();
        });
        
        $('#btn-bulk-delete-search').on('click', () => this.bulkDeleteSearch());
        $('#btn-bulk-delete-execute').on('click', () => this.bulkDeleteExecute());
        
        // 활동 로그
        $('#menu-activity-logs').on('click', e => {
            e.preventDefault();
            this.showActivityLogsModal();
        });
        
        $('#btn-activity-search').on('click', () => this.loadActivityLogs());
        $('#btn-activity-reset').on('click', () => this.resetActivityFilters());
        $('#btn-activity-clear').on('click', () => this.clearActivityLogs());
        
        // 검색 인덱스
        $('#menu-search-index').on('click', e => {
            e.preventDefault();
            this.showSearchIndexModal();
        });
        
        $('#btn-rebuild-index').on('click', () => this.rebuildSearchIndex());
        $('#btn-clear-index').on('click', () => this.clearSearchIndex());
        
        // 자동 갱신 활성화 링크 클릭
        $(document).on('click', '#link-enable-auto-index', (e) => {
            e.preventDefault();
            closeModal();
            this.showSystemSettingsModal();
        });
        
        $('#menu-security').on('click', e => {
            e.preventDefault();
            this.showSecurityModal();
        });
        
        $('#btn-save-security').on('click', () => this.saveSecuritySettings());
        $('#btn-test-security').on('click', () => this.testSecuritySettings());
        
        // 국가 코드 목록 토글
        $('#toggle-country-codes').on('click', function(e) {
            e.preventDefault();
            const $list = $('#country-codes-list');
            if ($list.is(':visible')) {
                $list.hide();
                $(this).text('📋 국가 코드 목록 보기');
            } else {
                $list.show();
                $(this).text('📋 국가 코드 목록 숨기기');
            }
        });
        
        $('#menu-system-settings').on('click', e => {
            e.preventDefault();
            this.showSystemSettingsModal();
        });
        
        $('#menu-system-info').on('click', e => {
            e.preventDefault();
            this.showSystemInfoModal();
        });
        
        // 휴지통 비우기
        $('#btn-trash-empty').on('click', () => this.emptyTrash(true));
        $('#btn-my-trash-empty').on('click', () => this.emptyTrash(false));
        
        // 내 휴지통
        $('#menu-my-trash').on('click', e => {
            e.preventDefault();
            this.showMyTrashModal();
        });
        
        // 스토리지 추가 - 타입 변경
        $('#storage-type').on('change', e => {
            const type = e.target.value;
            // 모든 옵션 숨기기
            $('.storage-options').hide();
            // 선택된 타입의 옵션만 표시
            $(`#storage-${type}-options`).show();
        });
        
        // SFTP 인증 방식 변경
        $('#sftp-auth-type').on('change', e => {
            const authType = e.target.value;
            $('#sftp-password-group').toggle(authType === 'password');
            $('#sftp-key-group').toggle(authType === 'key');
        });
        
        $('#btn-save-storage').on('click', () => this.saveStorage());
        
        // 사용량 계산 체크박스
        $('#storage-calc-usage').on('change', function() {
            $('#calc-usage-warning').toggle($(this).is(':checked'));
        });
        
        // 공유
        $('#btn-create-share').on('click', () => this.createShare());
        $('#btn-copy-url').on('click', () => this.copyShareUrl());
        
        // 이름 변경
        $('#btn-rename-confirm').on('click', () => this.renameFile());
        
        // 사용자 관리
        $('#btn-add-user').on('click', () => this.showUserForm());
        $('#btn-save-user').on('click', () => this.saveUser());
        $('#btn-bulk-quota').on('click', () => this.showBulkQuotaModal());
        $('#btn-apply-bulk-quota').on('click', () => this.applyBulkQuota());
        
        // 역할 변경 시 UI 처리
        $('#user-role').on('change', function() {
            App.handleRoleChange($(this).val());
        });
        
        // 상태 변경 시 UI 처리
        $('#user-status').on('change', function() {
            App.handleStatusChange($(this).val());
        });
        
        // 역할 관리
        $('#menu-roles').on('click', e => {
            e.preventDefault();
            this.showRolesModal();
        });
        $('#btn-add-role').on('click', () => this.addRole());
        
        // QoS 속도 제한
        $('#menu-qos').on('click', e => {
            e.preventDefault();
            this.showQosModal();
        });
        $('#btn-save-qos').on('click', () => this.saveQosSettings());
        
        // QoS 탭 전환
        $(document).on('click', '.qos-tab-btn', function() {
            const tabId = $(this).data('tab');
            $('.qos-tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.qos-tab-content').hide();
            $('#' + tabId).show();
        });
        
        // QoS 사용자 검색
        $('#qos-user-search').on('input', (e) => {
            this.filterQosUsers(e.target.value);
        });
        
        // 권한
        $('#btn-add-perm').on('click', () => this.addPermission());
        
        // 모달 닫기 (X 버튼 클릭 시에만)
        $('.modal-close').on('click', () => closeModal());
        
        // 드래그 앤 드롭
        const fileArea = $('.file-area')[0];
        if (fileArea) {
            fileArea.addEventListener('dragover', e => {
                e.preventDefault();
                $('.file-area').addClass('dragover');
            });
            
            fileArea.addEventListener('dragleave', e => {
                e.preventDefault();
                $('.file-area').removeClass('dragover');
            });
            
            fileArea.addEventListener('drop', async e => {
                e.preventDefault();
                $('.file-area').removeClass('dragover');
                
                // 폴더 드래그 앤 드롭 지원
                const items = e.dataTransfer.items;
                if (items && items.length > 0) {
                    const files = [];
                    const entries = [];
                    
                    // 모든 항목의 entry 가져오기
                    for (let i = 0; i < items.length; i++) {
                        const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
                        if (entry) {
                            entries.push(entry);
                        }
                    }
                    
                    // entry가 있으면 (폴더 포함 가능)
                    if (entries.length > 0) {
                        await this.handleDropEntries(entries);
                    } else if (e.dataTransfer.files.length) {
                        // 일반 파일만 있는 경우
                        this.handleUpload(e.dataTransfer.files);
                    }
                } else if (e.dataTransfer.files.length) {
                    this.handleUpload(e.dataTransfer.files);
                }
            });
        }
        
        // 키보드 단축키
        $(document).on('keydown', e => {
            // 입력 필드에서는 키보드 네비게이션 비활성화
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
                return;
            }
            
            // 모달이 열려있으면 일부 키만 처리
            const visibleModals = document.querySelectorAll('.modal');
            const hasVisibleModal = Array.from(visibleModals).some(m => {
                const style = window.getComputedStyle(m);
                return style.display !== 'none' && style.visibility !== 'hidden';
            });
            
            if (hasVisibleModal) {
                if (e.key === 'Escape') {
                    this.hideContextMenu();
                }
                return;
            }
            
            // 파일 목록이 없으면 무시
            const fileItems = $('#file-list .file-item');
            if (fileItems.length === 0) return;
            
            switch (e.key) {
                case 'Delete':
                    if (this.selectedItems.length) {
                        this.deleteSelected();
                    }
                    break;
                    
                case 'F2':
                    if (this.selectedItems.length === 1) {
                        this.showRenameModal();
                    }
                    break;
                    
                case 'Escape':
                    this.hideContextMenu();
                    // 선택 해제
                    $('.file-item').removeClass('selected');
                    this.selectedItems = [];
                    this.updateSelectionUI();
                    break;
                    
                case 'ArrowUp':
                case 'ArrowDown':
                case 'ArrowLeft':
                case 'ArrowRight':
                    e.preventDefault();
                    this.navigateWithArrow(e.key, e.shiftKey);
                    break;
                    
                case 'Enter':
                    e.preventDefault();
                    this.openSelectedItem();
                    break;
                    
                case ' ': // Space
                    e.preventDefault();
                    this.toggleCurrentSelection();
                    break;
                    
                case 'Home':
                    e.preventDefault();
                    this.selectFirstItem(e.shiftKey);
                    break;
                    
                case 'End':
                    e.preventDefault();
                    this.selectLastItem(e.shiftKey);
                    break;
                    
                case 'Backspace':
                    e.preventDefault();
                    this.goUp();
                    break;
                    
                case 'a':
                case 'A':
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        this.selectAllFiles();
                    }
                    break;
            }
        });
        
        // 파일 목록에 포커스 유지를 위한 tabindex 설정
        $('#file-list').attr('tabindex', '0');
        
        // 파일 목록 빈 공간 클릭 시에만 포커스 (file-item 클릭은 제외)
        document.getElementById('file-list')?.addEventListener('click', function(e) {
            // file-item이나 그 자식 요소 클릭이 아닐 때만 포커스
            if (!e.target.closest('.file-item')) {
                this.focus();
            }
        });
        
        // 정렬 드롭다운 (Vanilla JS)
        document.addEventListener('click', function(e) {
            var sortBtn = document.getElementById('btn-sort');
            var sortMenu = document.getElementById('sort-menu');
            
            // 정렬 버튼 클릭
            if (e.target === sortBtn || e.target.parentNode === sortBtn) {
                e.preventDefault();
                e.stopPropagation();
                if (sortMenu.style.display === 'block') {
                    sortMenu.style.display = 'none';
                } else {
                    sortMenu.style.display = 'block';
                }
                return;
            }
            
            // 정렬 옵션 클릭
            if (e.target.classList.contains('sort-option')) {
                e.preventDefault();
                e.stopPropagation();
                var sort = e.target.getAttribute('data-sort');
                var order = e.target.getAttribute('data-order');
                App.setSort(sort, order);
                sortMenu.style.display = 'none';
                return;
            }
            
            // 외부 클릭 시 닫기
            if (sortMenu && !sortMenu.contains(e.target)) {
                sortMenu.style.display = 'none';
            }
        });
        
        // 설정 탭 전환
        $('.tab-btn').on('click', function() {
            const tabId = $(this).data('tab');
            $('.tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.tab-content').hide();
            $('#' + tabId).show();
            
            // 탭 전환 시 데이터 로드
            if (tabId === 'tab-sessions') {
                App.loadSessions();
            } else if (tabId === 'tab-login-logs') {
                App.loadLoginLogs();
            } else if (tabId === 'tab-theme') {
                // 현재 테마 표시
                $('.theme-item').removeClass('active');
                $(`.theme-item[data-theme="${App.currentTheme}"]`).addClass('active');
            }
        });
        
        // 테마 선택
        $('.theme-item').on('click', function() {
            const theme = $(this).data('theme');
            App.setTheme(theme);
        });
        
        // 미리보기 다운로드
        $('#btn-preview-download').on('click', () => {
            if (this.currentPreviewPath) {
                this.downloadFile(this.currentPreviewPath, true, true);  // 강제 진행률 표시
            }
        });
        
        // 모든 기기 로그아웃
        $('#btn-terminate-all').on('click', () => this.terminateAllSessions());
    },
    
    // 인증 확인
    async checkAuth() {
        const res = await this.api('me', {}, 'GET');
        if (res.success) {
            this.user = res.user;
            this.showMain();
        } else {
            this.showLogin();
        }
    },
    
    // 로그인
    async login() {
        const username = $('#login-username').val();
        const password = $('#login-password').val();
        const remember = $('#login-remember').prop('checked') || false;
        
        const res = await this.api('login', { username, password, remember });
        
        if (res.success) {
            // 2FA 필요한 경우
            if (res['2fa_required']) {
                $('#login-form').hide();
                $('#twofa-form').show();
                $('#twofa-otp-section').show();
                $('#twofa-backup-section').hide();
                $('#twofa-code').val('').focus();
                $('#twofa-backup-code').val('');
                $('#login-error').text('');
                return;
            }
            
            // CSRF 토큰 업데이트 (로그인 후 새 토큰)
            if (res.csrf_token) {
                this.csrfToken = res.csrf_token;
                window.CSRF_TOKEN = res.csrf_token;
            }
            
            this.user = res.user;
            this.showMain();
        } else {
            $('#login-error').text(res.error);
        }
    },
    
    // 2FA 검증
    async verify2FA() {
        // OTP 섹션이 보이면 OTP, 아니면 백업 코드
        const isOtpMode = $('#twofa-otp-section').is(':visible');
        const code = isOtpMode 
            ? $('#twofa-code').val().trim() 
            : $('#twofa-backup-code').val().trim();
        
        if (!code) {
            $('#login-error').text(isOtpMode ? '인증 코드를 입력하세요.' : '백업 코드를 입력하세요.');
            return;
        }
        
        const res = await this.api('2fa_verify', { code });
        
        if (res.success) {
            // CSRF 토큰 업데이트
            if (res.csrf_token) {
                this.csrfToken = res.csrf_token;
                window.CSRF_TOKEN = res.csrf_token;
            }
            
            // 백업 코드 사용 시 알림
            if (res.used_backup) {
                this.toast('백업 코드로 로그인했습니다. 남은 백업 코드를 확인하세요.', 'warning');
            }
            
            this.user = res.user;
            this.showMain();
        } else {
            $('#login-error').text(res.error);
        }
    },
    
    // 2FA 입력 취소
    cancel2FA() {
        $('#twofa-form').hide();
        $('#login-form').show();
        $('#twofa-code').val('');
        $('#twofa-backup-code').val('');
        $('#twofa-otp-section').show();
        $('#twofa-backup-section').hide();
        $('#login-error').text('');
        $('#login-password').val('').focus();
    },
    
    // 회원가입
    async signup() {
        const username = $('#signup-username').val().trim();
        const password = $('#signup-password').val();
        const password2 = $('#signup-password2').val();
        const displayName = $('#signup-displayname').val().trim();
        const email = $('#signup-email').val().trim();
        
        // 유효성 검사
        if (!username || !password) {
            $('#signup-error').text('아이디와 비밀번호를 입력하세요.');
            return;
        }
        
        if (password !== password2) {
            $('#signup-error').text('비밀번호가 일치하지 않습니다.');
            return;
        }
        
        const res = await this.api('signup', {
            username,
            password,
            display_name: displayName,
            email
        });
        
        if (res.success) {
            alert(res.message || '가입 신청이 완료되었습니다.');
            // 로그인 화면으로 전환
            $('#signup-box').hide();
            $('#login-box').show();
            $('#signup-form')[0].reset();
            $('#signup-error').text('');
            $('#login-username').val(username).focus();
        } else {
            $('#signup-error').text(res.error);
        }
    },
    
    // 로그아웃
   async logout() {
        await this.api('logout');
        this.user = null;
        
        // 검색어 초기화
        sessionStorage.removeItem('webhard_search');
        $('#search-input').val('');
        $('#mobile-search-input').val('');
        this.isSearchMode = false;
        this.searchQuery = '';
        
        // 스토리지/파일 목록 초기화
        this.currentStorage = null;
        this.currentPath = '';
        this.storages = [];
        $('#file-list').empty();
        $('#storage-list').empty();
        
        // 승인 대기 알림 제거
        const pendingNotif = document.querySelector('.pending-notification');
        if (pendingNotif) pendingNotif.remove();
        
        this.showLogin();
    },
    
    // 화면 전환
    async showLogin() {
        // 클래스 기반 화면 전환
        document.getElementById('login-screen').classList.add('active');
        document.getElementById('login-screen').classList.remove('hidden');
        document.getElementById('main-screen').classList.add('hidden');
        document.getElementById('main-screen').classList.remove('active');
        
        $('#login-username').val('').focus();
        $('#login-password').val('');
        $('#login-error').text('');
        
        // 2FA 폼 초기화
        $('#twofa-form').hide();
        $('#login-form').show();
        $('#twofa-code').val('');
        $('#twofa-backup-code').val('');
        $('#twofa-otp-section').show();
        $('#twofa-backup-section').hide();
        
        // 승인 대기 알림 제거
        const pendingNotif = document.querySelector('.pending-notification');
        if (pendingNotif) pendingNotif.remove();
        
        // 회원가입 박스 숨기고 로그인 박스 표시
        $('#signup-box').hide();
        $('#login-box').show();
        
        // 회원가입 설정 확인
        const res = await this.api('signup_status', {}, 'GET');
        if (res.success && res.signup_enabled) {
            $('#signup-link-wrap').show();
            
            // 첫 번째 사용자 안내
            if (res.is_first_user) {
                $('#first-user-notice').show();
            } else {
                $('#first-user-notice').hide();
            }
        } else {
            $('#signup-link-wrap').hide();
            $('#first-user-notice').hide();
        }
    },
    
    showMain() {
        // 클래스 기반 화면 전환
        document.getElementById('login-screen').classList.add('hidden');
        document.getElementById('login-screen').classList.remove('active');
        document.getElementById('main-screen').classList.add('active');
        document.getElementById('main-screen').classList.remove('hidden');
        
        $('#user-name').text(this.user.display_name || this.user.username);
        
        // 검색창 초기화 (브라우저 자동완성 강력 방지)
        const clearSearch = () => {
            const savedSearch = sessionStorage.getItem('webhard_search');
            if (!savedSearch) {
                $('#search-input').val('');
                $('#mobile-search-input').val('');
            }
        };
        clearSearch();
        setTimeout(clearSearch, 50);
        setTimeout(clearSearch, 100);
        setTimeout(clearSearch, 200);
        
        // 저장된 뷰 모드 불러오기
        const savedViewMode = localStorage.getItem('filestation_viewMode') || 'grid';
        this.viewMode = savedViewMode;
        $('#file-list').removeClass('grid-view list-view').addClass(savedViewMode + '-view');
        $('#btn-view-grid, #btn-view-list').removeClass('active');
        $(`#btn-view-${savedViewMode}`).addClass('active');
        
        if (this.user.role === 'admin') {
            $('#admin-section').show();
            // 관리자는 모든 메뉴 표시
            $('#admin-section .menu-list li').show();
            // 승인 대기 사용자 확인
            this.checkPendingUsers();
        } else if (this.user.role === 'sub_admin') {
            $('#admin-section').show();
            // 부관리자는 허용된 메뉴만 표시
            this.applySubAdminMenus();
            // 사용자 관리 권한 있으면 승인 대기 확인
            if ((this.user.admin_perms || []).includes('users')) {
                this.checkPendingUsers();
            }
        } else {
            $('#admin-section').hide();
        }
        
        this.loadStorages();
        this.loadSystemSettingsOnLogin();
        this.updateTrashIcon();
        
        // 즐겨찾기, 최근 파일 로드
        this.loadFavorites();
        this.loadRecentFiles();
    },
    
    // 승인 대기 사용자 확인
    async checkPendingUsers() {
        const res = await this.api('pending_users_count', {}, 'GET');
        if (res.success && res.count > 0) {
            this.showPendingUsersNotification(res.count);
        }
    },
    
    // 승인 대기 알림 표시
    showPendingUsersNotification(count) {
        // 로그인 화면이 보이면 표시하지 않음 (클래스 기반 체크)
        const loginScreen = document.getElementById('login-screen');
        if (loginScreen && loginScreen.classList.contains('active')) {
            return;
        }
        
        // 기존 알림 제거
        const existing = document.querySelector('.pending-notification');
        if (existing) existing.remove();
        
        const notification = document.createElement('div');
        notification.className = 'pending-notification';
        notification.innerHTML = `
            <span class="pending-icon">👤</span>
            <span class="pending-text">승인 대기 중인 사용자가 <strong>${count}명</strong> 있습니다.</span>
            <button class="pending-btn" id="btn-goto-pending">승인하기</button>
            <button class="pending-close">&times;</button>
        `;
        
        document.body.appendChild(notification);
        
        // 승인하기 버튼
        document.getElementById('btn-goto-pending').addEventListener('click', () => {
            notification.remove();
            this.showUsersModal();
        });
        
        // 닫기 버튼
        notification.querySelector('.pending-close').addEventListener('click', () => {
            notification.remove();
        });
    },
    
    // 부관리자 메뉴 필터링
    applySubAdminMenus() {
        const perms = this.user.admin_perms || [];
        const menuMap = {
            'storages': '#menu-storages',
            'users': '#menu-users',
            'roles': '#menu-roles',
            'qos': '#menu-qos',
            'shares': '#menu-shares',
            'logins': '#menu-all-logins',
            'trash': '#menu-trash',
            'security': '#menu-security',
            'system_settings': '#menu-system-settings',
            'system_info': '#menu-system-info'
        };
        
        // 모든 메뉴 숨기기
        $('#admin-section .menu-list li').hide();
        
        // 허용된 메뉴만 표시
        perms.forEach(p => {
            if (menuMap[p]) {
                $(menuMap[p]).closest('li').show();
            }
        });
        
        // 역할 관리, QoS는 사용자 관리 권한이 있으면 표시
        if (perms.includes('users')) {
            $('#menu-roles').closest('li').show();
            $('#menu-qos').closest('li').show();
        }
    },
    
    // 로그인 시 시스템 설정 불러오기
    async loadSystemSettingsOnLogin() {
        const res = await this.api('settings', {}, 'GET');
        if (res.success && res.settings) {
            this.systemSettings = res.settings;
        }
        
        // QoS 설정 불러오기
        const qosRes = await this.api('qos_user', {}, 'GET');
        if (qosRes.success) {
            this.userQos = {
                download: qosRes.download || 0,  // MB/s, 0 = 무제한
                upload: qosRes.upload || 0       // MB/s, 0 = 무제한
            };
        }
    },
    
    // 스토리지 로드
    async loadStorages() {
        const res = await this.api('storages', {}, 'GET');
        if (!res.success) return;
        
        const list = $('#storage-list').empty();
        
        // 새로운 형식: { home: [], public: [], shared: [] }
        const storages = res.storages;
        const homeList = storages.home || [];
        const publicList = storages.public || [];
        const sharedList = storages.shared || [];
        
        // 전체 스토리지 목록 저장
        this.storages = [...homeList, ...publicList, ...sharedList];
        
        // 홈 스토리지 ID 저장
        this.homeStorageId = homeList.length ? homeList[0].id : null;
        
        // 기존 형식 호환 (배열인 경우)
        if (Array.isArray(storages)) {
            storages.forEach(s => {
                list.append(`
                    <li>
                        <a href="#" data-id="${s.id}" title="${this.escapeHtml(s.path)}">
                            <span class="storage-icon">${s.icon || '📁'}</span>
                            <span class="storage-name">${this.escapeHtml(s.name)}</span>
                        </a>
                    </li>
                `);
            });
            if (storages.length && !this.currentStorage) {
                this.selectStorage(storages[0].id);
            }
            return;
        }
        
        // 내 파일 (홈)
        if (homeList.length) {
            list.append(`<li class="storage-divider"><span>개인</span></li>`);
            homeList.forEach(s => {
                list.append(`
                    <li>
                        <a href="#" data-id="${s.id}" title="${this.escapeHtml(s.path)}">
                            <span class="storage-icon">${s.icon || '🏠'}</span>
                            <span class="storage-name">${this.escapeHtml(s.name)}</span>
                        </a>
                    </li>
                `);
            });
        }
        
        // 공용 폴더 (모든 사용자 공유)
        if (publicList.length) {
            list.append(`<li class="storage-divider"><span>공용</span></li>`);
            publicList.forEach(s => {
                list.append(`
                    <li>
                        <a href="#" data-id="${s.id}" title="${this.escapeHtml(s.path)}">
                            <span class="storage-icon">${s.icon || '📂'}</span>
                            <span class="storage-name">${this.escapeHtml(s.name)}</span>
                        </a>
                    </li>
                `);
            });
        }
        
        // 외부 스토리지 (관리자가 추가한 드라이브, FTP 등)
        if (sharedList.length) {
            list.append(`<li class="storage-divider"><span>외부</span></li>`);
            sharedList.forEach(s => {
                list.append(`
                    <li>
                        <a href="#" data-id="${s.id}" title="${this.escapeHtml(s.path)}">
                            <span class="storage-icon">${s.icon || '📁'}</span>
                            <span class="storage-name">${this.escapeHtml(s.name)}</span>
                        </a>
                    </li>
                `);
            });
        }
        
        // 홈 스토리지 우선 선택 (없으면 공용, 없으면 외부)
        if (!this.currentStorage) {
            if (this.homeStorageId) {
                this.selectStorage(this.homeStorageId);
            } else if (publicList.length) {
                this.selectStorage(publicList[0].id);
            } else if (sharedList.length) {
                this.selectStorage(sharedList[0].id);
            }
        }
        
        // 저장된 검색어 복원 (새로고침 시)
        setTimeout(() => {
            const savedSearch = sessionStorage.getItem('webhard_search');
            if (savedSearch) {
                $('#search-input').val(savedSearch);
                $('#mobile-search-input').val(savedSearch);
                this.doSearch();
            }
        }, 100);
    },
    
    // 스토리지 선택
    selectStorage(id) {
        const isFirstLoad = !this.currentStorage;
        
        // 검색 모드 종료 (UI만 정리, loadFiles는 아래서 호출)
        if (this.isSearchMode) {
            this.isSearchMode = false;
            this.searchQuery = '';
            this.searchState = { query: '', filters: {}, page: 1, totalPages: 1, total: 0 };
            $('#search-input').val('');
            $('#mobile-search-input').val('');
            $('#search-result-header').hide();
            $('#search-pagination').hide();
            $('#search-filters').hide();
            $('#search-filter-toggle').removeClass('active');
            // 필터 초기화
            $('#filter-type').val('all');
            $('#filter-date-from').val('');
            $('#filter-date-to').val('');
            $('#filter-size-min').val('');
            $('#filter-size-max').val('');
            sessionStorage.removeItem('webhard_search');
        }
        
        this.currentStorage = id;
        this.currentPath = '';
        
        // 현재 스토리지의 권한 설정
        const storage = this.storages.find(s => s.id === id);
        if (storage) {
            this.currentPermissions = {
                can_read: storage.can_read ?? 1,
                can_download: storage.can_download ?? 1,
                can_write: storage.can_write ?? 1,
                can_delete: storage.can_delete ?? 1,
                can_share: storage.can_share ?? 1
            };
        } else {
            // 기본 권한 (관리자 등)
            this.currentPermissions = {
                can_read: 1,
                can_download: 1,
                can_write: 1,
                can_delete: 1,
                can_share: 1
            };
        }
        
        // 툴바 버튼 권한 처리
        $('#btn-upload').toggle(!!this.currentPermissions.can_write);
        $('#btn-new-folder').toggle(!!this.currentPermissions.can_write);
        
        $('#storage-list a').removeClass('active');
        $(`#storage-list a[data-id="${id}"]`).addClass('active');
        
        // 첫 로드면 replaceState, 아니면 pushState
        if (isFirstLoad) {
            const state = { storageId: id, path: '' };
            const url = `#storage=${id}&path=`;
            history.replaceState(state, '', url);
            this.loadFiles(false);
        } else {
            this.loadFiles(true);
        }
        this.loadStorageQuota();
    },
    
    // 파일 로드
    async loadFiles(addHistory = false) {
        if (!this.currentStorage) {
            $('#file-list').html('<div class="empty-msg">스토리지를 선택하세요</div>');
            return { success: false };
        }
        
        // 이전 요청 취소
        if (this.loadFilesController) {
            this.loadFilesController.abort();
        }
        this.loadFilesController = new AbortController();
        
        // path가 undefined면 빈 문자열로
        if (this.currentPath === undefined) {
            this.currentPath = '';
        }
        
        // 브라우저 히스토리에 추가
        if (addHistory) {
            const state = { 
                storageId: this.currentStorage, 
                path: this.currentPath 
            };
            const url = `#storage=${this.currentStorage}&path=${encodeURIComponent(this.currentPath)}`;
            history.pushState(state, '', url);
        }
        
        // 잠금 파일 목록 로드
        this.loadLockedFiles();
        
        const res = await this.api('files', {
            storage_id: this.currentStorage,
            path: this.currentPath,
            sort: this.sortBy,
            order: this.sortOrder
        }, 'GET', this.loadFilesController.signal);
        
        // 취소된 요청이면 무시
        if (!res) return { success: false };
        
        if (!res.success) {
            this.toast(res.error, 'error');
            return { success: false, error: res.error };
        }
        
        this.renderFiles(res.items);
        this.renderBreadcrumb(res.breadcrumb);
        this.selectedItems = [];
        
        return { success: true };
    },
    
    // 파일 렌더링
    renderFiles(items) {
        const list = $('#file-list').empty();
        
        // 전체 선택 체크박스 초기화
        const selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        
        // 삭제 권한이 있으면 전체 삭제 버튼 표시
        const btnDeleteAll = document.getElementById('btn-delete-all');
        const btnDeleteSelected = document.getElementById('btn-delete-selected');
        if (btnDeleteAll) {
            btnDeleteAll.style.display = (this.currentPermissions && this.currentPermissions.can_delete && items.length > 0) ? '' : 'none';
        }
        if (btnDeleteSelected) {
            btnDeleteSelected.style.display = 'none';
        }
        
        if (!items.length) {
            list.html('<div class="empty-msg">폴더가 비어있습니다</div>');
            return;
        }
        
        // 삭제 권한 여부
        const canDelete = this.currentPermissions && this.currentPermissions.can_delete;
        
        items.forEach(item => {
            const escapedName = this.escapeHtml(item.name);
            const escapedPath = this.escapeHtml(item.path);
            const checkbox = canDelete 
                ? `<input type="checkbox" class="file-checkbox" data-path="${escapedPath}" onclick="event.stopPropagation();">`
                : '';
            
            // 공유 아이콘
            const shareIcon = item.shared ? '<span class="share-badge" title="공유됨">🔗</span>' : '';
            
            // 잠금 아이콘
            const isLocked = this.isFileLocked(item.path);
            const lockIcon = isLocked ? '<span class="lock-badge" title="잠김">🔒</span>' : '';
            const lockedClass = isLocked ? ' is-locked' : '';
            
            const html = this.viewMode === 'grid' 
                ? `<div class="file-item ${item.shared ? 'is-shared' : ''}${lockedClass}" draggable="true" data-path="${escapedPath}" data-is-dir="${item.is_dir}" data-name="${escapedName}" data-size="${item.size || 0}" data-shared="${item.shared || false}" data-locked="${isLocked}">
                        ${checkbox ? `<div class="file-check">${checkbox}</div>` : ''}
                        ${shareIcon}${lockIcon}
                        <div class="file-icon">${item.icon}</div>
                        <div class="file-name" title="${escapedName}">${escapedName}</div>
                   </div>`
                : `<div class="file-item ${item.shared ? 'is-shared' : ''}${lockedClass}" draggable="true" data-path="${escapedPath}" data-is-dir="${item.is_dir}" data-name="${escapedName}" data-size="${item.size || 0}" data-shared="${item.shared || false}" data-locked="${isLocked}">
                        ${checkbox ? `<div class="file-check">${checkbox}</div>` : ''}
                        <div class="file-icon">${item.icon}</div>
                        <div class="file-name" title="${escapedName}">${escapedName}${shareIcon}${lockIcon}</div>
                        <div class="file-size">${item.is_dir ? '-' : this.formatSize(item.size)}</div>
                        <div class="file-date">${item.modified}</div>
                   </div>`;
            
            list.append(html);
        });
        
        // 드래그앤드롭 바인딩
        this.bindDragDrop();
    },
    
    // 브레드크럼 렌더링
    renderBreadcrumb(breadcrumb) {
        const bc = $('#breadcrumb').empty();
        
        bc.append(`<a href="#" data-path="">홈</a>`);
        
        breadcrumb.forEach((item, i) => {
            const escapedName = this.escapeHtml(item.name);
            const escapedPath = this.escapeHtml(item.path);
            bc.append(`<span>/</span>`);
            if (i === breadcrumb.length - 1) {
                bc.append(`<span>${escapedName}</span>`);
            } else {
                bc.append(`<a href="#" data-path="${escapedPath}">${escapedName}</a>`);
            }
        });
        
        // 이벤트는 bindEvents에서 위임 방식으로 처리
    },
    
    // 폴더 이동
    navigate(path, addHistory = true) {
        this.currentPath = path || '';
        this.loadFiles(addHistory);
    },
    
    // 뒤로가기 (앱 내부 버튼)
    goBack() {
        if (!this.currentPath) return;
        
        const parts = this.currentPath.split('/');
        parts.pop();
        this.currentPath = parts.join('/');
        this.loadFiles(true); // 히스토리에 추가
    },
    
    // 홈으로 (루트)
    goHome() {
        // 검색 모드 완전 종료
        this.isSearchMode = false;
        this.searchQuery = '';
        this.searchState = { query: '', filters: {}, page: 1, totalPages: 1, total: 0 };
        
        $('#search-input').val('');
        $('#mobile-search-input').val('');
        $('#search-result-header').hide();
        $('#search-pagination').hide();
        $('#search-filters').hide();
        $('#search-filter-toggle').removeClass('active');
        
        // 필터 초기화
        $('#filter-type').val('all');
        $('#filter-date-from').val('');
        $('#filter-date-to').val('');
        $('#filter-size-min').val('');
        $('#filter-size-max').val('');
        
        sessionStorage.removeItem('webhard_search');
        
        // 홈 스토리지가 있으면 홈으로, 없으면 현재 스토리지 루트로
        if (this.homeStorageId) {
            this.selectStorage(this.homeStorageId);
        } else {
            this.currentPath = '';
            this.loadFiles(true); // 히스토리에 추가
        }
    },
    
    // 파일 클릭 핸들러
    handleFileClick(item) {
        $('.file-item').removeClass('selected');
        item.addClass('selected');
        this.updateSelection();
    },
    
    // 선택 항목 업데이트
    updateSelection() {
        this.selectedItems = [];
        $('.file-item.selected').each((i, el) => {
            const isDir = $(el).data('is-dir');
            this.selectedItems.push({
                path: $(el).data('path'),
                isDir: isDir === true || isDir === 'true' || isDir === 1,
                name: $(el).data('name'),
                size: parseInt($(el).data('size')) || 0,
                storageId: parseInt($(el).data('storage-id')) || this.currentStorage
            });
        });
    },
    
    // ===== 키보드 네비게이션 =====
    
    // 현재 포커스된 아이템 인덱스 가져오기
    getFocusedIndex() {
        const items = document.querySelectorAll('#file-list .file-item');
        const selectedItems = document.querySelectorAll('#file-list .file-item.selected');
        if (selectedItems.length === 0) return -1;
        
        // 마지막 선택된 항목
        const lastSelected = selectedItems[selectedItems.length - 1];
        return Array.from(items).indexOf(lastSelected);
    },
    
    // 그리드 뷰에서 열당 아이템 수 계산
    getItemsPerRow() {
        if (this.viewMode !== 'grid') return 1;
        const fileList = document.getElementById('file-list');
        if (!fileList) return 1;
        const items = fileList.querySelectorAll('.file-item');
        if (items.length < 2) return 1;
        
        const firstTop = items[0].getBoundingClientRect().top;
        let count = 1;
        for (let i = 1; i < items.length; i++) {
            if (items[i].getBoundingClientRect().top === firstTop) {
                count++;
            } else {
                break;
            }
        }
        return count;
    },
    
    // 화살표 키로 네비게이션
    navigateWithArrow(key, shiftKey) {
        const items = document.querySelectorAll('#file-list .file-item');
        if (items.length === 0) return;
        
        let currentIndex = this.getFocusedIndex();
        const itemsPerRow = this.getItemsPerRow();
        let newIndex = currentIndex;
        
        // 선택된 게 없으면 첫 번째 선택
        if (currentIndex === -1) {
            newIndex = 0;
        } else {
            switch (key) {
                case 'ArrowUp':
                    newIndex = Math.max(0, currentIndex - itemsPerRow);
                    break;
                case 'ArrowDown':
                    newIndex = Math.min(items.length - 1, currentIndex + itemsPerRow);
                    break;
                case 'ArrowLeft':
                    newIndex = Math.max(0, currentIndex - 1);
                    break;
                case 'ArrowRight':
                    newIndex = Math.min(items.length - 1, currentIndex + 1);
                    break;
            }
        }
        
        if (shiftKey && currentIndex !== -1) {
            // Shift+화살표: 범위 선택
            this.extendSelection(currentIndex, newIndex);
        } else {
            // 일반 이동: 단일 선택
            items.forEach(item => item.classList.remove('selected'));
            items[newIndex].classList.add('selected');
            this.updateSelection();
        }
        
        // 스크롤 위치 조정
        this.scrollToItem(items[newIndex]);
    },
    
    // 범위 선택 확장
    extendSelection(fromIndex, toIndex) {
        const items = document.querySelectorAll('#file-list .file-item');
        const start = Math.min(fromIndex, toIndex);
        const end = Math.max(fromIndex, toIndex);
        
        for (let i = start; i <= end; i++) {
            items[i].classList.add('selected');
        }
        this.updateSelection();
    },
    
    // 아이템으로 스크롤
    scrollToItem(item) {
        if (!item) return;
        const container = document.getElementById('file-list');
        if (!container) return;
        
        const itemRect = item.getBoundingClientRect();
        const containerRect = container.getBoundingClientRect();
        
        // 아이템이 보이지 않으면 스크롤
        if (itemRect.top < containerRect.top) {
            item.scrollIntoView({ block: 'start', behavior: 'smooth' });
        } else if (itemRect.bottom > containerRect.bottom) {
            item.scrollIntoView({ block: 'end', behavior: 'smooth' });
        }
    },
    
    // Enter 키: 선택된 항목 열기
    openSelectedItem() {
        if (this.selectedItems.length === 0) return;
        
        const item = this.selectedItems[0];
        if (item.isDir) {
            this.navigate(item.path);
        } else {
            // 파일 미리보기 또는 다운로드
            const fileItem = { path: item.path, name: item.name, isDir: false };
            if (this.getFileType(item.name)) {
                this.showPreview(fileItem);
            } else {
                this.downloadFile(item.path);
            }
        }
    },
    
    // Space 키: 현재 선택 토글
    toggleCurrentSelection() {
        const items = document.querySelectorAll('#file-list .file-item');
        const currentIndex = this.getFocusedIndex();
        
        if (currentIndex === -1 && items.length > 0) {
            // 아무것도 선택 안 됐으면 첫 번째 선택
            items[0].classList.add('selected');
        } else if (currentIndex >= 0) {
            items[currentIndex].classList.toggle('selected');
        }
        this.updateSelection();
    },
    
    // Home 키: 첫 번째 아이템으로
    selectFirstItem(shiftKey) {
        const items = document.querySelectorAll('#file-list .file-item');
        if (items.length === 0) return;
        
        if (shiftKey) {
            const currentIndex = this.getFocusedIndex();
            if (currentIndex > 0) {
                this.extendSelection(0, currentIndex);
            }
        } else {
            items.forEach(item => item.classList.remove('selected'));
            items[0].classList.add('selected');
            this.updateSelection();
        }
        this.scrollToItem(items[0]);
    },
    
    // End 키: 마지막 아이템으로
    selectLastItem(shiftKey) {
        const items = document.querySelectorAll('#file-list .file-item');
        if (items.length === 0) return;
        
        const lastIndex = items.length - 1;
        
        if (shiftKey) {
            const currentIndex = this.getFocusedIndex();
            if (currentIndex >= 0 && currentIndex < lastIndex) {
                this.extendSelection(currentIndex, lastIndex);
            }
        } else {
            items.forEach(item => item.classList.remove('selected'));
            items[lastIndex].classList.add('selected');
            this.updateSelection();
        }
        this.scrollToItem(items[lastIndex]);
    },
    
    // Ctrl+A: 전체 선택
    selectAllFiles() {
        const items = document.querySelectorAll('#file-list .file-item');
        items.forEach(item => item.classList.add('selected'));
        this.updateSelection();
    },
    
    // 선택 UI 업데이트 (상태바 등)
    updateSelectionUI() {
        const count = this.selectedItems.length;
        // 상태 표시줄 업데이트 등 필요시 추가
    },
    
    // Backspace 키: 상위 폴더로 이동
    goUp() {
        this.goBack();
    },
    
    // 뷰 모드 전환
    setViewMode(mode) {
        this.viewMode = mode;
        localStorage.setItem('filestation_viewMode', mode);
        $('#file-list').removeClass('grid-view list-view').addClass(mode + '-view');
        $('#btn-view-grid, #btn-view-list').removeClass('active');
        $(`#btn-view-${mode}`).addClass('active');
        
        // 검색 모드일 때는 재검색
        if (this.isSearchMode && this.searchState.query) {
            $('#search-input').val(this.searchState.query);
            this.doSearch(this.searchState.page);
        } else {
            this.loadFiles();
        }
    },
    
    // 컨텍스트 메뉴
    showContextMenu(x, y, emptySpace = false) {
        const menuEl = document.getElementById('context-menu');
        const perms = this.currentPermissions;
        
        // 개인 폴더 공유 가능 여부 체크
        const isHomeStorage = this.currentStorage === this.homeStorageId;
        const homeShareEnabled = this.systemSettings.home_share_enabled !== false;
        const canShare = perms.can_share && (!isHomeStorage || homeShareEnabled);
        
        // 클립보드에 항목이 있는지 확인
        const hasClipboard = this.clipboard.items.length > 0;
        
        // 선택된 항목 확인
        const selectedItems = this.selectedItems || [];
        const hasZipFile = selectedItems.some(item => {
            const ext = (item.name || '').split('.').pop().toLowerCase();
            return ext === 'zip';
        });
        const hasSelection = selectedItems.length > 0;
        
        // 선택된 첫 번째 항목의 즐겨찾기/잠금 상태 확인
        const firstItem = selectedItems[0];
        const isFavorite = firstItem ? this.isFavorite(firstItem.path) : false;
        const isLocked = firstItem ? this.isFileLocked(firstItem.path) : false;
        
        // 권한에 따라 메뉴 항목 표시/숨김
        let actions;
        if (emptySpace) {
            // 빈 공간 우클릭: 새 폴더, 업로드, 새로고침, 붙여넣기
            actions = {
                'open': false,
                'preview': false,
                'download': false,
                'save-as': false,
                'share': false,
                'favorite-add': false,
                'favorite-remove': false,
                'file-lock': false,
                'file-unlock': false,
                'rename': false,
                'move': false,
                'copy': false,
                'paste': hasClipboard && !!perms.can_write,
                'info': false,
                'delete': false,
                'delete-all': false,
                'extract': false,
                'compress': false,
                'new-folder': !!perms.can_write,
                'upload-file': !!perms.can_write,
                'upload-folder': !!perms.can_write,
                'refresh': true
            };
        } else {
            // 파일/폴더 선택 시
            actions = {
                'open': true,
                'preview': !!perms.can_read,
                'download': !!perms.can_download,
                'save-as': !!perms.can_download,
                'share': !!canShare,
                'favorite-add': !isFavorite,
                'favorite-remove': isFavorite,
                'file-lock': !isLocked && !!perms.can_write,
                'file-unlock': isLocked,
                'rename': !!perms.can_write && !isLocked,
                'move': !!perms.can_write && !isLocked,
                'copy': !!perms.can_write,
                'paste': hasClipboard && !!perms.can_write,
                'info': true,
                'delete': !!perms.can_delete && !isLocked,
                'delete-all': false,
                'extract': hasZipFile && !!perms.can_write && !isLocked,
                'compress': hasSelection && !!perms.can_write,
                'new-folder': false,
                'upload-file': false,
                'upload-folder': false,
                'refresh': false
            };
        }
        
        // 각 메뉴 항목 표시/숨김
        menuEl.querySelectorAll('li[data-action]').forEach(function(li) {
            var action = li.getAttribute('data-action');
            li.style.display = actions[action] ? '' : 'none';
        });
        
        // 구분선 처리
        var items = menuEl.querySelectorAll('li');
        items.forEach(function(li, idx) {
            if (li.classList.contains('divider')) {
                // 이전/다음 보이는 항목 찾기
                var prevVisible = false, nextVisible = false;
                for (var i = idx - 1; i >= 0; i--) {
                    if (!items[i].classList.contains('divider') && items[i].style.display !== 'none') {
                        prevVisible = true;
                        break;
                    }
                    if (items[i].classList.contains('divider')) break;
                }
                for (var i = idx + 1; i < items.length; i++) {
                    if (!items[i].classList.contains('divider') && items[i].style.display !== 'none') {
                        nextVisible = true;
                        break;
                    }
                    if (items[i].classList.contains('divider')) break;
                }
                li.style.display = (prevVisible && nextVisible) ? '' : 'none';
            }
        });
        
        // 메뉴 표시 (position: fixed 이므로 뷰포트 기준 좌표 사용)
        // pageX/pageY는 문서 기준이므로 스크롤 위치를 빼서 뷰포트 좌표로 변환
        const viewportX = x - window.scrollX;
        const viewportY = y - window.scrollY;
        
        menuEl.style.left = viewportX + 'px';
        menuEl.style.top = viewportY + 'px';
        menuEl.style.display = 'block';
        
        // 화면 밖으로 나가지 않게
        var rect = menuEl.getBoundingClientRect();
        var padding = 10;
        
        if (rect.right > window.innerWidth - padding) {
            menuEl.style.left = Math.max(padding, viewportX - rect.width) + 'px';
        }
        if (rect.bottom > window.innerHeight - padding) {
            menuEl.style.top = Math.max(padding, viewportY - rect.height) + 'px';
        }
        if (rect.top < padding) {
            menuEl.style.top = padding + 'px';
        }
        if (rect.left < padding) {
            menuEl.style.left = padding + 'px';
        }
    },
    
    hideContextMenu() {
        $('#context-menu').hide();
    },
    
    // 컨텍스트 메뉴 액션
    handleContextAction(action) {
        // 선택 항목 없이도 동작하는 액션들
        switch (action) {
            case 'paste':
                this.clipboardPaste();
                return;
            case 'new-folder':
                this.showModal('modal-new-folder');
                return;
            case 'upload-file':
                document.getElementById('file-input').click();
                return;
            case 'upload-folder':
                document.getElementById('folder-input').click();
                return;
            case 'refresh':
                this.loadFiles();
                return;
        }
        
        // 체크박스 또는 클릭 선택된 항목 가져오기
        const items = this.getSelectedOrCheckedItems();
        
        
        if (!items.length) return;
        
        const item = items[0];
        
        switch (action) {
            case 'open':
                if (item.isDir) {
                    // 검색 결과에서 폴더 열기 - 스토리지 변경
                    if (item.storageId && item.storageId !== this.currentStorage) {
                        this.currentStorage = item.storageId;
                        $('.storage-item').removeClass('active');
                        $(`.storage-item[data-id="${item.storageId}"]`).addClass('active');
                    }
                    this.exitSearchMode();
                    this.navigate(item.path);
                } else {
                    this.downloadFile(item.path, true, false, item.storageId);
                }
                break;
            case 'download':
                // 다중 선택 다운로드
                this.downloadSelectedFiles(items);
                break;
            case 'preview':
                this.showPreview(item);
                break;
            case 'save-as':
                this.saveFileAs(item.path, item.name, item.storageId);
                break;
            case 'share':
                this.showShareModal(item);
                break;
            case 'rename':
                this.showRenameModal();
                break;
            case 'move':
                this.clipboardCut();
                break;
            case 'copy':
                this.clipboardCopy();
                break;
            case 'info':
                this.showDetailedInfo(item);
                break;
            case 'delete':
                // 다중 선택 삭제
                this.deleteSelectedItems(items);
                break;
            case 'extract':
                this.extractZip(item);
                break;
            case 'compress':
                this.compressFiles(items);
                break;
            // 즐겨찾기
            case 'favorite-add':
                this.addToFavorites(item);
                break;
            case 'favorite-remove':
                this.removeFromFavorites(item);
                break;
            // 파일 잠금
            case 'file-lock':
                this.lockFile(item);
                break;
            case 'file-unlock':
                this.unlockFile(item);
                break;
        }
    },
    
    // 선택된 파일들 다운로드
    async downloadSelectedFiles(items) {
        if (items.length === 1) {
            // 단일 파일 - storageId 전달
            this.downloadFile(items[0].path, true, false, items[0].storageId);
            return;
        }
        
        // 다중 파일 다운로드
        const totalFiles = items.length;
        this.showTransferProgress('download', items[0].name, 0, totalFiles, 1);
        
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            
            if (this.transfer.cancelled) {
                this.hideTransferProgress();
                this.toast('다운로드가 취소되었습니다', 'info');
                return;
            }
            
            this.updateTransferFileCount(i + 1, totalFiles, item.name);
            
            // 폴더는 건너뛰기
            if (item.isDir) {
                continue;
            }
            
            await this.downloadFile(item.path, false, false, item.storageId);
            
            // 약간의 딜레이 (브라우저 다운로드 처리)
            await new Promise(r => setTimeout(r, 300));
        }
        
        this.hideTransferProgress();
        this.toast(`${totalFiles}개 파일 다운로드 완료`, 'success');
    },
    
    // ZIP 압축 해제
    async extractZip(item) {
        if (!item || !item.name) return;
        
        const ext = item.name.split('.').pop().toLowerCase();
        if (ext !== 'zip') {
            this.toast('ZIP 파일만 압축 해제할 수 있습니다', 'error');
            return;
        }
        
        this.toast('압축 해제 중...', 'info');
        
        const res = await this.api('extract', {
            storage_id: this.currentStorage,
            path: item.path
        });
        
        if (res.success) {
            this.toast(`압축 해제 완료: ${res.extracted_to} (${res.file_count}개 파일)`, 'success');
            this.loadFiles();
        } else {
            this.toast(res.error || '압축 해제 실패', 'error');
        }
    },
    
    // 파일/폴더 압축
    async compressFiles(items) {
        if (!items || !items.length) return;
        
        // 압축 파일명 입력 받기
        let defaultName;
        if (items.length === 1) {
            defaultName = items[0].name.replace(/\.[^/.]+$/, '') + '.zip';
        } else {
            defaultName = 'archive_' + new Date().toISOString().slice(0,10).replace(/-/g,'') + '.zip';
        }
        
        const zipName = prompt('압축 파일명을 입력하세요:', defaultName);
        if (!zipName) return;
        
        // .zip 확장자 확인
        const finalName = zipName.endsWith('.zip') ? zipName : zipName + '.zip';
        
        this.toast('압축 중...', 'info');
        
        const paths = items.map(item => item.path);
        
        const res = await this.api('compress', {
            storage_id: this.currentStorage,
            paths: paths,
            zip_name: finalName
        });
        
        if (res.success) {
            this.toast(`압축 완료: ${res.zip_name} (${res.file_count}개 항목)`, 'success');
            this.loadFiles();
        } else {
            this.toast(res.error || '압축 실패', 'error');
        }
    },
    
    // 선택된 파일들 삭제
    async deleteSelectedItems(items) {
        if (!items.length) return;
        
        const names = items.length > 3 
            ? `${items[0].name} 외 ${items.length - 1}개`
            : items.map(i => i.name).join(', ');
            
        if (!confirm(`"${names}"을(를) 삭제하시겠습니까?`)) return;
        
        const totalFiles = items.length;
        const totalSize = items.reduce((sum, item) => sum + (item.size || 0), 0);
        let processedSize = 0;
        
        this.showTransferProgress('delete', items[0].name, totalSize, totalFiles, 1);
        
        let success = 0;
        let failed = 0;
        
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            
            if (this.transfer.cancelled) {
                this.hideTransferProgress();
                this.toast('삭제가 취소되었습니다', 'info');
                if (!this.isSearchMode) this.loadFiles();
                return;
            }
            
            this.updateTransferProgressWithSize(i + 1, totalFiles, item.name, processedSize, totalSize);
            
            // 검색 결과에서 선택한 경우 해당 스토리지 ID 사용
            const storageId = item.storageId || this.currentStorage;
            
            const res = await this.api('delete', {
                storage_id: storageId,
                path: item.path
            });
            
            if (res.success) {
                success++;
                processedSize += item.size || 0;
            } else {
                failed++;
            }
        }
        
        this.hideTransferProgress();
        
        if (success > 0) {
            this.toast(`${success}개 항목이 삭제되었습니다`, 'success');
        }
        if (failed > 0) {
            this.toast(`${failed}개 항목 삭제 실패`, 'error');
        }
        
        // 검색 모드면 재검색, 아니면 파일 목록 새로고침
        if (this.isSearchMode && this.searchState.query) {
            this.doSearch(this.searchState.page);
        } else {
            this.loadFiles();
        }
        this.updateTrashIcon();
    },
    
    // 파일 업로드 (무제한 용량)
    async handleUpload(files) {
        if (!this.currentStorage) {
            this.toast('스토리지를 먼저 선택하세요', 'error');
            return;
        }
        
        // 전체 파일 크기 계산
        let totalSize = 0;
        for (const file of files) {
            totalSize += file.size;
        }
        
        // 업로드 전 용량 체크
        const checkRes = await this.api('check_quota', {
            storage_id: this.currentStorage,
            size: totalSize
        });
        
        if (!checkRes.success) {
            this.toast(checkRes.error || '용량 체크 실패', 'error');
            $('#file-input').val('');
            return;
        }
        
        if (!checkRes.allowed) {
            this.toast(checkRes.error || '용량이 부족합니다', 'error');
            $('#file-input').val('');
            return;
        }
        
        // 현재 폴더의 파일 목록 가져오기 (중복 체크용, GET 방식)
        const listRes = await this.api('files', {
            storage_id: this.currentStorage,
            path: this.currentPath
        }, 'GET');
        
        if (!listRes.success) {
            this.toast('폴더 정보를 읽을 수 없습니다', 'error');
            $('#file-input').val('');
            return;
        }
        
        // 기존 파일명 목록 (응답이 items)
        const existingNames = new Set((listRes.items || []).map(f => f.name));
        
        // 중복 파일 확인
        const fileArray = Array.from(files);
        const duplicates = fileArray.filter(file => existingNames.has(file.name));
        
        if (duplicates.length > 0) {
            // 중복 파일이 있으면 선택 모달 표시
            this.showUploadDuplicateModal(duplicates, fileArray);
        } else {
            // 중복 없으면 바로 업로드
            await this.executeUpload(fileArray, 'rename');
        }
    },
    
    // 업로드 중복 파일 모달 표시
    showUploadDuplicateModal(duplicates, allFiles) {
        const listEl = document.getElementById('duplicate-list');
        listEl.innerHTML = duplicates.map(file => 
            `<div class="duplicate-item">📄 ${this.escapeHtml(file.name)}</div>`
        ).join('');
        
        const total = allFiles.length;
        const dupCount = duplicates.length;
        document.getElementById('duplicate-message').textContent = 
            `${total}개 중 ${dupCount}개 파일이 이미 존재합니다:`;
        
        // 버튼 이벤트 (일회성)
        const skipBtn = document.getElementById('btn-dup-skip-all');
        const overwriteBtn = document.getElementById('btn-dup-overwrite-all');
        const renameBtn = document.getElementById('btn-dup-rename-all');
        
        const cleanup = () => {
            skipBtn.replaceWith(skipBtn.cloneNode(true));
            overwriteBtn.replaceWith(overwriteBtn.cloneNode(true));
            renameBtn.replaceWith(renameBtn.cloneNode(true));
            $('#file-input').val('');
        };
        
        // 건너뛰기: 중복 파일 제외하고 업로드
        skipBtn.onclick = async () => {
            closeModal();
            const nonDuplicates = allFiles.filter(file => 
                !duplicates.some(d => d.name === file.name)
            );
            if (nonDuplicates.length > 0) {
                await this.executeUpload(nonDuplicates, 'skip');
            } else {
                this.toast('업로드할 파일이 없습니다', 'info');
            }
            cleanup();
        };
        
        // 덮어쓰기: 모든 파일 덮어쓰기
        overwriteBtn.onclick = async () => {
            closeModal();
            await this.executeUpload(allFiles, 'overwrite');
            cleanup();
        };
        
        // 이름 변경: 중복 파일은 (1), (2) 등 붙여서 업로드
        renameBtn.onclick = async () => {
            closeModal();
            await this.executeUpload(allFiles, 'rename');
            cleanup();
        };
        
        this.showModal('modal-duplicate');
    },
    
    // 실제 업로드 실행
    async executeUpload(files, duplicateAction) {
        const totalFiles = files.length;
        let currentFile = 0;
        let uploadedCount = 0;
        
        // 다중 파일 업로드 시 transfer 설정
        if (totalFiles > 1) {
            this.transfer.totalFiles = totalFiles;
        }
        
        for (const file of files) {
            currentFile++;
            
            // 다중 파일일 때 진행 상태 표시
            if (totalFiles > 1) {
                this.showTransferProgress('upload', file.name, file.size, totalFiles, currentFile);
            }
            
            const result = await this.uploadChunked(file, null, duplicateAction);
            
            // 취소 또는 에러 확인
            if (result?.cancelled || this.transfer.cancelled) {
                break;
            }
            
            if (result?.success) {
                uploadedCount++;
            } else if (result?.skipped) {
                // 건너뛴 파일
            }
        }
        
        if (totalFiles > 1 && !this.transfer.cancelled) {
            this.hideTransferProgress();
            this.toast(`${uploadedCount}개 파일 업로드 완료`, 'success');
        }
        
        this.loadFiles();
        $('#file-input').val('');
    },
    
    // 폴더 업로드
    async handleFolderUpload(files) {
        if (!this.currentStorage) {
            this.toast('스토리지를 먼저 선택하세요', 'error');
            return;
        }
        
        if (!files || files.length === 0) {
            return;
        }
        
        // 전체 파일 크기 계산
        let totalSize = 0;
        for (const file of files) {
            totalSize += file.size;
        }
        
        // 업로드 전 용량 체크
        const checkRes = await this.api('check_quota', {
            storage_id: this.currentStorage,
            size: totalSize
        });
        
        if (!checkRes.success) {
            this.toast(checkRes.error || '용량 체크 실패', 'error');
            $('#folder-input').val('');
            return;
        }
        
        if (!checkRes.allowed) {
            this.toast(checkRes.error || '용량이 부족합니다', 'error');
            $('#folder-input').val('');
            return;
        }
        
        // 폴더 이름 추출 (첫 번째 파일의 경로에서)
        const firstPath = files[0].webkitRelativePath;
        const folderName = firstPath.split('/')[0];
        
        // 현재 폴더의 파일 목록 가져오기 (중복 체크용)
        const listRes = await this.api('files', {
            storage_id: this.currentStorage,
            path: this.currentPath
        }, 'GET');
        
        if (!listRes.success) {
            this.toast('폴더 정보를 읽을 수 없습니다', 'error');
            $('#folder-input').val('');
            return;
        }
        
        // 기존 파일명 목록
        const existingNames = new Set((listRes.items || []).map(f => f.name));
        
        // 폴더 이름 중복 확인
        if (existingNames.has(folderName)) {
            this.showFolderUploadDuplicateModal(folderName, Array.from(files));
        } else {
            await this.executeFolderUpload(Array.from(files), 'rename');
        }
    },
    
    // 폴더 업로드 중복 모달
    showFolderUploadDuplicateModal(folderName, files) {
        const listEl = document.getElementById('duplicate-list');
        listEl.innerHTML = `<div class="duplicate-item">📁 ${this.escapeHtml(folderName)}</div>`;
        
        document.getElementById('duplicate-message').textContent = 
            `폴더가 이미 존재합니다:`;
        
        // 버튼 이벤트 (일회성)
        const skipBtn = document.getElementById('btn-dup-skip-all');
        const overwriteBtn = document.getElementById('btn-dup-overwrite-all');
        const renameBtn = document.getElementById('btn-dup-rename-all');
        
        const cleanup = () => {
            skipBtn.replaceWith(skipBtn.cloneNode(true));
            overwriteBtn.replaceWith(overwriteBtn.cloneNode(true));
            renameBtn.replaceWith(renameBtn.cloneNode(true));
            $('#folder-input').val('');
        };
        
        // 건너뛰기
        skipBtn.onclick = async () => {
            closeModal();
            this.toast('업로드가 취소되었습니다', 'info');
            cleanup();
        };
        
        // 덮어쓰기 (기존 폴더에 병합)
        overwriteBtn.onclick = async () => {
            closeModal();
            await this.executeFolderUpload(files, 'overwrite');
            cleanup();
        };
        
        // 이름 변경
        renameBtn.onclick = async () => {
            closeModal();
            await this.executeFolderUpload(files, 'rename');
            cleanup();
        };
        
        this.showModal('modal-duplicate');
    },
    
    // 폴더 업로드 실행
    async executeFolderUpload(files, duplicateAction) {
        const firstPath = files[0].webkitRelativePath;
        const folderName = firstPath.split('/')[0];
        
        const totalFiles = files.length;
        let currentFile = 0;
        let uploadedCount = 0;
        
        // 진행 표시 시작
        this.transfer.totalFiles = totalFiles;
        this.showTransferProgress('upload', files[0].name, files[0].size, totalFiles, 1);
        
        for (const file of files) {
            currentFile++;
            
            // 진행 상태 업데이트
            this.updateTransferFileCount(currentFile, totalFiles, file.name);
            
            // webkitRelativePath에서 상대 경로 추출
            const relativePath = file.webkitRelativePath;
            const result = await this.uploadChunked(file, relativePath, duplicateAction);
            
            // 취소 확인
            if (result?.cancelled || this.transfer.cancelled) {
                break;
            }
            
            if (result?.success) {
                uploadedCount++;
            }
        }
        
        if (!this.transfer.cancelled) {
            this.hideTransferProgress();
            this.toast(`📁 ${folderName} 폴더 업로드 완료 (${uploadedCount}개 파일)`, 'success');
        }
        this.loadFiles();
        $('#folder-input').val('');
    },
    
    // 드래그 앤 드롭 항목 처리
    async handleDropEntries(entries) {
        if (!this.currentStorage) {
            this.toast('스토리지를 먼저 선택하세요', 'error');
            return;
        }
        
        const files = [];
        
        // 모든 entry에서 파일 수집
        for (const entry of entries) {
            await this.collectFilesFromEntry(entry, '', files);
        }
        
        if (files.length === 0) {
            this.toast('업로드할 파일이 없습니다', 'warning');
            return;
        }
        
        // 전체 파일 크기 계산
        let totalSize = 0;
        for (const item of files) {
            totalSize += item.file.size;
        }
        
        // 업로드 전 용량 체크
        const checkRes = await this.api('check_quota', {
            storage_id: this.currentStorage,
            size: totalSize
        });
        
        if (!checkRes.success) {
            this.toast(checkRes.error || '용량 체크 실패', 'error');
            return;
        }
        
        if (!checkRes.allowed) {
            this.toast(checkRes.error || '용량이 부족합니다', 'error');
            return;
        }
        
        // 현재 폴더의 파일 목록 가져오기 (중복 체크용)
        const listRes = await this.api('files', {
            storage_id: this.currentStorage,
            path: this.currentPath
        }, 'GET');
        
        if (!listRes.success) {
            this.toast('폴더 정보를 읽을 수 없습니다', 'error');
            return;
        }
        
        // 기존 파일명 목록
        const existingNames = new Set((listRes.items || []).map(f => f.name));
        
        // 최상위 레벨 파일/폴더 중복 확인 (relativePath가 없거나 단일 레벨인 것)
        const topLevelFiles = files.filter(item => {
            const parts = (item.relativePath || item.file.name).split('/');
            return parts.length === 1; // 최상위 레벨만
        });
        
        const duplicates = topLevelFiles.filter(item => {
            const name = item.relativePath || item.file.name;
            return existingNames.has(name);
        });
        
        if (duplicates.length > 0) {
            // 중복 파일이 있으면 선택 모달 표시
            this.showDropDuplicateModal(duplicates, files);
        } else {
            // 중복 없으면 바로 업로드
            await this.executeDropUpload(files, 'rename');
        }
    },
    
    // 드롭 업로드 중복 모달
    showDropDuplicateModal(duplicates, allFiles) {
        const listEl = document.getElementById('duplicate-list');
        listEl.innerHTML = duplicates.map(item => {
            const name = item.relativePath || item.file.name;
            return `<div class="duplicate-item">📄 ${this.escapeHtml(name)}</div>`;
        }).join('');
        
        const total = allFiles.length;
        const dupCount = duplicates.length;
        document.getElementById('duplicate-message').textContent = 
            `${total}개 중 ${dupCount}개 파일/폴더가 이미 존재합니다:`;
        
        // 버튼 이벤트 (일회성)
        const skipBtn = document.getElementById('btn-dup-skip-all');
        const overwriteBtn = document.getElementById('btn-dup-overwrite-all');
        const renameBtn = document.getElementById('btn-dup-rename-all');
        
        const cleanup = () => {
            skipBtn.replaceWith(skipBtn.cloneNode(true));
            overwriteBtn.replaceWith(overwriteBtn.cloneNode(true));
            renameBtn.replaceWith(renameBtn.cloneNode(true));
        };
        
        // 건너뛰기
        skipBtn.onclick = async () => {
            closeModal();
            const duplicateNames = new Set(duplicates.map(d => d.relativePath || d.file.name));
            const nonDuplicates = allFiles.filter(item => {
                const name = item.relativePath || item.file.name;
                const topName = name.split('/')[0];
                return !duplicateNames.has(topName);
            });
            if (nonDuplicates.length > 0) {
                await this.executeDropUpload(nonDuplicates, 'skip');
            } else {
                this.toast('업로드할 파일이 없습니다', 'info');
            }
            cleanup();
        };
        
        // 덮어쓰기
        overwriteBtn.onclick = async () => {
            closeModal();
            await this.executeDropUpload(allFiles, 'overwrite');
            cleanup();
        };
        
        // 이름 변경
        renameBtn.onclick = async () => {
            closeModal();
            await this.executeDropUpload(allFiles, 'rename');
            cleanup();
        };
        
        this.showModal('modal-duplicate');
    },
    
    // 드롭 업로드 실행
    async executeDropUpload(files, duplicateAction) {
        const totalFiles = files.length;
        let currentFile = 0;
        let uploadedCount = 0;
        
        // 진행 표시 시작
        this.transfer.totalFiles = totalFiles;
        this.showTransferProgress('upload', files[0].file.name, files[0].file.size, totalFiles, 1);
        
        for (const item of files) {
            currentFile++;
            
            // 진행 상태 업데이트
            this.updateTransferFileCount(currentFile, totalFiles, item.file.name);
            
            let result;
            if (item.relativePath) {
                result = await this.uploadChunked(item.file, item.relativePath, duplicateAction);
            } else {
                result = await this.uploadChunked(item.file, null, duplicateAction);
            }
            
            // 취소 확인
            if (result?.cancelled || this.transfer.cancelled) {
                break;
            }
            
            if (result?.success) {
                uploadedCount++;
            }
        }
        
        if (!this.transfer.cancelled) {
            this.hideTransferProgress();
            this.toast(`업로드 완료 (${uploadedCount}개 파일)`, 'success');
        }
        this.loadFiles();
    },
    
    // Entry에서 파일 수집 (재귀)
    async collectFilesFromEntry(entry, basePath, files) {
        if (entry.isFile) {
            const file = await this.getFileFromEntry(entry);
            if (file) {
                const relativePath = basePath ? basePath + '/' + entry.name : '';
                files.push({ file, relativePath: relativePath || entry.name });
            }
        } else if (entry.isDirectory) {
            const dirReader = entry.createReader();
            const entries = await this.readAllDirectoryEntries(dirReader);
            const newBasePath = basePath ? basePath + '/' + entry.name : entry.name;
            
            for (const childEntry of entries) {
                await this.collectFilesFromEntry(childEntry, newBasePath, files);
            }
        }
    },
    
    // Entry에서 File 객체 가져오기
    getFileFromEntry(entry) {
        return new Promise((resolve) => {
            entry.file(
                file => resolve(file),
                () => resolve(null)
            );
        });
    },
    
    // 디렉토리의 모든 항목 읽기
    async readAllDirectoryEntries(dirReader) {
        const entries = [];
        let readEntries = await this.readDirectoryEntries(dirReader);
        
        while (readEntries.length > 0) {
            entries.push(...readEntries);
            readEntries = await this.readDirectoryEntries(dirReader);
        }
        
        return entries;
    },
    
    // 디렉토리 항목 읽기 (한 번에 최대 100개)
    readDirectoryEntries(dirReader) {
        return new Promise((resolve) => {
            dirReader.readEntries(
                entries => resolve(entries),
                () => resolve([])
            );
        });
    },
    
    // 청크 업로드 (모든 파일)
    async uploadChunked(file, relativePath = null, duplicateAction = 'rename') {
        // 업로드 속도 제한 (MB/s, 0 = 무제한)
        const uploadLimit = this.userQos?.upload || 0;
        const bytesPerSecond = uploadLimit > 0 ? uploadLimit * 1024 * 1024 : 0;
        
        // 서버 설정 기반 청크 크기 (php.ini 제한 고려)
        const serverMaxChunk = this.serverConfig.maxChunkSize || (10 * 1024 * 1024);
        
        // 속도 제한이 있으면 청크 크기를 작게 (더 정밀한 제어)
        // 속도 제한 없으면 서버 최대값, 있으면 1MB (1초에 여러 청크 전송 가능하도록)
        const CHUNK_SIZE = uploadLimit > 0 ? Math.min(1 * 1024 * 1024, serverMaxChunk) : serverMaxChunk;
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const uploadId = Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
        
        // 표시할 파일명 (폴더 업로드 시 상대 경로 포함)
        const displayName = relativePath || file.name;
        
        // 다중 파일 모드가 아닐 때만 진행 표시 초기화 (단일 파일 업로드)
        if (!this.transfer.totalFiles || this.transfer.totalFiles <= 1) {
            this.showUploadProgress(displayName, 0, file.size);
        } else {
            // 다중 파일 모드: 파일명만 업데이트
            document.getElementById('transfer-filename').textContent = displayName;
        }
        
        // duplicateAction 저장 (청크에서 사용)
        this.currentDuplicateAction = duplicateAction;
        
        let retryCount = 0;
        const maxRetries = 3;
        
        // 전체 업로드 시작 시간 및 전송량 추적
        let totalBytesSent = 0;
        const uploadStartTime = Date.now();
        
        for (let i = 0; i < totalChunks; i++) {
            // 취소 확인
            if (this.transfer.cancelled) {
                this.hideTransferProgress();
                this.toast('업로드가 취소되었습니다', 'info');
                return { cancelled: true };
            }
            
            const start = i * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            const chunkSize = end - start;
            
            const formData = new FormData();
            formData.append('chunk', chunk);
            formData.append('storage_id', this.currentStorage);
            formData.append('path', this.currentPath);
            formData.append('filename', file.name);
            formData.append('chunkIndex', i);
            formData.append('totalChunks', totalChunks);
            formData.append('totalSize', file.size);
            formData.append('uploadId', uploadId);
            formData.append('lastModified', Math.floor(file.lastModified / 1000)); // 초 단위로 변환
            formData.append('duplicateAction', this.currentDuplicateAction || 'rename');
            
            // 폴더 업로드 시 상대 경로 전송
            if (relativePath) {
                formData.append('relativePath', relativePath);
            }
            
            let success = false;
            const chunkStartTime = Date.now();
            
            while (!success && retryCount < maxRetries) {
                // 취소 확인 (재시도 중에도)
                if (this.transfer.cancelled) {
                    this.hideTransferProgress();
                    this.toast('업로드가 취소되었습니다', 'info');
                    return { cancelled: true };
                }
                
                try {
                    const res = await this.api('upload_chunk', formData);
                    
                    if (!res.success) {
                        throw new Error(res.error || '업로드 실패');
                    }
                    
                    success = true;
                    retryCount = 0; // 성공하면 재시도 카운트 초기화
                    
                    // 진행률 업데이트
                    const uploadedBytes = Math.min((i + 1) * CHUNK_SIZE, file.size);
                    const percent = Math.round((uploadedBytes / file.size) * 100);
                    this.updateUploadProgress(percent, uploadedBytes, file.size);
                    
                    // 전송량 추적
                    totalBytesSent += chunkSize;
                    
                    // 속도 제한 적용: 누적 전송량 기준으로 delay 계산
                    if (bytesPerSecond > 0 && !res.complete) {
                        const elapsedTotal = Date.now() - uploadStartTime;
                        const expectedTime = (totalBytesSent / bytesPerSecond) * 1000;
                        const delay = expectedTime - elapsedTotal;
                        if (delay > 0) {
                            await new Promise(r => setTimeout(r, delay));
                        }
                    }
                    
                    // 완료 확인
                    if (res.complete) {
                        this.hideUploadProgress();
                        this.toast(`${res.filename} 업로드 완료 (${this.formatSize(res.size || file.size)})`, 'success');
                        // 최근 파일에 추가
                        const uploadedPath = this.currentPath ? `${this.currentPath}/${res.filename}` : res.filename;
                        this.addToRecentFiles(uploadedPath, res.filename, 'upload');
                        return { success: true };
                    }
                } catch (e) {
                    retryCount++;
                    console.error(`청크 ${i} 업로드 실패 (시도 ${retryCount}/${maxRetries}):`, e);
                    
                    if (retryCount >= maxRetries) {
                        this.hideUploadProgress();
                        this.toast(`업로드 실패: ${e.message}`, 'error');
                        return { error: true };
                    }
                    
                    // 재시도 전 대기
                    await new Promise(r => setTimeout(r, 1000 * retryCount));
                }
            }
        }
        
        this.hideUploadProgress();
        this.toast(`${file.name} 업로드 완료`, 'success');
        return { success: true };
    },
    
    showUploadProgress(filename, percent, totalSize) {
        this.showTransferProgress('upload', filename, totalSize);
    },
    
    updateUploadProgress(percent, uploaded, total) {
        this.updateTransferProgress(percent, uploaded, total);
    },
    
    hideUploadProgress() {
        this.hideTransferProgress();
    },
    
    // 전송 진행률 표시 (공통)
    showTransferProgress(type, filename, totalSize, totalFiles = 1, currentFile = 1) {
        const titles = {
            'upload': '📤 업로드 중...',
            'download': '📥 다운로드 중...',
            'copy': '📋 복사 중...',
            'move': '📁 이동 중...',
            'delete': '🗑️ 삭제 중...'
        };
        
        document.getElementById('transfer-title').textContent = titles[type] || '전송 중...';
        document.getElementById('transfer-filename').textContent = filename;
        document.getElementById('transfer-percent').textContent = '0%';
        document.getElementById('transfer-speed').textContent = '';
        document.getElementById('transfer-eta').textContent = '';
        document.getElementById('progress-fill').style.width = '0%';
        document.getElementById('transfer-progress').style.display = 'block';
        
        // 파일 개수 표시
        const countEl = document.getElementById('transfer-count');
        if (totalFiles > 1) {
            countEl.textContent = `${currentFile} / ${totalFiles} 파일`;
            countEl.parentElement.style.display = 'block';
        } else {
            countEl.parentElement.style.display = 'none';
        }
        
        // 크기 표시 (바이트 전송이 있는 경우만)
        const sizeEl = document.getElementById('transfer-size');
        if (totalSize > 0) {
            sizeEl.textContent = `0 B / ${this.formatSize(totalSize)}`;
            sizeEl.style.display = '';
        } else {
            sizeEl.style.display = 'none';
        }
        
        // 전송 상태 초기화
        this.transfer.type = type;
        this.transfer.startTime = Date.now();
        this.transfer.lastTime = Date.now();
        this.transfer.lastBytes = 0;
        this.transfer.speed = 0;
        this.transfer.cancelled = false;
        this.transfer.totalFiles = totalFiles;
        this.transfer.completedFiles = currentFile - 1;
        this.transfer.currentFile = filename;
        this.transfer.totalSize = totalSize;
        this.transfer.transferredSize = 0;
    },
    
    // 파일 개수 업데이트
    updateTransferFileCount(currentFile, totalFiles, filename) {
        const countEl = document.getElementById('transfer-count');
        countEl.textContent = `${currentFile} / ${totalFiles} 파일`;
        document.getElementById('transfer-filename').textContent = filename;
        
        // 전체 진행률 (파일 개수 기준)
        const percent = Math.round((currentFile / totalFiles) * 100);
        document.getElementById('transfer-percent').textContent = `${percent}%`;
        document.getElementById('progress-fill').style.width = `${percent}%`;
        
        this.transfer.completedFiles = currentFile;
        this.transfer.currentFile = filename;
    },
    
    // 바이트 기반 진행률 업데이트 (복사/이동/삭제용)
    updateTransferProgressWithSize(currentFile, totalFiles, filename, processedSize, totalSize) {
        const now = Date.now();
        const elapsed = (now - this.transfer.lastTime) / 1000;
        
        // 속도 계산 (최소 0.3초마다 업데이트)
        if (elapsed >= 0.3) {
            const bytesDiff = processedSize - this.transfer.lastBytes;
            this.transfer.speed = bytesDiff / elapsed;
            this.transfer.lastTime = now;
            this.transfer.lastBytes = processedSize;
        }
        
        // 남은 시간 계산
        let eta = '';
        if (this.transfer.speed > 0 && processedSize < totalSize) {
            const remaining = totalSize - processedSize;
            const seconds = Math.ceil(remaining / this.transfer.speed);
            eta = this.formatTime(seconds);
        }
        
        // 퍼센트 계산 (바이트 기준)
        const percent = totalSize > 0 ? Math.round((processedSize / totalSize) * 100) : Math.round((currentFile / totalFiles) * 100);
        
        // UI 업데이트
        const countEl = document.getElementById('transfer-count');
        countEl.textContent = `${currentFile} / ${totalFiles} 파일`;
        document.getElementById('transfer-filename').textContent = filename;
        document.getElementById('transfer-percent').textContent = `${percent}%`;
        document.getElementById('progress-fill').style.width = `${percent}%`;
        
        // 크기와 속도 표시
        if (totalSize > 0) {
            document.getElementById('transfer-speed').textContent = this.transfer.speed > 0 ? `${this.formatSize(this.transfer.speed)}/s` : '';
            document.getElementById('transfer-size').textContent = `${this.formatSize(processedSize)} / ${this.formatSize(totalSize)}`;
            document.getElementById('transfer-size').style.display = '';
            document.getElementById('transfer-eta').textContent = eta ? `남은 시간: ${eta}` : '';
        }
        
        this.transfer.completedFiles = currentFile;
        this.transfer.currentFile = filename;
    },
    
    updateTransferProgress(percent, transferred, total) {
        const now = Date.now();
        const elapsed = (now - this.transfer.lastTime) / 1000; // 초
        
        // 속도 계산 (최소 0.5초마다 업데이트)
        if (elapsed >= 0.5) {
            const bytesDiff = transferred - this.transfer.lastBytes;
            this.transfer.speed = bytesDiff / elapsed;
            this.transfer.lastTime = now;
            this.transfer.lastBytes = transferred;
        }
        
        // 남은 시간 계산
        let eta = '';
        if (this.transfer.speed > 0 && transferred < total) {
            const remaining = total - transferred;
            const seconds = Math.ceil(remaining / this.transfer.speed);
            eta = this.formatTime(seconds);
        }
        
        // UI 업데이트
        document.getElementById('transfer-percent').textContent = `${percent}%`;
        document.getElementById('transfer-speed').textContent = this.transfer.speed > 0 ? `${this.formatSize(this.transfer.speed)}/s` : '';
        document.getElementById('transfer-size').textContent = `${this.formatSize(transferred)} / ${this.formatSize(total)}`;
        document.getElementById('transfer-eta').textContent = eta ? `남은 시간: ${eta}` : '';
        document.getElementById('progress-fill').style.width = `${percent}%`;
    },
    
    hideTransferProgress() {
        document.getElementById('transfer-progress').style.display = 'none';
        this.transfer.cancelled = false;
        this.transfer.type = '';
    },
    
    // 시간 포맷 (초 → 시:분:초)
    formatTime(seconds) {
        if (seconds < 60) {
            return `${seconds}초`;
        } else if (seconds < 3600) {
            const min = Math.floor(seconds / 60);
            const sec = seconds % 60;
            return `${min}분 ${sec}초`;
        } else {
            const hr = Math.floor(seconds / 3600);
            const min = Math.floor((seconds % 3600) / 60);
            return `${hr}시간 ${min}분`;
        }
    },
    
    // 다운로드 (진행률 표시)
    async downloadFile(path, showProgress = true, forceProgress = false, storageId = null) {
        const targetStorageId = storageId || this.currentStorage;
        const url = `api.php?action=download&storage_id=${targetStorageId}&path=${encodeURIComponent(path)}`;
        const filename = path.split('/').pop();
        
        try {
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error('다운로드 실패');
            }
            
            // Content-Length 헤더에서 파일 크기 가져오기
            const contentLength = response.headers.get('Content-Length');
            const total = parseInt(contentLength, 10) || 0;
            
            // 크기를 알 수 없거나, (강제가 아니고 작은 파일)이면 기존 방식
            if (!showProgress || total === 0 || (!forceProgress && total < 1024 * 1024)) {
                const blob = await response.blob();
                this.saveBlob(blob, filename);
                // 최근 파일에 추가
                this.addToRecentFiles(path, filename, 'download');
                return;
            }
            
            // 진행률 표시
            this.showTransferProgress('download', filename, total);
            
            // ReadableStream으로 진행률 추적
            const reader = response.body.getReader();
            const chunks = [];
            let received = 0;
            
            while (true) {
                if (this.transfer.cancelled) {
                    reader.cancel();
                    this.hideTransferProgress();
                    this.toast('다운로드가 취소되었습니다', 'info');
                    return;
                }
                
                const { done, value } = await reader.read();
                
                if (done) break;
                
                chunks.push(value);
                received += value.length;
                
                const percent = Math.round((received / total) * 100);
                this.updateTransferProgress(percent, received, total);
            }
            
            this.hideTransferProgress();
            
            // Blob으로 합치기
            const blob = new Blob(chunks);
            this.saveBlob(blob, filename);
            
            // 최근 파일에 추가
            this.addToRecentFiles(path, filename, 'download');
            
        } catch (e) {
            this.hideTransferProgress();
            // 오류 시 기존 방식으로 폴백
            window.location.href = url;
        }
    },
    
    // Blob 저장 헬퍼
    saveBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    },
    
    // 다른 이름으로 저장 (저장 다이얼로그 표시)
    async saveFileAs(path, filename, storageId = null) {
        const targetStorageId = storageId || this.currentStorage;
        const url = `api.php?action=download&storage_id=${targetStorageId}&path=${encodeURIComponent(path)}`;
        const name = filename || path.split('/').pop();
        const isSecure = location.protocol === 'https:';
        
        // HTTPS + File System Access API 지원 시 저장 다이얼로그
        if (isSecure && window.showSaveFilePicker) {
            try {
                const ext = name.includes('.') ? name.split('.').pop() : '';
                
                const handle = await window.showSaveFilePicker({
                    suggestedName: name,
                    types: ext ? [{
                        description: ext.toUpperCase() + ' 파일',
                        accept: { ['application/' + ext]: ['.' + ext] }
                    }] : []
                });
                
                const response = await fetch(url);
                const blob = await response.blob();
                
                const writable = await handle.createWritable();
                await writable.write(blob);
                await writable.close();
                
                this.toast('저장되었습니다', 'success');
                return;
            } catch (e) {
                if (e.name !== 'AbortError') {
                    console.error('saveFileAs error:', e);
                }
                return;
            }
        }
        
        // HTTP: 일반 다운로드 (브라우저 설정에 따름)
        window.location.href = url;
        
        if (!isSecure) {
            this.toast('HTTP에서는 저장 위치 선택이 불가능합니다. HTTPS를 사용하세요.', 'info');
        }
    },
    
    // 잘라내기 (클립보드에 저장)
    clipboardCut() {
        // 체크박스 또는 클릭 선택된 항목 수집
        const items = this.getSelectedOrCheckedItems();
        
        if (!items.length) {
            this.toast('항목을 선택하세요', 'warning');
            return;
        }
        
        // 검색 결과에서 선택한 경우 첫 번째 아이템의 storageId 사용
        const sourceStorageId = items[0].storageId || this.currentStorage;
        
        this.clipboard = {
            items: items,
            mode: 'cut',
            storageId: sourceStorageId
        };
        
        const count = items.length;
        this.toast(`${count}개 항목이 잘라내기되었습니다. 붙여넣기할 위치로 이동하세요.`, 'info');
        this.updatePasteButton();
    },
    
    // 복사 (클립보드에 저장)
    clipboardCopy() {
        // 체크박스 또는 클릭 선택된 항목 수집
        const items = this.getSelectedOrCheckedItems();
        
        if (!items.length) {
            this.toast('항목을 선택하세요', 'warning');
            return;
        }
        
        // 검색 결과에서 선택한 경우 첫 번째 아이템의 storageId 사용
        const sourceStorageId = items[0].storageId || this.currentStorage;
        
        this.clipboard = {
            items: items,
            mode: 'copy',
            storageId: sourceStorageId
        };
        
        const count = items.length;
        this.toast(`${count}개 항목이 복사되었습니다. 붙여넣기할 위치로 이동하세요.`, 'info');
        this.updatePasteButton();
    },
    
    // 체크박스 또는 클릭 선택된 항목 수집
    getSelectedOrCheckedItems() {
        
        // 먼저 체크박스 선택 확인 (.item-checkbox 클래스 사용)
        const checked = document.querySelectorAll('.item-checkbox:checked');
        
        if (checked.length > 0) {
            const items = [];
            checked.forEach(el => {
                const fileItem = el.closest('.file-item');
                if (fileItem) {
                    const isDir = fileItem.getAttribute('data-is-dir');
                    items.push({
                        path: fileItem.getAttribute('data-path'),
                        name: fileItem.getAttribute('data-name'),
                        isDir: isDir === 'true' || isDir === '1',
                        size: parseInt(fileItem.getAttribute('data-size')) || 0,
                        storageId: parseInt(fileItem.getAttribute('data-storage-id')) || this.currentStorage
                    });
                }
            });
            return items;
        }
        
        // 체크박스 선택이 없으면 클릭 선택 사용
        return this.selectedItems;
    },
    
    // 붙여넣기 버튼 상태 업데이트
    updatePasteButton() {
        const btn = document.getElementById('btn-paste');
        if (btn) {
            if (this.clipboard.items.length > 0) {
                btn.style.display = '';
                const mode = this.clipboard.mode === 'cut' ? '이동' : '복사';
                btn.textContent = `📋 붙여넣기 (${this.clipboard.items.length}개 ${mode})`;
            } else {
                btn.style.display = 'none';
            }
        }
    },
    
    // 붙여넣기
    async clipboardPaste() {
        if (!this.clipboard.items.length) {
            this.toast('클립보드가 비어있습니다', 'warning');
            return;
        }
        
        const mode = this.clipboard.mode;
        const items = this.clipboard.items;
        const sourceStorageId = this.clipboard.storageId;
        const destPath = this.currentPath;
        
        // 대상 폴더의 파일 목록 가져오기 (GET 방식)
        const listRes = await this.api('files', {
            storage_id: this.currentStorage,
            path: destPath
        }, 'GET');
        
        if (!listRes.success) {
            this.toast('대상 폴더를 읽을 수 없습니다', 'error');
            return;
        }
        
        // 기존 파일명 목록 (응답이 items)
        const existingNames = new Set((listRes.items || []).map(f => f.name));
        
        // 중복 파일 확인
        const duplicates = items.filter(item => existingNames.has(item.name));
        
        if (duplicates.length > 0) {
            // 중복 파일이 있으면 선택 모달 표시
            this.showDuplicateModal(duplicates, items, mode, sourceStorageId, destPath);
        } else {
            // 중복 없으면 바로 진행
            await this.executePaste(items, mode, sourceStorageId, destPath, 'copy');
        }
    },
    
    // 중복 파일 모달 표시
    showDuplicateModal(duplicates, allItems, mode, sourceStorageId, destPath) {
        const listEl = document.getElementById('duplicate-list');
        listEl.innerHTML = duplicates.map(item => 
            `<div class="duplicate-item">📄 ${this.escapeHtml(item.name)}</div>`
        ).join('');
        
        const total = allItems.length;
        const dupCount = duplicates.length;
        document.getElementById('duplicate-message').textContent = 
            `${total}개 중 ${dupCount}개 파일이 이미 존재합니다:`;
        
        // 버튼 이벤트 (일회성)
        const skipBtn = document.getElementById('btn-dup-skip-all');
        const overwriteBtn = document.getElementById('btn-dup-overwrite-all');
        const renameBtn = document.getElementById('btn-dup-rename-all');
        
        const cleanup = () => {
            skipBtn.replaceWith(skipBtn.cloneNode(true));
            overwriteBtn.replaceWith(overwriteBtn.cloneNode(true));
            renameBtn.replaceWith(renameBtn.cloneNode(true));
        };
        
        // 건너뛰기: 중복 파일 제외하고 복사
        skipBtn.onclick = async () => {
            closeModal();
            cleanup();
            const nonDuplicates = allItems.filter(item => 
                !duplicates.some(d => d.name === item.name)
            );
            if (nonDuplicates.length > 0) {
                await this.executePaste(nonDuplicates, mode, sourceStorageId, destPath, 'skip');
            } else {
                this.toast('복사할 파일이 없습니다', 'info');
            }
        };
        
        // 덮어쓰기: 모든 파일 덮어쓰기
        overwriteBtn.onclick = async () => {
            closeModal();
            cleanup();
            await this.executePaste(allItems, mode, sourceStorageId, destPath, 'overwrite');
        };
        
        // 이름 변경: 중복 파일은 (1), (2) 등 붙여서 복사
        renameBtn.onclick = async () => {
            closeModal();
            cleanup();
            await this.executePaste(allItems, mode, sourceStorageId, destPath, 'rename');
        };
        
        this.showModal('modal-duplicate');
    },
    
    // 실제 붙여넣기 실행
    async executePaste(items, mode, sourceStorageId, destPath, duplicateAction) {
        const totalFiles = items.length;
        
        // 총 크기 계산
        const totalSize = items.reduce((sum, item) => sum + (item.size || 0), 0);
        let processedSize = 0;
        
        // 진행 표시 시작
        const transferType = mode === 'cut' ? 'move' : 'copy';
        this.showTransferProgress(transferType, items[0].name, totalSize, totalFiles, 1);
        
        let success = 0;
        let failed = 0;
        let skipped = 0;
        
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            const currentFile = i + 1;
            
            // 진행 상태 업데이트 (바이트 기반)
            this.updateTransferProgressWithSize(currentFile, totalFiles, item.name, processedSize, totalSize);
            
            // 취소 확인
            if (this.transfer.cancelled) {
                this.hideTransferProgress();
                this.toast(`${mode === 'cut' ? '이동' : '복사'}가 취소되었습니다`, 'info');
                this.loadFiles();
                return;
            }
            
            const action = mode === 'cut' ? 'move' : 'copy';
            const res = await this.api(action, {
                storage_id: sourceStorageId,
                dest_storage_id: this.currentStorage,  // 대상 스토리지 추가
                source: item.path,
                dest: destPath,
                duplicate_action: duplicateAction  // 'skip', 'overwrite', 'rename'
            });
            
            if (res.success) {
                if (res.skipped) {
                    skipped++;
                } else {
                    success++;
                }
                processedSize += item.size || 0;
            } else {
                failed++;
                console.error(`${action} 실패:`, item.path, res.error);
            }
        }
        
        // 진행 표시 숨김
        this.hideTransferProgress();
        
        // 완료 후 클립보드 비우기 (복사/이동 모두)
        if (success > 0 || skipped > 0) {
            this.clipboard = { items: [], mode: null, storageId: null };
            this.updatePasteButton();
        }
        
        // 결과 메시지
        const actionName = mode === 'cut' ? '이동' : '복사';
        let message = [];
        if (success > 0) message.push(`${success}개 ${actionName}`);
        if (skipped > 0) message.push(`${skipped}개 건너뜀`);
        if (failed > 0) message.push(`${failed}개 실패`);
        
        if (success > 0 || skipped > 0) {
            this.toast(message.join(', '), success > 0 ? 'success' : 'info');
            this.loadFiles();
        } else if (failed > 0) {
            this.toast(message.join(', '), 'error');
        }
    },
    
    // 이동 모달 (기존 호환용 - 클립보드 방식으로 변경)
    showMoveModal() {
        this.clipboardCut();
    },
    
    // 복사 모달 (기존 호환용 - 클립보드 방식으로 변경)  
    showCopyModal() {
        this.clipboardCopy();
    },
    
    async moveFile(source, dest) {
        const res = await this.api('move', {
            storage_id: this.currentStorage,
            source: source,
            dest: dest
        });
        
        if (res.success) {
            this.toast('이동되었습니다', 'success');
            this.loadFiles();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    async copyFile(source, dest) {
        const res = await this.api('copy', {
            storage_id: this.currentStorage,
            source: source,
            dest: dest
        });
        
        if (res.success) {
            this.toast('복사되었습니다', 'success');
            this.loadFiles();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 새 폴더
    async createFolder() {
        const name = $('#new-folder-name').val().trim();
        if (!name) {
            this.toast('폴더 이름을 입력하세요', 'error');
            return;
        }
        
        const res = await this.api('mkdir', {
            storage_id: this.currentStorage,
            path: this.currentPath,
            name: name
        });
        
        if (res.success) {
            this.toast('폴더가 생성되었습니다', 'success');
            closeModal();
            $('#new-folder-name').val('');
            this.loadFiles();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 삭제 (기존 방식 - 선택된 항목)
    async deleteSelected() {
        if (!this.selectedItems.length) return;
        
        const names = this.selectedItems.map(i => i.name).join(', ');
        if (!confirm(`"${names}"을(를) 삭제하시겠습니까?`)) return;
        
        const totalFiles = this.selectedItems.length;
        const totalSize = this.selectedItems.reduce((sum, item) => sum + (item.size || 0), 0);
        let processedSize = 0;
        
        // 진행 표시 시작
        this.showTransferProgress('delete', this.selectedItems[0].name, totalSize, totalFiles, 1);
        
        let success = 0;
        let failed = 0;
        
        for (let i = 0; i < this.selectedItems.length; i++) {
            const item = this.selectedItems[i];
            
            // 취소 확인
            if (this.transfer.cancelled) {
                this.hideTransferProgress();
                this.toast('삭제가 취소되었습니다', 'info');
                this.loadFiles();
                return;
            }
            
            // 진행 상태 업데이트 (바이트 기반)
            this.updateTransferProgressWithSize(i + 1, totalFiles, item.name, processedSize, totalSize);
            
            const res = await this.api('delete', {
                storage_id: this.currentStorage,
                path: item.path
            });
            
            if (res.success) {
                success++;
                processedSize += item.size || 0;
            } else {
                failed++;
            }
        }
        
        this.hideTransferProgress();
        
        if (success > 0) {
            this.toast(`${success}개 항목이 삭제되었습니다`, 'success');
        }
        if (failed > 0) {
            this.toast(`${failed}개 항목 삭제 실패`, 'error');
        }
        this.loadFiles();
        this.updateTrashIcon();
    },
    
    // 체크박스 선택 상태 업데이트
    updateCheckboxSelection() {
        const checked = document.querySelectorAll('.file-checkbox:checked');
        const total = document.querySelectorAll('.file-checkbox');
        const selectAll = document.getElementById('select-all');
        const btnDeleteSelected = document.getElementById('btn-delete-selected');
        
        // 전체 선택 체크박스 상태 업데이트
        if (checked.length === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (checked.length === total.length) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else {
            selectAll.checked = false;
            selectAll.indeterminate = true;
        }
        
        // 선택 삭제 버튼 표시/숨김
        if (checked.length > 0) {
            btnDeleteSelected.style.display = '';
            btnDeleteSelected.textContent = `🗑️ 선택 삭제 (${checked.length})`;
        } else {
            btnDeleteSelected.style.display = 'none';
        }
    },
    
    // 체크된 파일 삭제
    async deleteCheckedFiles() {
        const checked = document.querySelectorAll('.file-checkbox:checked');
        if (checked.length === 0) {
            this.toast('삭제할 항목을 선택하세요', 'warning');
            return;
        }
        
        const items = [];
        checked.forEach(function(el) {
            const path = el.getAttribute('data-path');
            const fileItem = el.closest('.file-item');
            const size = fileItem ? parseInt(fileItem.getAttribute('data-size')) || 0 : 0;
            items.push({
                path: path,
                name: path.split('/').pop(),
                size: size
            });
        });
        
        if (!confirm(`선택한 ${items.length}개 항목을 삭제하시겠습니까?`)) return;
        
        const totalFiles = items.length;
        const totalSize = items.reduce((sum, item) => sum + item.size, 0);
        let processedSize = 0;
        
        // 진행 표시 시작
        this.showTransferProgress('delete', items[0].name, totalSize, totalFiles, 1);
        
        let success = 0;
        let failed = 0;
        
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            
            // 취소 확인
            if (this.transfer.cancelled) {
                this.hideTransferProgress();
                this.toast('삭제가 취소되었습니다', 'info');
                this.loadFiles();
                return;
            }
            
            // 진행 상태 업데이트 (바이트 기반)
            this.updateTransferProgressWithSize(i + 1, totalFiles, item.name, processedSize, totalSize);
            
            const res = await this.api('delete', {
                storage_id: this.currentStorage,
                path: item.path
            });
            
            if (res.success) {
                success++;
                processedSize += item.size;
            } else {
                failed++;
            }
        }
        
        this.hideTransferProgress();
        
        if (success > 0) {
            this.toast(`${success}개 항목이 삭제되었습니다`, 'success');
        }
        if (failed > 0) {
            this.toast(`${failed}개 항목 삭제 실패`, 'error');
        }
        this.loadFiles();
        this.updateTrashIcon();
    },
    
    // 전체 삭제 (현재 폴더 내 모든 항목)
    async deleteAllFiles() {
        const checkboxes = document.querySelectorAll('.file-checkbox');
        if (checkboxes.length === 0) {
            this.toast('삭제할 항목이 없습니다', 'warning');
            return;
        }
        
        if (!confirm(`현재 폴더의 모든 항목(${checkboxes.length}개)을 삭제하시겠습니까?\n\n⚠️ 이 작업은 되돌릴 수 없습니다.`)) return;
        
        // 한번 더 확인
        if (!confirm('정말로 모든 항목을 삭제하시겠습니까?')) return;
        
        const items = [];
        checkboxes.forEach(function(el) {
            const path = el.getAttribute('data-path');
            const fileItem = el.closest('.file-item');
            const size = fileItem ? parseInt(fileItem.getAttribute('data-size')) || 0 : 0;
            items.push({
                path: path,
                name: path.split('/').pop(),
                size: size
            });
        });
        
        const totalFiles = items.length;
        const totalSize = items.reduce((sum, item) => sum + item.size, 0);
        let processedSize = 0;
        
        // 진행 표시 시작
        this.showTransferProgress('delete', items[0].name, totalSize, totalFiles, 1);
        
        let success = 0;
        let failed = 0;
        
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            
            // 취소 확인
            if (this.transfer.cancelled) {
                this.hideTransferProgress();
                this.toast('삭제가 취소되었습니다', 'info');
                this.loadFiles();
                return;
            }
            
            // 진행 상태 업데이트 (바이트 기반)
            this.updateTransferProgressWithSize(i + 1, totalFiles, item.name, processedSize, totalSize);
            
            const res = await this.api('delete', {
                storage_id: this.currentStorage,
                path: item.path
            });
            
            if (res.success) {
                success++;
                processedSize += item.size;
            } else {
                failed++;
            }
        }
        
        this.hideTransferProgress();
        
        if (success > 0) {
            this.toast(`${success}개 항목이 삭제되었습니다`, 'success');
        }
        if (failed > 0) {
            this.toast(`${failed}개 항목 삭제 실패`, 'error');
        }
        this.loadFiles();
        this.updateTrashIcon();
    },
    
    // 이름 변경 모달
    showRenameModal() {
        if (!this.selectedItems.length) return;
        
        const item = this.selectedItems[0];
        $('#rename-input').val(item.name);
        this.showModal('modal-rename');
        $('#rename-input').focus().select();
    },
    
    // 이름 변경
    async renameFile() {
        const newName = $('#rename-input').val().trim();
        if (!newName) return;
        
        const item = this.selectedItems[0];
        
        const res = await this.api('rename', {
            storage_id: this.currentStorage,
            path: item.path,
            new_name: newName
        });
        
        if (res.success) {
            this.toast('이름이 변경되었습니다', 'success');
            closeModal();
            this.loadFiles();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 통합 검색
    // ===== 통합 검색 =====
    searchState: {
        query: '',
        filters: {},
        page: 1,
        totalPages: 1,
        total: 0
    },
    
    async doSearch(page = 1) {
        const query = $('#search-input').val().trim();
        if (!query) {
            this.toast('검색어를 입력하세요', 'warning');
            return;
        }
        
        // 필터 수집
        const filters = {
            type: $('#filter-type').val() || 'all',
            date_from: $('#filter-date-from').val() || '',
            date_to: $('#filter-date-to').val() || '',
            size_min: (parseInt($('#filter-size-min').val()) || 0) * 1024 * 1024,
            size_max: (parseInt($('#filter-size-max').val()) || 0) * 1024 * 1024
        };
        
        // 상태 저장
        this.searchState.query = query;
        this.searchState.filters = filters;
        this.searchState.page = page;
        
        // UI 업데이트
        $('#file-list').html('<div class="empty-msg">🔍 검색 중...</div>');
        $('#search-pagination').hide();
        
        // API 호출 (정렬 포함)
        const res = await this.api('search_advanced', {
            storage_id: 0, // 전체 스토리지
            query: query,
            filters: filters,
            page: page,
            per_page: 50,
            sort_by: this.sortBy || 'name',
            sort_order: this.sortOrder || 'asc'
        });
        
        if (!res.success) {
            this.toast(res.error || '검색 실패', 'error');
            return;
        }
        
        // 상태 업데이트
        this.searchState.total = res.total || 0;
        this.searchState.totalPages = res.total_pages || 1;
        
        // 검색 모드 활성화
        this.isSearchMode = true;
        this.searchQuery = query;
        
        // 검색 결과 헤더 표시
        const startNum = (page - 1) * 50 + 1;
        const endNum = Math.min(page * 50, res.total);
        $('#search-result-header').show();
        $('#search-result-header .search-query').text(`🔍 "${query}"`);
        $('#search-result-header .search-count').text(
            res.total > 0 
                ? `${res.total.toLocaleString()}개 결과 (${startNum}-${endNum} 표시중)`
                : '결과 없음'
        );
        
        // 결과 렌더링
        this.renderSearchResults(res.results || []);
        
        // 페이지네이션 표시
        if (res.total_pages > 1) {
            this.renderSearchPagination(page, res.total_pages);
            $('#search-pagination').show();
        } else {
            $('#search-pagination').hide();
        }
    },
    
    // 검색어 하이라이트
    highlightSearchText(text, query) {
        if (!query || !text) return this.escapeHtml(text);
        
        // 와일드카드를 정규식으로 변환
        // *.mp3 → .mp3, test* → test 등 실제 매칭 부분 추출
        let searchPattern = query
            .replace(/\*/g, '')  // * 제거
            .replace(/\?/g, ''); // ? 제거
        
        if (!searchPattern) return this.escapeHtml(text);
        
        // 대소문자 무시 검색
        const escapedPattern = searchPattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${escapedPattern})`, 'gi');
        const escaped = this.escapeHtml(text);
        
        return escaped.replace(regex, '<mark class="search-highlight">$1</mark>');
    },
    
    // 검색 결과 렌더링
    renderSearchResults(items) {
        const list = document.getElementById('file-list');
        list.innerHTML = '';
        
        if (items.length === 0) {
            list.innerHTML = '<div class="empty-folder">검색 결과가 없습니다</div>';
            return;
        }
        
        const searchQuery = this.searchState.query || '';
        
        // 디버그: 첫 번째 항목의 storage_id 확인
        
        items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'file-item';
            
            const filepath = item.filepath || item.path || '';
            const filename = filepath.split('/').pop();
            const isDir = item.is_dir ? true : false;
            const icon = isDir ? '📁' : this.getFileIcon(filename);
            const folderPath = filepath.substring(0, filepath.lastIndexOf('/')) || '/';
            
            div.setAttribute('data-path', filepath);
            div.setAttribute('data-name', filename);
            div.setAttribute('data-is-dir', isDir ? '1' : '0');
            div.setAttribute('data-size', item.size || 0);
            div.setAttribute('data-storage-id', item.storage_id);
            
            const storageName = item.storage_name || '';
            
            // 파일명에 검색어 하이라이트 적용
            const highlightedName = this.highlightSearchText(filename, searchQuery);
            
            div.innerHTML = `
                <div class="file-checkbox">
                    <input type="checkbox" class="item-checkbox">
                </div>
                <div class="file-icon">${icon}</div>
                <div class="file-info">
                    <div class="file-name">${highlightedName}</div>
                    <div class="file-meta">
                        <span class="search-storage">[${this.escapeHtml(storageName)}]</span>
                        <span class="search-path">${this.escapeHtml(folderPath)}</span>
                    </div>
                </div>
                <div class="file-size">${isDir ? '' : this.formatSize(item.size || 0)}</div>
                <div class="file-date">${item.modified || ''}</div>
            `;
            
            // 클릭 이벤트 - PC와 모바일 모두 지원
            const isMobile = () => window.innerWidth <= 768 || 'ontouchstart' in window;
            
            div.addEventListener('click', (e) => {
                if (e.target.closest('.file-checkbox')) return;
                
                // 선택 처리 (PC/모바일 공통)
                if (!e.ctrlKey && !e.metaKey) {
                    document.querySelectorAll('.file-item.selected').forEach(el => el.classList.remove('selected'));
                }
                div.classList.toggle('selected');
                
                // selectedItems 업데이트
                this.updateSearchSelection();
                
                // 모바일: 이미 선택된 항목 다시 클릭하면 이동
                if (isMobile() && div.classList.contains('selected')) {
                    // 두 번째 클릭인지 확인
                    if (div.dataset.lastClick && Date.now() - div.dataset.lastClick < 500) {
                        this.navigateToSearchResult(item);
                    }
                    div.dataset.lastClick = Date.now();
                }
            });
            
            // 우클릭 - 컨텍스트 메뉴용 선택 및 메뉴 표시
            div.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                // 선택 안 된 항목 우클릭하면 해당 항목만 선택
                if (!div.classList.contains('selected')) {
                    document.querySelectorAll('.file-item.selected').forEach(el => el.classList.remove('selected'));
                    div.classList.add('selected');
                    this.updateSearchSelection();
                }
                
                // 컨텍스트 메뉴 표시
                this.showContextMenu(e.pageX, e.pageY, false);
            });
            
            div.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                e.preventDefault();
                this.navigateToSearchResult(item);
            });
            
            div.classList.add('search-result-item');
            list.appendChild(div);
        });
    },
    
    // 검색 결과 선택 업데이트
    updateSearchSelection() {
        this.selectedItems = [];
        document.querySelectorAll('.file-item.selected').forEach(el => {
            const isDir = el.getAttribute('data-is-dir');
            const item = {
                path: el.getAttribute('data-path'),
                name: el.getAttribute('data-name'),
                isDir: isDir === 'true' || isDir === '1',
                size: parseInt(el.getAttribute('data-size')) || 0,
                storageId: parseInt(el.getAttribute('data-storage-id')) || this.currentStorage
            };
            this.selectedItems.push(item);
        });
    },
    
    // 검색 페이지네이션 렌더링
    renderSearchPagination(currentPage, totalPages) {
        let html = '<div class="page-buttons">';
        
        // 이전 버튼
        if (currentPage > 1) {
            html += `<button class="page-btn" data-page="${currentPage - 1}">◀ 이전</button>`;
        }
        
        // 페이지 번호
        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        
        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        if (startPage > 1) {
            html += `<button class="page-btn" data-page="1">1</button>`;
            if (startPage > 2) html += '<span style="color:#999;padding:0 5px;">...</span>';
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const isActive = i === currentPage;
            html += `<button class="page-btn${isActive ? ' active' : ''}" data-page="${i}">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += '<span style="color:#999;padding:0 5px;">...</span>';
            html += `<button class="page-btn" data-page="${totalPages}">${totalPages}</button>`;
        }
        
        // 다음 버튼
        if (currentPage < totalPages) {
            html += `<button class="page-btn" data-page="${currentPage + 1}">다음 ▶</button>`;
        }
        
        html += '</div>';
        html += `<div class="page-info">${currentPage} / ${totalPages} 페이지</div>`;
        
        const paginationDiv = document.getElementById('search-pagination');
        paginationDiv.innerHTML = html;
        
        // 페이지 버튼 이벤트
        paginationDiv.querySelectorAll('.page-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = parseInt(btn.dataset.page);
                if (p && p !== currentPage) {
                    this.doSearch(p);
                    // 상단으로 스크롤
                    document.getElementById('file-list').scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    },
    
    // 검색 결과 항목으로 이동
    async navigateToSearchResult(item) {
        // 검색 모드 종료
        this.exitSearchMode();
        
        // 스토리지 변경
        this.currentStorage = item.storage_id;
        
        // 사이드바 스토리지 선택 업데이트
        $('.storage-item').removeClass('active');
        $(`.storage-item[data-id="${item.storage_id}"]`).addClass('active');
        
        // 경로 계산
        const filepath = item.filepath || item.path || '';
        const isDir = item.is_dir ? true : false;
        let targetPath;
        
        if (isDir) {
            targetPath = filepath;
        } else {
            const pathParts = filepath.split('/');
            pathParts.pop();
            targetPath = pathParts.join('/');
        }
        
        this.currentPath = targetPath;
        
        const result = await this.loadFiles();
        
        if (!result.success) {
            this.currentPath = '';
            this.toast('해당 경로를 찾을 수 없습니다. 인덱스를 재구축해주세요.', 'warning');
            await this.loadFiles();
            return;
        }
        
        // 파일이면 선택 상태로
        if (!isDir) {
            const filename = filepath.split('/').pop();
            setTimeout(() => {
                const fileItem = document.querySelector(`[data-name="${filename}"]`);
                if (fileItem) {
                    fileItem.classList.add('selected');
                    fileItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 100);
        }
    },
    
    // 검색 필터 초기화
    resetSearchFilters() {
        $('#filter-type').val('all');
        $('#filter-date-from').val('');
        $('#filter-date-to').val('');
        $('#filter-size-min').val('');
        $('#filter-size-max').val('');
        
        // 검색 중이면 재검색
        if (this.isSearchMode && this.searchState.query) {
            this.doSearch(1);
        }
    },
    
    // 검색 모드 종료
    exitSearchMode() {
        this.isSearchMode = false;
        this.searchQuery = '';
        this.searchState = { query: '', filters: {}, page: 1, totalPages: 1, total: 0 };
        
        $('#search-input').val('');
        $('#mobile-search-input').val('');
        $('#search-result-header').hide();
        $('#search-pagination').hide();
        $('#search-filters').hide();
        $('#search-filter-toggle').removeClass('active');
        
        // 필터 초기화
        $('#filter-type').val('all');
        $('#filter-date-from').val('');
        $('#filter-date-to').val('');
        $('#filter-size-min').val('');
        $('#filter-size-max').val('');
        
        sessionStorage.removeItem('webhard_search');
        this.loadFiles();
    },
    
    // 공유 URL 생성 헬퍼
    getShareUrl(token) {
        // 외부 URL 설정이 있으면 사용
        const externalUrl = this.systemSettings.external_url;
        if (externalUrl) {
            return `${externalUrl.replace(/\/$/, '')}/share.php?t=${token}`;
        }
        // 기본: 현재 접속 URL 사용
        return `${window.location.origin}${window.location.pathname.replace('index.php', '')}share.php?t=${token}`;
    },
    
    // 공유 모달
    async showShareModal(item) {
        
        // selectedItems에 item 설정 (공유 생성 시 사용)
        this.selectedItems = [item];
        
        // 검색 결과에서 선택한 경우 해당 스토리지 ID 사용
        const storageId = item.storageId || this.currentStorage;
        
        $('#share-filename').text(item.path);
        $('#share-result').hide();
        $('#btn-create-share').show();
        $('#share-expire').val('7');
        $('#share-password').val('');
        $('#share-max-downloads').val('');
        
        // 기존 공유 링크 확인
        const res = await this.api('share_check', {
            storage_id: storageId,
            path: item.path
        }, 'GET');
        
        if (res.success && res.share) {
            const url = this.getShareUrl(res.share.token);
            $('#share-url').val(url);
            $('#share-result').show();
            $('#btn-create-share').hide();
        }
        
        this.showModal('modal-share');
    },
    
    // 공유 생성
    async createShare() {
        const item = this.selectedItems[0];
        
        if (!item) {
            this.toast('공유할 파일을 선택해주세요', 'error');
            return;
        }
        
        // 검색 결과에서 선택한 경우 해당 스토리지 ID 사용
        const storageId = item.storageId || this.currentStorage;
        
        const res = await this.api('share_create', {
            storage_id: storageId,
            path: item.path,
            expire_days: $('#share-expire').val() || null,
            password: $('#share-password').val() || null,
            max_downloads: $('#share-max-downloads').val() || null
        });
        
        if (res.success) {
            $('#share-url').val(res.url);
            $('#share-result').show();
            $('#btn-create-share').hide();
            this.toast('공유 링크가 생성되었습니다', 'success');
            
            // 검색 모드가 아닐 때만 파일 목록 새로고침
            if (!this.isSearchMode) {
                this.loadFiles();
            }
        } else {
            this.toast(res.error || '공유 생성 실패', 'error');
        }
    },
    
    // 공유 URL 복사
    copyShareUrl() {
        const url = $('#share-url').val();
        this.copyToClipboard(url);
    },
    
    // 클립보드 복사 (HTTP 호환)
    copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                this.toast('클립보드에 복사되었습니다', 'success');
            }).catch(() => {
                this.fallbackCopy(text);
            });
        } else {
            this.fallbackCopy(text);
        }
    },
    
    fallbackCopy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            this.toast('클립보드에 복사되었습니다', 'success');
        } catch (e) {
            this.toast('복사 실패: 직접 선택해서 복사하세요', 'error');
        }
        document.body.removeChild(textarea);
    },
    
    // 파일 정보 모달
    async showInfoModal(item) {
        const res = await this.api('info', {
            storage_id: this.currentStorage,
            path: item.path
        }, 'GET');
        
        if (!res.success) {
            this.toast(res.error, 'error');
            return;
        }
        
        const info = res.info;
        let html = `
            <tr><td>이름</td><td>${info.name}</td></tr>
            <tr><td>유형</td><td>${info.is_dir ? '폴더' : '파일'}</td></tr>
            <tr><td>크기</td><td>${this.formatSize(info.size)}</td></tr>
            <tr><td>수정일</td><td>${info.modified}</td></tr>
            <tr><td>생성일</td><td>${info.created}</td></tr>
        `;
        
        if (!info.is_dir) {
            html += `<tr><td>MIME</td><td>${info.mime_type}</td></tr>`;
        }
        
        $('#file-info-table').html(html);
        this.showModal('modal-info');
    },
    
    // 스토리지 저장
    // 스토리지 목록 모달
    async showStoragesModal() {
        // 먼저 로딩 표시 후 모달 열기
        $('#storages-table tbody').html('<tr><td colspan="6" class="text-center">로딩 중...</td></tr>');
        this.showModal('modal-storages');
        
        const res = await this.api('storages_all', {}, 'GET');
        if (!res.success) return;
        
        const tbody = $('#storages-table tbody').empty();
        
        res.storages.forEach(s => {
            const typeName = {
                'local': '로컬',
                'smb': 'SMB',
                'home': '홈',
                'shared': '공유',
                'ftp': 'FTP',
                'sftp': 'SFTP',
                'webdav': 'WebDAV',
                's3': 'S3'
            }[s.storage_type] || s.storage_type;
            
            // 용량 표시 (간결하게)
            const quota = parseInt(s.quota) || 0;
            const usedSize = parseInt(s.used_size) || 0;
            let quotaHtml = '<span style="color:#888;">무제한</span>';
            if (quota > 0) {
                const percent = Math.round((usedSize / quota) * 100);
                const barColor = percent > 90 ? '#e74c3c' : percent > 70 ? '#f39c12' : '#3498db';
                quotaHtml = `
                    <div style="white-space:nowrap;">${this.formatSize(usedSize)} / ${this.formatSize(quota)}</div>
                    <div style="background:#eee;height:4px;border-radius:2px;margin-top:3px;width:100px;">
                        <div style="background:${barColor};height:100%;width:${percent}%;border-radius:2px;"></div>
                    </div>
                `;
            } else if (usedSize > 0) {
                quotaHtml = `<span>${this.formatSize(usedSize)}</span>`;
            }
            
            // 경로 (너무 길면 줄임)
            const path = s.path || '-';
            const shortPath = path.length > 25 ? '...' + path.slice(-22) : path;
            
            tbody.append(`
                <tr>
                    <td style="text-align:center;color:#888;font-size:0.85em;">${s.id}</td>
                    <td><strong>${this.escapeHtml(s.name)}</strong></td>
                    <td class="path-cell" title="${this.escapeHtml(path)}" style="font-size:0.9em;color:#666;">${this.escapeHtml(shortPath)}</td>
                    <td style="text-align:center;">${typeName}</td>
                    <td>${quotaHtml}</td>
                    <td style="font-size:0.9em;color:#666;">${this.escapeHtml(s.description) || '-'}</td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm" onclick="App.editStorage(${s.id})">수정</button>
                        <button class="btn btn-sm btn-danger" onclick="App.deleteStorage(${s.id})">삭제</button>
                    </td>
                </tr>
            `);
        });
    },
    
    // 스토리지 수정
    editStorage(id) {
        closeModal();
        this.showStorageModal(id);
    },
    
    // 스토리지 사용량 재계산
    async recalculateStorageSize(storageId) {
        if (!confirm('사용량을 재계산하시겠습니까?\n대용량 스토리지는 시간이 걸릴 수 있습니다.')) return;
        
        const btn = document.getElementById('btn-recalculate');
        if (btn) {
            btn.disabled = true;
            btn.textContent = '⏳ 계산중...';
        }
        
        const res = await this.api('storage_recalculate', { storage_id: storageId });
        
        if (btn) {
            btn.disabled = false;
            btn.textContent = '📊 사용량 재계산';
        }
        
        if (res.success) {
            $('#storage-used-size').text(`(사용: ${res.used_size_formatted})`);
            this.toast('재계산 완료: ' + res.used_size_formatted, 'success');
        } else {
            this.toast(res.error || '재계산 실패', 'error');
        }
    },
    
    // 스토리지 삭제
    async deleteStorage(id) {
        if (!confirm('정말 삭제하시겠습니까?\n이 스토리지의 모든 공유 링크도 삭제됩니다.')) return;
        
        const res = await this.api('storage_delete', { id });
        
        if (res.success) {
            this.toast('삭제되었습니다', 'success');
            this.showStoragesModal();
            this.loadStorages();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 스토리지 모달 표시
    async showStorageModal(storageId = null) {
        // 모든 옵션 초기화
        $('#storage-id').val(storageId || '');
        $('#storage-modal-title').text(storageId ? '스토리지 수정' : '스토리지 추가');
        
        // 모든 입력 필드 초기화
        $('#storage-name, #storage-path, #storage-desc').val('');
        $('#smb-host, #smb-share, #smb-username, #smb-password').val('');
        $('#ftp-host, #ftp-username, #ftp-password, #ftp-root').val('');
        $('#ftp-port').val('21');
        $('#ftp-passive').prop('checked', true);
        $('#ftp-ssl').prop('checked', false);
        $('#sftp-host, #sftp-username, #sftp-password, #sftp-private-key, #sftp-root').val('');
        $('#sftp-port').val('22');
        $('#sftp-auth-type').val('password');
        $('#sftp-password-group').show();
        $('#sftp-key-group').hide();
        $('#webdav-url, #webdav-username, #webdav-password').val('');
        $('#s3-endpoint, #s3-region, #s3-bucket, #s3-access-key, #s3-secret-key, #s3-prefix').val('');
        
        // 용량 초기화
        $('#storage-quota-value').val(0);
        $('#storage-quota-unit').val('1073741824'); // GB
        $('#storage-used-size').text('');
        $('#storage-calc-usage').prop('checked', false);
        $('#calc-usage-warning').hide();
        const recalcBtn = document.getElementById('btn-recalculate');
        if (recalcBtn) recalcBtn.style.display = 'none';
        
        // 모든 옵션 숨기고 기본값만 표시
        $('.storage-options').hide();
        $('#storage-type').val('local').prop('disabled', false);
        $('#storage-type option[value="shared"]').hide();  // shared 옵션 숨김
        $('#storage-local-options').show();
        
        // 유저 목록 로드
        await this.loadPermissionList(storageId);
        
        // 수정 모드면 기존 데이터 로드
        if (storageId) {
            const res = await this.api('storage_get', { id: storageId }, 'GET');
            if (res.success && res.storage) {
                const s = res.storage;
                const type = s.storage_type || 'local';
                
                $('#storage-name').val(s.name);
                $('#storage-type').val(type);
                $('#storage-desc').val(s.description || '');
                
                // 공유폴더는 타입 변경 불가
                if (type === 'shared') {
                    $('#storage-type').prop('disabled', true);
                    // shared 옵션 표시
                    $('#storage-type option[value="shared"]').show();
                } else {
                    $('#storage-type').prop('disabled', false);
                }
                
                // 용량 설정 로드
                const quota = parseInt(s.quota) || 0;
                const usedSize = parseInt(s.used_size) || 0;
                if (quota >= 1099511627776) { // TB 이상
                    $('#storage-quota-value').val(Math.round(quota / 1099511627776));
                    $('#storage-quota-unit').val('1099511627776');
                } else {
                    $('#storage-quota-value').val(Math.round(quota / 1073741824));
                    $('#storage-quota-unit').val('1073741824');
                }
                if (usedSize > 0 || quota > 0) {
                    $('#storage-used-size').text(`(사용: ${this.formatSize(usedSize)})`);
                    const btn = document.getElementById('btn-recalculate');
                    if (btn) {
                        btn.style.display = 'inline-block';
                        // 기존 이벤트 제거 후 새로 등록
                        const newBtn = btn.cloneNode(true);
                        btn.parentNode.replaceChild(newBtn, btn);
                        newBtn.addEventListener('click', () => this.recalculateStorageSize(storageId));
                    }
                }
                
                // 모든 옵션 숨기고 해당 타입만 표시
                $('.storage-options').hide();
                $(`#storage-${type}-options`).show();
                
                // 타입별 데이터 로드
                switch (type) {
                    case 'local':
                        $('#storage-path').val(s.path);
                        break;
                    case 'smb':
                        $('#smb-host').val(s.config?.host || '');
                        $('#smb-share').val(s.config?.share || '');
                        $('#smb-username').val(s.config?.username || '');
                        break;
                    case 'ftp':
                        $('#ftp-host').val(s.config?.host || '');
                        $('#ftp-port').val(s.config?.port || 21);
                        $('#ftp-username').val(s.config?.username || '');
                        $('#ftp-root').val(s.config?.root || '');
                        $('#ftp-passive').prop('checked', s.config?.passive !== false);
                        $('#ftp-ssl').prop('checked', s.config?.ssl === true);
                        break;
                    case 'sftp':
                        $('#sftp-host').val(s.config?.host || '');
                        $('#sftp-port').val(s.config?.port || 22);
                        $('#sftp-username').val(s.config?.username || '');
                        $('#sftp-auth-type').val(s.config?.auth_type || 'password');
                        $('#sftp-root').val(s.config?.root || '');
                        if (s.config?.auth_type === 'key') {
                            $('#sftp-password-group').hide();
                            $('#sftp-key-group').show();
                        }
                        break;
                    case 'webdav':
                        $('#webdav-url').val(s.config?.url || '');
                        $('#webdav-username').val(s.config?.username || '');
                        break;
                    case 's3':
                        $('#s3-endpoint').val(s.config?.endpoint || '');
                        $('#s3-region').val(s.config?.region || '');
                        $('#s3-bucket').val(s.config?.bucket || '');
                        $('#s3-access-key').val(s.config?.access_key || '');
                        $('#s3-prefix').val(s.config?.prefix || '');
                        break;
                }
            }
        }
        
        this.showModal('modal-add-storage');
    },
    
    // 권한 목록 로드
    async loadPermissionList(storageId = null) {
        const usersRes = await this.api('users', {}, 'GET');
        if (!usersRes.success) return;
        
        let permissions = [];
        if (storageId) {
            const permRes = await this.api('storage_permissions', { storage_id: storageId }, 'GET');
            if (permRes.success) {
                permissions = permRes.permissions || [];
            }
        }
        
        const container = $('#permission-list');
        container.html('');
        
        usersRes.users.forEach(user => {
            const perm = permissions.find(p => p.user_id === user.id) || {};
            // 새 스토리지(storageId가 없음)면 기본값 전부 체크 해제
            const isVisible = storageId ? (perm.can_visible !== undefined ? perm.can_visible : 0) : 0;
            const canRead = storageId ? (perm.can_read !== undefined ? perm.can_read : 0) : 0;
            const canDownload = storageId ? (perm.can_download !== undefined ? perm.can_download : 0) : 0;
            const canWrite = storageId ? (perm.can_write !== undefined ? perm.can_write : 0) : 0;
            const canDelete = storageId ? (perm.can_delete !== undefined ? perm.can_delete : 0) : 0;
            const canShare = storageId ? (perm.can_share !== undefined ? perm.can_share : 0) : 0;
            
            container.append(`
                <div class="permission-row" data-user-id="${user.id}">
                    <span class="perm-user">${this.escapeHtml(user.display_name || user.username)}</span>
                    <div class="perm-checks">
                        <label title="스토리지 목록에 표시"><input type="checkbox" class="perm-visible" ${isVisible ? 'checked' : ''}> 표시</label>
                        <label title="파일 열기, 미리보기, 정보"><input type="checkbox" class="perm-read" ${canRead ? 'checked' : ''}> 읽기</label>
                        <label title="파일 다운로드"><input type="checkbox" class="perm-download" ${canDownload ? 'checked' : ''}> 다운로드</label>
                        <label title="업로드, 새 폴더, 이름변경, 이동, 복사"><input type="checkbox" class="perm-write" ${canWrite ? 'checked' : ''}> 쓰기</label>
                        <label title="파일/폴더 삭제"><input type="checkbox" class="perm-delete" ${canDelete ? 'checked' : ''}> 삭제</label>
                        <label title="외부 공유 링크 생성"><input type="checkbox" class="perm-share" ${canShare ? 'checked' : ''}> 공유</label>
                    </div>
                </div>
            `);
        });
    },
    
    // 일괄 권한 적용
    applyBulkPermission() {
        const visible = $('#bulk-visible').is(':checked');
        const read = $('#bulk-read').is(':checked');
        const download = $('#bulk-download').is(':checked');
        const write = $('#bulk-write').is(':checked');
        const del = $('#bulk-delete').is(':checked');
        const share = $('#bulk-share').is(':checked');
        
        $('.permission-row').each((i, row) => {
            $(row).find('.perm-visible').prop('checked', visible);
            $(row).find('.perm-read').prop('checked', read);
            $(row).find('.perm-download').prop('checked', download);
            $(row).find('.perm-write').prop('checked', write);
            $(row).find('.perm-delete').prop('checked', del);
            $(row).find('.perm-share').prop('checked', share);
        });
        
        this.toast('일괄 적용되었습니다', 'success');
    },
    
    // 권한 데이터 수집
    collectPermissions() {
        const permissions = [];
        $('.permission-row').each((i, row) => {
            const $row = $(row);
            permissions.push({
                user_id: parseInt($row.data('user-id')),
                can_visible: $row.find('.perm-visible').is(':checked') ? 1 : 0,
                can_read: $row.find('.perm-read').is(':checked') ? 1 : 0,
                can_download: $row.find('.perm-download').is(':checked') ? 1 : 0,
                can_write: $row.find('.perm-write').is(':checked') ? 1 : 0,
                can_delete: $row.find('.perm-delete').is(':checked') ? 1 : 0,
                can_share: $row.find('.perm-share').is(':checked') ? 1 : 0
            });
        });
        return permissions;
    },
    
    async saveStorage() {
        const storageId = $('#storage-id').val();
        const type = $('#storage-type').val();
        const name = $('#storage-name').val().trim();
        const permissions = this.collectPermissions();
        
        // 유효성 검사 - 이름
        if (!name) {
            this.toast('스토리지 이름을 입력하세요', 'error');
            $('#storage-name').focus();
            return;
        }
        
        // 타입별 유효성 검사
        const validation = this.validateStorageConfig(type);
        if (!validation.valid) {
            this.toast(validation.message, 'error');
            if (validation.focus) $(validation.focus).focus();
            return;
        }
        
        // 유효성 검사 - 권한 (최소 한 명에게 표시 권한)
        const hasAnyPermission = permissions.some(p => p.can_visible === 1);
        if (!hasAnyPermission) {
            this.toast('최소 한 명의 사용자에게 권한을 설정하세요', 'error');
            return;
        }
        
        // 용량 설정 계산
        const quotaValue = parseInt($('#storage-quota-value').val()) || 0;
        const quotaUnit = parseInt($('#storage-quota-unit').val()) || 1073741824;
        const quota = quotaValue * quotaUnit;
        const recalculateUsage = $('#storage-calc-usage').is(':checked');
        
        const data = {
            name: name,
            storage_type: type,
            description: $('#storage-desc').val(),
            permissions: permissions,
            config: this.collectStorageConfig(type),
            quota: quota,
            recalculate_usage: recalculateUsage
        };
        
        // local 타입은 path 직접 설정
        if (type === 'local') {
            data.path = $('#storage-path').val();
        }
        
        // 저장 버튼 비활성화
        const $saveBtn = $('#btn-save-storage');
        const originalText = $saveBtn.text();
        $saveBtn.prop('disabled', true);
        if (recalculateUsage) {
            $saveBtn.text('⏳ 사용량 계산 중...');
        } else {
            $saveBtn.text('저장 중...');
        }
        
        let res;
        if (storageId) {
            data.id = parseInt(storageId);
            res = await this.api('storage_update', data);
        } else {
            res = await this.api('storage_add', data);
        }
        
        // 저장 버튼 복원
        $saveBtn.prop('disabled', false).text(originalText);
        
        if (res.success) {
            let msg = storageId ? '스토리지가 수정되었습니다' : '스토리지가 추가되었습니다';
            if (recalculateUsage && res.used_size_formatted) {
                msg += ` (사용량: ${res.used_size_formatted})`;
            }
            this.toast(msg, 'success');
            closeModal();
            this.loadStorages();
            this.showStoragesModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 스토리지 설정 유효성 검사
    validateStorageConfig(type) {
        switch (type) {
            case 'local':
                if (!$('#storage-path').val().trim()) {
                    return { valid: false, message: '경로를 입력하세요', focus: '#storage-path' };
                }
                break;
            case 'smb':
                if (!$('#smb-host').val().trim()) {
                    return { valid: false, message: 'SMB 호스트를 입력하세요', focus: '#smb-host' };
                }
                if (!$('#smb-share').val().trim()) {
                    return { valid: false, message: 'SMB 공유 이름을 입력하세요', focus: '#smb-share' };
                }
                break;
            case 'ftp':
                if (!$('#ftp-host').val().trim()) {
                    return { valid: false, message: 'FTP 호스트를 입력하세요', focus: '#ftp-host' };
                }
                if (!$('#ftp-username').val().trim()) {
                    return { valid: false, message: 'FTP 사용자명을 입력하세요', focus: '#ftp-username' };
                }
                break;
            case 'sftp':
                if (!$('#sftp-host').val().trim()) {
                    return { valid: false, message: 'SFTP 호스트를 입력하세요', focus: '#sftp-host' };
                }
                if (!$('#sftp-username').val().trim()) {
                    return { valid: false, message: 'SFTP 사용자명을 입력하세요', focus: '#sftp-username' };
                }
                break;
            case 'webdav':
                if (!$('#webdav-url').val().trim()) {
                    return { valid: false, message: 'WebDAV URL을 입력하세요', focus: '#webdav-url' };
                }
                break;
            case 's3':
                if (!$('#s3-bucket').val().trim()) {
                    return { valid: false, message: 'S3 버킷을 입력하세요', focus: '#s3-bucket' };
                }
                if (!$('#s3-access-key').val().trim()) {
                    return { valid: false, message: 'Access Key를 입력하세요', focus: '#s3-access-key' };
                }
                if (!$('#s3-secret-key').val().trim()) {
                    return { valid: false, message: 'Secret Key를 입력하세요', focus: '#s3-secret-key' };
                }
                break;
        }
        return { valid: true };
    },
    
    // 스토리지 설정 수집
    collectStorageConfig(type) {
        const config = {};
        
        switch (type) {
            case 'smb':
                config.host = $('#smb-host').val();
                config.share = $('#smb-share').val();
                config.username = $('#smb-username').val();
                config.password = $('#smb-password').val();
                break;
            case 'ftp':
                config.host = $('#ftp-host').val();
                config.port = parseInt($('#ftp-port').val()) || 21;
                config.username = $('#ftp-username').val();
                config.password = $('#ftp-password').val();
                config.root = $('#ftp-root').val() || '/';
                config.passive = $('#ftp-passive').is(':checked');
                config.ssl = $('#ftp-ssl').is(':checked');
                break;
            case 'sftp':
                config.host = $('#sftp-host').val();
                config.port = parseInt($('#sftp-port').val()) || 22;
                config.username = $('#sftp-username').val();
                config.auth_type = $('#sftp-auth-type').val();
                if (config.auth_type === 'password') {
                    config.password = $('#sftp-password').val();
                } else {
                    config.private_key = $('#sftp-private-key').val();
                }
                config.root = $('#sftp-root').val() || '/';
                break;
            case 'webdav':
                config.url = $('#webdav-url').val();
                config.username = $('#webdav-username').val();
                config.password = $('#webdav-password').val();
                break;
            case 's3':
                config.endpoint = $('#s3-endpoint').val() || 's3.amazonaws.com';
                config.region = $('#s3-region').val() || 'us-east-1';
                config.bucket = $('#s3-bucket').val();
                config.access_key = $('#s3-access-key').val();
                config.secret_key = $('#s3-secret-key').val();
                config.prefix = $('#s3-prefix').val() || '';
                break;
        }
        
        return config;
    },
    
    // 사용자 관리 모달
    async showUsersModal() {
        // 먼저 로딩 표시 후 모달 열기
        $('#users-table tbody').html('<tr><td colspan="7" class="text-center">로딩 중...</td></tr>');
        this.showModal('modal-users');
        
        const res = await this.api('users', {}, 'GET');
        const rolesRes = await this.api('roles', {}, 'GET');
        if (!res.success) return;
        
        // 시스템 설정 상태 로드 및 표시
        const settingsRes = await this.api('settings', {}, 'GET');
        if (settingsRes.success) {
            const s = settingsRes.settings;
            
            // 회원가입 허용 상태
            if (s.signup_enabled) {
                $('#status-signup').html('<span class="status-on">✅ 회원가입 허용</span>');
                // 자동 승인 상태 표시
                if (s.auto_approve) {
                    $('#status-approve').html('<span class="status-on">⚡ 자동 승인</span>').show();
                } else {
                    $('#status-approve').html('<span class="status-off">✋ 관리자 승인 필요</span>').show();
                }
            } else {
                $('#status-signup').html('<span class="status-off">🚫 회원가입 비허용</span>');
                $('#status-approve').hide();
            }
            
            // 외부 공유 허용 상태
            if (s.home_share_enabled !== false) {
                $('#status-home-share').html('<span class="status-on">🔗 개인폴더 외부 공유 허용</span>');
            } else {
                $('#status-home-share').html('<span class="status-off">🔒 개인폴더 외부 공유 차단</span>');
            }
        }
        
        // 역할 맵 생성 (기본 + 커스텀)
        const roleMap = {
            'admin': '관리자',
            'sub_admin': '부관리자',
            'user': '사용자'
        };
        if (rolesRes.success && rolesRes.roles) {
            rolesRes.roles.forEach(r => { roleMap[r.value] = r.name; });
        }
        
        const tbody = $('#users-table tbody').empty();
        
        const statusLabels = {
            'active': '<span class="status-badge status-active">활성</span>',
            'suspended': '<span class="status-badge status-suspended">정지</span>',
            'pending': '<span class="status-badge status-pending">대기</span>'
        };
        
        res.users.forEach(u => {
            const quotaText = u.quota ? this.formatSize(u.quota) : '무제한';
            const status = u.status || (u.is_active ? 'active' : 'inactive');
            const statusHtml = statusLabels[status] || statusLabels['pending'];
            const roleText = roleMap[u.role] || u.role || '사용자';
            
            // 삭제 버튼: 관리자는 삭제 불가
            const canDelete = u.role !== 'admin';
            const deleteBtn = canDelete 
                ? `<button class="btn btn-sm btn-danger" onclick="App.deleteUser(${u.id})">삭제</button>`
                : '';
            
            tbody.append(`
                <tr>
                    <td>${this.escapeHtml(u.username)}</td>
                    <td>${this.escapeHtml(u.display_name || '-')}</td>
                    <td>${this.escapeHtml(roleText)}</td>
                    <td>${quotaText}</td>
                    <td>${statusHtml}</td>
                    <td>${u.last_login || '-'}</td>
                    <td>
                        <button class="btn btn-sm" onclick="App.showUserForm(${u.id})">수정</button>
                        ${deleteBtn}
                    </td>
                </tr>
            `);
        });
    },
    
    // 사용자 폼 표시
    async showUserForm(id = null) {
        $('#user-id').val(id || '');
        $('#user-form-title').text(id ? '사용자 수정' : '사용자 추가');
        
        // 역할 목록 로드 (기본 + 커스텀)
        const rolesRes = await this.api('roles', {}, 'GET');
        const roleSelect = $('#user-role').empty();
        roleSelect.append('<option value="user">일반 사용자</option>');
        roleSelect.append('<option value="sub_admin">부 관리자</option>');
        roleSelect.append('<option value="admin">관리자</option>');
        if (rolesRes.success && rolesRes.roles) {
            rolesRes.roles.forEach(r => {
                roleSelect.append(`<option value="${this.escapeHtml(r.value)}">${this.escapeHtml(r.name)}</option>`);
            });
        }
        
        if (id) {
            const res = await this.api('users', {}, 'GET');
            const user = res.users.find(u => u.id === id);
            if (user) {
                $('#user-username').val(user.username).prop('disabled', true);
                $('#user-display-name').val(user.display_name);
                $('#user-role').val(user.role || 'user');
                $('#user-status').val(user.status || 'active');
                
                // 관리자는 역할 변경 불가
                if (user.role === 'admin') {
                    $('#user-role').prop('disabled', true);
                } else {
                    $('#user-role').prop('disabled', false);
                }
                
                // 정지 기간 정보 로드
                $('#suspend-from').val(user.suspend_from || '');
                $('#suspend-until').val(user.suspend_until || '');
                $('#suspend-reason').val(user.suspend_reason || '');
                
                // 부관리자 권한 체크박스 설정
                $('input[name="admin_perm"]').prop('checked', false);
                if (user.admin_perms && Array.isArray(user.admin_perms)) {
                    user.admin_perms.forEach(p => {
                        $(`input[name="admin_perm"][value="${p}"]`).prop('checked', true);
                    });
                }
                
                // 용량 설정
                const quota = user.quota || 0;
                if (quota === 0) {
                    $('#user-quota').val(0);
                    $('#user-quota-unit').val('0');
                } else if (quota >= 1073741824) {
                    $('#user-quota').val(Math.round(quota / 1073741824));
                    $('#user-quota-unit').val('1073741824');
                } else {
                    $('#user-quota').val(Math.round(quota / 1048576));
                    $('#user-quota-unit').val('1048576');
                }
                
                // 역할에 따른 UI 처리
                this.handleRoleChange(user.role);
            }
        } else {
            $('#user-username').val('').prop('disabled', false);
            $('#user-password').val('');
            $('#user-display-name').val('');
            $('#user-role').val('user').prop('disabled', false);
            $('#user-status').val('active');
            $('#user-quota').val(0);
            $('#user-quota-unit').val('0');
            $('#suspend-from').val('');
            $('#suspend-until').val('');
            $('#suspend-reason').val('');
            $('input[name="admin_perm"]').prop('checked', false);
            this.handleRoleChange('user');
        }
        
        this.showModal('modal-user-form');
    },
    
    // 역할 변경 시 UI 처리
    handleRoleChange(role) {
        // 관리자는 상태 변경 불가
        if (role === 'admin') {
            $('#user-status').val('active').prop('disabled', true);
            $('#sub-admin-perms').hide();
            $('#suspend-period').hide();
        } else if (role === 'sub_admin') {
            $('#user-status').prop('disabled', false);
            $('#sub-admin-perms').show();
            this.handleStatusChange($('#user-status').val());
        } else {
            $('#user-status').prop('disabled', false);
            $('#sub-admin-perms').hide();
            this.handleStatusChange($('#user-status').val());
        }
    },
    
    // 상태 변경 시 UI 처리
    handleStatusChange(status) {
        if (status === 'suspended') {
            $('#suspend-period').show();
        } else {
            $('#suspend-period').hide();
        }
    },
    
    // 사용자 저장
    async saveUser() {
        const id = $('#user-id').val();
        const quotaValue = parseInt($('#user-quota').val()) || 0;
        const quotaUnit = parseInt($('#user-quota-unit').val()) || 0;
        const quota = quotaUnit === 0 ? 0 : quotaValue * quotaUnit;
        const role = $('#user-role').val();
        const status = $('#user-status').val();
        
        // 부관리자 권한 수집
        const adminPerms = [];
        if (role === 'sub_admin') {
            $('input[name="admin_perm"]:checked').each(function() {
                adminPerms.push($(this).val());
            });
        }
        
        const data = {
            username: $('#user-username').val(),
            password: $('#user-password').val(),
            display_name: $('#user-display-name').val(),
            role: role,
            status: status,
            admin_perms: adminPerms.length > 0 ? adminPerms : null,
            quota: quota
        };
        
        // 정지 상태인 경우 기간 정보 추가
        if (status === 'suspended') {
            data.suspend_from = $('#suspend-from').val() || null;
            data.suspend_until = $('#suspend-until').val() || null;
            data.suspend_reason = $('#suspend-reason').val() || null;
        }
        
        if (id) {
            data.id = parseInt(id);
        }
        
        const action = id ? 'user_update' : 'user_create';
        const res = await this.api(action, data);
        
        if (res.success) {
            this.toast('저장되었습니다', 'success');
            closeModal();
            this.showUsersModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 사용자 삭제
    async deleteUser(id) {
        if (!confirm('정말 삭제하시겠습니까?')) return;
        
        const res = await this.api('user_delete', { id });
        
        if (res.success) {
            this.toast('삭제되었습니다', 'success');
            this.showUsersModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 그룹 관리 모달
    // 역할 관리 모달
    async showRolesModal() {
        $('#roles-list').html('<div class="text-center">로딩 중...</div>');
        this.showModal('modal-roles');
        
        const res = await this.api('roles', {}, 'GET');
        const list = $('#roles-list').empty();
        
        // 기본 역할 표시
        if (res.success && res.default_roles) {
            res.default_roles.forEach(r => {
                list.append(`
                    <div class="role-item role-default">
                        <span class="role-name">🔒 ${this.escapeHtml(r.name)}</span>
                        <span class="role-hint">기본 역할</span>
                    </div>
                `);
            });
        }
        
        // 커스텀 역할 표시
        if (res.success && res.roles && res.roles.length > 0) {
            res.roles.forEach(r => {
                list.append(`
                    <div class="role-item">
                        <span class="role-name">🏷️ ${this.escapeHtml(r.name)}</span>
                        <button class="btn btn-sm btn-danger" onclick="App.deleteRole(${r.id})">삭제</button>
                    </div>
                `);
            });
        }
        
        $('#new-role-name').val('');
    },
    
    // 역할 추가
    async addRole() {
        const name = $('#new-role-name').val().trim();
        if (!name) {
            this.toast('역할 이름을 입력하세요', 'error');
            return;
        }
        
        const res = await this.api('role_create', { name });
        if (res.success) {
            this.toast('역할이 추가되었습니다', 'success');
            this.showRolesModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 역할 삭제
    async deleteRole(id) {
        if (!confirm('역할을 삭제하시겠습니까?\n해당 역할의 사용자는 일반 사용자로 변경됩니다.')) return;
        
        const res = await this.api('role_delete', { id });
        if (res.success) {
            this.toast('삭제되었습니다', 'success');
            this.showRolesModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // QoS 속도 제한 설정 모달
    async showQosModal() {
        $('#qos-roles-list').html('<div class="text-center">로딩 중...</div>');
        this.showModal('modal-qos');
        
        // 역할 목록 로드
        const rolesRes = await this.api('roles', {}, 'GET');
        // QoS 설정 로드
        const qosRes = await this.api('qos_get', {}, 'GET');
        
        const qosSettings = qosRes.success ? qosRes.settings : {};
        const rolesList = $('#qos-roles-list').empty();
        
        // 기본 역할
        const defaultRoles = [
            { id: 'admin', name: '관리자' },
            { id: 'sub_admin', name: '부관리자' },
            { id: 'user', name: '사용자' }
        ];
        
        // 역할별 설정 렌더링
        const allRoles = [...defaultRoles];
        if (rolesRes.success && rolesRes.roles) {
            rolesRes.roles.forEach(r => allRoles.push({ id: 'custom_' + r.id, name: r.name }));
        }
        
        allRoles.forEach(role => {
            const roleQos = qosSettings.roles?.[role.id] || { download: 0, upload: 0 };
            rolesList.append(`
                <div class="qos-item" data-role-id="${role.id}">
                    <div class="qos-item-name">🏷️ ${this.escapeHtml(role.name)}</div>
                    <div class="qos-item-settings">
                        <label>
                            <span>⬇️ 다운로드</span>
                            <input type="number" class="qos-download" value="${roleQos.download}" min="0">
                            <span class="qos-unit">MB/s</span>
                        </label>
                        <label>
                            <span>⬆️ 업로드</span>
                            <input type="number" class="qos-upload" value="${roleQos.upload}" min="0">
                            <span class="qos-unit">MB/s</span>
                        </label>
                    </div>
                </div>
            `);
        });
        
        // 사용자 목록 로드
        const usersRes = await this.api('users', {}, 'GET');
        this.qosUsers = usersRes.success ? usersRes.users : [];
        this.qosSettings = qosSettings;
        this.renderQosUsers();
        
        // 첫번째 탭 활성화 (바닐라 JS)
        document.querySelectorAll('.qos-tab-btn').forEach((btn, idx) => {
            if (idx === 0) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        document.getElementById('qos-roles').style.display = 'block';
        document.getElementById('qos-users').style.display = 'none';
        document.getElementById('qos-user-search').value = '';
    },
    
    // QoS 사용자 목록 렌더링
    renderQosUsers(filter = '') {
        const usersList = $('#qos-users-list').empty();
        const qosSettings = this.qosSettings || {};
        
        let filtered = this.qosUsers || [];
        if (filter) {
            const lowerFilter = filter.toLowerCase();
            filtered = filtered.filter(u => 
                u.username.toLowerCase().includes(lowerFilter) ||
                (u.display_name && u.display_name.toLowerCase().includes(lowerFilter))
            );
        }
        
        if (filtered.length === 0) {
            usersList.html('<div class="qos-empty">사용자가 없습니다</div>');
            return;
        }
        
        filtered.forEach(user => {
            const userQos = qosSettings.users?.[user.id] || { download: '', upload: '' };
            const displayName = user.display_name || user.username;
            usersList.append(`
                <div class="qos-item" data-user-id="${user.id}">
                    <div class="qos-item-name">
                        👤 ${this.escapeHtml(displayName)}
                        <span class="qos-username">@${this.escapeHtml(user.username)}</span>
                    </div>
                    <div class="qos-item-settings">
                        <label>
                            <span>⬇️ 다운로드</span>
                            <input type="number" class="qos-download" value="${userQos.download}" min="0">
                            <span class="qos-unit">MB/s</span>
                        </label>
                        <label>
                            <span>⬆️ 업로드</span>
                            <input type="number" class="qos-upload" value="${userQos.upload}" min="0">
                            <span class="qos-unit">MB/s</span>
                        </label>
                    </div>
                </div>
            `);
        });
    },
    
    // QoS 사용자 필터
    filterQosUsers(query) {
        this.renderQosUsers(query);
    },
    
    // QoS 설정 저장
    async saveQosSettings() {
        const settings = {
            roles: {},
            users: {}
        };
        
        // 역할별 설정 수집 (바닐라 JS)
        document.querySelectorAll('#qos-roles-list .qos-item').forEach(function(item) {
            const roleId = item.getAttribute('data-role-id');
            const download = parseInt(item.querySelector('.qos-download').value) || 0;
            const upload = parseInt(item.querySelector('.qos-upload').value) || 0;
            settings.roles[roleId] = { download, upload };
        });
        
        // 사용자별 설정 수집 (바닐라 JS)
        document.querySelectorAll('#qos-users-list .qos-item').forEach(function(item) {
            const userId = item.getAttribute('data-user-id');
            const downloadVal = item.querySelector('.qos-download').value;
            const uploadVal = item.querySelector('.qos-upload').value;
            
            // 값이 입력된 경우에만 저장 (빈 값은 역할 기본값 사용)
            if (downloadVal !== '' || uploadVal !== '') {
                settings.users[userId] = {
                    download: downloadVal !== '' ? parseInt(downloadVal) : null,
                    upload: uploadVal !== '' ? parseInt(uploadVal) : null
                };
            }
        });
        
        const res = await this.api('qos_save', settings);
        if (res.success) {
            this.toast('속도 제한 설정이 저장되었습니다', 'success');
            closeModal();
        } else {
            this.toast(res.error || '저장 실패', 'error');
        }
    },
    
    // 사용자의 QoS 설정 가져오기
    getUserQosLimits() {
        // 로그인 시 서버에서 받아온 QoS 설정 사용
        return this.userQos || { download: 0, upload: 0 };
    },
    
    // 일괄 용량 설정 모달
    showBulkQuotaModal() {
        $('#bulk-quota-target').val('all');
        $('#bulk-quota-value').val(10);
        $('#bulk-quota-unit').val('1073741824');
        this.showModal('modal-bulk-quota');
    },
    
    // 일괄 용량 적용
    async applyBulkQuota() {
        const target = $('#bulk-quota-target').val();
        const quotaValue = parseInt($('#bulk-quota-value').val()) || 0;
        const quotaUnit = parseInt($('#bulk-quota-unit').val()) || 0;
        const quota = quotaUnit === 0 ? 0 : quotaValue * quotaUnit;
        
        const targetText = {
            'all': '모든 사용자',
            'user': '일반 사용자',
            'unlimited': '무제한 사용자'
        }[target];
        
        const quotaText = quota === 0 ? '무제한' : this.formatSize(quota);
        
        if (!confirm(`${targetText}에게 ${quotaText} 용량을 적용하시겠습니까?`)) return;
        
        const res = await this.api('user_bulk_quota', { target, quota });
        
        if (res.success) {
            this.toast(`${res.updated}명의 용량이 변경되었습니다`, 'success');
            closeModal();
            this.showUsersModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 공유 목록 모달
    async showSharesModal() {
        // 먼저 로딩 표시 후 모달 열기
        $('#shares-list-container').html('<div class="text-center">로딩 중...</div>');
        $('#shares-empty').hide();
        this.showModal('modal-shares-list');
        
        const res = await this.api('shares', {}, 'GET');
        
        const container = $('#shares-list-container').empty();
        const emptyMsg = $('#shares-empty');
        
        if (!res.success) {
            emptyMsg.text(res.error || '공유 목록을 불러올 수 없습니다').show();
            return;
        }
        
        if (!res.shares || res.shares.length === 0) {
            emptyMsg.text('공유된 파일이 없습니다').show();
            return;
        }
        
        emptyMsg.hide();
        const baseUrl = `${window.location.origin}${window.location.pathname.replace('index.php', '')}share.php?t=`;
        
        res.shares.forEach(s => {
            const shareUrl = baseUrl + s.token;
            const fileName = s.file_path.split('/').pop() || s.file_path;
            const expireText = s.expire_at ? this.formatDate(s.expire_at) : '무제한';
            const downloadText = s.max_downloads ? `${s.download_count}/${s.max_downloads}` : s.download_count;
            
            container.append(`
                <div class="share-card">
                    <div class="share-card-header">
                        <span class="share-file-icon">📄</span>
                        <div class="share-file-info">
                            <div class="share-file-name" title="${this.escapeHtml(s.file_path)}">${this.escapeHtml(fileName)}</div>
                            <div class="share-file-path">${this.escapeHtml(s.file_path)}</div>
                        </div>
                        <button class="btn btn-sm btn-danger share-delete-btn" onclick="App.deleteShare(${s.id})">🗑️</button>
                    </div>
                    <div class="share-card-body">
                        <div class="share-url-row">
                            <input type="text" class="share-url-input" value="${shareUrl}" readonly>
                            <button class="btn btn-sm btn-primary" onclick="App.copyUrl('${s.token}')">📋 복사</button>
                            <a href="${shareUrl}" target="_blank" class="btn btn-sm">🔗 열기</a>
                        </div>
                        <div class="share-meta">
                            <span>👤 ${this.escapeHtml(s.creator_name || '알 수 없음')}</span>
                            <span>📅 ${this.formatDate(s.created_at)}</span>
                            <span>⏰ ${expireText}</span>
                            <span>📥 ${downloadText}회</span>
                        </div>
                    </div>
                </div>
            `);
        });
    },
    
    formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
    },
    
    copyUrl(token) {
        const url = `${window.location.origin}${window.location.pathname.replace('index.php', '')}share.php?t=${token}`;
        this.copyToClipboard(url);
    },
    
    async deleteShare(id) {
        if (!confirm('공유를 삭제하시겠습니까?')) return;
        
        const res = await this.api('share_delete', { id });
        
        if (res.success) {
            this.toast('삭제되었습니다', 'success');
            this.showSharesModal();
            
            // 파일 목록 새로고침 (배지 제거용)
            this.loadFiles();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // ===== 관리자 기능 =====
    
    // 전체 로그인 기록 모달
    allLogsPage: 1,
    
    async showAllLoginsModal(page = 1) {
        if (page === 1) {
            $('#all-logins-tbody').html('<tr><td colspan="7" class="text-center">로딩 중...</td></tr>');
            this.showModal('modal-all-logins');
        }
        
        this.allLogsPage = page;
        const res = await this.api('login_logs', { page, per_page: 20, all: true }, 'GET');
        
        const tbody = $('#all-logins-tbody').empty();
        
        if (!res.success || !res.logs?.length) {
            tbody.html('<tr><td colspan="7">로그인 기록이 없습니다</td></tr>');
            $('#all-logins-pagination').empty();
        } else {
            res.logs.forEach(log => {
                const uaDetails = this.parseUserAgentDetails(log.user_agent);
                const uaEscaped = (log.user_agent || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
                
                tbody.append(`
                    <tr>
                        <td><input type="checkbox" class="log-checkbox" data-id="${log.id}"></td>
                        <td>${this.escapeHtml(log.username || '-')}</td>
                        <td class="text-nowrap">${log.created_at}</td>
                        <td><code>${this.escapeHtml(log.ip)}</code></td>
                        <td>${this.escapeHtml(log.country || '-')}</td>
                        <td class="ua-cell">
                            <span class="ua-detail d-none d-md-inline">${uaDetails.icon} ${this.escapeHtml(uaDetails.os)} / ${this.escapeHtml(uaDetails.browser)}</span>
                            <span class="ua-icon d-inline d-md-none" onclick="App.showUserAgentPopup('${uaEscaped}')" style="cursor:pointer;font-size:1.3em;">${uaDetails.icon}</span>
                        </td>
                        <td><span class="badge ${log.success ? 'badge-success' : 'badge-danger'}">${log.success ? '성공' : '실패'}</span></td>
                    </tr>
                `);
            });
            
            this.renderPagination('#all-logins-pagination', res.page, res.total_pages, res.total, 'showAllLoginsModal');
        }
        
        $('#log-select-all').prop('checked', false);
    },
    
    // 페이지네이션 렌더링 (5페이지 단위)
    renderPagination(container, currentPage, totalPages, total, callback) {
        const $container = $(container).empty();
        
        if (totalPages <= 1) return;
        
        // 5페이지 단위 계산
        const pageGroup = Math.ceil(currentPage / 5);
        const startPage = (pageGroup - 1) * 5 + 1;
        const endPage = Math.min(startPage + 4, totalPages);
        
        let html = `<div class="pagination"><span class="page-info">총 ${total}개</span>`;
        
        // 이전 그룹
        if (startPage > 1) {
            html += `<a href="#" class="page-link" data-page="${startPage - 1}" data-callback="${callback}">«</a>`;
        }
        
        // 이전 페이지
        if (currentPage > 1) {
            html += `<a href="#" class="page-link" data-page="${currentPage - 1}" data-callback="${callback}">‹</a>`;
        }
        
        // 페이지 번호
        for (let i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                html += `<span class="page-current">${i}</span>`;
            } else {
                html += `<a href="#" class="page-link" data-page="${i}" data-callback="${callback}">${i}</a>`;
            }
        }
        
        // 다음 페이지
        if (currentPage < totalPages) {
            html += `<a href="#" class="page-link" data-page="${currentPage + 1}" data-callback="${callback}">›</a>`;
        }
        
        // 다음 그룹
        if (endPage < totalPages) {
            html += `<a href="#" class="page-link" data-page="${endPage + 1}" data-callback="${callback}">»</a>`;
        }
        
        html += '</div>';
        $container.html(html);
    },
    
    // 선택된 로그 삭제
    async deleteSelectedLogs() {
        const ids = [];
        $('.log-checkbox:checked').each(function() {
            ids.push($(this).data('id'));
        });
        
        if (!ids.length) {
            this.toast('선택된 항목이 없습니다', 'error');
            return;
        }
        
        if (!confirm(`${ids.length}개의 로그를 삭제하시겠습니까?`)) return;
        
        const res = await this.api('login_logs_delete', { ids });
        
        if (res.success) {
            this.toast(`${res.deleted}개 삭제됨`, 'success');
            this.showAllLoginsModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 전체 로그 삭제
    async deleteAllLogs() {
        if (!confirm('모든 로그인 기록을 삭제하시겠습니까?')) return;
        
        const res = await this.api('login_logs_delete_all');
        
        if (res.success) {
            this.toast('전체 삭제 완료', 'success');
            this.showAllLoginsModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 오래된 로그 삭제
    async deleteOldLogs() {
        const days = parseInt($('#log-delete-days').val()) || 30;
        
        if (!confirm(`${days}일 이전 로그를 삭제하시겠습니까?`)) return;
        
        const res = await this.api('login_logs_delete_old', { days });
        
        if (res.success) {
            this.toast(`${res.deleted}개 삭제됨`, 'success');
            this.showAllLoginsModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // User-Agent 파싱 - 상세 정보 반환
    parseUserAgentDetails(ua) {
        if (!ua) return { os: '-', browser: '-', device: 'unknown', icon: '🌐' };
        
        // OS 파싱
        let os = 'Unknown';
        let icon = '🌐';
        let device = 'desktop';
        
        if (ua.includes('Windows NT 10')) { os = 'Windows 10/11'; icon = '💻'; }
        else if (ua.includes('Windows NT 6.3')) { os = 'Windows 8.1'; icon = '💻'; }
        else if (ua.includes('Windows NT 6.2')) { os = 'Windows 8'; icon = '💻'; }
        else if (ua.includes('Windows NT 6.1')) { os = 'Windows 7'; icon = '💻'; }
        else if (ua.includes('Windows')) { os = 'Windows'; icon = '💻'; }
        else if (ua.includes('Mac OS X')) { 
            const match = ua.match(/Mac OS X (\d+[._]\d+)/);
            os = match ? `macOS ${match[1].replace('_', '.')}` : 'macOS'; 
            icon = '🖥️'; 
        }
        else if (ua.includes('iPhone')) { os = 'iOS (iPhone)'; icon = '📱'; device = 'mobile'; }
        else if (ua.includes('iPad')) { os = 'iPadOS'; icon = '📱'; device = 'tablet'; }
        else if (ua.includes('Android')) { 
            const match = ua.match(/Android (\d+\.?\d*)/);
            os = match ? `Android ${match[1]}` : 'Android';
            icon = '📱'; 
            device = ua.includes('Mobile') ? 'mobile' : 'tablet';
        }
        else if (ua.includes('Linux')) { os = 'Linux'; icon = '🐧'; }
        else if (ua.includes('CrOS')) { os = 'Chrome OS'; icon = '💻'; }
        
        // 브라우저 파싱
        let browser = 'Unknown';
        if (ua.includes('Edg/')) {
            const match = ua.match(/Edg\/(\d+)/);
            browser = match ? `Edge ${match[1]}` : 'Edge';
        } else if (ua.includes('OPR/') || ua.includes('Opera')) {
            const match = ua.match(/(?:OPR|Opera)\/(\d+)/);
            browser = match ? `Opera ${match[1]}` : 'Opera';
        } else if (ua.includes('Chrome/')) {
            const match = ua.match(/Chrome\/(\d+)/);
            browser = match ? `Chrome ${match[1]}` : 'Chrome';
        } else if (ua.includes('Firefox/')) {
            const match = ua.match(/Firefox\/(\d+)/);
            browser = match ? `Firefox ${match[1]}` : 'Firefox';
        } else if (ua.includes('Safari/') && !ua.includes('Chrome')) {
            const match = ua.match(/Version\/(\d+)/);
            browser = match ? `Safari ${match[1]}` : 'Safari';
        } else if (ua.includes('MSIE') || ua.includes('Trident/')) {
            browser = 'Internet Explorer';
        }
        
        return { os, browser, device, icon };
    },
    
    // User-Agent 간단 표시 (브라우저만)
    parseUserAgent(ua) {
        if (!ua) return '-';
        const details = this.parseUserAgentDetails(ua);
        return details.browser;
    },
    
    // User-Agent 팝업 표시
    showUserAgentPopup(ua) {
        if (!ua) {
            this.toast('정보 없음', 'info');
            return;
        }
        const details = this.parseUserAgentDetails(ua);
        const content = `
            <div style="text-align:left;line-height:1.8;">
                <p><strong>${details.icon} 디바이스:</strong> ${details.device === 'mobile' ? '모바일' : details.device === 'tablet' ? '태블릿' : 'PC'}</p>
                <p><strong>🖥️ 운영체제:</strong> ${details.os}</p>
                <p><strong>🌐 브라우저:</strong> ${details.browser}</p>
                <hr style="margin:10px 0;">
                <p style="font-size:11px;color:#888;word-break:break-all;"><strong>User-Agent:</strong><br>${ua}</p>
            </div>
        `;
        this.showAlert('접속 정보', content);
    },
    
    // 간단한 알림 모달 (HTML 지원)
    showAlert(title, content) {
        const existingModal = document.getElementById('modal-alert-popup');
        if (existingModal) existingModal.remove();
        
        const modal = document.createElement('div');
        modal.id = 'modal-alert-popup';
        modal.className = 'modal';
        modal.style.display = 'flex';
        modal.innerHTML = `
            <div class="modal-header">
                <h2>${title}</h2>
                <button class="modal-close" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">${content}</div>
        `;
        document.body.appendChild(modal);
        modal.querySelector('.modal-close').focus();
    },
    
    // 전체 휴지통 관리 모달 (관리자)
    async showTrashModal() {
        document.getElementById('trash-list').innerHTML = '<div class="text-center">로딩 중...</div>';
        this.showModal('modal-trash');
        
        const res = await this.api('trash_list', { all: true }, 'GET');
        
        const listEl = document.getElementById('trash-list');
        const emptyMsg = document.getElementById('trash-empty-msg');
        const countEl = document.getElementById('trash-count');
        const sizeEl = document.getElementById('trash-size');
        
        listEl.innerHTML = '';
        
        if (!res.success || !res.items?.length) {
            listEl.style.display = 'none';
            emptyMsg.style.display = 'block';
            countEl.textContent = '0개 항목';
            sizeEl.textContent = '';
        } else {
            listEl.style.display = 'block';
            emptyMsg.style.display = 'none';
            
            let totalSize = 0;
            res.items.forEach(item => {
                totalSize += item.size || 0;
                const icon = item.is_dir ? '📁' : this.getFileIcon(item.name);
                const storageIcon = item.storage_name || '';
                
                listEl.innerHTML += `
                    <div class="trash-item" data-id="${item.id}">
                        <div class="trash-item-icon">${icon}</div>
                        <div class="trash-item-info">
                            <div class="trash-item-name" title="${this.escapeHtml(item.name)}">${this.escapeHtml(item.name)}</div>
                            <div class="trash-item-meta">
                                <span class="trash-item-path" title="${this.escapeHtml(item.original_path)}">📂 ${this.escapeHtml(item.original_path)}</span>
                            </div>
                            <div class="trash-item-details">
                                <span>👤 ${this.escapeHtml(item.deleted_by_name || '-')}</span>
                                <span>🕐 ${item.deleted_at}</span>
                                <span>💾 ${item.is_dir ? '폴더' : this.formatSize(item.size)}</span>
                            </div>
                        </div>
                        <div class="trash-item-actions">
                            <button class="btn btn-sm btn-primary" onclick="App.restoreTrash('${item.id}', true)" title="복원">↩️</button>
                            <button class="btn btn-sm btn-danger" onclick="App.deleteTrashItem('${item.id}', true)" title="영구삭제">🗑️</button>
                        </div>
                    </div>
                `;
            });
            
            countEl.textContent = `${res.items.length}개 항목`;
            sizeEl.textContent = this.formatSize(totalSize);
        }
    },
    
    // 내 휴지통 모달 (개인)
    async showMyTrashModal() {
        document.getElementById('my-trash-list').innerHTML = '<div class="text-center">로딩 중...</div>';
        this.showModal('modal-my-trash');
        
        const res = await this.api('trash_list', {}, 'GET');
        
        const listEl = document.getElementById('my-trash-list');
        const emptyMsg = document.getElementById('my-trash-empty-msg');
        const countEl = document.getElementById('my-trash-count');
        const sizeEl = document.getElementById('my-trash-size');
        
        listEl.innerHTML = '';
        
        if (!res.success || !res.items?.length) {
            listEl.style.display = 'none';
            emptyMsg.style.display = 'block';
            countEl.textContent = '0개 항목';
            sizeEl.textContent = '';
        } else {
            listEl.style.display = 'block';
            emptyMsg.style.display = 'none';
            
            let totalSize = 0;
            res.items.forEach(item => {
                totalSize += item.size || 0;
                const icon = item.is_dir ? '📁' : this.getFileIcon(item.name);
                
                listEl.innerHTML += `
                    <div class="trash-item" data-id="${item.id}">
                        <div class="trash-item-icon">${icon}</div>
                        <div class="trash-item-info">
                            <div class="trash-item-name" title="${this.escapeHtml(item.name)}">${this.escapeHtml(item.name)}</div>
                            <div class="trash-item-meta">
                                <span class="trash-item-path" title="${this.escapeHtml(item.original_path)}">📂 ${this.escapeHtml(item.original_path)}</span>
                            </div>
                            <div class="trash-item-details">
                                <span>🕐 ${item.deleted_at}</span>
                                <span>💾 ${item.is_dir ? '폴더' : this.formatSize(item.size)}</span>
                            </div>
                        </div>
                        <div class="trash-item-actions">
                            <button class="btn btn-sm btn-primary" onclick="App.restoreTrash('${item.id}', false)" title="복원">↩️</button>
                            <button class="btn btn-sm btn-danger" onclick="App.deleteTrashItem('${item.id}', false)" title="영구삭제">🗑️</button>
                        </div>
                    </div>
                `;
            });
            
            countEl.textContent = `${res.items.length}개 항목`;
            sizeEl.textContent = this.formatSize(totalSize);
        }
    },
    
    // 파일 아이콘 가져오기
    getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const iconMap = {
            // 이미지
            'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'webp': '🖼️', 'bmp': '🖼️', 'svg': '🖼️',
            // 동영상
            'mp4': '🎬', 'mkv': '🎬', 'avi': '🎬', 'mov': '🎬', 'wmv': '🎬', 'flv': '🎬', 'webm': '🎬', 'ts': '🎬',
            // 음악
            'mp3': '🎵', 'wav': '🎵', 'flac': '🎵', 'aac': '🎵', 'ogg': '🎵', 'm4a': '🎵',
            // 문서
            'pdf': '📕', 'doc': '📘', 'docx': '📘', 'xls': '📗', 'xlsx': '📗', 'ppt': '📙', 'pptx': '📙', 'txt': '📝',
            // 압축
            'zip': '📦', 'rar': '📦', '7z': '📦', 'tar': '📦', 'gz': '📦',
            // 코드
            'html': '💻', 'css': '💻', 'js': '💻', 'php': '💻', 'py': '💻', 'java': '💻', 'c': '💻', 'cpp': '💻',
        };
        return iconMap[ext] || '📄';
    },
    
    async restoreTrash(id, isAdmin = false) {
        const res = await this.api('trash_restore', { id });
        
        if (res.success) {
            this.toast('복원되었습니다', 'success');
            if (isAdmin) {
                this.showTrashModal();
            } else {
                this.showMyTrashModal();
            }
            this.loadFiles();
            this.updateTrashIcon();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    async deleteTrashItem(id, isAdmin = false) {
        if (!confirm('영구 삭제하시겠습니까?')) return;
        
        const res = await this.api('trash_delete', { id });
        
        if (res.success) {
            this.toast('삭제되었습니다', 'success');
            if (isAdmin) {
                this.showTrashModal();
            } else {
                this.showMyTrashModal();
            }
            this.updateTrashIcon();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    async emptyTrash(isAdmin = false) {
        const msg = isAdmin ? '전체 휴지통을 비우시겠습니까?' : '내 휴지통을 비우시겠습니까?';
        if (!confirm(msg + ' 모든 파일이 영구 삭제됩니다.')) return;
        
        const res = await this.api('trash_empty', { all: isAdmin });
        
        if (res.success) {
            this.toast('휴지통을 비웠습니다', 'success');
            if (isAdmin) {
                this.showTrashModal();
            } else {
                this.showMyTrashModal();
            }
            this.updateTrashIcon();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 휴지통 아이콘 업데이트
    async updateTrashIcon() {
        const res = await this.api('trash_list', {}, 'GET');
        const hasItems = res.success && res.items && res.items.length > 0;
        const myCount = res.items?.length || 0;
        
        // 사이드바 휴지통 아이콘 업데이트
        const myTrashLink = document.getElementById('menu-my-trash');
        const trashLink = document.getElementById('menu-trash');
        
        if (myTrashLink) {
            myTrashLink.innerHTML = hasItems 
                ? `♻️ 내 휴지통 <span class="trash-count">(${myCount})</span>` 
                : '🗑️ 내 휴지통';
        }
        
        // 전체 휴지통 (관리자용)
        if (trashLink) {
            const allRes = await this.api('trash_list', { all: true }, 'GET');
            const hasAllItems = allRes.success && allRes.items && allRes.items.length > 0;
            const allCount = allRes.items?.length || 0;
            trashLink.innerHTML = hasAllItems 
                ? `♻️ 전체 휴지통 <span class="trash-count">(${allCount})</span>` 
                : '🗑️ 전체 휴지통';
        }
    },
    
    // ===== 조건부 일괄 삭제 =====
    showBulkDeleteModal() {
        if (!this.currentStorage) {
            this.toast('먼저 스토리지를 선택하세요', 'error');
            return;
        }
        
        // 현재 경로 표시
        const currentPathDisplay = this.currentPath ? `/${this.currentPath}` : '/ (루트)';
        $('.bulk-delete-info .info-notice').html(
            `📍 <strong>현재 폴더:</strong> <code>${currentPathDisplay}</code>를 기준으로 조건에 맞는 파일/폴더를 검색하여 삭제합니다.`
        );
        
        // 입력 초기화 (placeholder로 예시 표시)
        $('#bulk-delete-patterns').val('');
        $('#bulk-delete-scope').val('recursive');
        $('#bulk-delete-type').val('all');
        $('#bulk-delete-results').hide();
        $('#bulk-delete-list').empty();
        
        this.showModal('modal-bulk-delete');
    },
    
    async bulkDeleteSearch() {
        const patterns = $('#bulk-delete-patterns').val().trim();
        if (!patterns) {
            this.toast('삭제할 패턴을 입력하세요', 'error');
            return;
        }
        
        const scope = $('#bulk-delete-scope').val();
        const type = $('#bulk-delete-type').val();
        
        // 버튼 비활성화 및 로딩 표시
        const searchBtn = $('#btn-bulk-delete-search');
        searchBtn.prop('disabled', true).text('🔄 검색 중...');
        
        const res = await this.api('bulk_search', {
            storage_id: this.currentStorage,
            path: this.currentPath,
            patterns: patterns,
            scope: scope,
            type: type
        });
        
        // 버튼 복원
        searchBtn.prop('disabled', false).text('🔍 검색');
        
        if (!res.success) {
            this.toast(res.error || '검색 실패', 'error');
            return;
        }
        
        const listEl = document.getElementById('bulk-delete-list');
        listEl.innerHTML = '';
        
        if (res.items.length === 0) {
            this.toast('조건에 맞는 항목이 없습니다', 'info');
            $('#bulk-delete-results').hide();
            return;
        }
        
        // 검색 방식 표시
        const methodText = res.method === 'index' ? '⚡ 인덱스 검색' : '📂 파일 스캔';
        $('#bulk-delete-count').html(`(${res.items.length}개 발견) <small style="color:#888">${methodText}</small>`);
        
        res.items.forEach((item, idx) => {
            const icon = item.is_dir ? '📁' : '📄';
            const size = item.is_dir ? '폴더' : this.formatSize(item.size);
            
            listEl.innerHTML += `
                <div class="bulk-delete-item">
                    <label>
                        <input type="checkbox" class="bulk-delete-check" data-path="${this.escapeHtml(item.path)}" checked>
                        <span class="bulk-item-icon">${icon}</span>
                        <span class="bulk-item-name" title="${this.escapeHtml(item.path)}">${this.escapeHtml(item.name)}</span>
                        <span class="bulk-item-path">${this.escapeHtml(item.path)}</span>
                        <span class="bulk-item-size">${size}</span>
                    </label>
                </div>
            `;
        });
        
        $('#bulk-delete-results').show();
        this.toast(`${res.items.length}개 항목 발견`, 'success');
    },
    
    async bulkDeleteExecute() {
        const checkboxes = document.querySelectorAll('.bulk-delete-check:checked');
        if (checkboxes.length === 0) {
            this.toast('삭제할 항목을 선택하세요', 'error');
            return;
        }
        
        if (!confirm(`선택한 ${checkboxes.length}개 항목을 삭제하시겠습니까?\n\n⚠️ 삭제된 항목은 휴지통으로 이동합니다.`)) {
            return;
        }
        
        const paths = [];
        checkboxes.forEach(cb => {
            paths.push(cb.dataset.path);
        });
        
        this.toast('삭제 중...', 'info');
        
        const res = await this.api('bulk_delete', {
            storage_id: this.currentStorage,
            paths: paths
        });
        
        if (res.success) {
            this.toast(`${res.deleted}개 삭제 완료` + (res.failed > 0 ? `, ${res.failed}개 실패` : ''), 'success');
            $('#bulk-delete-results').hide();
            this.loadFiles();
            this.updateTrashIcon();
        } else {
            this.toast(res.error || '삭제 실패', 'error');
        }
    },
    
    // ===== 활동 로그 =====
    activityPage: 1,
    
    async showActivityLogsModal() {
        // 먼저 로딩 표시 후 모달 열기
        $('#activity-logs-container').html('<p class="text-center">로딩 중...</p>');
        this.showModal('modal-activity-logs');
        
        // 사용자 목록 로드
        const usersRes = await this.api('users', {}, 'GET');
        if (usersRes.success) {
            const select = document.getElementById('activity-filter-user');
            select.innerHTML = '<option value="">전체</option>';
            (usersRes.users || []).forEach(u => {
                select.innerHTML += `<option value="${u.id}">${this.escapeHtml(u.display_name || u.username)}</option>`;
            });
        }
        
        // 필터 초기화
        this.resetActivityFilters();
        
        // 로그 로드
        await this.loadActivityLogs();
    },
    
    async loadActivityLogs(page = 1) {
        this.activityPage = page;
        
        const filters = {
            page: page,
            limit: 50
        };
        
        // null이 아닌 값만 추가
        const userId = $('#activity-filter-user').val();
        const type = $('#activity-filter-type').val();
        const dateFrom = $('#activity-filter-from').val();
        const dateTo = $('#activity-filter-to').val();
        const search = $('#activity-filter-search').val();
        
        if (userId) filters.user_id = userId;
        if (type) filters.type = type;
        if (dateFrom) filters.date_from = dateFrom;
        if (dateTo) filters.date_to = dateTo;
        if (search) filters.search = search;
        
        const res = await this.api('activity_logs', filters, 'GET');
        
        if (!res.success) {
            this.toast(res.error || '로그 로드 실패', 'error');
            return;
        }
        
        // 통계 표시
        const statsEl = document.getElementById('activity-stats');
        statsEl.innerHTML = `<span>총 ${res.total}건</span>`;
        
        // 테이블 렌더링
        const tbody = document.getElementById('activity-table-body');
        tbody.innerHTML = '';
        
        if (res.logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">로그가 없습니다</td></tr>';
        } else {
            res.logs.forEach(log => {
                const typeLabel = this.getActivityTypeLabel(log.type);
                const size = log.size ? this.formatSize(log.size) : '-';
                const path = log.filename || log.path || '-';
                
                tbody.innerHTML += `
                    <tr>
                        <td class="nowrap">${log.created_at || '-'}</td>
                        <td>${typeLabel}</td>
                        <td>${this.escapeHtml(log.display_name || log.username || '-')}</td>
                        <td class="path-cell" title="${this.escapeHtml(log.path || '')}">${this.escapeHtml(path)}</td>
                        <td class="nowrap">${size}</td>
                        <td class="nowrap">${this.escapeHtml(log.ip || '-')}</td>
                    </tr>
                `;
            });
        }
        
        // 페이지네이션
        this.renderActivityPagination(res.page, res.total_pages, res.total);
    },
    
    renderActivityPagination(currentPage, totalPages, total) {
        const container = document.getElementById('activity-pagination');
        
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = '<div class="pagination">';
        
        // 이전 버튼
        if (currentPage > 1) {
            html += `<button class="btn btn-sm" onclick="App.loadActivityLogs(${currentPage - 1})">◀ 이전</button>`;
        }
        
        // 페이지 번호
        html += `<span class="page-info">${currentPage} / ${totalPages}</span>`;
        
        // 다음 버튼
        if (currentPage < totalPages) {
            html += `<button class="btn btn-sm" onclick="App.loadActivityLogs(${currentPage + 1})">다음 ▶</button>`;
        }
        
        html += '</div>';
        container.innerHTML = html;
    },
    
    getActivityTypeLabel(type) {
        const labels = {
            'upload': '📤 업로드',
            'download': '📥 다운로드',
            'delete': '🗑️ 삭제',
            'create_folder': '📁 폴더 생성',
            'rename': '✏️ 이름 변경',
            'move': '📦 이동',
            'copy': '📋 복사',
            'share_create': '🔗 공유 생성',
            'share_delete': '🔗 공유 삭제',
            'share_access': '👁️ 공유 접근',
            'extract': '📦 압축 해제',
            'compress': '🗜️ 압축',
            'restore': '↩️ 복원',
            'login': '🔐 로그인',
            'logout': '🔓 로그아웃',
            'login_fail': '⚠️ 로그인 실패',
            'hack_attempt': '🚨 해킹시도'
        };
        return labels[type] || type;
    },
    
    resetActivityFilters() {
        $('#activity-filter-user').val('');
        $('#activity-filter-type').val('');
        $('#activity-filter-from').val('');
        $('#activity-filter-to').val('');
        $('#activity-filter-search').val('');
        this.loadActivityLogs(1);
    },
    
    async clearActivityLogs() {
        const choice = confirm('로그를 삭제하시겠습니까?\n\n[확인] - 전체 삭제\n[취소] - 취소');
        if (!choice) return;
        
        const days = prompt('며칠 이전 로그를 삭제할까요? (비워두면 전체 삭제)', '30');
        if (days === null) return;
        
        let beforeDate = null;
        if (days && !isNaN(days)) {
            const date = new Date();
            date.setDate(date.getDate() - parseInt(days));
            beforeDate = date.toISOString().split('T')[0];
        }
        
        const res = await this.api('activity_logs_clear', { before_date: beforeDate });
        
        if (res.success) {
            this.toast('로그가 삭제되었습니다', 'success');
            this.loadActivityLogs(1);
        } else {
            this.toast(res.error || '삭제 실패', 'error');
        }
    },
    
    // ===== 검색 인덱스 =====
    async showSearchIndexModal() {
        $('#index-total').text('로딩 중...');
        this.showModal('modal-search-index');
        
        // 인덱스 통계 로드
        const res = await this.api('index_stats', {}, 'GET');
        
        // 자동 갱신 설정 상태 확인
        const settingsRes = await this.api('settings', {}, 'GET');
        const autoIndexEnabled = settingsRes.success && settingsRes.settings.auto_index === true;
        
        // 자동 갱신 상태 표시
        if (autoIndexEnabled) {
            $('#index-auto-on').show();
            $('#index-auto-off').hide();
        } else {
            $('#index-auto-on').hide();
            $('#index-auto-off').show();
        }
        
        if (res.success) {
            const stats = res.stats;
            
            // SQLite3 사용 불가 시
            if (stats.available === false) {
                $('#index-total').text('사용 불가');
                $('#index-files').text('-');
                $('#index-folders').text('-');
                $('#index-last-rebuild').text('-');
                $('#btn-rebuild-index').prop('disabled', true);
                $('#btn-clear-index').prop('disabled', true);
                $('#sqlite-warning').show();
                $('.index-requirement').hide();
                $('#index-auto-status').hide();
            } else {
                $('#index-total').text(stats.total.toLocaleString() + '개');
                $('#index-files').text(stats.files.toLocaleString() + '개');
                $('#index-folders').text(stats.folders.toLocaleString() + '개');
                $('#index-last-rebuild').text(stats.last_rebuild || '없음');
                $('#btn-rebuild-index').prop('disabled', false);
                $('#btn-clear-index').prop('disabled', false);
                $('#sqlite-warning').hide();
                $('.index-requirement').show();
                $('#index-auto-status').show();
            }
        } else {
            $('#index-total').text('-');
            $('#index-files').text('-');
            $('#index-folders').text('-');
            $('#index-last-rebuild').text('-');
        }
        
        $('#index-progress').hide();
        $('#index-status').hide();
    },
    
    async rebuildSearchIndex() {
        if (!confirm('전체 인덱스를 재구축하시겠습니까?\n파일이 많은 경우 시간이 걸릴 수 있습니다.')) {
            return;
        }
        
        $('#index-progress').show();
        $('#index-progress .progress-text').text('스토리지 목록 조회 중...');
        $('#index-progress .progress-fill').css('width', '0%');
        $('#btn-rebuild-index').prop('disabled', true);
        $('#index-status').hide();
        
        try {
            // 1. 스토리지 목록 가져오기 (관리자 전용 API)
            const storagesRes = await this.api('storages_all', {}, 'GET');
            if (!storagesRes.success || !storagesRes.storages) {
                throw new Error('스토리지 목록을 가져올 수 없습니다.');
            }
            
            const storages = storagesRes.storages;
            const total = storages.length;
            
            if (total === 0) {
                throw new Error('등록된 스토리지가 없습니다.');
            }
            
            let completed = 0;
            let totalItems = 0;
            const results = [];
            
            // 2. 스토리지별로 재구축
            for (const storage of storages) {
                const percent = Math.round((completed / total) * 100);
                $('#index-progress .progress-text').text(`인덱스 재구축 중... (${completed + 1}/${total}) ${storage.name}`);
                $('#index-progress .progress-fill').css('width', percent + '%');
                
                try {
                    const res = await this.api('index_rebuild_storage', {
                        storage_id: storage.id
                    });
                    
                    if (res.success) {
                        totalItems += res.count || 0;
                        results.push({
                            name: storage.name,
                            count: res.count || 0
                        });
                    }
                } catch (e) {
                    console.error(`스토리지 ${storage.name} 인덱싱 실패:`, e);
                    results.push({
                        name: storage.name,
                        count: 0,
                        error: true
                    });
                }
                
                completed++;
            }
            
            // 3. 완료
            $('#index-progress .progress-fill').css('width', '100%');
            $('#index-progress .progress-text').text('완료!');
            
            // 통계 업데이트
            const statsRes = await this.api('index_stats', {}, 'GET');
            if (statsRes.success && statsRes.stats) {
                const stats = statsRes.stats;
                $('#index-total').text(stats.total.toLocaleString() + '개');
                $('#index-files').text(stats.files.toLocaleString() + '개');
                $('#index-folders').text(stats.folders.toLocaleString() + '개');
                $('#index-last-rebuild').text(stats.last_rebuild || '방금');
            }
            
            // 결과 상세
            const detailHtml = results.map(r => 
                `${r.name}: ${r.count.toLocaleString()}개${r.error ? ' ⚠️' : ''}`
            ).join('<br>');
            
            $('#index-status').html(`
                <div class="index-complete">
                    ✅ 인덱스 재구축 완료! (${totalItems.toLocaleString()}개 항목)
                    <div style="font-size:12px;color:#666;margin-top:8px;">${detailHtml}</div>
                </div>
            `).show();
            
            this.toast(`인덱스 재구축 완료: ${totalItems.toLocaleString()}개 항목`, 'success');
            
        } catch (e) {
            console.error('인덱스 재구축 오류:', e);
            this.toast('인덱스 재구축 중 오류 발생: ' + e.message, 'error');
            $('#index-status').html(`
                <div class="index-error">
                    ❌ 재구축 실패: ${e.message || '서버 오류'}
                </div>
            `).show();
        }
        
        setTimeout(() => {
            $('#index-progress').hide();
            $('#index-progress .progress-fill').css('width', '0%');
        }, 1500);
        
        $('#btn-rebuild-index').prop('disabled', false);
    },
    
    async clearSearchIndex() {
        if (!confirm('검색 인덱스를 초기화하시겠습니까?\n검색이 느려질 수 있습니다.')) {
            return;
        }
        
        const res = await this.api('index_clear', {});
        
        if (res.success) {
            this.toast('인덱스가 초기화되었습니다', 'success');
            $('#index-total').text('0개');
            $('#index-files').text('0개');
            $('#index-folders').text('0개');
            $('#index-last-rebuild').text('없음');
            $('#index-status').html(`
                <div class="index-complete">
                    ✅ 인덱스가 초기화되었습니다
                </div>
            `).show();
        } else {
            this.toast(res.error || '초기화 실패', 'error');
        }
    },
    
    // ===== 보안 설정 =====
    async showSecurityModal() {
        this.showModal('modal-security');
        
        const res = await this.api('security_settings', {}, 'GET');
        
        if (res.success) {
            const s = res.settings || {};
            
            // 현재 접속 정보 표시
            $('#current-ip').text(res.current_ip || '-');
            $('#current-country').text(res.current_country || '-');
            $('#current-ip-hint').text(res.current_ip || '-');
            
            // 기본 설정
            $('#security-enabled').prop('checked', s.enabled || false);
            $('#security-block-country').prop('checked', s.block_country || false);
            $('#security-allow-country-only').prop('checked', s.allow_country_only || false);
            $('#security-block-ip').prop('checked', s.block_ip || false);
            $('#security-allow-ip-only').prop('checked', s.allow_ip_only || false);
            
            // IP/국가 목록
            $('#security-allowed-ips').val((s.allowed_ips || []).join('\n'));
            $('#security-blocked-ips').val((s.blocked_ips || []).join('\n'));
            $('#security-allowed-countries').val((s.allowed_countries || []).join(','));
            $('#security-blocked-countries').val((s.blocked_countries || []).join(','));
            $('#security-admin-ips').val((s.admin_ips || []).join('\n'));
            
            // 추가 설정
            $('#security-block-message').val(s.block_message || '접근이 차단되었습니다.');
            $('#security-cache-hours').val(s.cache_hours || 24);
            $('#security-log-enabled').prop('checked', s.log_enabled || false);
            
            // 브루트포스
            $('#security-max-attempts').val(s.max_attempts || 5);
            $('#security-lockout-minutes').val(s.lockout_minutes || 15);
        }
        
        // 입력 필드 활성화 상태 업데이트
        this.updateSecurityInputState();
        
        // 체크박스 변경 이벤트 바인딩 (중복 방지)
        const checkboxIds = ['security-block-country', 'security-allow-country-only', 'security-block-ip', 'security-allow-ip-only'];
        checkboxIds.forEach(id => {
            const el = document.getElementById(id);
            if (el && !el._securityBound) {
                el._securityBound = true;
                el.addEventListener('change', () => this.updateSecurityInputState());
            }
        });
    },
    
    updateSecurityInputState() {
        const blockCountry = $('#security-block-country').is(':checked');
        const allowCountryOnly = $('#security-allow-country-only').is(':checked');
        const blockIp = $('#security-block-ip').is(':checked');
        const allowIpOnly = $('#security-allow-ip-only').is(':checked');
        
        // 국가 입력 필드
        $('#security-blocked-countries').prop('disabled', !blockCountry);
        $('#security-allowed-countries').prop('disabled', !allowCountryOnly);
        
        // IP 입력 필드
        $('#security-blocked-ips').prop('disabled', !blockIp);
        $('#security-allowed-ips').prop('disabled', !allowIpOnly);
    },
    
    async saveSecuritySettings() {
        // 텍스트 필드에서 배열로 변환
        const parseList = (val, separator = '\n') => {
            return val.split(/[\n,]/).map(x => x.trim()).filter(x => x);
        };
        
        const settings = {
            enabled: $('#security-enabled').is(':checked'),
            block_country: $('#security-block-country').is(':checked'),
            allow_country_only: $('#security-allow-country-only').is(':checked'),
            block_ip: $('#security-block-ip').is(':checked'),
            allow_ip_only: $('#security-allow-ip-only').is(':checked'),
            
            allowed_ips: parseList($('#security-allowed-ips').val()),
            blocked_ips: parseList($('#security-blocked-ips').val()),
            allowed_countries: parseList($('#security-allowed-countries').val(), ',').map(x => x.toUpperCase()),
            blocked_countries: parseList($('#security-blocked-countries').val(), ',').map(x => x.toUpperCase()),
            admin_ips: parseList($('#security-admin-ips').val()),
            
            block_message: $('#security-block-message').val() || '접근이 차단되었습니다.',
            cache_hours: parseInt($('#security-cache-hours').val()) || 24,
            log_enabled: $('#security-log-enabled').is(':checked'),
            
            max_attempts: parseInt($('#security-max-attempts').val()) || 5,
            lockout_minutes: parseInt($('#security-lockout-minutes').val()) || 15
        };
        
        // 유효성 검사
        if (settings.enabled) {
            if (!settings.block_country && !settings.allow_country_only && !settings.block_ip && !settings.allow_ip_only) {
                this.toast('차단 모드를 최소 1개 이상 선택하세요', 'warning');
                return;
            }
            
            if (settings.admin_ips.length === 0) {
                if (!confirm('⚠️ 관리자 IP가 설정되지 않았습니다!\n\n실수로 자신의 IP가 차단되면 접근할 수 없게 됩니다.\n정말 저장하시겠습니까?')) {
                    return;
                }
            }
        }
        
        const res = await this.api('security_settings_save', settings);
        
        if (res.success) {
            this.toast('보안 설정이 저장되었습니다', 'success');
            closeModal();
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    async testSecuritySettings() {
        const res = await this.api('security_test', {}, 'GET');
        
        if (res.success) {
            let msg = `🧪 테스트 결과\n\n`;
            msg += `현재 IP: ${res.ip}\n`;
            msg += `국가 코드: ${res.country}\n`;
            msg += `차단 여부: ${res.blocked ? '⛔ 차단됨' : '✅ 허용됨'}\n`;
            if (res.reason) {
                msg += `사유: ${res.reason}`;
            }
            alert(msg);
        } else {
            this.toast(res.error || '테스트 실패', 'error');
        }
    },
    
    // 시스템 정보 모달
    async showSystemInfoModal() {
        // 먼저 로딩 상태로 모달 표시 (빠른 응답)
        $('#system-info-content').html('<div class="loading-spinner"><div class="spinner"></div><p>시스템 정보를 불러오는 중...</p></div>');
        this.showModal('modal-system-info');
        
        const res = await this.api('system_info', {}, 'GET');
        
        // WebDAV URL 생성
        const webdavUrl = `${window.location.origin}${window.location.pathname.replace('index.php', '')}mydav.php`;
        const externalUrl = this.systemSettings.external_url;
        const webdavExternal = externalUrl ? `${externalUrl.replace(/\/$/, '')}/mydav.php` : webdavUrl;
        
        let html = '';
        
        if (res.success) {
            // 디스크 용량 포맷
            const diskFree = res.disk_free ? this.formatSize(res.disk_free) : '-';
            const diskTotal = res.disk_total ? this.formatSize(res.disk_total) : '-';
            const diskUsed = (res.disk_total && res.disk_free) ? this.formatSize(res.disk_total - res.disk_free) : '-';
            const diskPercent = (res.disk_total && res.disk_free) ? Math.round((1 - res.disk_free / res.disk_total) * 100) : 0;
            
            // PHP 확장 모듈 HTML 생성
            let extHtml = '';
            if (res.extensions) {
                for (const [name, ext] of Object.entries(res.extensions)) {
                    const status = ext.loaded 
                        ? '<span class="status-ok">✅ 활성</span>'
                        : (ext.required ? '<span class="status-error">❌ 필수</span>' : '<span class="status-warn">⚠️ 선택</span>');
                    extHtml += `<tr><th>${name}</th><td>${status}</td><td>${ext.desc}</td></tr>`;
                }
            }
            
            // 폴더 권한 HTML 생성
            let folderHtml = '';
            if (res.folders) {
                for (const [name, folder] of Object.entries(res.folders)) {
                    const status = folder.writable 
                        ? '<span class="status-ok">✅ 쓰기 가능</span>'
                        : '<span class="status-error">❌ 쓰기 불가</span>';
                    folderHtml += `<tr><th>${name}</th><td>${status}</td><td class="path-cell">${folder.path || '-'}</td></tr>`;
                }
            }
            
            // 검색 인덱스 상태
            const indexStats = res.index_stats || {};
            const indexStatus = indexStats.available === false 
                ? '<span class="status-warn">⚠️ SQLite3 미설치</span>'
                : (indexStats.total > 0 ? '<span class="status-ok">✅ 활성</span>' : '<span class="status-warn">⚠️ 재구축 필요</span>');
            
            // 서버 리소스 정보
            const sr = res.server_resources || {};
            const cpuPercent = sr.cpu?.usage || 0;
            const memPercent = sr.memory?.percent || 0;
            const cpuBarColor = cpuPercent > 90 ? '#e74c3c' : (cpuPercent > 70 ? '#f39c12' : '#27ae60');
            const memBarColor = memPercent > 90 ? '#e74c3c' : (memPercent > 70 ? '#f39c12' : '#27ae60');
            const traffic = sr.traffic || {};
            const webservers = sr.webserver?.processes || [];
            // admin.php처럼 트래픽 인터페이스를 네트워크 인터페이스 목록으로 사용
            const trafficIfaces = traffic.interfaces || [];
            
            // 인터페이스 이름에서 링크 속도 추출 (admin.php 방식)
            const getLinkSpeed = (name) => {
                if (/2\.5G|2,5G/i.test(name)) return '2.5 Gbps';
                if (/10G/i.test(name)) return '10 Gbps';
                if (/1000|Gigabit/i.test(name)) return '1 Gbps';
                if (/100M/i.test(name)) return '100 Mbps';
                return '';
            };
            
            // 웹서버 프로세스 HTML
            let webserverHtml = '';
            if (webservers.length > 0) {
                webserverHtml = webservers.map(w => `
                    <span class="ws-badge">
                        ${w.icon || (w.name === 'Apache' ? '🌐' : (w.name === 'Nginx' ? '🟢' : (w.name === 'IIS' ? '🔷' : '🐘')))}
                        ${w.name} <strong>${w.count}</strong>
                        ${w.memory > 0 ? `<small>(${this.formatSize(w.memory)})</small>` : ''}
                    </span>
                `).join('');
            } else {
                webserverHtml = '<span class="text-muted">감지된 프로세스 없음</span>';
            }
            
            // 네트워크 인터페이스 HTML (admin.php처럼 traffic.interfaces 사용)
            let netIfaceHtml = '';
            if (trafficIfaces.length > 0) {
                let activeCount = 0;
                netIfaceHtml = trafficIfaces.map(n => {
                    const isActive = (n.rx > 0 || n.tx > 0);
                    if (isActive) activeCount++;
                    const icon = isActive ? '🟢' : '⚪';
                    const linkSpeed = getLinkSpeed(n.name);
                    return `
                        <span class="net-iface-badge">
                            ${icon} ${n.name}
                            ${linkSpeed ? `<small class="badge-speed">${linkSpeed}</small>` : ''}
                        </span>
                    `;
                }).join('');
            } else {
                netIfaceHtml = '<span class="text-muted">감지된 인터페이스 없음</span>';
            }
            
            html = `
                <div class="info-section resource-monitor">
                    <h3>🖥️ 서버 리소스 모니터</h3>
                    <div class="resource-grid">
                        <div class="resource-card">
                            <div class="resource-header">
                                <span class="resource-icon">⚡</span>
                                <span class="resource-title">CPU</span>
                                <span class="resource-value" id="rt-cpu">${cpuPercent}%</span>
                            </div>
                            <div class="resource-bar">
                                <div class="resource-bar-fill" id="rt-cpu-bar" style="width: ${cpuPercent}%; background: ${cpuBarColor}"></div>
                            </div>
                            <div class="resource-info">
                                <small>${sr.cpu?.model || 'Unknown'}</small>
                                <small>${sr.cpu?.cores || 0}코어 / ${sr.cpu?.threads || 0}스레드</small>
                            </div>
                        </div>
                        <div class="resource-card">
                            <div class="resource-header">
                                <span class="resource-icon">🧠</span>
                                <span class="resource-title">메모리</span>
                                <span class="resource-value" id="rt-mem">${memPercent}%</span>
                            </div>
                            <div class="resource-bar">
                                <div class="resource-bar-fill" id="rt-mem-bar" style="width: ${memPercent}%; background: ${memBarColor}"></div>
                            </div>
                            <div class="resource-info">
                                <small>사용: <span id="rt-mem-used">${sr.memory?.used ? this.formatSize(sr.memory.used) : '-'}</span></small>
                                <small>전체: ${sr.memory?.total ? this.formatSize(sr.memory.total) : '-'}</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="resource-grid" style="margin-top: 12px;">
                        <div class="resource-card network-card">
                            <div class="resource-header">
                                <span class="resource-icon">📊</span>
                                <span class="resource-title">네트워크 트래픽</span>
                            </div>
                            <div class="network-stats">
                                <div class="net-stat">
                                    <span class="net-label">⬇️ 수신 (Total)</span>
                                    <span class="net-value" id="rt-net-rx">${traffic.total_rx ? this.formatSize(traffic.total_rx) : '0 B'}</span>
                                </div>
                                <div class="net-stat">
                                    <span class="net-label">⬆️ 송신 (Total)</span>
                                    <span class="net-value" id="rt-net-tx">${traffic.total_tx ? this.formatSize(traffic.total_tx) : '0 B'}</span>
                                </div>
                            </div>
                            <div class="network-speed">
                                <span>⬇️ <span id="rt-rx-speed">0 B/s</span></span>
                                <span>⬆️ <span id="rt-tx-speed">0 B/s</span></span>
                            </div>
                        </div>
                        <div class="resource-card">
                            <div class="resource-header">
                                <span class="resource-icon">💾</span>
                                <span class="resource-title">디스크 I/O</span>
                            </div>
                            <div class="network-stats">
                                <div class="net-stat">
                                    <span class="net-label">📖 읽기</span>
                                    <span class="net-value" id="rt-disk-read">0 B/s</span>
                                </div>
                                <div class="net-stat">
                                    <span class="net-label">📝 쓰기</span>
                                    <span class="net-value" id="rt-disk-write">0 B/s</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="resource-extra">
                        <div class="extra-section">
                            <span class="extra-title">🌐 웹서버 프로세스</span>
                            <div class="extra-content">${webserverHtml}</div>
                        </div>
                        <div class="extra-section">
                            <span class="extra-title">📡 네트워크 인터페이스</span>
                            <div class="extra-content">${netIfaceHtml}</div>
                        </div>
                    </div>
                    
                    <div class="resource-details">
                        <span>🏠 호스트: <strong>${sr.hostname || 'Unknown'}</strong></span>
                        <span>🔒 사설 IP: <code>${sr.private_ip || '-'}</code></span>
                        <span>🌍 공인 IP: <code>${sr.public_ip || '-'}</code></span>
                        <span>⏱️ 가동: <strong>${sr.uptime || 'Unknown'}</strong></span>
                        <span>💻 ${sr.is_windows ? 'Windows' : 'Linux'}</span>
                    </div>
                    
                    <div class="realtime-controls">
                        <span id="rt-time">${res.php_info?.current_time || '-'}</span>
                        <div class="rt-buttons">
                            <select id="rt-interval" class="rt-select">
                                <option value="3" selected>3초</option>
                                <option value="5">5초</option>
                                <option value="10">10초</option>
                            </select>
                            <button id="rt-toggle" class="rt-btn" onclick="App.toggleRealtimeMonitor()">
                                <span id="rt-status">▶️ 시작</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h3>📊 서버 개요</h3>
                    <table class="info-table">
                        <tr><th>PHP 버전</th><td><span class="badge badge-info">${res.php_version || '-'}</span></td></tr>
                        <tr><th>서버 소프트웨어</th><td>${res.server_software || '-'}</td></tr>
                        <tr><th>운영체제</th><td>${res.os || '-'} ${sr.is_windows ? '(Windows)' : '(Linux)'}</td></tr>
                        <tr><th>호스트명</th><td><code>${sr.hostname || 'Unknown'}</code></td></tr>
                        <tr><th>서버 시간</th><td>${res.php_info?.current_time || '-'}</td></tr>
                        <tr><th>타임존</th><td>${res.php_info?.timezone || '-'}</td></tr>
                        <tr><th>HTTPS</th><td>${res.security_checks?.https ? '<span class="status-ok">✅ 활성화</span>' : '<span class="status-warn">⚠️ 비활성화</span>'}</td></tr>
                    </table>
                </div>
                
                <div class="info-section">
                    <h3>⚙️ PHP 설정</h3>
                    <table class="info-table">
                        <tr><th>최대 업로드</th><td>${res.upload_max || '-'}</td></tr>
                        <tr><th>POST 최대</th><td>${res.post_max || '-'}</td></tr>
                        <tr><th>메모리 제한</th><td>${res.memory_limit || '-'}</td></tr>
                        <tr><th>실행 시간 제한</th><td>${res.max_execution_time || '-'}초</td></tr>
                        <tr><th>PHP SAPI</th><td>${res.php_info?.sapi || '-'}</td></tr>
                        <tr><th>Zend 엔진</th><td>${res.php_info?.zend_version || '-'}</td></tr>
                        <tr><th>현재 메모리</th><td>${this.formatSize(res.php_info?.memory_usage || 0)} (최대: ${this.formatSize(res.php_info?.memory_peak || 0)})</td></tr>
                    </table>
                </div>
                
                <div class="info-section">
                    <h3>💽 디스크 공간</h3>
                    <div class="disk-usage">
                        <div class="disk-bar">
                            <div class="disk-used" style="width: ${diskPercent}%"></div>
                        </div>
                        <div class="disk-text">
                            사용: ${diskUsed} / 전체: ${diskTotal} (여유: ${diskFree})
                        </div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h3>🔧 PHP 확장 모듈</h3>
                    <table class="info-table ext-table">
                        <thead>
                            <tr><th>모듈</th><th>상태</th><th>설명</th></tr>
                        </thead>
                        <tbody>
                            ${extHtml}
                        </tbody>
                    </table>
                </div>
                
                <div class="info-section">
                    <h3>📁 폴더 권한</h3>
                    <table class="info-table folder-table">
                        <thead>
                            <tr><th>폴더</th><th>상태</th><th>경로</th></tr>
                        </thead>
                        <tbody>
                            ${folderHtml}
                        </tbody>
                    </table>
                </div>
                
                <div class="info-section">
                    <h3>🔍 검색 인덱스</h3>
                    <table class="info-table">
                        <tr><th>상태</th><td>${indexStatus}</td></tr>
                        <tr><th>인덱스 항목</th><td>${(indexStats.total || 0).toLocaleString()}개 (파일: ${(indexStats.files || 0).toLocaleString()}, 폴더: ${(indexStats.folders || 0).toLocaleString()})</td></tr>
                        <tr><th>마지막 재구축</th><td>${indexStats.last_rebuild || '없음'}</td></tr>
                        <tr><th>DB 파일 크기</th><td>${indexStats.db_size ? this.formatSize(indexStats.db_size) : '-'}</td></tr>
                        <tr><th>DB 수정 시간</th><td>${indexStats.db_modified || '-'}</td></tr>
                    </table>
                </div>
                
                <div class="info-section">
                    <h3>👥 사용자 통계</h3>
                    <table class="info-table">
                        <tr><th>전체 사용자</th><td>${res.total_users || 0}명</td></tr>
                        <tr><th>활성 세션</th><td>${res.active_sessions || 0}개</td></tr>
                        <tr><th>전체 스토리지</th><td>${res.total_storages || 0}개</td></tr>
                        <tr><th>활성 공유</th><td>${res.total_shares || 0}개</td></tr>
                    </table>
                </div>
                
                <div class="info-section">
                    <h3>🔐 세션 정보</h3>
                    <table class="info-table">
                        <tr><th>저장 방식</th><td>${res.session_info?.save_handler || '-'}</td></tr>
                        <tr><th>GC 수명</th><td>${res.session_info?.gc_maxlifetime || '-'}초 (${Math.round((res.session_info?.gc_maxlifetime || 0) / 60)}분)</td></tr>
                        <tr><th>쿠키 수명</th><td>${res.session_info?.cookie_lifetime || 0}초</td></tr>
                        <tr><th>SameSite</th><td>${res.session_info?.cookie_samesite || '-'}</td></tr>
                    </table>
                </div>
                
                <div class="info-section">
                    <h3>⚡ OPcache 상태</h3>
                    ${res.opcache_info?.enabled ? `
                    <table class="info-table">
                        <tr><th>상태</th><td><span class="status-ok">✅ 활성화</span></td></tr>
                        <tr><th>메모리 사용</th><td>${this.formatSize(res.opcache_info.memory_used || 0)} / ${this.formatSize(res.opcache_info.memory_total || 0)}</td></tr>
                        <tr><th>캐시 히트율</th><td><strong>${res.opcache_info.hit_rate || 0}%</strong> (${(res.opcache_info.hits || 0).toLocaleString()} hits)</td></tr>
                        <tr><th>캐시된 스크립트</th><td>${(res.opcache_info.cached_scripts || 0).toLocaleString()}개</td></tr>
                    </table>
                    ` : `<p class="text-muted">⚠️ OPcache 비활성화 - 성능 향상을 위해 활성화 권장</p>`}
                </div>
                
                <div class="info-section">
                    <h3>🗄️ APCu 캐시</h3>
                    ${res.apcu_info?.enabled ? `
                    <table class="info-table">
                        <tr><th>상태</th><td><span class="status-ok">✅ 활성화</span></td></tr>
                        <tr><th>메모리 사용</th><td>${this.formatSize(res.apcu_info.memory_used || 0)} / ${this.formatSize(res.apcu_info.memory_total || 0)}</td></tr>
                        <tr><th>캐시 히트율</th><td><strong>${res.apcu_info.hit_rate || 0}%</strong></td></tr>
                        <tr><th>저장된 항목</th><td>${(res.apcu_info.entries || 0).toLocaleString()}개</td></tr>
                    </table>
                    ` : `<p class="text-muted">⚠️ APCu 미설치 또는 비활성화</p>`}
                </div>
                
                <div class="info-section">
                    <h3>🛡️ 보안 체크리스트</h3>
                    <div class="security-grid">
                        <div class="security-card ${res.security_checks?.https ? 'ok' : 'warn'}">
                            <span class="security-icon">${res.security_checks?.https ? '✅' : '⚠️'}</span>
                            <span class="security-label">HTTPS 연결</span>
                            <span class="security-desc">${res.security_checks?.https ? 'SSL 암호화 활성' : 'SSL 미적용'}</span>
                        </div>
                        <div class="security-card ${res.security_checks?.display_errors ? 'ok' : 'warn'}">
                            <span class="security-icon">${res.security_checks?.display_errors ? '✅' : '⚠️'}</span>
                            <span class="security-label">에러 표시 숨김</span>
                            <span class="security-desc">display_errors: ${res.security_checks?.display_errors ? 'Off' : 'On'}</span>
                        </div>
                        <div class="security-card ${res.security_checks?.cookie_httponly ? 'ok' : 'warn'}">
                            <span class="security-icon">${res.security_checks?.cookie_httponly ? '✅' : '⚠️'}</span>
                            <span class="security-label">HttpOnly 쿠키</span>
                            <span class="security-desc">XSS 공격 방어</span>
                        </div>
                        <div class="security-card ${res.security_checks?.cookie_secure ? 'ok' : 'warn'}">
                            <span class="security-icon">${res.security_checks?.cookie_secure ? '✅' : '⚠️'}</span>
                            <span class="security-label">Secure 쿠키</span>
                            <span class="security-desc">HTTPS 전용 쿠키</span>
                        </div>
                        <div class="security-card ${res.security_checks?.expose_php ? 'ok' : 'warn'}">
                            <span class="security-icon">${res.security_checks?.expose_php ? '✅' : '⚠️'}</span>
                            <span class="security-label">PHP 버전 숨김</span>
                            <span class="security-desc">expose_php: ${res.security_checks?.expose_php ? 'Off' : 'On'}</span>
                        </div>
                        <div class="security-card ${res.security_checks?.allow_url_include ? 'ok' : 'error'}">
                            <span class="security-icon">${res.security_checks?.allow_url_include ? '✅' : '❌'}</span>
                            <span class="security-label">URL Include 차단</span>
                            <span class="security-desc">원격 코드 실행 방지</span>
                        </div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h3>🔗 WebDAV 연결</h3>
                    <p class="info-desc">Windows 탐색기에서 네트워크 드라이브로 연결할 수 있습니다.</p>
                    <table class="info-table">
                        <tr>
                            <th>내부망 접속</th>
                            <td>
                                <code class="webdav-url">${webdavUrl}</code>
                                <button class="btn btn-sm" onclick="App.copyToClipboard('${webdavUrl}')">복사</button>
                            </td>
                        </tr>
                        ${externalUrl ? `
                        <tr>
                            <th>외부 접속</th>
                            <td>
                                <code class="webdav-url">${webdavExternal}</code>
                                <button class="btn btn-sm" onclick="App.copyToClipboard('${webdavExternal}')">복사</button>
                            </td>
                        </tr>
                        ` : ''}
                    </table>
                    <div class="webdav-help">
                        <p><strong>Windows 연결 방법:</strong></p>
                        <ol>
                            <li>파일 탐색기 → "내 PC" 우클릭 → "네트워크 드라이브 연결"</li>
                            <li>폴더: 위 URL 입력</li>
                            <li>사용자: 사이트 아이디 / 비밀번호</li>
                        </ol>
                    </div>
                </div>
            `;
        } else {
            html = '<p>시스템 정보를 불러올 수 없습니다.</p>';
        }
        
        $('#system-info-content').html(html);
        
        // 실시간 모니터 초기화 및 자동 시작
        this.realtimeInterval = null;
        this.prevNetStats = null;
        this.prevDiskStats = null;
        
        // 자동으로 실시간 모니터 시작 (3초 간격)
        setTimeout(() => {
            if (!this.realtimeInterval) {
                this.toggleRealtimeMonitor();
            }
        }, 100);
    },
    
    // 실시간 모니터 토글
    toggleRealtimeMonitor() {
        if (this.realtimeInterval) {
            clearInterval(this.realtimeInterval);
            this.realtimeInterval = null;
            this.prevNetStats = null;
            this.prevDiskStats = null;
            $('#rt-status').text('▶️ 시작');
            $('#rt-toggle').removeClass('active');
        } else {
            const interval = parseInt($('#rt-interval').val()) * 1000;
            this.updateRealtimeStats();
            this.realtimeInterval = setInterval(() => this.updateRealtimeStats(), interval);
            $('#rt-status').text('⏸️ 중지');
            $('#rt-toggle').addClass('active');
        }
    },
    
    // 실시간 통계 업데이트
    async updateRealtimeStats() {
        try {
            const res = await this.api('server_stats', {}, 'GET');
            if (!res.success || !res.stats) return;
            
            const s = res.stats;
            
            // CPU
            const cpuPercent = s.cpu || 0;
            const cpuColor = cpuPercent > 90 ? '#e74c3c' : (cpuPercent > 70 ? '#f39c12' : '#27ae60');
            $('#rt-cpu').text(cpuPercent + '%');
            $('#rt-cpu-bar').css({ width: cpuPercent + '%', background: cpuColor });
            
            // 메모리
            const memPercent = s.memory?.percent || 0;
            const memColor = memPercent > 90 ? '#e74c3c' : (memPercent > 70 ? '#f39c12' : '#27ae60');
            $('#rt-mem').text(memPercent + '%');
            $('#rt-mem-bar').css({ width: memPercent + '%', background: memColor });
            $('#rt-mem-used').text(this.formatSize(s.memory?.used || 0));
            
            // 네트워크 트래픽
            const rx = s.network?.rx || 0;
            const tx = s.network?.tx || 0;
            $('#rt-net-rx').text(this.formatSize(rx));
            $('#rt-net-tx').text(this.formatSize(tx));
            
            // 네트워크 속도 계산
            if (this.prevNetStats) {
                const interval = parseInt($('#rt-interval').val());
                const rxSpeed = Math.max(0, (rx - this.prevNetStats.rx) / interval);
                const txSpeed = Math.max(0, (tx - this.prevNetStats.tx) / interval);
                $('#rt-rx-speed').text(this.formatSize(rxSpeed) + '/s');
                $('#rt-tx-speed').text(this.formatSize(txSpeed) + '/s');
            }
            this.prevNetStats = { rx, tx };
            
            // 디스크 I/O 속도 계산
            const diskRead = s.disk?.read || 0;
            const diskWrite = s.disk?.write || 0;
            if (this.prevDiskStats) {
                const interval = parseInt($('#rt-interval').val());
                const readSpeed = Math.max(0, (diskRead - this.prevDiskStats.read) / interval);
                const writeSpeed = Math.max(0, (diskWrite - this.prevDiskStats.write) / interval);
                $('#rt-disk-read').text(this.formatSize(readSpeed) + '/s');
                $('#rt-disk-write').text(this.formatSize(writeSpeed) + '/s');
            }
            this.prevDiskStats = { read: diskRead, write: diskWrite };
            
            // 시간 업데이트
            $('#rt-time').text(s.time || '-');
            
        } catch (e) {
            console.error('Realtime stats error:', e);
        }
    },
    
    // 설정 모달
    showSettingsModal() {
        if (this.user) {
            $('#settings-display-name').val(this.user.display_name || '');
            $('#settings-email').val(this.user.email || '');
        }
        $('#current-password').val('');
        $('#new-password').val('');
        $('#confirm-password').val('');
        
        // 2FA 상태 로드
        this.load2FAStatus();
        
        this.showModal('modal-settings');
    },
    
    // 2FA 상태 로드
    async load2FAStatus() {
        const res = await this.api('2fa_status');
        
        if (res.success) {
            $('#twofa-setup-section').hide();
            $('#twofa-backup-codes-section').hide();
            
            if (res.enabled) {
                $('#twofa-disabled-section').hide();
                $('#twofa-enabled-section').show();
                
                let info = `활성화 일시: ${res.enabled_at || '알 수 없음'}`;
                if (res.backup_codes_remaining !== undefined) {
                    info += `<br>남은 백업 코드: ${res.backup_codes_remaining}개`;
                }
                $('#twofa-enabled-info').html(info);
            } else {
                $('#twofa-enabled-section').hide();
                $('#twofa-disabled-section').show();
            }
        }
    },
    
    // 2FA 설정 시작
    async setup2FA() {
        const res = await this.api('2fa_setup');
        
        if (res.success) {
            $('#twofa-disabled-section').hide();
            $('#twofa-setup-section').show();
            
            // QR 코드 생성 (qrcodejs 라이브러리 사용)
            const qrContainer = document.getElementById('twofa-qr-code');
            qrContainer.innerHTML = '';  // 기존 QR 코드 제거
            
            if (typeof QRCode !== 'undefined') {
                new QRCode(qrContainer, {
                    text: res.uri,
                    width: 180,
                    height: 180,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } else {
                // QRCode 라이브러리 없으면 URI 직접 표시
                qrContainer.innerHTML = '<p style="color:#c00;">QR 코드를 생성할 수 없습니다.<br>아래 키를 직접 입력하세요.</p>';
            }
            
            $('#twofa-secret-key').text(res.secret);
            $('#twofa-verify-code').val('').focus();
        } else {
            this.toast(res.error || '2FA 설정 실패', 'error');
        }
    },
    
    // 2FA 활성화 확인
    async enable2FA() {
        const code = $('#twofa-verify-code').val().trim();
        
        if (!code || code.length !== 6) {
            this.toast('6자리 인증 코드를 입력하세요.', 'error');
            return;
        }
        
        const res = await this.api('2fa_enable', { code });
        
        if (res.success) {
            this.toast('2단계 인증이 활성화되었습니다!', 'success');
            
            // 백업 코드 표시
            $('#twofa-setup-section').hide();
            this.show2FABackupCodes(res.backup_codes);
        } else {
            this.toast(res.error || '인증 실패', 'error');
        }
    },
    
    // 백업 코드 표시
    show2FABackupCodes(codes) {
        const list = $('#twofa-backup-codes-list');
        list.html(codes.map(code => 
            `<code style="background:#f5f5f5; padding:8px; text-align:center; font-family:monospace; font-size:14px;">${code}</code>`
        ).join(''));
        $('#twofa-backup-codes-section').show();
    },
    
    // 2FA 비활성화
    async disable2FA() {
        const password = prompt('비밀번호를 입력하세요:');
        if (!password) return;
        
        const code = prompt('인증 앱의 6자리 코드를 입력하세요 (또는 백업 코드):');
        if (!code) return;
        
        if (!confirm('정말 2단계 인증을 해제하시겠습니까?')) return;
        
        const res = await this.api('2fa_disable', { password, code });
        
        if (res.success) {
            this.toast('2단계 인증이 해제되었습니다.', 'success');
            this.load2FAStatus();
        } else {
            this.toast(res.error || '해제 실패', 'error');
        }
    },
    
    // 백업 코드 재생성
    async regenerateBackupCodes() {
        const password = prompt('비밀번호를 입력하세요:');
        if (!password) return;
        
        if (!confirm('새 백업 코드를 생성하면 기존 코드는 사용할 수 없습니다. 계속하시겠습니까?')) return;
        
        const res = await this.api('2fa_regenerate_backup', { password });
        
        if (res.success) {
            this.toast('새 백업 코드가 생성되었습니다.', 'success');
            this.show2FABackupCodes(res.backup_codes);
        } else {
            this.toast(res.error || '재생성 실패', 'error');
        }
    },
    
    // 시스템 설정 모달 (관리자)
    async showSystemSettingsModal() {
        this.showModal('modal-system-settings');
        
        // 일반 설정 로드
        const res = await this.api('settings', {}, 'GET');
        if (res.success) {
            $('#setting-signup-enabled').prop('checked', res.settings.signup_enabled === true);
            $('#setting-auto-approve').prop('checked', res.settings.auto_approve === true);
            $('#setting-home-share').prop('checked', res.settings.home_share_enabled !== false);
            $('#setting-external-url').val(res.settings.external_url || '');
            $('#setting-auto-index').prop('checked', res.settings.auto_index === true);
            
            // 자동 승인 옵션 표시/숨김
            if (res.settings.signup_enabled) {
                $('#auto-approve-wrap').show();
            } else {
                $('#auto-approve-wrap').hide();
            }
        }
        
        // 사이트 설정 로드
        const siteRes = await this.api('site_settings_get', {}, 'GET');
        if (siteRes.success) {
            const settings = siteRes.settings || {};
            $('#setting-site-name').val(settings.site_name || '');
            
            // 로고 이미지 미리보기
            if (settings.logo_image) {
                $('#logo-preview').html(`<img src="${this.escapeHtml(settings.logo_image)}" alt="Logo">`);
                $('#btn-logo-delete').show();
            } else {
                $('#logo-preview').html('<span class="no-image">📁</span>');
                $('#btn-logo-delete').hide();
            }
            
            // 배경 이미지 미리보기
            if (settings.bg_image) {
                $('#bg-preview').html(`<img src="${this.escapeHtml(settings.bg_image)}" alt="Background">`);
                $('#btn-bg-delete').show();
            } else {
                $('#bg-preview').html('<span class="no-image">🖼️</span>');
                $('#btn-bg-delete').hide();
            }
        }
        
        // 스토리지 경로 설정 로드
        const pathRes = await this.api('storage_paths_get', {}, 'GET');
        if (pathRes.success) {
            $('#setting-user-files-root').val(pathRes.paths.user_files_root || '');
            $('#setting-shared-files-root').val(pathRes.paths.shared_files_root || '');
            $('#setting-trash-path').val(pathRes.paths.trash_path || '');
            
            // 현재 적용된 경로 표시
            $('#current-user-path').text('현재 적용: ' + pathRes.current.user_files_root);
            $('#current-shared-path').text('현재 적용: ' + pathRes.current.shared_files_root);
            $('#current-trash-path').text('현재 적용: ' + pathRes.current.trash_path);
        }
    },
    
    // 시스템 설정 저장
    async saveSystemSettings() {
        // 일반 설정 저장
        const res = await this.api('settings_update', {
            signup_enabled: $('#setting-signup-enabled').is(':checked'),
            auto_approve: $('#setting-auto-approve').is(':checked'),
            home_share_enabled: $('#setting-home-share').is(':checked'),
            external_url: $('#setting-external-url').val().trim(),
            auto_index: $('#setting-auto-index').is(':checked')
        });
        
        // 사이트 설정 저장
        const siteRes = await this.api('site_settings_update', {
            site_name: $('#setting-site-name').val().trim()
        });
        
        // 스토리지 경로 설정 저장
        const pathRes = await this.api('storage_paths_update', {
            user_files_root: $('#setting-user-files-root').val().trim(),
            shared_files_root: $('#setting-shared-files-root').val().trim(),
            trash_path: $('#setting-trash-path').val().trim()
        });
        
        if (!pathRes.success) {
            this.toast(pathRes.error || '경로 설정 저장 실패', 'error');
            return;
        }
        
        if (res.success && siteRes.success) {
            this.systemSettings = {
                signup_enabled: $('#setting-signup-enabled').is(':checked'),
                home_share_enabled: $('#setting-home-share').is(':checked'),
                external_url: $('#setting-external-url').val().trim()
            };
            
            // 상단 로고 텍스트 업데이트 (빈 값이면 기본값 사용)
            const siteName = $('#setting-site-name').val().trim() || 'FileStation';
            const logoEl = document.querySelector('.logo');
            if (logoEl) {
                const img = logoEl.querySelector('img');
                if (img) {
                    // 이미지가 있으면 이미지 유지 + 텍스트 변경
                    logoEl.innerHTML = '';
                    logoEl.appendChild(img);
                    logoEl.appendChild(document.createTextNode(' ' + siteName));
                } else {
                    // 이미지가 없으면 이모지 + 텍스트
                    logoEl.textContent = '📁 ' + siteName;
                }
            }
            document.title = siteName;
            
            // 경로가 변경되었으면 알림
            if (pathRes.message) {
                this.toast(pathRes.message, 'success');
            } else {
                this.toast('시스템 설정이 저장되었습니다', 'success');
            }
            closeModal();
        } else {
            this.toast(res.error || siteRes.error || '저장 실패', 'error');
        }
    },
    
    // 사이트 이미지 업로드
    async uploadSiteImage(type, file) {
        const formData = new FormData();
        formData.append('type', type);
        formData.append('image', file);
        
        const res = await this.api('site_image_upload', formData);
        
        if (res.success) {
            const previewId = type === 'logo' ? '#logo-preview' : '#bg-preview';
            const deleteBtn = type === 'logo' ? '#btn-logo-delete' : '#btn-bg-delete';
            
            $(previewId).html(`<img src="${this.escapeHtml(res.path)}" alt="${this.escapeHtml(type)}">`);
            $(deleteBtn).show();
            
            this.toast('이미지가 업로드되었습니다', 'success');
        } else {
            this.toast(res.error || '업로드 실패', 'error');
        }
    },
    
    // 사이트 이미지 삭제
    async deleteSiteImage(type) {
        if (!confirm('이미지를 삭제하시겠습니까?')) return;
        
        const res = await this.api('site_image_delete', { type: type });
        
        if (res.success) {
            const previewId = type === 'logo' ? '#logo-preview' : '#bg-preview';
            const deleteBtn = type === 'logo' ? '#btn-logo-delete' : '#btn-bg-delete';
            const defaultIcon = type === 'logo' ? '📁' : '🖼️';
            
            $(previewId).html(`<span class="no-image">${defaultIcon}</span>`);
            $(deleteBtn).hide();
            
            this.toast('이미지가 삭제되었습니다', 'success');
        } else {
            this.toast(res.error || '삭제 실패', 'error');
        }
    },
    
    // 내 정보 저장
    async saveSettings() {
        const res = await this.api('user_update', {
            id: this.user.id,
            display_name: $('#settings-display-name').val(),
            email: $('#settings-email').val()
        });
        
        if (res.success) {
            this.user.display_name = $('#settings-display-name').val();
            this.user.email = $('#settings-email').val();
            $('#user-name').text(this.user.display_name || this.user.username);
            this.toast('저장되었습니다', 'success');
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 비밀번호 변경
    async changePassword() {
        const currentPw = $('#current-password').val();
        const newPw = $('#new-password').val();
        const confirmPw = $('#confirm-password').val();
        
        if (!currentPw || !newPw) {
            this.toast('비밀번호를 입력하세요', 'error');
            return;
        }
        
        if (newPw !== confirmPw) {
            this.toast('새 비밀번호가 일치하지 않습니다', 'error');
            return;
        }
        
        if (newPw.length < 4) {
            this.toast('비밀번호는 4자 이상이어야 합니다', 'error');
            return;
        }
        
        const res = await this.api('change_password', {
            current_password: currentPw,
            new_password: newPw
        });
        
        if (res.success) {
            this.toast('비밀번호가 변경되었습니다', 'success');
            $('#current-password').val('');
            $('#new-password').val('');
            $('#confirm-password').val('');
        } else {
            this.toast(res.error, 'error');
        }
    },
    
    // 사이드바 오버레이 토글 (모바일용)
    toggleSidebarOverlay() {
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }
        
        if ($('.sidebar').hasClass('open')) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    },
    
    // 모달
    showModal(id) {
        
        $('#modal-overlay').show();
        $('.modal').hide();
        $(`#${id}`).show();
        
    },
    
    // ===== 드래그앤드롭 =====
    bindDragDrop() {
        const fileList = document.getElementById('file-list');
        if (!fileList) return;
        
        fileList.querySelectorAll('.file-item').forEach(item => {
            item.addEventListener('dragstart', e => {
                e.dataTransfer.effectAllowed = 'copyMove';
                e.dataTransfer.setData('text/plain', item.dataset.path);
                
                // 선택된 항목들 수집
                const selected = document.querySelectorAll('.file-item.selected');
                if (selected.length > 0 && item.classList.contains('selected')) {
                    this.draggedItems = Array.from(selected).map(el => el.dataset.path);
                } else {
                    this.draggedItems = [item.dataset.path];
                }
                
                item.classList.add('dragging');
            });
            
            item.addEventListener('dragend', e => {
                item.classList.remove('dragging');
                document.querySelectorAll('.file-item').forEach(el => {
                    el.classList.remove('drag-over');
                });
            });
            
            // 폴더에만 드롭 허용
            if (item.dataset.isDir === 'true') {
                item.addEventListener('dragover', e => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = e.ctrlKey ? 'copy' : 'move';
                    item.classList.add('drag-over');
                });
                
                item.addEventListener('dragleave', e => {
                    item.classList.remove('drag-over');
                });
                
                item.addEventListener('drop', async e => {
                    e.preventDefault();
                    e.stopPropagation();
                    item.classList.remove('drag-over');
                    
                    const destPath = item.dataset.path;
                    const action = e.ctrlKey ? 'copy' : 'move';
                    
                    if (this.draggedItems.includes(destPath)) {
                        this.toast('자기 자신으로 이동할 수 없습니다', 'error');
                        return;
                    }
                    
                    const res = await this.api('drag_drop', {
                        storage_id: this.currentStorage,
                        sources: this.draggedItems,
                        dest: destPath,
                        action: action
                    });
                    
                    if (res.success) {
                        this.toast(`${this.draggedItems.length}개 항목을 ${action === 'copy' ? '복사' : '이동'}했습니다`, 'success');
                        this.loadFiles();
                    } else {
                        this.toast(res.error || '작업 실패', 'error');
                    }
                    
                    this.draggedItems = [];
                });
            }
        });
    },
    
    // ===== 정렬 =====
    setSort(sortBy, order) {
        this.sortBy = sortBy;
        this.sortOrder = order;
        
        // 검색 모드일 때는 재검색 (검색어 복원)
        if (this.isSearchMode && this.searchState.query) {
            $('#search-input').val(this.searchState.query); // 검색어 복원
            this.doSearch(this.searchState.page);
        } else {
            this.loadFiles();
        }
        
        // 정렬 메뉴 업데이트
        $('.sort-option').removeClass('active');
        $(`.sort-option[data-sort="${sortBy}"][data-order="${order}"]`).addClass('active');
    },
    
    // ===== 상세 정보 =====
    async showDetailedInfo(item) {
        // 검색 결과에서 선택한 경우 해당 스토리지 ID 사용
        const storageId = item.storageId || this.currentStorage;
        
        const res = await this.api('detailed_info', {
            storage_id: storageId,
            path: item.path
        }, 'GET');
        
        if (!res.success) {
            this.toast(res.error || '정보를 불러올 수 없습니다', 'error');
            return;
        }
        
        const info = res.info;
        let html = `
            <table class="detailed-info-table">
                <tr><th>이름</th><td>${this.escapeHtml(info.name)}</td></tr>
                <tr><th>경로</th><td>${this.escapeHtml(info.path)}</td></tr>
                <tr><th>유형</th><td>${info.is_dir ? '폴더' : '파일'}</td></tr>
                <tr><th>크기</th><td>${info.size_formatted}</td></tr>
        `;
        
        if (info.is_dir && info.item_count) {
            html += `<tr><th>내용</th><td>${info.item_count.folders}개 폴더, ${info.item_count.files}개 파일</td></tr>`;
        }
        
        if (!info.is_dir) {
            html += `<tr><th>확장자</th><td>${info.extension || '-'}</td></tr>`;
            html += `<tr><th>MIME</th><td>${info.mime || '-'}</td></tr>`;
        }
        
        if (info.dimensions) {
            html += `<tr><th>크기(픽셀)</th><td>${info.dimensions}</td></tr>`;
        }
        
        html += `
            <tr><th>생성일</th><td>${info.created}</td></tr>
            <tr><th>수정일</th><td>${info.modified}</td></tr>
            <tr><th>접근일</th><td>${info.accessed}</td></tr>
            </table>
        `;
        
        // EXIF 정보
        if (info.exif && Object.keys(info.exif).length > 0) {
            html += `<div class="exif-section"><h4>📷 EXIF 정보</h4><table class="detailed-info-table">`;
            
            if (info.exif.make) html += `<tr><th>제조사</th><td>${this.escapeHtml(info.exif.make)}</td></tr>`;
            if (info.exif.model) html += `<tr><th>모델</th><td>${this.escapeHtml(info.exif.model)}</td></tr>`;
            if (info.exif.taken) html += `<tr><th>촬영일</th><td>${info.exif.taken}</td></tr>`;
            if (info.exif.exposure) html += `<tr><th>노출</th><td>${info.exif.exposure}</td></tr>`;
            if (info.exif.aperture) html += `<tr><th>조리개</th><td>${info.exif.aperture}</td></tr>`;
            if (info.exif.iso) html += `<tr><th>ISO</th><td>${info.exif.iso}</td></tr>`;
            if (info.exif.focal_length) html += `<tr><th>초점거리</th><td>${info.exif.focal_length}</td></tr>`;
            if (info.exif.gps) {
                html += `<tr><th>GPS</th><td>
                    <a href="https://www.google.com/maps?q=${info.exif.gps.latitude},${info.exif.gps.longitude}" target="_blank">
                        ${info.exif.gps.formatted} 🗺️
                    </a>
                </td></tr>`;
            }
            
            html += `</table></div>`;
        }
        
        $('#detailed-info-content').html(html);
        this.showModal('modal-detailed-info');
    },
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    // ===== 세션 관리 =====
    async loadSessions() {
        const res = await this.api('sessions', {}, 'GET');
        const list = $('#sessions-list');
        
        if (!res.success || !res.sessions.length) {
            list.html('<div class="empty-msg">활성 세션이 없습니다</div>');
            return;
        }
        
        let html = '';
        res.sessions.forEach(session => {
            const currentBadge = session.is_current ? '<span class="badge badge-current">현재</span>' : '';
            const terminateBtn = session.is_current ? '' : 
                `<button class="btn btn-danger btn-sm btn-terminate" data-session="${session.session_id}">로그아웃</button>`;
            
            html += `
                <div class="session-item ${session.is_current ? 'current' : ''}">
                    <div class="session-info">
                        <div class="session-device">
                            ${this.getDeviceIcon(session.user_agent)} ${this.escapeHtml(session.user_agent)} ${currentBadge}
                        </div>
                        <div class="session-details">
                            <span>IP: ${this.escapeHtml(session.ip)}</span>
                            <span>마지막 활동: ${session.last_activity}</span>
                        </div>
                    </div>
                    <div class="session-actions">
                        ${terminateBtn}
                    </div>
                </div>
            `;
        });
        
        list.html(html);
        
        // 로그아웃 버튼 이벤트
        list.find('.btn-terminate').on('click', async function() {
            const sessionId = $(this).data('session');
            await App.terminateSession(sessionId);
        });
    },
    
    getDeviceIcon(userAgent) {
        if (userAgent.includes('Windows')) return '💻';
        if (userAgent.includes('Mac')) return '🖥️';
        if (userAgent.includes('iOS')) return '📱';
        if (userAgent.includes('Android')) return '📱';
        if (userAgent.includes('Linux')) return '🐧';
        return '🌐';
    },
    
    async terminateSession(sessionId) {
        const res = await this.api('terminate_session', { session_id: sessionId });
        if (res.success) {
            this.toast('세션이 종료되었습니다', 'success');
            this.loadSessions();
        } else {
            this.toast(res.error || '세션 종료 실패', 'error');
        }
    },
    
    async terminateAllSessions() {
        if (!confirm('현재 기기를 제외한 모든 기기에서 로그아웃합니다. 계속하시겠습니까?')) {
            return;
        }
        
        const res = await this.api('terminate_all_sessions');
        if (res.success) {
            this.toast('모든 다른 기기에서 로그아웃되었습니다', 'success');
            this.loadSessions();
        } else {
            this.toast(res.error || '실패', 'error');
        }
    },
    
    // ===== 로그인 로그 =====
    myLogsPage: 1,
    
    async loadLoginLogs(page = 1) {
        this.myLogsPage = page;
        const res = await this.api('login_logs', { page, per_page: 20 }, 'GET');
        const list = $('#login-logs-list');
        
        if (!res.success || !res.logs?.length) {
            list.html('<div class="empty-msg">로그인 기록이 없습니다</div>');
            $('#my-login-pagination').empty();
            return;
        }
        
        let html = '<table class="login-logs-table"><thead><tr><th>시간</th><th>결과</th><th>IP</th><th>디바이스</th></tr></thead><tbody>';
        
        res.logs.forEach(log => {
            const statusClass = log.success ? 'success' : 'fail';
            const statusText = log.success ? '✅ 성공' : '❌ 실패';
            const uaDetails = this.parseUserAgentDetails(log.user_agent);
            const uaEscaped = (log.user_agent || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
            
            html += `
                <tr class="${statusClass}">
                    <td class="text-nowrap">${log.created_at}</td>
                    <td>${statusText}</td>
                    <td><code>${this.escapeHtml(log.ip)}</code></td>
                    <td class="ua-cell">
                        <span class="ua-detail d-none d-md-inline">${uaDetails.icon} ${this.escapeHtml(uaDetails.browser)}</span>
                        <span class="ua-icon d-inline d-md-none" onclick="App.showUserAgentPopup('${uaEscaped}')" style="cursor:pointer;font-size:1.3em;">${uaDetails.icon}</span>
                    </td>
                </tr>
            `;
        });
        
        html += '</tbody></table>';
        html += '<div id="my-login-pagination"></div>';
        list.html(html);
        
        this.renderPagination('#my-login-pagination', res.page, res.total_pages, res.total, 'loadLoginLogs');
    },
    
    // ===== 서버 설정 =====
    async loadServerConfig() {
        try {
            const res = await this.api('server_config', {}, 'GET');
            if (res.success && res.max_chunk_size) {
                this.serverConfig.maxChunkSize = res.max_chunk_size;
            }
        } catch (e) {
            // 실패 시 기본값 사용
        }
    },
    
    // ===== 테마 =====
    currentTheme: 'default',
    
    initTheme() {
        const saved = localStorage.getItem('theme') || 'default';
        this.setTheme(saved);
    },
    
    setTheme(theme) {
        // 테마 이름 검증 (영문/숫자/하이픈/언더스코어만 허용)
        if (!/^[a-zA-Z0-9_-]+$/.test(theme)) {
            console.warn('Invalid theme name:', theme);
            theme = 'default';
        }
        
        this.currentTheme = theme;
        
        // 기존 테마 CSS 제거
        $('#theme-css').remove();
        
        // 새 테마 CSS 적용 (모든 테마에 적용)
        $('head').append(`<link id="theme-css" rel="stylesheet" href="assets/themes/${theme}/theme.css">`);
        
        // 선택 표시 업데이트
        $('.theme-item').removeClass('active');
        $(`.theme-item[data-theme="${theme}"]`).addClass('active');
        
        // 저장
        localStorage.setItem('theme', theme);
    },
    
    // ===== 파일 미리보기 =====
    previewExtensions: {
        image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'],
        video: ['mp4', 'webm', 'mkv', 'avi', 'mov'],
        audio: ['mp3', 'wav', 'ogg', 'flac', 'm4a'],
        document: ['pdf', 'txt', 'md', 'html', 'htm'],
        code: ['php', 'js', 'css', 'json', 'xml', 'sql', 'py', 'java', 'c', 'cpp', 'h', 'ps1', 'bat', 'cmd', 'sh', 'bash', 'yml', 'yaml', 'ini', 'conf', 'log', 'csv']
    },
    
    currentPreviewPath: '',
    
    getFileType(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        for (const [type, exts] of Object.entries(this.previewExtensions)) {
            if (exts.includes(ext)) return type;
        }
        return null;
    },
    
    showPreview(item) {
        
        
        if (!item || !item.path) {
            this.toast('파일을 선택하세요', 'error');
            return;
        }
        
        if (item.isDir) {
            this.navigate(item.path);
            return;
        }
        
        const type = this.getFileType(item.name);
        
        
        if (!type) {
            this.toast('미리보기를 지원하지 않는 파일입니다', 'error');
            return;
        }
        
        // 검색 결과에서 선택한 경우 해당 스토리지 ID 사용
        const storageId = item.storageId || this.currentStorage;
        
        if (!storageId) {
            this.toast('스토리지가 선택되지 않았습니다', 'error');
            return;
        }
        
        this.currentPreviewPath = item.path;
        this.currentPreviewStorageId = storageId; // 미리보기 스토리지 ID 저장
        const url = `api.php?action=download&storage_id=${storageId}&path=${encodeURIComponent(item.path)}&inline=1`;
        
        
        
        // 로딩 표시
        $('#preview-title').text(item.name);
        $('#preview-content').html('<div class="preview-loading">로딩 중...</div>');
        this.showModal('modal-preview');
        
        let content = '';
        const ext = item.name.split('.').pop().toLowerCase();
        
        switch (type) {
            case 'image':
                content = `<img src="${url}" alt="${this.escapeHtml(item.name)}" class="preview-image" onerror="this.parentElement.innerHTML='<div class=preview-error>이미지를 불러올 수 없습니다</div>'">`;
                break;
            case 'video':
                content = `<video controls class="preview-video"><source src="${url}">동영상을 재생할 수 없습니다.</video>`;
                break;
            case 'audio':
                content = `
                    <div class="preview-audio-wrap">
                        <div class="audio-icon">🎵</div>
                        <div class="audio-title">${this.escapeHtml(item.name)}</div>
                        <audio controls class="audio-player">
                            <source src="${url}">
                            오디오를 재생할 수 없습니다.
                        </audio>
                    </div>`;
                break;
            case 'document':
                // PDF는 iframe으로 표시
                if (ext === 'pdf') {
                    content = `<iframe src="${url}" class="preview-pdf"></iframe>`;
                    break;
                }
                // 나머지 문서는 텍스트로
                this.loadTextPreview(url, item.name);
                return;
            case 'code':
                this.loadTextPreview(url, item.name);
                return;
        }
        
        $('#preview-content').html(content);
    },
    
    async loadTextPreview(url, filename) {
        try {
            const res = await fetch(url);
            let text = await res.text();
            
            // HTML escape
            text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            
            const ext = filename.split('.').pop().toLowerCase();
            const isCode = this.previewExtensions.code.includes(ext);
            
            const content = isCode 
                ? `<pre class="preview-code"><code>${text}</code></pre>`
                : `<pre class="preview-text">${text}</pre>`;
            
            $('#preview-title').text(filename);
            $('#preview-content').html(content);
            this.showModal('modal-preview');
        } catch (e) {
            this.toast('파일을 불러올 수 없습니다', 'error');
        }
    },
    
    // ===== 스토리지 용량 =====
    async loadStorageQuota() {
        if (!this.currentStorage) {
            $('#storage-quota').hide();
            return;
        }
        
        const res = await this.api('storage_quota', { storage_id: this.currentStorage }, 'GET');
        
        if (res.success) {
            // 기존 경고 클래스 제거
            $('#quota-used-bar').removeClass('quota-danger quota-warning');
            
            if (res.total > 0) {
                // quota 설정된 경우 - 프로그레스바 표시
                const percent = Math.min(100, (res.used / res.total) * 100);
                
                // 프로그레스 바 너비 설정
                $('#quota-used-bar').css('width', percent + '%');
                
                // 텍스트 업데이트
                $('#quota-text').text(`${this.formatSize(res.used)} / ${this.formatSize(res.total)} (${percent.toFixed(1)}%)`);
                
                // 용량 경고 클래스 추가
                if (percent > 90) {
                    $('#quota-used-bar').addClass('quota-danger');
                } else if (percent > 70) {
                    $('#quota-used-bar').addClass('quota-warning');
                }
            } else {
                // 무제한인 경우 - 사용량만 표시
                $('#quota-used-bar').css('width', '0%');
                $('#quota-text').text(`${this.formatSize(res.used)} 사용 중`);
            }
            
            $('#storage-quota').show();
        } else {
            $('#storage-quota').hide();
        }
    },

    // 토스트
    toast(message, type = '') {
        const toast = $('#toast');
        toast.removeClass('error success info').addClass(type);
        toast.text(message).addClass('show');
        
        setTimeout(() => {
            toast.removeClass('show');
        }, 3000);
    },
    
    // 파일 크기 포맷
    formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
    },
    
    // ===== 즐겨찾기 =====
    favorites: [],
    
    async loadFavorites() {
        try {
            const res = await this.api('favorites_get');
            if (res.success) {
                this.favorites = res.favorites || [];
                this.renderFavorites();
            }
        } catch (e) {
            console.error('즐겨찾기 로드 실패:', e);
        }
    },
    
    renderFavorites() {
        const list = document.getElementById('favorites-list');
        if (!list) return;
        
        if (this.favorites.length === 0) {
            list.innerHTML = '<li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;">즐겨찾기가 없습니다</li>';
            return;
        }
        
        // 최대 15개만 표시
        list.innerHTML = this.favorites.slice(0, 15).map(fav => {
            const icon = fav.is_dir ? '📁' : this.getFileIcon(fav.name);
            const escapedPath = this.escapeHtml(fav.path);
            const escapedName = this.escapeHtml(fav.name);
            return `<li class="favorite-item" data-storage="${fav.storage_id}" data-path="${escapedPath}" data-is-dir="${fav.is_dir}">
                <a href="#" title="${escapedPath}">${icon} ${escapedName}</a>
                <span class="favorite-remove" title="제거">×</span>
            </li>`;
        }).join('');
        
        // 하단에 전체 삭제 버튼
        list.innerHTML += `<li class="favorite-clear" style="text-align:center;padding:5px;">
            <a href="#" id="clear-favorites" style="color:#999;font-size:11px;">전체 삭제</a>
        </li>`;
        
        // 클릭 이벤트 - 폴더/파일 이동
        list.querySelectorAll('.favorite-item a').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                const li = el.closest('.favorite-item');
                const storageId = parseInt(li.dataset.storage);
                const path = li.dataset.path;
                const isDir = li.dataset.isDir === '1';
                
                if (isDir) {
                    this.currentStorage = storageId;
                    this.navigate(path);
                } else {
                    // 파일이면 해당 폴더로 이동
                    const folderPath = path.substring(0, path.lastIndexOf('/')) || '/';
                    this.currentStorage = storageId;
                    this.navigate(folderPath);
                }
            });
        });
        
        // 개별 삭제 버튼
        list.querySelectorAll('.favorite-remove').forEach(el => {
            el.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const li = el.closest('.favorite-item');
                const storageId = parseInt(li.dataset.storage);
                const path = li.dataset.path;
                
                const res = await this.api('favorites_remove', {
                    storage_id: storageId,
                    path: path
                });
                
                if (res.success) {
                    li.remove();
                    // 목록이 비어있으면 메시지 표시
                    if (list.querySelectorAll('.favorite-item').length === 0) {
                        list.innerHTML = '<li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;">즐겨찾기가 없습니다</li>';
                    }
                }
            });
        });
        
        // 전체 삭제
        const clearBtn = document.getElementById('clear-favorites');
        if (clearBtn) {
            clearBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                if (confirm('즐겨찾기를 모두 삭제하시겠습니까?')) {
                    const res = await this.api('favorites_clear');
                    if (res.success) {
                        this.toast('즐겨찾기가 삭제되었습니다', 'success');
                        this.loadFavorites();
                    }
                }
            });
        }
    },
    
    async addToFavorites(item) {
        const res = await this.api('favorites_add', {
            storage_id: this.currentStorage,
            path: item.path,
            name: item.name,
            is_dir: item.isDir
        });
        
        if (res.success) {
            this.toast('즐겨찾기에 추가되었습니다', 'success');
            this.loadFavorites();
        } else {
            this.toast(res.error || '즐겨찾기 추가 실패', 'error');
        }
    },
    
    async removeFromFavorites(item) {
        const res = await this.api('favorites_remove', {
            storage_id: this.currentStorage,
            path: item.path
        });
        
        if (res.success) {
            this.toast('즐겨찾기에서 제거되었습니다', 'success');
            this.loadFavorites();
        } else {
            this.toast(res.error || '즐겨찾기 제거 실패', 'error');
        }
    },
    
    isFavorite(path) {
        return this.favorites.some(f => f.storage_id === this.currentStorage && f.path === path);
    },
    
    // ===== 최근 파일 =====
    recentFiles: [],
    
    async loadRecentFiles() {
        try {
            const res = await this.api('recent_files_get', { limit: 20 });
            if (res.success) {
                this.recentFiles = res.files || [];
                this.renderRecentFiles();
            }
        } catch (e) {
            console.error('최근 파일 로드 실패:', e);
        }
    },
    
    renderRecentFiles() {
        const list = document.getElementById('recent-files-list');
        if (!list) return;
        
        if (this.recentFiles.length === 0) {
            list.innerHTML = '<li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;">최근 파일이 없습니다</li>';
            return;
        }
        
        list.innerHTML = this.recentFiles.slice(0, 15).map(file => {
            const icon = this.getFileIcon(file.name);
            const escapedPath = this.escapeHtml(file.path);
            const escapedName = this.escapeHtml(file.name);
            return `<li class="recent-file-item" data-storage="${file.storage_id}" data-path="${escapedPath}">
                <a href="#" title="${escapedPath}">${icon} ${escapedName}</a>
                <span class="recent-remove" title="삭제">×</span>
            </li>`;
        }).join('');
        
        // 하단에 기록 삭제 버튼
        list.innerHTML += `<li class="recent-clear" style="text-align:center;padding:5px;">
            <a href="#" id="clear-recent-files" style="color:#999;font-size:11px;">기록 삭제</a>
        </li>`;
        
        // 클릭 이벤트 - 파일 이동
        list.querySelectorAll('.recent-file-item a').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                const li = el.closest('.recent-file-item');
                const storageId = parseInt(li.dataset.storage);
                const path = li.dataset.path;
                
                // 해당 파일이 있는 폴더로 이동
                const folderPath = path.substring(0, path.lastIndexOf('/')) || '/';
                this.currentStorage = storageId;
                this.navigate(folderPath);
            });
        });
        
        // 개별 삭제 버튼
        list.querySelectorAll('.recent-remove').forEach(el => {
            el.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const li = el.closest('.recent-file-item');
                const storageId = parseInt(li.dataset.storage);
                const path = li.dataset.path;
                
                const res = await this.api('recent_files_remove', {
                    storage_id: storageId,
                    path: path
                });
                
                if (res.success) {
                    li.remove();
                    // 목록이 비어있으면 메시지 표시
                    if (list.querySelectorAll('.recent-file-item').length === 0) {
                        list.innerHTML = '<li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;">최근 파일이 없습니다</li>';
                    }
                }
            });
        });
        
        // 기록 삭제
        const clearBtn = document.getElementById('clear-recent-files');
        if (clearBtn) {
            clearBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                if (confirm('최근 파일 기록을 모두 삭제하시겠습니까?')) {
                    const res = await this.api('recent_files_clear');
                    if (res.success) {
                        this.toast('최근 파일 기록이 삭제되었습니다', 'success');
                        this.loadRecentFiles();
                    }
                }
            });
        }
    },
    
    async addToRecentFiles(path, name, action = 'view') {
        try {
            await this.api('recent_files_add', {
                storage_id: this.currentStorage,
                path: path,
                name: name,
                action: action
            });
            // 목록 갱신은 너무 자주 하지 않음
        } catch (e) {
            // 무시
        }
    },
    
    getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const icons = {
            // 이미지
            'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'webp': '🖼️', 'bmp': '🖼️', 'svg': '🖼️',
            // 비디오
            'mp4': '🎬', 'webm': '🎬', 'avi': '🎬', 'mkv': '🎬', 'mov': '🎬',
            // 오디오
            'mp3': '🎵', 'wav': '🎵', 'ogg': '🎵', 'flac': '🎵', 'm4a': '🎵',
            // 문서
            'pdf': '📕', 'doc': '📘', 'docx': '📘', 'xls': '📗', 'xlsx': '📗', 'ppt': '📙', 'pptx': '📙', 'txt': '📝',
            // 압축
            'zip': '📦', 'rar': '📦', '7z': '📦', 'tar': '📦', 'gz': '📦',
            // 코드
            'js': '📜', 'php': '📜', 'html': '📜', 'css': '📜', 'json': '📜', 'xml': '📜'
        };
        return icons[ext] || '📄';
    },
    
    // ===== 파일 잠금 =====
    lockedPaths: [],
    
    async loadLockedFiles() {
        try {
            const res = await this.api('locked_files_get', {
                storage_id: this.currentStorage
            });
            if (res.success) {
                this.lockedPaths = res.locked_paths || [];
            }
        } catch (e) {
            console.error('잠금 파일 로드 실패:', e);
        }
    },
    
    isFileLocked(path) {
        return this.lockedPaths.includes(path);
    },
    
    async lockFile(item) {
        const res = await this.api('file_lock', {
            storage_id: this.currentStorage,
            path: item.path
        });
        
        if (res.success) {
            this.toast('파일이 잠겼습니다', 'success');
            this.loadLockedFiles();
            this.loadFiles(); // 목록 갱신
        } else {
            this.toast(res.error || '파일 잠금 실패', 'error');
        }
    },
    
    async unlockFile(item) {
        const res = await this.api('file_unlock', {
            storage_id: this.currentStorage,
            path: item.path
        });
        
        if (res.success) {
            this.toast('파일 잠금이 해제되었습니다', 'success');
            this.loadLockedFiles();
            this.loadFiles(); // 목록 갱신
        } else {
            this.toast(res.error || '파일 잠금 해제 실패', 'error');
        }
    }
};

// jQuery 축약
function $(selector, context) {
    let els;
    const root = context || document;
    
    // document 객체 처리
    if (selector === document || selector === window) {
        els = [selector];
    } else if (typeof selector === 'string') {
        // :visible, :hidden 같은 jQuery 전용 선택자 처리
        if (selector.includes(':visible') || selector.includes(':hidden')) {
            const isVisibleFilter = selector.includes(':visible');
            const baseSelector = selector.replace(/:visible|:hidden/g, '').trim() || '*';
            try {
                els = Array.from(root.querySelectorAll(baseSelector)).filter(el => {
                    const style = window.getComputedStyle(el);
                    const visible = style.display !== 'none' && style.visibility !== 'hidden' && el.offsetParent !== null;
                    return isVisibleFilter ? visible : !visible;
                });
            } catch (e) {
                els = [];
            }
        } else {
            els = root.querySelectorAll(selector);
        }
    } else if (selector instanceof Element) {
        els = [selector];
    } else if (selector instanceof NodeList || Array.isArray(selector)) {
        els = selector;
    } else {
        els = [];
    }
    
    const methods = {
        on(event, selectorOrHandler, handler) {
            els.forEach(el => {
                if (typeof selectorOrHandler === 'function') {
                    el.addEventListener(event, selectorOrHandler);
                } else {
                    el.addEventListener(event, e => {
                        const target = e.target.closest(selectorOrHandler);
                        if (target && el.contains(target)) {
                            // matchedTarget을 이벤트 객체에 추가
                            e.matchedTarget = target;
                            handler.call(target, e);
                        }
                    });
                }
            });
            return methods;
        },
        val(v) {
            if (v === undefined) return els[0]?.value;
            els.forEach(el => el.value = v);
            return methods;
        },
        text(v) {
            if (v === undefined) return els[0]?.textContent;
            els.forEach(el => el.textContent = v);
            return methods;
        },
        html(v) {
            if (v === undefined) return els[0]?.innerHTML;
            els.forEach(el => el.innerHTML = v);
            return methods;
        },
        show() { els.forEach(el => el.style.display = ''); return methods; },
        hide() { els.forEach(el => el.style.display = 'none'); return methods; },
        toggle(show) { 
            els.forEach(el => el.style.display = show ? '' : 'none'); 
            return methods; 
        },
        addClass(c) { 
            const classes = c.split(/\s+/).filter(Boolean);
            els.forEach(el => classes.forEach(cls => el.classList.add(cls))); 
            return methods; 
        },
        removeClass(c) { 
            const classes = c.split(/\s+/).filter(Boolean);
            els.forEach(el => classes.forEach(cls => el.classList.remove(cls))); 
            return methods; 
        },
        toggleClass(c) { els.forEach(el => el.classList.toggle(c)); return methods; },
        hasClass(c) { return els[0]?.classList.contains(c); },
        is(selector) {
            if (selector === ':visible') {
                return els[0] && els[0].style.display !== 'none' && els[0].offsetParent !== null;
            }
            if (selector === ':hidden') {
                return !els[0] || els[0].style.display === 'none' || els[0].offsetParent === null;
            }
            if (selector === ':checked') {
                return els[0]?.checked || false;
            }
            return els[0]?.matches(selector) || false;
        },
        css(prop, val) {
            if (typeof prop === 'object') {
                els.forEach(el => Object.assign(el.style, prop));
            } else {
                els.forEach(el => el.style[prop] = val);
            }
            return methods;
        },
        attr(name, val) {
            if (val === undefined) return els[0]?.getAttribute(name);
            els.forEach(el => el.setAttribute(name, val));
            return methods;
        },
        data(name, val) {
            // kebab-case를 camelCase로 변환 (data-is-dir -> isDir)
            const camelName = name.replace(/-([a-z])/g, (g) => g[1].toUpperCase());
            if (val === undefined) {
                const v = els[0]?.dataset[camelName];
                if (v === 'true') return true;
                if (v === 'false') return false;
                return v;
            }
            els.forEach(el => el.dataset[camelName] = val);
            return methods;
        },
        prop(name, val) {
            if (val === undefined) return els[0]?.[name];
            els.forEach(el => el[name] = val);
            return methods;
        },
        append(html) { els.forEach(el => el.insertAdjacentHTML('beforeend', html)); return methods; },
        empty() { els.forEach(el => el.innerHTML = ''); return methods; },
        remove() { els.forEach(el => el.remove()); return methods; },
        find(sel) { return $(sel, els[0]); },
        focus() { els[0]?.focus(); return methods; },
        select() { els[0]?.select(); return methods; },
        click() { els[0]?.click(); return methods; },
        each(fn) { els.forEach((el, i) => fn(i, el)); return methods; },
        get(i) { return els[i]; },
        0: els[0],
        length: els.length
    };
    
    return methods;
}

function closeModal() {
    // 미디어 정지
    const previewContent = document.querySelector('#preview-content');
    if (previewContent) {
        const videos = previewContent.querySelectorAll('video');
        const audios = previewContent.querySelectorAll('audio');
        
        videos.forEach(v => {
            v.pause();
            v.src = '';
        });
        audios.forEach(a => {
            a.pause();
            a.src = '';
        });
        previewContent.innerHTML = '';
    }
    
    // 모달 위치 초기화
    document.querySelectorAll('.modal').forEach(modal => {
        modal.classList.remove('draggable');
        modal.style.left = '';
        modal.style.top = '';
    });
    
    $('#modal-overlay').hide();
    $('.modal').hide();
}

// 모달 드래그 기능
function initModalDrag() {
    let isDragging = false;
    let currentModal = null;
    let startX, startY, modalX, modalY;
    
    // 모달 헤더에서 드래그 시작
    document.addEventListener('mousedown', function(e) {
        const header = e.target.closest('.modal-header');
        if (!header) return;
        
        // 닫기 버튼 클릭은 제외
        if (e.target.classList.contains('modal-close') || e.target.closest('.modal-close')) {
            return;
        }
        
        currentModal = header.closest('.modal');
        if (!currentModal) return;
        
        isDragging = true;
        
        // 첫 드래그 시 위치 고정
        if (!currentModal.classList.contains('draggable')) {
            const rect = currentModal.getBoundingClientRect();
            currentModal.classList.add('draggable');
            currentModal.style.left = rect.left + 'px';
            currentModal.style.top = rect.top + 'px';
        }
        
        startX = e.clientX;
        startY = e.clientY;
        modalX = parseInt(currentModal.style.left) || 0;
        modalY = parseInt(currentModal.style.top) || 0;
        
        e.preventDefault();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging || !currentModal) return;
        
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        
        currentModal.style.left = (modalX + dx) + 'px';
        currentModal.style.top = (modalY + dy) + 'px';
    });
    
    document.addEventListener('mouseup', function() {
        isDragging = false;
        currentModal = null;
    });
}

// 모달 리사이즈 기능
function initModalResize() {
    let isResizing = false;
    let currentModal = null;
    let currentHandle = null;
    let startX, startY, startWidth, startHeight, startLeft, startTop;
    
    document.addEventListener('mousedown', function(e) {
        const handle = e.target.closest('.resize-handle');
        if (!handle) return;
        
        currentModal = handle.closest('.modal');
        if (!currentModal) return;
        
        isResizing = true;
        currentHandle = handle;
        
        const rect = currentModal.getBoundingClientRect();
        startX = e.clientX;
        startY = e.clientY;
        startWidth = rect.width;
        startHeight = rect.height;
        startLeft = rect.left;
        startTop = rect.top;
        
        // 처음 리사이즈 시 위치 고정
        if (!currentModal.classList.contains('draggable')) {
            currentModal.classList.add('draggable');
            currentModal.style.left = rect.left + 'px';
            currentModal.style.top = rect.top + 'px';
        }
        
        currentModal.style.width = rect.width + 'px';
        currentModal.style.height = rect.height + 'px';
        
        e.preventDefault();
        e.stopPropagation();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isResizing || !currentModal || !currentHandle) return;
        
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        
        const minWidth = 400;
        const minHeight = 300;
        
        if (currentHandle.classList.contains('resize-handle-se')) {
            // 우하단 - 너비와 높이 조절
            const newWidth = Math.max(minWidth, startWidth + dx);
            const newHeight = Math.max(minHeight, startHeight + dy);
            currentModal.style.width = newWidth + 'px';
            currentModal.style.height = newHeight + 'px';
        } else if (currentHandle.classList.contains('resize-handle-e')) {
            // 우측 - 너비만 조절
            const newWidth = Math.max(minWidth, startWidth + dx);
            currentModal.style.width = newWidth + 'px';
        } else if (currentHandle.classList.contains('resize-handle-s')) {
            // 하단 - 높이만 조절
            const newHeight = Math.max(minHeight, startHeight + dy);
            currentModal.style.height = newHeight + 'px';
        }
        
        // 내부 컨텐츠 크기도 조절
        const body = currentModal.querySelector('.modal-body');
        if (body) {
            const headerHeight = currentModal.querySelector('.modal-header')?.offsetHeight || 50;
            const footerHeight = currentModal.querySelector('.modal-footer')?.offsetHeight || 50;
            const bodyHeight = currentModal.offsetHeight - headerHeight - footerHeight;
            body.style.height = bodyHeight + 'px';
            
            // preview-content와 내부 컨텐츠 크기 조절
            const previewContent = body.querySelector('#preview-content');
            if (previewContent) {
                previewContent.style.width = '100%';
                previewContent.style.height = bodyHeight + 'px';
                
                // 이미지
                const img = previewContent.querySelector('.preview-image');
                if (img) {
                    img.style.maxWidth = '100%';
                    img.style.maxHeight = bodyHeight + 'px';
                }
                // 비디오
                const video = previewContent.querySelector('.preview-video');
                if (video) {
                    video.style.maxWidth = '100%';
                    video.style.maxHeight = bodyHeight + 'px';
                }
                // PDF
                const pdf = previewContent.querySelector('.preview-pdf');
                if (pdf) {
                    pdf.style.height = bodyHeight + 'px';
                }
                // 텍스트/코드
                const text = previewContent.querySelector('.preview-text, .preview-code');
                if (text) {
                    text.style.height = bodyHeight + 'px';
                }
                // 오디오
                const audio = previewContent.querySelector('.preview-audio-wrap');
                if (audio) {
                    audio.style.height = bodyHeight + 'px';
                }
            }
        }
    });
    
    document.addEventListener('mouseup', function() {
        isResizing = false;
        currentModal = null;
        currentHandle = null;
    });
}

// 초기화
document.addEventListener('DOMContentLoaded', () => {
    App.init();
    initModalDrag();
    initModalResize();
});