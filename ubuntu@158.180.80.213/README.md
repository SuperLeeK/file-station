# FileStation

시놀로지 파일 스테이션 대체용 웹 기반 파일 관리자

## 주요 기능

- ✅ 네트워크 드라이브 / 로컬 드라이브 다중 추가
- ✅ SMB/CIFS 공유 폴더 연결 (Windows)
- ✅ UNC 경로 지원 (\\\\server\\share)
- ✅ **무제한 대용량 파일 업로드** (청크 업로드 방식)
- ✅ 파일 업로드/다운로드 (이어받기 지원)
- ✅ 폴더 생성/삭제/이름변경
- ✅ 공유 링크 생성 (비밀번호, 만료일, 다운로드 횟수 제한)
- ✅ 사용자별 권한 관리 (읽기/쓰기/삭제/공유)
- ✅ 드래그 앤 드롭 업로드
- ✅ 그리드/리스트 뷰 전환
- ✅ 파일 검색

## 대용량 업로드

파일 크기 제한 없이 업로드 가능합니다.
- 10MB 청크 단위로 분할 업로드
- 네트워크 오류 시 자동 재시도 (최대 3회)
- 업로드 중단 후 재개 가능 (24시간 내)
- PHP upload_max_filesize 설정과 무관하게 동작

## 요구사항

- PHP 7.4 이상 (8.x 권장)
- Apache 또는 IIS
- SQLite 3 (PDO)
- Windows 또는 Linux

## 설치

1. 파일을 웹 서버 디렉토리에 복사
2. `data` 폴더에 쓰기 권한 부여
3. 브라우저에서 접속

```
# Linux
chmod 755 data/

# Windows
data 폴더에 IIS_IUSRS 또는 IUSR 쓰기 권한 부여
```

## 기본 계정

- 아이디: `admin`
- 비밀번호: `admin`

⚠️ **첫 로그인 후 반드시 비밀번호를 변경하세요!**

## 스토리지 추가 예시

### 로컬 드라이브 (Windows)
```
경로: D:\Files
경로: E:\Documents
```

### 네트워크 드라이브 - UNC 경로
```
경로: \\192.168.1.100\share
경로: \\NAS\public
```

### SMB 공유 (인증 필요시)
```
유형: SMB 공유
호스트: 192.168.1.100
공유 이름: share
사용자명: user
비밀번호: pass
```

### Linux 경로
```
경로: /mnt/data
경로: /home/user/files
```

## php.ini 권장 설정

```ini
upload_max_filesize = 2G
post_max_size = 2G
max_execution_time = 3600
max_input_time = 3600
memory_limit = 512M
```

## IIS 설정 (web.config)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <requestLimits maxAllowedContentLength="2147483648" />
      </requestFiltering>
    </security>
    <defaultDocument>
      <files>
        <add value="index.php" />
      </files>
    </defaultDocument>
  </system.webServer>
</configuration>
```

## 파일 구조

```
filestation/
├── index.php          # 메인 페이지
├── api.php            # REST API
├── share.php          # 공유 링크 페이지
├── config.php         # 설정
├── .htaccess          # Apache 설정
├── api/
│   ├── Database.php   # DB 클래스
│   ├── Auth.php       # 인증 클래스
│   ├── Storage.php    # 스토리지 클래스
│   ├── FileManager.php # 파일 관리 클래스
│   └── ShareManager.php # 공유 클래스
├── assets/
│   ├── css/style.css
│   └── js/app.js
└── data/
    └── filestation.db  # SQLite DB (자동 생성)
```

## API 엔드포인트

| 액션 | 메서드 | 설명 |
|------|--------|------|
| login | POST | 로그인 |
| logout | POST | 로그아웃 |
| me | GET | 현재 사용자 정보 |
| storages | GET | 스토리지 목록 |
| storage_add | POST | 스토리지 추가 |
| files | GET | 파일 목록 |
| upload | POST | 파일 업로드 |
| download | GET | 파일 다운로드 |
| mkdir | POST | 폴더 생성 |
| delete | POST | 삭제 |
| rename | POST | 이름 변경 |
| share_create | POST | 공유 생성 |
| shares | GET | 공유 목록 |

## 라이선스

MIT License
