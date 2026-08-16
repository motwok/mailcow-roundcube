# Mailcow Roundcube Extension

[![License](https://img.shields.io/github/license/motwok/mailcow-roundcube)](./LICENSE)
![Status](https://img.shields.io/badge/status-WIP-orange)
![Docker Compose](https://img.shields.io/badge/docker-compose-2496ED?logo=docker&logoColor=white)
![Bash](https://img.shields.io/badge/shell-bash-121011?logo=gnu-bash&logoColor=white)
![Roundcube](https://img.shields.io/badge/Roundcube-Webmail-3c6eb4)
![Mailcow](https://img.shields.io/badge/Mailcow-Integration-2d9cdb)
[![GitHub Stars](https://img.shields.io/github/stars/motwok/mailcow-roundcube?style=social)](https://github.com/motwok/mailcow-roundcube/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/motwok/mailcow-roundcube?style=social)](https://github.com/motwok/mailcow-roundcube/network/members)
[![GitHub Issues](https://img.shields.io/github/issues/motwok/mailcow-roundcube)](https://github.com/motwok/mailcow-roundcube/issues)
[![Version (latest tag)](https://img.shields.io/github/v/tag/motwok/mailcow-roundcube?sort=semver&label=version)](https://github.com/motwok/mailcow-roundcube/tags)

WARNING: THIS IS A WORK IN PROGRESS. DON'T USE IT UNTIL THIS WARNING IS REMOVED.

This project adds a Roundcube webmail service to an existing Mailcow installation using a Docker Compose extension file and helper scripts.

THIS IS AN UNOFFICIAL EXTENSION. IT IS NOT SUPPORTED BY THE MAILCOW TEAM.

(At least not yet. Maybe one day.)

## What this project does

- Adds a `roundcube` service via `docker-compose.extension.yml`
- Adds access to Roundcube via `/roundcube` path on the Mailcow hostname
- Adds a middleware via nginy to translate sogo groups from and 
to VCARD Version 3/4 with Apple Extensions
- Provides install, update, and uninstall automation scripts
- Includes optional Roundcube plugins:
  - `mailcow`: adds a "Mailcow UI" button in the Roundcube taskbar

### TODOs
- Integrates Roundcube with Mailcow client configuration
- Add Authentication via Mailcow SSO

## Repository layout

- `docker-compose.extension.yml` - Compose extension for Roundcube + Nginx mounts
- `install.sh` - Full setup automation
- `update.sh` - Pull/update flow
- `uninstall.sh` - Uninstall script
- `data/` - Roundcube and Nginx custom files

## Prerequisites

- Existing Mailcow installation
- Docker + Docker Compose
- Bash shell
- OpenSSL (used by installer for credential generation)

## Installation

This repository must be placed **inside the Mailcow root directory**.

Recommended default path:

```bash
cd /opt/mailcow-dockerized
git clone https://github.com/motwok/mailcow-roundcube.git mailcow-roundcube
cd mailcow-roundcube
```

The scripts compute paths relative to this location.

### Run installer

From the `mailcow-roundcube` directory:

```bash
bash install.sh
```

The installer will:

1. Validate required files and running services
2. Ensure Roundcube DB password exists in `mailcow.conf` (or generate one)
3. Create Roundcube DB/user
4. Update `COMPOSE_FILE` in `mailcow.conf`, adding loading of `docker-compose.extension.yml`
5. Optional restart Mailcow stack


## Update

```bash
bash update.sh
```

1. Validate required files and running services
2. Pulls repository updates
3. Pulls Roundcube image updates
4. Optional restart Mailcow stack

## Uninstall

```bash
bash uninstall.sh
```

1. Validate required files and running services
2. Shuts down Roundcube service
3. optionally removes Roundcube DB
4. Removes Roundcube DB password frpm `mailcow.conf` if DB is removed
5. Update `COMPOSE_FILE` in `mailcow.conf`, removing docker-compose.extension.yml

## Webmail Selection (First Visit) TODO!!!

On the first visit, users can choose which webmail interface they want to use:

- **SOGo**
- **Roundcube**

If they enable "remember this device", the choice is saved in the browser and reused automatically on future visits.

If they want to change it later, they can simply open:

- `/webmail`

This resets the saved choice and shows the selection again.

## Disclaimer

Use at your own risk. Test in a staging environment before deploying to production.

## Support Me

If you find this project useful, please consider supporting it by buying me a coffee: [Buy Me a Coffee](https://ko-fi.com/motwok)
