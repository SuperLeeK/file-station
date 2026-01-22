<?php
/**
 * 활동 로그 관리
 * 업로드, 다운로드, 삭제, 공유 등 모든 활동 기록
 */

class ActivityLog {
    private $db;
    private $auth;
    
    // 로그 타입 상수
    const TYPE_UPLOAD = 'upload';
    const TYPE_DOWNLOAD = 'download';
    const TYPE_DELETE = 'delete';
    const TYPE_CREATE_FOLDER = 'create_folder';
    const TYPE_RENAME = 'rename';
    const TYPE_MOVE = 'move';
    const TYPE_COPY = 'copy';
    const TYPE_SHARE_CREATE = 'share_create';
    const TYPE_SHARE_DELETE = 'share_delete';
    const TYPE_SHARE_ACCESS = 'share_access';
    const TYPE_EXTRACT = 'extract';
    const TYPE_COMPRESS = 'compress';
    const TYPE_RESTORE = 'restore';
    const TYPE_LOGIN = 'login';
    const TYPE_LOGOUT = 'logout';
    const TYPE_LOGIN_FAIL = 'login_fail';
    const TYPE_HACK_ATTEMPT = 'hack_attempt';
    
    public function __construct($db, $auth) {
        $this->db = $db;
        $this->auth = $auth;
    }
    
    /**
     * 로그 기록
     */
    public function log(string $type, array $data = []): int {
        $user = $this->auth->getUser();
        
        $logEntry = [
            'type' => $type,
            'user_id' => $user['id'] ?? 0,
            'username' => $user['username'] ?? 'guest',
            'display_name' => $user['display_name'] ?? 'Guest',
            'storage_id' => $data['storage_id'] ?? null,
            'storage_name' => $data['storage_name'] ?? null,
            'path' => $data['path'] ?? null,
            'filename' => $data['filename'] ?? null,
            'size' => $data['size'] ?? null,
            'details' => $data['details'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->db->insert('activity_logs', $logEntry);
    }
    
    /**
     * 로그 목록 조회
     */
    public function getLogs(array $filters = [], int $page = 1, int $limit = 50): array {
        $logs = $this->db->load('activity_logs');
        
        // 최신순 정렬
        usort($logs, function($a, $b) {
            return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
        });
        
        // 필터 적용
        if (!empty($filters['user_id'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return ($log['user_id'] ?? 0) == $filters['user_id'];
            });
        }
        
        if (!empty($filters['type'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return ($log['type'] ?? '') === $filters['type'];
            });
        }
        
        if (!empty($filters['storage_id'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return ($log['storage_id'] ?? 0) == $filters['storage_id'];
            });
        }
        
        if (!empty($filters['date_from'])) {
            $from = strtotime($filters['date_from']);
            $logs = array_filter($logs, function($log) use ($from) {
                return strtotime($log['created_at'] ?? 0) >= $from;
            });
        }
        
        if (!empty($filters['date_to'])) {
            $to = strtotime($filters['date_to'] . ' 23:59:59');
            $logs = array_filter($logs, function($log) use ($to) {
                return strtotime($log['created_at'] ?? 0) <= $to;
            });
        }
        
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $logs = array_filter($logs, function($log) use ($search) {
                return strpos(strtolower($log['filename'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['path'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['username'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['display_name'] ?? ''), $search) !== false;
            });
        }
        
        $logs = array_values($logs);
        $total = count($logs);
        
        // 페이지네이션
        $offset = ($page - 1) * $limit;
        $logs = array_slice($logs, $offset, $limit);
        
        return [
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    }
    
    /**
     * 사용자별 통계
     */
    public function getUserStats(int $userId): array {
        $logs = $this->db->load('activity_logs');
        
        $userLogs = array_filter($logs, function($log) use ($userId) {
            return ($log['user_id'] ?? 0) == $userId;
        });
        
        $stats = [
            'total' => count($userLogs),
            'uploads' => 0,
            'downloads' => 0,
            'deletes' => 0,
            'shares' => 0,
            'total_upload_size' => 0,
            'total_download_size' => 0
        ];
        
        foreach ($userLogs as $log) {
            switch ($log['type'] ?? '') {
                case self::TYPE_UPLOAD:
                    $stats['uploads']++;
                    $stats['total_upload_size'] += $log['size'] ?? 0;
                    break;
                case self::TYPE_DOWNLOAD:
                    $stats['downloads']++;
                    $stats['total_download_size'] += $log['size'] ?? 0;
                    break;
                case self::TYPE_DELETE:
                    $stats['deletes']++;
                    break;
                case self::TYPE_SHARE_CREATE:
                    $stats['shares']++;
                    break;
            }
        }
        
        return $stats;
    }
    
    /**
     * 로그 삭제 (관리자)
     */
    public function clearLogs(?string $beforeDate = null): array {
        if ($beforeDate) {
            $logs = $this->db->load('activity_logs');
            $cutoff = strtotime($beforeDate);
            
            $logs = array_filter($logs, function($log) use ($cutoff) {
                return strtotime($log['created_at'] ?? 0) >= $cutoff;
            });
            
            $this->db->save('activity_logs', array_values($logs));
        } else {
            $this->db->save('activity_logs', []);
        }
        
        return ['success' => true];
    }
    
    /**
     * 로그 타입 한글 변환
     */
    public static function getTypeLabel(string $type): string {
        $labels = [
            self::TYPE_UPLOAD => '📤 업로드',
            self::TYPE_DOWNLOAD => '📥 다운로드',
            self::TYPE_DELETE => '🗑️ 삭제',
            self::TYPE_CREATE_FOLDER => '📁 폴더 생성',
            self::TYPE_RENAME => '✏️ 이름 변경',
            self::TYPE_MOVE => '📦 이동',
            self::TYPE_COPY => '📋 복사',
            self::TYPE_SHARE_CREATE => '🔗 공유 생성',
            self::TYPE_SHARE_DELETE => '🔗 공유 삭제',
            self::TYPE_SHARE_ACCESS => '👁️ 공유 접근',
            self::TYPE_EXTRACT => '📦 압축 해제',
            self::TYPE_COMPRESS => '🗜️ 압축',
            self::TYPE_RESTORE => '↩️ 복원',
            self::TYPE_LOGIN => '🔐 로그인',
            self::TYPE_LOGOUT => '🔓 로그아웃',
            self::TYPE_LOGIN_FAIL => '⚠️ 로그인 실패',
            self::TYPE_HACK_ATTEMPT => '🚨 해킹시도',
        ];
        
        return $labels[$type] ?? $type;
    }
}
