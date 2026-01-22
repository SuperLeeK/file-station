# FileStation Docker 배포 가이드 (Oracle Cloud)

이 가이드는 Oracle Cloud 인스턴스(Ubuntu/Oracle Linux)에 FileStation을 Docker로 배포하는 방법을 설명합니다.

## 1. 사전 준비

### 포트 개방
Oracle Cloud 보안 목록(Security List)에서 **8080** 포트(Ingress)를 허용해야 합니다.

1. Oracle Cloud 콘솔 접속
2. `Networking` -> `Virtual Cloud Networks` 선택
3. 사용하는 VCN 클릭 -> `Security Lists` -> `Default Security List` 클릭
4. `Add Ingress Rules` 클릭
   - **Source CIDR**: `0.0.0.0/0`
   - **Destination Port Range**: `8080`
   - **Protocol**: TCP
5. `Add Ingress Rules` 버튼 클릭

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

브라우저를 열고 `http://<서버_공인_IP>:8080` 으로 접속합니다.

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
