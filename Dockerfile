FROM php:8.2-apache

# 필수 패키지 설치 및 PHP 확장 모듈 활성화
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libsqlite3-dev \
    unzip \
    && docker-php-ext-install pdo_sqlite zip gd

# Apache rewrite 모듈 활성화 (.htaccess 지원)
RUN a2enmod rewrite

# 작업 디렉토리 설정
WORKDIR /var/www/html

# 소스 코드 복사
COPY . /var/www/html/

# 데이터 디렉토리 권한 설정
# www-data 유저가 Apache 실행 유저임
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/data \
    && chmod -R 755 /var/www/html/users \
    && chmod -R 755 /var/www/html/shared

# 불필요한 파일 제거 (선택 사항)
# RUN rm -rf .git

# Apache 포트 노출
EXPOSE 80
