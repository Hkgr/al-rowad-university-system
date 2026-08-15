# Cloud Agent base image for the Al Rowad University System monorepo.
#
# Provides the toolchains the repository pins/requires:
#   - PHP 8.4 (composer.lock resolves Symfony 8.x which needs PHP >= 8.4; also
#     matches deploy.sh's Plesk PHP 8.4 preference) + the extensions Laravel 13
#     and this app use.
#   - MariaDB server + client (the domain schema lives in a SQL dump, not in
#     Laravel migrations, so a real MySQL-compatible server is required).
#   - Composer.
#   - Node.js 24 (pinned in frontend/.node-version) + npm.
#
# Repository source is checked out separately by Cursor, so nothing is COPYed in.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Etc/UTC

# Base tooling.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates curl git unzip gnupg lsb-release sudo software-properties-common \
 && rm -rf /var/lib/apt/lists/*

# PHP 8.4 from the ondrej/php PPA (Ubuntu 24.04 only ships PHP 8.3) + extensions.
RUN add-apt-repository -y ppa:ondrej/php \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
      php8.4-zip php8.4-bcmath php8.4-gd php8.4-sqlite3 php8.4-intl \
 && update-alternatives --set php /usr/bin/php8.4 \
 && rm -rf /var/lib/apt/lists/*

# MariaDB server + client.
RUN apt-get update \
 && apt-get install -y --no-install-recommends mariadb-server mariadb-client \
 && rm -rf /var/lib/apt/lists/*

# Composer.
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
 && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm -f /tmp/composer-setup.php

# Node.js 24 (pinned via frontend/.node-version) + npm.
RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

# The default 'ubuntu' user (uid 1000) runs the agent; it needs passwordless sudo
# to start/stop the MariaDB service and to import the schema as the root DB account.
RUN usermod -aG sudo ubuntu \
 && echo 'ubuntu ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-ubuntu-nopasswd \
 && chmod 0440 /etc/sudoers.d/90-ubuntu-nopasswd

USER ubuntu
WORKDIR /home/ubuntu
