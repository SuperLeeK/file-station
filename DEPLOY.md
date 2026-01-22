# FileStation Docker 배포 가이드 (Oracle Cloud)

이 가이드는 Oracle Cloud 인스턴스(Ubuntu/Oracle Linux)에 FileStation을 Docker로 배포하는 방법을 설명합니다.

## 1. 사전 준비

### 1. 프로젝트 업데이트 및 실행
기존 서비스(Nginx)와 충돌을 피하기 위해 Caddy를 제거하고, **FileStation만 8080 포트**로 실행합니다.

```bash
# 로컬에서 업데이트
git add .
git commit -m "Update: Remove Caddy for Nginx integration"
git push
```

```bash
# 서버에서 업데이트
cd file-station
git pull

# 기존 컨테이너 종료 및 정리
sudo docker-compose down --remove-orphans

# FileStation만 실행 (8080 포트)
sudo docker-compose up -d --build
```

---

### 2. Nginx 리버스 프록시 설정 (서버 설정)
이미 실행 중인 Nginx에 `/file-station` 경로를 추가하여 FileStation 컨테이너(8080)로 연결합니다.

1.  **Nginx 설정 파일 열기** (서버마다 경로가 다를 수 있음, 보통 `/etc/nginx/sites-available/default` 또는 `/etc/nginx/nginx.conf`)
    ```bash
    sudo nano /etc/nginx/sites-available/default
    ```

2.  **server 블록(`server { ... }`) 안에 아래 내용을 추가**합니다. (443 SSL 블록 내부에 추가하는 것을 권장)

    ```nginx
    # FileStation 리버스 프록시 설정
    location /file-station/ {
        proxy_pass http://localhost:8080/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # 업로드 용량 제한 해제 (필요시)
        client_max_body_size 0;
    }
    ```
    > **주의:** `proxy_pass http://localhost:8080/;` 끝에 있는 **슬래시(/)**가 매우 중요합니다! (경로 재작성을 위해 필요)

3.  **Nginx 설정 테스트 및 재시작**
    ```bash
    # 설정 문법 확인
    sudo nginx -t
    
    # 문제 없으면 재시작
    sudo systemctl reload nginx
    ```

---

### 3. 접속 확인
이제 설정하신 도메인의 하위 경로로 접속할 수 있습니다.

*   **접속 주소**: `https://<도메인>/file-station/`
*   (예: `https://example.com/file-station/`)

---

### 4. 문제 해결
**Q: "404 Not Found" (CSS/JS 로딩 실패)**
A: `location /file-station/` 설정에서 `proxy_pass` 끝에 슬래시(`/`)가 빠졌는지 확인하세요.

**Q: "413 Request Entity Too Large" (업로드 실패)**
A: Nginx 설정의 `client_max_body_size`를 늘려주어야 합니다. (위 설정 예시 참조)

---

## 2. 서버 접속 및 프로젝트 설정

터미널을 통해 서버에 접속한 후, 프로젝트 파일을 업로드합니다.
(Git을 사용하지 않는 경우, 로컬에서 `scp` 명령어로 업로드하는 것이 가장 간편합니다.)

### 1) 로컬 PC에서 서버로 파일 전송
터미널(새 창)을 열고 아래 명령어를 입력하여 프로젝트 폴더 전체를 서버로 복사합니다.

```bash
# 로컬 터미널에서 실행
# scp -r <로컬_프로젝트_경로> <사용자>@<서버IP>:<서버_저장_경로>

scp -r ~/Workspace/superleek/filestation ubuntu@123.456.78.9:~/
```
> **참고**: `ubuntu`는 Oracle Cloud Ubuntu 인스턴스의 기본 사용자명입니다. Oracle Linux는 `opc`를 사용합니다.

### 2) 서버 접속 및 폴더 이동
```bash
ssh ubuntu@123.456.78.9
cd filestation
```

---

## 3. Docker 설치 (만약 없다면)

### Ubuntu
```bash
# 필수 패키지 설치
sudo apt update
sudo apt install -y docker.io docker-compose

# 현재 사용자를 docker 그룹에 추가 (재로그인 필요)
sudo usermod -aG docker $USER
```

### Oracle Linux
```bash
# Docker 설치
sudo dnf install -y docker-engine docker-cli
sudo systemctl enable --now docker

# Docker Compose 설치
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# 권한 설정
sudo usermod -aG docker $USER
```
*권한 설정 후에는 반드시 로그아웃 후 다시 로그인해야 적용됩니다.*

---

## 4. 실행

```bash
# 백그라운드에서 실행
docker-compose up -d --build
```

---

## 5. 접속 확인

- **기본 계정**: `admin` / `admin`
- **파일 저장 위치**: `filestation/data`, `filestation/users` 폴더에 파일이 저장됩니다.

---

## 6. 문제 해결

### 1) 접속이 안 될 때
Ubuntu 방화벽(UFW)이나 iptables 설정을 확인하세요.

```bash
# Ubuntu UFW 사용 시 8080 포트 허용
sudo ufw allow 8080/tcp
```

### 2) 권한 오류 발생 시
Docker 컨테이너가 로컬 폴더에 쓰기 권한이 없는 경우일 수 있습니다.

```bash
# 데이터 폴더 권한 재설정
sudo chmod -R 777 data users shared
```
