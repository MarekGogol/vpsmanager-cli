# VPS Manager CLI

A command line hosting manager for your VPS: websites, PHP-FPM pools, MySQL, SSL, chroot and backups.

VPS Manager sets up and manages a LEMP stack (Linux, NGINX, MySQL, PHP-FPM) on an Ubuntu VPS. Each website ("hosting") gets its own configuration:

- creates a new hosting user with directories and correct, secure permissions
- creates and manages NGINX hosts, PHP-FPM pools and MySQL databases for each hosting
- manages Let's Encrypt SSL certificates (certbot) and updates the NGINX configuration to match
- optionally locks the hosting user into an isolated **chroot** SSH/SFTP environment
- backs up whatever you need: websites, databases, custom directories (NGINX, PHP, MySQL configs, crontabs...)
- supports remote server backups (rsync over SSH) and email notifications on errors

Everything is ready out of the box. You configure the features with the installation commands. If you only need backups, you can set up just the backups.

---

## Table of contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration reference](#configuration-reference)
4. [How it works (architecture)](#how-it-works-architecture)
5. [Managing hostings](#managing-hostings)
6. [SSL certificates](#ssl-certificates)
7. [Monitor: protection against scanners](#monitor-protection-against-scanners)
8. [Chroot environments](#chroot-environments)
9. [MySQL databases](#mysql-databases)
10. [Backups](#backups)
11. [Laravel queues and Octane](#laravel-queues-and-octane)
12. [Updating](#updating)
13. [Local development with Docker](#local-development-with-docker)
14. [Code style](#code-style)

---

## Requirements

- **Ubuntu server** (other Debian based systems may work). The tool is built around `apt`, `systemd`/`service`, `useradd` and `/etc/*` paths.
- **root access.** Every command checks `whoami` and refuses to run as any user other than `root`.
- **PHP 8.3 or newer** (8.3, 8.4, 8.5) for running the CLI itself (Composer dependencies: Symfony Console 7.4 LTS, Carbon 3, PHPMailer 7). `composer.json` pins the platform to PHP 8.3, so Composer resolves the same packages on every supported version and `composer install` / `composer update` work on all of them.
- **NGINX**
- **PHP-FPM** in one or more supported versions: `8.2`, `8.3`, `8.4`, `8.5`
- **MySQL** (the installer offers MySQL 9.7 LTS)
- **certbot** with the NGINX plugin (`python3-certbot-nginx`) for SSL certificates
- For backups: `zip`, `tar`, `rsync` (checked before every backup run), plus `ssh` for remote backups

`wamp_setup.sh` installs all of these for you (see below).

---

## Installation

```bash
cd /root/
git clone https://github.com/MarekGogol/vpsmanager-cli vpsmanager-cli && cd vpsmanager-cli
bash install.sh
```

`install.sh` runs three steps:

1. `bash wamp_setup.sh` prepares the server (packages, PHP, MySQL, NGINX...).
2. `composer install --no-plugins --no-scripts` installs the PHP dependencies.
3. `php vpsmanager install --vpsmanager_path=$(pwd)` starts the interactive configuration of VPS Manager.

### What `wamp_setup.sh` does

The script must run as root. First it checks your VPS for PHP, MySQL, NPM, NodeJS, Composer and more. It then asks before installing each missing component (`[Y/n]`). If a component is already installed, it only prints a green "is installed" message.

| Step | What happens |
|---|---|
| System update | `apt-get update && apt-get upgrade` |
| Timezone | `timedatectl set-timezone Europe/Bratislava` |
| Base packages | `zip`, `unzip`, `ssl-cert`, `gcc`, `libpng-dev`, `make`, `software-properties-common`, `fail2ban` (enabled), `rsyslog` (`/var/log/auth.log` for the `sshd` jail of fail2ban) |
| Locales | generates `sk_SK`, `cs_CZ`, `de_DE`, `pl_PL`, `ru_UA` (plain and `.UTF-8`) |
| NGINX | `apt install nginx` and starts the service |
| ImageMagick | `apt install imagemagick` |
| PHP 8.2 / 8.3 / 8.4 / 8.5 | each version is offered separately, from `ppa:ondrej/php` / `packages.sury.org`. Installs `fpm`, `cli`, `soap`, `mysql`, `zip`, `gd`, `mbstring`, `curl`, `xml`, `bcmath`, `redis`, `common`, `imagick`, `intl`, `tidy`, `sqlite3`, and adds `Restart=always` to the `phpX.Y-fpm` systemd unit |
| certbot | `certbot` + `python3-certbot-nginx` |
| NodeJS | NodeSource LTS setup, `nodejs` + `npm` |
| MySQL 9.7 LTS | adds the official MySQL APT repository (preselected `mysql-9.7-lts`), installs `mysql-server`, runs `mysql_secure_installation` and `scripts/protect-mysql-from-oom.sh`. That script adds a systemd override that protects MySQL from the OOM killer |
| Composer | installs into `/usr/local/bin/composer` |
| Image optimization (optional) | `jpegoptim`, `optipng`, `pngquant`, `gifsicle`, `webp`, and `svgo` through npm |
| journald | limits the journal size (`SystemMaxUse=300M`, `SystemKeepFree=500M`, `SystemMaxFileSize=200M`, `SystemMaxFiles=10`, `MaxRetentionSec=3month`) and restarts `systemd-journald` |

### The `install` command

```bash
php vpsmanager install
```

The command:

1. Adds the shell alias `alias vpsmanager="php /path/to/vpsmanager"` to `~/.bashrc`, once. After you open a new shell you can run `vpsmanager <command>` from anywhere.
2. Asks for each configuration value below. Press enter to keep the current value, or the default on a first install.
3. If you choose a default PHP version that is installed, it switches the system `php` CLI to it with `update-alternatives --set php /usr/bin/phpX.Y`.
4. If you allow self signed SSL, it uncomments the `listen 443 ssl default_server` and `include snippets/snakeoil.conf` lines in `sites-available/default`. If the snakeoil certificate is missing, it creates it with `make-ssl-cert generate-default-snakeoil`.
5. It saves everything into `src/config.php`.

Options:

| Option | Description |
|---|---|
| `--vpsmanager_path`, `--host`, `--open_basedir`, `--no_chmod` | Options for the (currently unused) VPS Manager web interface hosting. `install.sh` passes `--vpsmanager_path` |

You can run `install` again at any time to change the configuration. Values you already set are offered as defaults.

---

## Configuration reference

All configuration lives in **`src/config.php`**, a plain PHP file that returns an array. The file is git-ignored. It is written by `install` and `backup:setup`, and each save runs `chown root:root` and `chmod 600` on it, because it contains passwords. Never commit or share it.

### Server keys (`install`)

| Key | Default | Description |
|---|---|---|
| `nginx_path` | `/etc/nginx` | NGINX configuration directory (`sites-available`, `sites-enabled`, `conf.d`) |
| `nginx_is_proxied` | `false` | Set to `true` if the server sits behind a load balancer that sends proxied SSL requests. HTTPS `listen` directives then get `proxy_protocol` |
| `php_path` | `/etc/php` | PHP configuration directory. Pools are stored in `{php_path}/{version}/fpm/pool.d/` |
| `ssl_path` | `/etc/letsencrypt/live` | Directory with Let's Encrypt certificates |
| `ssl_email` | a placeholder address | Email passed to certbot (`-m`). Must be a valid address |
| `php_version` | `8.5` | Default PHP version for new hostings. One of `8.2`, `8.3`, `8.4`, `8.5` |
| `www_path` | `/var/www` | Root directory of all hostings |
| `self_signed_ssl` | `true` | Enable the snakeoil certificate on the NGINX default server |
| `mysql_user` | `root` | MySQL admin user that creates and removes databases |
| `mysql_pass` | empty | Password of `mysql_user`. The prompt shows it masked |
| `mysql_host` | `localhost` | Host part of created MySQL users (`localhost`, `192.168.1.%`, `%`...). The CLI itself always connects to `localhost` |

### Backup keys (`backup:setup`)

| Key | Default | Description |
|---|---|---|
| `backup_server_name` | `MyVpsServer` | Name of this server. Used in email subjects and the default remote path |
| `backup_path` | `/var/vpsmanager_backups` | Local backup root. It is created with `chmod 700` and owned by the `vpsmanager_backups` user |
| `backup_www_path` | `/var/www` | Directory whose subdirectories (one per website) are backed up |
| `backup_www_max_limit` | `2` | Maximum number of `www` and `dirs` backups kept locally (at least 1) |
| `backup_directories` | `/etc/nginx;/etc/php;/etc/mysql --exclude="*/debian.cnf";/etc/ssh/sshd_config;/var/spool/cron/crontabs` | `;`-separated list of extra paths. Anything after the first space is passed to `tar` as extra arguments. Use `-` to back up no directories |
| `email_notifications` | `true` | Send an email when a backup error occurs |
| `email_receiver` | | Address that receives notifications |
| `email_server` | | SMTP server as `host:port`. Port `465` uses SMTPS, any other port uses STARTTLS. With no port, `465` is used |
| `email_username` | | SMTP username, also used as the sender address |
| `email_password` | | SMTP password. You type it hidden and it is shown as `********` |
| `remote_backups` | `true` | Sync local backups to a remote server |
| `remote_server` | | IP address or domain of the remote backup server |
| `remote_user` | `vpsmanager_backups` | SSH user on the remote server |
| `remote_path` | `/var/vpsmanager_backups/remote/{backup_server_name}` | Destination directory on the remote server |
| `remote_backup_limit` | `2` | How many of the newest local backups of each type are kept on the remote server. Older ones are deleted there (at least 1) |
| `crontab_add` | `true` | Add the daily 4 AM backup cron job |

The email keys are asked only when `email_notifications` is on. The `remote_*` keys are asked only when `remote_backups` is on.

---

## How it works (architecture)

```
vpsmanager                  # entry script (Symfony Console application "VPS Manager")
install.sh                  # server setup + composer install + `install` command
wamp_setup.sh               # apt based server provisioning
scripts/                    # extra shell scripts (MySQL OOM protection)
src/
├── Application.php         # base class: config, console I/O, helper container, path helpers
├── config.php              # generated configuration (git-ignored, chmod 600)
├── Command/                # Symfony console commands (user interaction only)
│   ├── InstallManagerCommand.php
│   ├── SSLCreateCommand.php
│   ├── Hosting/            # hosting:create, hosting:remove
│   ├── Chroot/             # chroot:create, chroot:update, chroot:remove
│   ├── Mysql/              # mysql:create, mysql:reset, mysql:remove
│   ├── Backup/             # backup:setup, backup:run, backup:test-mail, backup:test-remote
│   ├── Laravel/            # laravel:queue, laravel:octane
│   └── Monitor/            # monitor:install, monitor:list, monitor:remove-ip, monitor:report, monitor:sync
├── Helpers/                # the actual server logic
│   ├── helpers.php         # global functions (vpsManager(), isValidDomain(), createDirectories()...)
│   ├── Hosting.php         # orchestrates create/remove of a whole hosting
│   ├── Server.php          # linux users, groups and the domain directory tree
│   ├── Nginx.php           # NGINX hosts, sites-enabled symlinks, config test/reload/restart,
│   │                       #   managed vpsmanager files and includes in the hosts (monitor)
│   ├── Fail2ban.php        # jails against scanners, bans, unbans, rsyslog for auth.log
│   ├── Monitor.php         # access log rotation and the report of monitor:report
│   ├── PHP.php             # PHP-FPM pools, versions, restart, default CLI version
│   ├── MySQLHelper.php     # databases and users via mysqli
│   ├── Certbot.php         # Let's Encrypt certificates + NGINX HTTPS rewrite
│   ├── Chroot.php          # chroot jail building and removal
│   ├── Backup.php          # backups, retention, rsync, email
│   ├── SSH.php             # sshd config test and restart
│   ├── Stub.php            # tiny template engine for files in src/Stub
│   └── Response.php        # success/error result object with console output
├── Stub/                   # templates: nginx.template.conf, nginx.redirect.conf,
│                           #   php-pool.conf, hello.php, banner.txt, fail2ban.scanners.conf
├── Resources/nginx/        # shared NGINX config copied into /etc/nginx
│   ├── nginx.conf
│   ├── conf.d/webp.conf, conf.d/vpsmanager-scanners.conf, conf.d/vpsmanager-monitor.conf
│   └── vpsmanager/         # general.conf, fastcgi-php.conf, cors-preflight.conf,
│                           #   scanners.conf, scanners.html, monitor.conf
├── Resources/fail2ban/     # filter.d and fail2ban.d files of the monitor
├── Resources/monitor/      # logrotate configuration and hourly cron of the access log
└── Traits/
    └── PHPSettingsTrait.php # supported PHP versions and per-pool php_admin_value settings
```

### Flow of a command

1. `vpsmanager` loads the Composer autoloader, including `src/Helpers/helpers.php`, registers all commands and runs the Symfony application.
2. A command calls `vpsManager()->bootConsole($output, $input, $helper)`. `vpsManager()` returns a single shared `Application` instance. Booting stores the console I/O, which lets helpers ask follow-up questions, and it checks that the user is `root`.
3. The command collects its input (arguments, options or interactive questions) and calls a helper, for example `vpsManager()->hosting()->create($domain, [...])`.
4. **Helpers** extend `Application`, so each one has `config()`, the path helpers and access to the other helpers (`$this->nginx()`, `$this->php()`, `$this->mysql()`...). Each helper is created once and cached (`Application::boot()`).
5. Helpers return a **`Response`** object: `success`, `error` or plain `message`. `->writeln()` prints it to the console, and `->isError()` lets the caller stop the flow.

### Stubs and resources

- `Stub` loads a file from `src/Stub`, replaces placeholders (`{host}`, `{path}`, `{php_sock_name}`, `{{user}}`...), can add lines before or after the content, and saves it to the target path.
- The first time an NGINX host is created, the contents of `src/Resources/nginx` are copied into `nginx_path`: the `vpsmanager/` snippets, `nginx.conf` and `conf.d/*`. This happens only if `{nginx_path}/vpsmanager` does not exist yet. The snippets add security headers, deny access to dotfiles, set static asset caching, serve WebP/JXR images when the browser accepts them, and hold the FastCGI and CORS preflight settings used by every host.

### Naming conventions

For a domain like `blog.example.com`:

| Thing | Value |
|---|---|
| Linux user and group | `example.com` (subdomains are stripped, see `toUserFormat()`) |
| User directory | `{www_path}/example.com` |
| Web data directory | `{www_path}/example.com/data` |
| PHP-FPM pool | `{php_path}/{ver}/fpm/pool.d/example.com.conf`, pool name `[example.com]` |
| PHP-FPM socket | `/run/php/php{ver}-fpm-example.com.sock` |
| NGINX host | `{nginx_path}/sites-available/blog.example.com`, symlinked into `sites-enabled` |
| MySQL database and user | `blog_example_com` (every non-alphanumeric run becomes `_`) |
| Supervisor programs | `/etc/supervisor/conf.d/example.com.conf`, programs `example.com-web-queue`, `example.com-sub-api-octane`... |

All hosting users are also members of the `vpsmanager_hosting_user` group. Chrooted users are also in `vpsmanager_chroot_user`.

---

## Managing hostings

### Create new hosting

```bash
sudo php vpsmanager hosting:create [domain] [--php_version=8.5]
```

| Argument / option | Description |
|---|---|
| `domain` (argument or `--domain`) | Domain of the hosting. You are asked for it if it is missing or invalid |
| `--php_version` | PHP version of the pool. If omitted, you choose from `8.2`, `8.3`, `8.4`, `8.5` (default from config). The version must be installed |

You are also asked whether to create a **chroot** environment and a **MySQL user and database**. The default for both is no.

What happens, step by step:

1. **Pre-checks.** The domain must be valid and the PHP version installed. If an NGINX host, linux user or PHP pool already exists, you are asked whether to continue with the existing one. Otherwise the command stops.
2. **Linux user.** `useradd` creates user `{domain}` with its own group, adds it to the `vpsmanager_hosting_user` group, and sets a random 16 character password. The password is printed once. The home directory is the web data directory, or `/data` for chroot users.
3. **MySQL** (optional). Creates database `{db_name}` (`utf8mb4_general_ci`) and user `{db_name}@{mysql_host}` (`caching_sha2_password`) with a random 20 character password and all privileges on that database. The password is printed once.
4. **Directory tree** (`/var/www/{domain}`):

   ```
   /var/www/example.com             710  (755 root:root with chroot)
   └── data                         710  web data, user home
       ├── web                      710
       │   └── public               710  document root of www.example.com (hello index.php)
       ├── sub                      710  subdomains
       │   └── {sub}/public         710  created when you create a subdomain hosting
       ├── logs                     750  root:{user}  NGINX error.log + php.log
       └── .ssh                     710  {user}:{user}
   ```

   Directories are owned by `{user}:www-data` with the setgid bit, unless the tree above says otherwise. NGINX can enter them, but other hosting users cannot. Each new `public` directory gets a placeholder `index.php`.
5. **Chroot** (optional). See [Chroot environments](#chroot-environments).
6. **PHP-FPM pool.** `{php_path}/{ver}/fpm/pool.d/{user}.conf` is generated from `src/Stub/php-pool.conf`. It runs as `{user}:{user}` and listens on `/run/php/php{ver}-fpm-{user}.sock` (owner `www-data`), with `pm = dynamic` and `max_children = 5`. The following `php_admin_value` settings are added:
   - `error_log = {data}/logs/php.log`
   - `memory_limit = 256M`, `upload_max_filesize = 40M`, `post_max_size = 40M`
   - `open_basedir = {www_path}/{user}:/tmp`
   - `disable_functions =` a long list of dangerous functions (`exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_*`, `pcntl_*`, `posix_*`, `eval`...)
7. **NGINX host.** `sites-available/{domain}` is generated from the stubs and symlinked into `sites-enabled`. It contains three `server {}` blocks, each marked with a comment. The SSL command finds the blocks by these comments, so **do not delete the comments**:
   - `# Default domain redirect (non www to www)`: `example.com` → `$host`
   - `# Default host configuration`: `www.example.com`, root `data/web/public`, PHP through the pool socket
   - automatic subdomains: `www.{sub}.example.com` → `{sub}.example.com`, and `~^(?<sub>.+)\.example\.com$` served from `data/sub/$sub/public`. A new subdomain only needs its directory.
8. **Reload.** `nginx -t` runs, then `service nginx restart` and `service php{ver}-fpm restart`. If the NGINX test fails, NGINX is not restarted and the error is printed.

### Remove hosting

```bash
sudo php vpsmanager hosting:remove [domain]
```

You are asked whether to **permanently delete the storage data** (`/var/www/{domain}`) and the **MySQL user and database**. The default for both is no. The domain must have an existing NGINX host.

1. Removes the NGINX host from `sites-enabled` (including a dangling symlink) and from `sites-available`
2. Removes the PHP-FPM pool of the domain from every PHP version where it exists
3. Tests and restarts NGINX, and restarts each affected PHP-FPM version
4. Removes the linux user (`userdel`)
5. Optional: drops the database and the user `{db_name}@{mysql_host}`
6. Optional: unmounts and removes the chroot, then deletes all web data with `rm -rf`

---

## SSL certificates

```bash
sudo php vpsmanager hosting:ssl [domain]
```

This command generates Let's Encrypt certificates with certbot and updates the NGINX host to use them.

1. Fails if a certificate for the domain already exists in `ssl_path`.
2. Runs `certbot certonly --nginx --agree-tos -n -m {ssl_email} -d example.com -d www.example.com`. For a subdomain only `-d sub.example.com` is passed. If certbot fails, the command to run manually is printed.
3. Rewrites `sites-available/{domain}`:
   - **Domain**: the redirect block now listens on port 80 for both `example.com` and `www.example.com` and redirects to `https://`. The default host block switches to `listen 443 ssl http2` (IPv4 and IPv6), serves both names, gets the `ssl_certificate` / `ssl_certificate_key` / `options-ssl-nginx.conf` / `ssl-dhparams.pem` directives, and redirects `https://example.com` to `https://www.example.com`.
   - **Subdomain**: adds a `# Redirect to https for subdomain ...` block (port 80 to https) and a `# HTTPS host for subdomain ...` block that reuses the existing PHP-FPM socket.
   - When `nginx_is_proxied` is on, `proxy_protocol` is added to the HTTPS listeners.
4. Tests and restarts NGINX.

Certificate renewal is handled by certbot's own systemd timer or cron job.

---

## Monitor: protection against scanners

Bots scan every domain of a server all day: WordPress paths (`/wp-login.php`, `/wp-content/plugins/…`), secrets (`/.env`, `/.git/config`), backups (`/backup.sql`) and known exploits. Without protection every such request boots the application in PHP-FPM, and a scan of a few dozen paths occupies all workers of a small pool for a moment. The monitor answers them in NGINX without PHP, bans the scanners in the firewall for all websites of the server, and keeps a week of access log to find what else should be banned.

| Command | What it does |
| --- | --- |
| `monitor:install` | Installs or updates the whole monitor: NGINX rules, fail2ban jails, access log. `--dry-run`, `--without-access-log`, `--remove` |
| `monitor:list` | Banned addresses of all fail2ban jails (also `sshd`) with the time of the ban and its end. `--jail=` |
| `monitor:remove-ip <ip>` | Unbans the address in all jails, or one with `--jail=`. `--everywhere` also forgets its previous bans (the next one starts from a day) and hides it in the monitor, all servers unban it with their next sync |
| `monitor:report` | Summary of the access log for an analysis (also by AI). `--hours=24`, `--limit=20`, `--json` |
| `monitor:sync` | Shares bans with the other servers through the monitor, see [Shared bans](#shared-bans-monitorsync). `--dry-run` |

### Install and update

```bash
cd /root/vpsmanager-cli && git pull
sudo php vpsmanager monitor:install --dry-run
sudo php vpsmanager monitor:install
```

Run `monitor:install` on every server after every update of VPS Manager. It writes only what changed and is safe to run again: the second run reports `already up to date` three times. The output has three parts, `Rules against scanners`, `Access log` and `fail2ban`.

New hosts and sections need no other run: `hosting:create`, `hosting:ssl` (HTTPS sections of subdomains) and `laravel:octane` add the same includes as `monitor:install` (rules, rules against `.php` files without a password, access log). Only the parts installed on the server are added, recognized by their files in `conf.d`, so a server without the monitor creates hosts without it.

- Every part tests its configuration first (`nginx -t`, `fail2ban-client -t`) and restores all hosts and files when it is not valid, so NGINX and fail2ban keep running with the previous configuration.
- `--without-access-log` installs the rules and fail2ban only.
- `--remove` removes everything: includes from the hosts, fail2ban jails, the access log with its rotation. It brings back `access_log off;` of the hosts.
- It works on Debian 12 and Ubuntu 20.04+ (NGINX 1.18+, fail2ban 0.11+), the server needs `fail2ban` (installed by `wamp_setup.sh`).

### How it works

```text
request ─▶ firewall (iptables) ─▶ NGINX server section ─▶ location ─▶ PHP-FPM / proxy
            │                       │
            │  f2b-vpsm-recidive    │  vpsmanager/scanners.conf (rewrite phase, before any location)
            │  f2b-vpsmanager-…     │    scanner path ─▶ 403 + scanners.html, line in vpsmanager-scanners.log
            │  (banned → rejected)  │  vpsmanager/monitor.conf
            │                       │    other request ─▶ line in /var/log/vpsmanager/access.log
            ▲                       │
            └── fail2ban reads vpsmanager-scanners.log, bans repeating addresses for all websites
```

1. **NGINX rules** (`vpsmanager/scanners.conf` and `vpsmanager/scanners-php.conf`) answer paths which no Laravel application serves with `403` and a static page in English and Slovak (`vpsmanager/scanners.html`, a link to the homepage). The request never reaches PHP. The rules are `if` blocks of the server level, they run in the rewrite phase before any `location`, so the position of the include does not matter. `418` is only an internal marker of the rules (`error_page 418 =403`), the visitor receives `403`, other `403` pages of the hosts are not changed. The block page has `auth_basic off`, hosts protected by a password show it too instead of asking for the password.
2. **fail2ban** reads the log of the blocked requests and bans an address in the firewall for every website of the server: an hour after 5 blocked requests in 10 minutes, then a day, 7 days and 30 days for a scanner which comes back (see [Levels of the bans](#levels-of-the-bans)).
3. **Access log** (`vpsmanager/monitor.conf`) logs all other requests for a week, `monitor:report` turns it into a summary of addresses and paths which may need a ban.

### Blocked paths

| Rule | Examples |
| --- | --- |
| Dot files, except Let's Encrypt challenges (`.well-known`) | `/.env`, `/api/.env`, `/.git/config`, `/.aws/credentials`, `/.htaccess` |
| WordPress, also under a directory | `/wp-admin`, `/wp-content/…`, `/blog/wp-includes/…`, `/wp-login.php`, `/xmlrpc.php`, `?rest_route=` |
| Backups and leftovers of editors, in any directory except uploads | `*.sql`, `*.sql.gz`, `*.sql.zip`, `*.bak`, `*.old`, `*.orig`, `*.save`, `*.swp`, `*~` |
| Archives of the whole site, in the root only | `/backup.zip`, `/www.tar.gz`, `/public_html.zip`, `/db.zip` |
| Secrets and project files, in the root only | `/id_rsa`, `/credentials.txt`, `/docker-compose.yml`, `/composer.json`, `/package.json`, `/artisan`, `/phpinfo.php` |
| Deployment and AI gateway configuration, in the root only | `/serverless.yml`, `/template.yaml`, `/samconfig.toml`, `/main.tf`, `/terraform.tfvars.json`, `/terraform.tfstate`, `/litellm_config.yaml`, `/litellm/config.yaml` |
| Private keys, certificates, secrets and Docker files, in the root only | `/server.key`, `/key.pem`, `/secrets.json`, `/credentials.json`, `/Dockerfile` |
| Exploits of other software | `/cgi-bin/…`, `/vendor/phpunit/…`, `eval-stdin.php`, `/rest/api/1.0/application-properties` (Jira, Confluence), `/_profiler/…` (Symfony), `/_ignition/…` (Laravel in debug mode) |
| Other `.php` files than `index.php` (`scanners-php.conf`) | `/worksec.php`, `/r5t.php`, `/admin/simple.php`, `/info.php` |

What is never blocked:

- `index.php` in any directory (`/index.php`, `/index.php/produkt/x`, `/pma/index.php`) and PHP files of CrudAdmin under `/vendor/crudadmin/` (the connector of CKFinder).
- Anything in sections marked by the comment `# vpsmanager:no-scanners` (e.g. phpMyAdmin of a tools host behind a password, whose own requests look like scans). They get no rules and no bans, the access log stays; `monitor:install` removes the rules of a section marked later.
- Any `.php` file in sections marked by the comment `# vpsmanager:allow-php` (an application serving its own PHP file besides `index.php`, e.g. `/booking/proxy.php` of owe.hu). The other rules stay.
- Any `.php` file in sections protected by a password (`auth_basic` of the server level, e.g. phpMyAdmin, adminer and `php/83/info.php` of `tools.*`) and in WordPress sections, they do not get `scanners-php.conf`. A legacy PHP website with other entry files than `index.php` needs a password, or the include of `scanners-php.conf` has to be removed from its section after every `monitor:install`.
- Uploads and downloads: `.zip`, `.gz`, `.pdf` anywhere (`/uploads/export.zip`, `/admin/files/imports_files/file/import.zip`).
- Backups in the storage of uploads of the applications, at least three directories deep with `uploads`: `/uploads/{table}/{field}/dump.sql.gz` is served, `/uploads/dump.sql` and `/uploads/x/dump.sql` are blocked.
- Paths which only resemble a rule: `/blog`, `/wordpress`, `/data`, `/backup`, `/search?q=wp-admin`, `/zmluva.old.pdf`.

The include is added to every server section of the enabled hosts which serves an application: after `include vpsmanager/general.conf;`, or at the end of sections without it (Nuxt, Node and other applications behind `proxy_pass`). Sections without any `location` (redirects to https or www) are skipped. Sections serving WordPress (`include vpsmanager/wordpress.conf`) keep their configuration, the rules would block WordPress itself; `Skipped WordPress sections of …` is printed for them. A WordPress which does not include `wordpress.conf` is not recognized, include the file in its host before running the command. `general.conf` also denies dot files with the default NGINX page, for hosts without the rules.

`include vpsmanager/scanners-php.conf;` follows right after `include vpsmanager/scanners.conf;` in every section without `auth_basic` of the server level; `Sections of … protected by a password serve all .php files.` is printed for the others. When a password is added to a section later, the next `monitor:install` removes the include from it.

New rules belong into `src/Resources/nginx/vpsmanager/scanners.conf` of this repository, never into the file on a server: `monitor:install` replaces the managed files on every run. After a change, test the rules in a local NGINX (both blocked and allowed paths), commit, `git pull` and `monitor:install` on the servers.

### fail2ban jails

| Jail | Ban | Ports | iptables chain |
| --- | --- | --- | --- |
| `vpsmanager-scanners` | 5 blocked requests in 10 minutes, banned for an hour | http, https | `f2b-vpsmanager-scanners` |
| `vpsmanager-http-auth` | 30 requests refused by `auth_basic` (wrong or missing password) in 10 minutes, banned for an hour | http, https | `f2b-vpsmanager-http-auth` |
| `vpsmanager-scanners-recidive` | 3 bans of the jails above in a day: a day, the next time 7 days, then 30 days | http, https (SSH stays reachable from a shared address) | `f2b-vpsm-recidive` |
| `vpsmanager-shared-1d`, `-7d`, `-30d` | addresses banned by the other servers, shared by the monitor (`monitor:sync`), in the jail of their level | http, https | `f2b-vpsmanager-shared-1d`… |
| `sshd` (default of fail2ban) | default of the distribution | ssh | `f2b-sshd` |

- `vpsmanager-http-auth` reads `/var/log/nginx/vpsmanager-auth.log`, written by `vpsmanager/monitor.conf` (it needs the access log part). Only refusals of NGINX itself are logged (`$status:$upstream_status` is `401:`), `401` of the applications (API without a token) have the status of their upstream and never count. A browser is refused once before it asks for the password, a bot guessing passwords is refused thousands of times (6 115 refusals in 10 minutes on `dev.trinityfinance.sk`).
- Servers with nftables (`banaction = nftables` of the distribution, e.g. wms) ban into the sets of `nft list table inet f2b-table` instead of iptables chains, `iptables -S f2b-…` prints nothing there.
- iptables chains are named `f2b-<name>` and may have 28 characters at most. The recidive jail therefore bans with `name=vpsm-recidive`; servers set up before this fix logged `chain name too long` into `/var/log/fail2ban.log` and their recidive bans were not blocked. `git pull` and `monitor:install` fix them.
- A chain is created at the first ban of its jail. `iptables -S f2b-sshd` of a jail without bans prints `chain … is incompatible, use 'nft' tool`: iptables-nft prints this also for a chain which does not exist, it is not an error.
- When fail2ban files change, `monitor:install` **restarts** fail2ban. A reload flushes the bans of a changed action and creates the chain only at the next ban; the start applies the bans of the database again with the current actions.
- `fail2ban.d/vpsmanager.conf` sets `dbpurgeage = 60d`. The default of Debian is one day, the database would forget the long bans and the previous bans of an address, which raise its next ban, and a restart would not apply them again.

### Levels of the bans

A scanner which comes back gets a longer ban each time, a single mistake (an employee, a shared address of a mobile operator) costs a day. Every level is shared with the other servers.

| Level | When | Ban on the server | Shared with the other servers |
| --- | --- | --- | --- |
| 0 | 5 blocked requests (or 30 refused passwords) in 10 minutes | 1 hour (`vpsmanager-scanners`, `vpsmanager-http-auth`) | no |
| 1 | 3 bans of level 0 in a day | 1 day (`vpsmanager-scanners-recidive`) | 1 day (`vpsmanager-shared-1d`) |
| 2 | level 1 again within 60 days | 7 days | 7 days (`vpsmanager-shared-7d`) |
| 3 | level 2 again within 60 days, and every next time | 30 days | 30 days (`vpsmanager-shared-30d`) |

- fail2ban raises the bans itself (`bantime = 1d`, `bantime.increment = true`, `bantime.multipliers = 1 7 30`, tested on fail2ban 0.11.2 and 1.0.2: 1×, 7×, 30×, 30×…). It counts the previous bans of the address in its database, after 60 days without a ban (`dbpurgeage`) the address starts again from a day.
- `monitor:sync` reports the level and the end of each ban, the monitor shares the address with the highest level and until the latest end any server reported, repeated reports of one ban do not move its end. The other servers ban it in the jail of its level, so the ban expires by itself also without the monitor.
- `monitor:remove-ip <ip> --everywhere` unbans the address, forgets its previous bans in the database of fail2ban and hides it in the monitor.
- Never banned: localhost, all addresses of the server (`hostname -I`, the server calls its own websites, e.g. an API of one hosting from another one), private networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`) and proxies trusted by NGINX (`set_real_ip_from` of any file in `/etc/nginx`, comments skipped). A router or a load balancer in front of the server sends all requests from its own address; its ban dropped all websites of auttia and optisia on 2026-10-04. No scanner of the internet comes from a private network. Own addresses which must never be banned (office, home, CI) belong into `/etc/fail2ban/jail.d/vpsmanager-scanners.local`, which is never overwritten, then `fail2ban-client reload`:

  ```ini
  [vpsmanager-scanners]
  ignoreip = %(known/ignoreip)s 203.0.113.10

  [vpsmanager-http-auth]
  ignoreip = %(known/ignoreip)s 203.0.113.10

  [vpsmanager-scanners-recidive]
  ignoreip = %(known/ignoreip)s 203.0.113.10
  ```

- Debian 12 logs into journald only, so the default `sshd` jail does not find `/var/log/auth.log` and fail2ban does not start at all (`Have not found any log file for sshd jail`). When the file is missing, `monitor:install` installs `rsyslog`, which writes the classic log files again. `wamp_setup.sh` installs it on new servers.

### Logs

| Log | Content | Rotation |
| --- | --- | --- |
| `/var/log/nginx/vpsmanager-scanners.log` | blocked requests: `IP - - [time] "request" 403 size "referer" "agent" "host"` (format `vpsmanager_scanners`, the combined format with the host at the end) | NGINX logrotate of the distribution (daily, 14 days) |
| `/var/log/vpsmanager/access.log` | other requests: `IP [time] "host" "METHOD url" status size duration "referer" "agent"` (format `vpsmanager_monitor`) | hourly by VPS Manager: daily and over 100 MB, compressed, deleted after 7 days |
| `/var/log/fail2ban.log` | bans and unbans of all jails | logrotate of the distribution |

The address starts every line, fail2ban and the commands rely on it.

The access log:

- does not log static files which exist (images, CSS, JS, fonts, video), they are most of the requests. A missing static file is rewritten to the application and logged, its `404` is useful for the analysis;
- logs the url as requested by the client (`$request_uri`), not the one rewritten to `/index.php` by `try_files`;
- masks query strings with secrets: a parameter name containing `token`, `code`, `pass`, `secret`, `key`, `hash`, `signature` or `auth` turns the query into `?[masked]` (e.g. `access_token` of a socialite pairing);
- never fills the disk: `/etc/cron.hourly/vpsmanager-monitor` runs logrotate with `/etc/vpsmanager/logrotate-monitor.conf` and its own state file every hour. The configuration is outside of `/etc/logrotate.d`, so the daily logrotate does not rotate it twice. At most about 100 MB of the current log and 7 compressed logs exist;
- needs the hosts to log: `access_log off;` of the server level would cancel it, so `monitor:install` comments it out (`# access_log off; (replaced by vpsmanager/monitor.conf)`) and `--remove` brings it back. `access_log off` of locations stays. WordPress hosts are logged too.

The access log holds addresses of visitors for a week, mention it in the privacy policy of the websites.

### Shared bans: monitor:sync

Scanners go from server to server, a fifth of the banned addresses attacks several of our servers. `monitor:sync` shares the most certain bans through the monitor (monitor.marekgogol.sk, admin module Blokované IP):

1. It reports the addresses of the jail `vpsmanager-scanners-recidive` with the level (1, 7 or 30 days) and the end of their ban to the monitor.
2. It downloads the addresses the monitor shares with their level (until the end of the longest reported ban, not hidden in the administration).
3. It bans the new ones in the jail of their level (`vpsmanager-shared-1d`, `-7d`, `-30d`), moves an address whose level changed and unbans those the monitor does not share any more, with `fail2ban-client set … banip|unbanip`. Nothing else changes, fail2ban is never reloaded or restarted, a sync without changes touches nothing.

- The monitor is optional. When it does not answer, nothing is banned nor unbanned and the jails of the server work as before; the reports are sent with the next sync. The shared bans last the days of their level, so they expire also when the monitor is gone.
- Own addresses, private networks and proxies are never banned (the same list as `ignoreip`), the addresses of the recidive jail are not banned twice. Hiding an address in the monitor unbans it on all servers with their next sync, its next reports do not share it again.
- The url comes from the agent of the monitor on the server (`/etc/server_monitor/monitor.sh`, `/monitor/{id}/{token}/collect` becomes `/monitor/{id}/{token}/blocked-ips`), or `monitor_sync_url` of the configuration. Without both the server does not share.
- `monitor:install` writes `/etc/cron.d/vpsmanager-monitor-sync`: every 3 hours, at a minute and hour derived from the hostname, so the servers do not call the monitor at once. Behind a router the jail lives on the router.

```bash
sudo php vpsmanager monitor:sync --dry-run
sudo php vpsmanager monitor:sync
```

### Servers behind a router

A server without a public address behind a router or a load balancer (`nginx_is_proxied`, e.g. auttia: router `ar1`, server `as1`) gets every connection from the router. Only NGINX of the server knows the visitor (`proxy_protocol`), the router does not see the requested path (HTTPS passes through it encrypted). A ban in the firewall of the server would drop the router and all websites, it happened on 2026-10-04. Therefore the server finds the scanners and the router bans them:

```text
scanner ─▶ router: iptables (fail2ban of the router) ─▶ server: NGINX, /.env → 403
                ▲                                              │ syslog UDP 514, sent by NGINX itself
                └── /var/log/vpsmanager/scanners.log ◀── rsyslog of the router
```

- `monitor:install` on the server manages the router over SSH, the router needs no VPS Manager and no own scripts. `monitor:list`, `monitor:remove-ip` and `monitor:report` of the server read and unban on the router too (jails `router <address>: …`).
- The server: `conf.d/vpsmanager-realip.conf` sets the real address for the whole `http` context (also the default server without `general.conf`), `vpsmanager/router-scanners.conf` and `router-auth.conf` send the blocked and refused requests to syslog of the router, the local logs stay. The server has no jails `vpsmanager-*`, only its own `sshd` (SSH comes through DNAT of the router with the real address).
- The router (Alpine): `rsyslog` replaces `syslogd` of BusyBox (system logs stay in `/var/log/messages`), `/etc/rsyslog.d/vpsmanager.conf` listens on the internal address only and accepts the internal network only, `/etc/logrotate.d/vpsmanager` keeps a week, the jails are the same as on other servers. Lines end with the address of the server which sent them.
- Setup of a new server behind a router: the configuration of the server gets the list of SSH destinations of its routers (`monitor_routers`, default `ssh://root@<default gateway>`; `monitor_router` with one destination works too), root of the server gets SSH access to every router limited to the internal network:

  ```bash
  # src/config.php of the server
  'nginx_is_proxied' => true,
  'monitor_routers' => [
      'ssh://root@192.168.1.1:1000',
      // 'ssh://root@192.168.1.2:1000', more routers in front of the same server
  ],

  # /root/.ssh/authorized_keys of the router: the key of root of the server
  from="192.168.1.0/24" ssh-rsa AAAA… root@server
  ```

- Without the router (no SSH access, unknown address) `monitor:install` stops before any change.
- Several servers behind one router share its jails: a scanner found by one of them is banned for all. A server behind several routers (e.g. two load balancers) sends every blocked request to all of them, each router bans in its own firewall, `monitor:list` shows the jails of every router (`router 192.168.1.1: …`).

### Files on the server

| File | Owner |
| --- | --- |
| `/etc/nginx/vpsmanager/scanners.conf`, `scanners-php.conf`, `scanners.html`, `monitor.conf` | managed, replaced by `monitor:install` |
| `/etc/nginx/conf.d/vpsmanager-scanners.conf`, `vpsmanager-monitor.conf` | managed (log formats and maps of the `http` context) |
| `/etc/nginx/vpsmanager/general.conf`, `wordpress.conf`, other files | yours, copied only when missing, never replaced |
| `/etc/nginx/sites-available/*` | yours, the command only adds or removes `include vpsmanager/scanners.conf;`, `include vpsmanager/scanners-php.conf;`, `include vpsmanager/monitor.conf;` and comments out `access_log off;` |
| `/etc/fail2ban/filter.d/vpsmanager-scanners.conf`, `vpsmanager-scanners-recidive.conf`, `vpsmanager-http-auth.conf`, `jail.d/vpsmanager-scanners.conf`, `fail2ban.d/vpsmanager.conf` | managed |
| `/etc/fail2ban/jail.d/vpsmanager-scanners.local` | yours (own addresses never banned) |
| `/etc/vpsmanager/logrotate-monitor.conf`, `/etc/cron.hourly/vpsmanager-monitor` | managed |
| `/etc/nginx/vpsmanager/router-scanners.conf`, `router-auth.conf`, `/etc/nginx/conf.d/vpsmanager-realip.conf` | generated for the server (a comment only when it is not behind a router) |
| Router: `/etc/rsyslog.d/vpsmanager.conf`, `/etc/logrotate.d/vpsmanager`, fail2ban files as above | managed over SSH by `monitor:install` of the server behind it |

### Everyday operations

```bash
# banned addresses and their blocked requests
sudo php vpsmanager monitor:list
grep "^203.0.113.10 " /var/log/nginx/vpsmanager-scanners.log

# unban, e.g. an office behind a shared address
sudo php vpsmanager monitor:remove-ip 203.0.113.10

# which websites and paths are scanned most
awk -F'"' '{print $(NF-1)}' /var/log/nginx/vpsmanager-scanners.log | sort | uniq -c | sort -rn
awk '{print $7}' /var/log/nginx/vpsmanager-scanners.log | sed 's/?.*//' | sort | uniq -c | sort -rn | head -30

# bans of the last days and the state of the firewall
grep -E "\] (Ban|Unban) " /var/log/fail2ban.log | tail -50
iptables -S f2b-vpsm-recidive
iptables -L INPUT -n --line-numbers
```

`monitor:list` prints the jails with the number of banned addresses and a table of the bans. The numbers of a jail and of REJECT rules in its iptables chain must be equal; when a jail has bans and its chain is missing, check `ERROR` lines of `/var/log/fail2ban.log`.

### Analysis by AI

`monitor:report` summarizes the access log of the last `--hours` (24 by default, the log keeps a week):

- requests by host and status;
- addresses with failed requests which are not banned (addresses of the server and localhost are skipped), with their peak of requests per minute, number of hosts and failed paths;
- failed paths with the number of addresses, candidates for new rules of `scanners.conf`;
- the busiest addresses, banned ones marked;
- user agents of failed requests.

```bash
sudo php vpsmanager monitor:report --hours=168 --limit=50 --json > report.json
```

An analysis by AI should look for: paths requested by many different addresses which no application of the server serves (new rule for `scanners.conf`), addresses with many failed requests over several hosts (scanners which the rules do not catch yet), very high peaks per minute (crawlers or brute force), and user agents of tools (`python-requests`, `Go-http-client`, `curl`, empty). Before adding a rule, check that no application serves the path: a rule must never block real users, and uploads under `/uploads/{table}/{field}/` must stay downloadable.

### Rules for working on a server (also for AI agents)

- Run `--dry-run` first and read what would change.
- Never edit managed files on a server, they are overwritten. Change them in this repository.
- Test from the server itself, the address of the tester is not banned then: `curl -sk --resolve api.example.com:443:127.0.0.1 https://api.example.com/.env` must print the block page with `403`, `/admin` of the same host its usual status.
- Testing bans: use a documentation address, e.g. `fail2ban-client set sshd banip 203.0.113.250` and `monitor:remove-ip 203.0.113.250`. Never ban real addresses for a test.
- Do not enable or start `nftables.service`: its start loads `/etc/nftables.conf` and flushes the whole ruleset, the bans of fail2ban included. The `nft` command itself is safe for reading (`nft list chain ip filter INPUT`).
- `iptables-legacy` commands load empty legacy tables, then iptables warns `iptables-legacy tables present`; it is harmless and disappears after a reboot.
- After `monitor:install` check: the second run reports `already up to date`, websites answer as before, blocked paths answer `403`, jails and iptables chains have the same numbers of bans.
---

## Chroot environments

A chrooted hosting user can log in over SSH/SFTP but is locked inside `/var/www/{domain}`. The user sees their web data as `/data` and has a small set of tools: bash, coreutils, nano, git, ssh, zip/unzip, wget, openssl, php (all installed versions), composer, node/npm/npx/pm2, gcc/make.

```bash
sudo php vpsmanager chroot:create [domain]   # create or refresh the chroot of one hosting
sudo php vpsmanager chroot:update            # refresh all existing chroots (e.g. after PHP/node upgrades)
sudo php vpsmanager chroot:remove [domain]   # turn a chrooted hosting back into a normal one
```

**`chroot:create`** asks for the PHP CLI version used inside the jail, then:

1. Sets `/var/www/{domain}` to `root:root 755`, as sshd requires. Adds the user to the `vpsmanager_chroot_user` group and changes their home to `/data`.
2. If the hosting has an old flat layout without `data/`, moves all existing files into `data/`.
3. Creates `.hushlogin`, `tmp` (700, owned by the user), `proc`, device nodes (`/dev/null`, `tty`, `random`, `urandom`), `usr/include`, `usr/lib/x86_64-linux-gnu` and a writable `usr/local`.
4. Copies the allowed binaries into the jail, together with their shared libraries (resolved with `ldd`). Also copies terminfo, CA certificates, timezones, DNS resolving (`resolv.conf`, `libnss_dns`), filtered `/etc/passwd` and `/etc/group`, all installed PHP versions with their `cli` configs and extensions, composer, and NodeJS/npm. The chosen PHP version becomes `/usr/bin/php`, and `/proc` is bind-mounted.
5. The first time it runs, it appends this to `/etc/ssh/sshd_config`, switches `Subsystem sftp` to `internal-sftp`, installs a login banner and restarts ssh:

   ```
   Match Group vpsmanager_chroot_user
       ChrootDirectory /var/www/%u
       AuthorizedKeysFile /var/www/%u/data/.ssh/authorized_keys
       Banner /etc/ssh/vpsmanager_banner.txt
   ```

**`chroot:update`** goes through every directory in `www_path` that is a chroot (it has `etc/passwd`, `data` and `lib`) and runs the create step again.

**`chroot:remove`** unmounts `proc` and deletes `bin`, `dev`, `etc`, `lib`, `lib64`, `proc`, `tmp` and `usr` from the user directory, keeping `data`. It then restores `{user}:www-data 710` permissions, removes the user from the chroot group, sets their home back to the data directory and deletes `.hushlogin`.

---

## MySQL databases

These commands work on any valid database name (`[0-9a-zA-Z$_]+`) or domain. A domain is converted to a database name (`example.com` → `example_com`). The database and the user always have the same name. The CLI connects to `localhost` as `mysql_user` / `mysql_pass`.

```bash
sudo php vpsmanager mysql:create [name]   # CREATE DATABASE + CREATE USER name@mysql_host + GRANT ALL; prints the password
sudo php vpsmanager mysql:reset  [name]   # ALTER USER with a new random password; prints it
sudo php vpsmanager mysql:remove [name]   # DROP DATABASE + DROP USER IF EXISTS name@mysql_host
```

`name` can also be passed as `--name`. `mysql:reset` fails if the database does not exist. `mysql:remove` does nothing if the database does not exist.

---

## Backups

If you need to back up all your websites, databases, NGINX configurations and more, you can use the backup tool built into VPS Manager.

- Back up custom directories such as NGINX, MySQL... whatever you need
- Back up each database into a separate file
- Back up all websites from `/var/www` (or another directory from the configuration)
- Remove old backups automatically (see [Retention](#retention-rotation))
- Send all local data to a remote server. You choose how many of the latest backups are stored there, so you don't need to store everything
- Get email notifications when an error occurs

### Backup setup

```bash
sudo php vpsmanager backup:setup
```

This command configures backups: what to back up (www directories, custom directories, databases), the remote backups, the backup directories and the email notifications. It asks for every [backup key](#backup-keys-backupsetup) and also:

- creates the linux user `vpsmanager_backups` (home = `backup_path`) if it does not exist, and sets `backup_path` to `700` owned by that user
- for remote backups, generates an SSH key pair at `{backup_path}/.ssh/id_rsa` (if missing) and prints the public key. **Add this public key to `authorized_keys` of `remote_user` on the remote server.** On that server, install VPS Manager and run `backup:setup` there too, so the `vpsmanager_backups` user exists and `{backup_path}/.ssh/authorized_keys` is prepared.
- if you confirm, appends `0 4 * * * php {package_path}/vpsmanager backup:run` to `/var/spool/cron/crontabs/root`, unless such a line already exists

### Run backup

```bash
sudo php vpsmanager backup:run                    # back up everything
sudo php vpsmanager backup:run --databases        # back up only databases
sudo php vpsmanager backup:run --databases --dirs # back up databases and custom directories
sudo php vpsmanager backup:run --www              # back up only web directories
```

Before it starts, the command checks that `backup:setup` has been run and that `zip`, `tar` and `rsync` are installed. A run then does the following:

1. **Databases.** Runs `SHOW DATABASES` and dumps every database except `information_schema` and `performance_schema` with `mysqldump --single-transaction --quick --routines --events --triggers`, piped to `gzip -1`. Result: `databases/{date}/{db}.sql.gz`. Missing dumps are reported as errors.
2. **Directories.** Each entry of `backup_directories` becomes `dirs/{date}/{path_with_underscores}.tar.gz`. Extra `tar` arguments after the path are respected, for example `/etc/mysql --exclude="*/debian.cnf"`.
3. **WWW.** Each subdirectory of `backup_www_path` becomes `www/{date}/{domain}.tar.gz`. For the new layout only the `data/` folder is archived. Always excluded:
   - `node_modules`, `vendor`, `cache` directories and `laravel.log` anywhere in the project
   - home directory clutter: `.config`, `.cache`, `.local`, `.npm`, `.pm2`, `.nano`, `.gnupg`, `.bash_history`, `.selected_editor`
   - everything listed in `.backups_ignore` (see below)
4. **Retention.** Old backups are removed from every storage directory (`local`, and anything else placed in `backup_path`).
5. **Remote sync.** If enabled, all local backups are synced with `rsync -avzP --delete --delete-excluded` over SSH (using the generated key) to `{remote_user}@{remote_server}:{remote_path}`. Only the newest `remote_backup_limit` backups of each type are sent, and older ones are deleted on the remote side.
6. **Notifications.** If any error occurred and `email_notifications` is on, one email with all errors is sent.
7. A summary line (types, duration) is appended to `{backup_path}/logs.log`. Errors are logged there too.

Every archive and dump runs under `nice -n 15 ionice -c2 -n7` with fast compression, to keep the load on a live server low. Before each backup type, VPS Manager checks that the free disk space is at least the size of the previous backup of that type plus **2000 MB**. Otherwise that type is skipped and an error is reported.

With the default setup, backups are stored in:

```
/var/vpsmanager_backups/
├── .ssh/                     # key pair for remote backups
├── logs.log
└── local/
    ├── databases/2026-01-31_04-00-00/{db}.sql.gz
    ├── dirs/2026-01-31_04-00-00/etc_nginx.tar.gz
    └── www/2026-01-31_04-00-00/example.com.tar.gz
```

Backup folders are named `Y-m-d_H-00-00` in the `Europe/Bratislava` timezone, so runs within the same hour share one folder.

### Retention (rotation)

- **Databases**
  - last 24 hours: keep every backup
  - last 7 days: keep one backup per day
  - last month: keep one backup per week
  - older backups are deleted, but the 2 newest backups are always kept, even if backups stop running
- **Custom directories and WWW data**
  - keep the latest backup (always), plus backups made on the last two Mondays
  - of those, keep only the newest `backup_www_max_limit` backups (default 2)
- **Remote server**: only the newest `remote_backup_limit` backups of each type (default 2)

### Excluding files from the www backup: `.backups_ignore`

To exclude folders, subdomains or files from the www backup, create a `.backups_ignore` file in the web data root of the domain:

- new layout: `/var/www/example.com/data/.backups_ignore`
- old layout without `data/`: `/var/www/example.com/.backups_ignore`

Put one path per line, relative to that directory. Empty lines and lines starting with `#` are ignored. Directories are excluded together with all their contents.

```
# big subdomain that has its own backup
sub/subdomain1
web/my-exclude-folder
web/storage/app/huge.zip
```

### Crontab

`backup:setup` can add the cron job for you. To add it manually, run `crontab -e` and add this line (use your installation path):

```bash
0 4 * * * php /root/vpsmanager-cli/vpsmanager backup:run
```

### Test email notifications

This command tests the SMTP configuration. A test email is sent to `email_receiver`. If it fails, the SMTP error is printed and the command exits with failure.

```bash
sudo php vpsmanager backup:test-mail
```

### Test the SSH connection for remote backups

This command tests your remote server configuration with `ssh -i {backup_path}/.ssh/id_rsa {remote_user}@{remote_server}` (batch mode, 5 second timeout).

> If it fails, you may just need to run the SSH connection manually once to accept the server key. The command prints the exact `ssh` command to run.

```bash
sudo php vpsmanager backup:test-remote
```

---

## Laravel queues and Octane

Laravel applications of a hosting are found automatically: every directory with an `artisan` file in `data/web` and `data/sub/*` is offered (`web`, `sub/api`, `sub/dev-api`...). Programs run in **supervisor**, which `wamp_setup.sh` installs. All programs of one hosting are stored together in **`/etc/supervisor/conf.d/{domain}.conf`**, each in its own `[program:...]` section, and `hosting:remove` stops and removes them.

Programs run as the hosting user with the PHP version of the hosting pool (`/usr/bin/php{ver}`).

### Queue workers

```bash
sudo php vpsmanager laravel:queue example.com [--path=sub/api] [--workers=2] [--queue=high,default]
sudo php vpsmanager laravel:queue example.com --path=sub/api --remove
```

| Option | Description |
|---|---|
| `domain` | Hosting domain. You are asked for it if it is missing |
| `--path` | Application path (`web`, `sub/api`...). If omitted, you choose from the found applications |
| `--workers` | Number of worker processes (`numprocs`), default `2` |
| `--queue` | Queues to process, passed to `queue:work --queue` |
| `--remove` | Stop the workers and remove the program |

The program `{domain}-{app}-queue` runs `artisan queue:work --sleep=3 --tries=3 --max-time=3600`. Output goes to `storage/logs/worker.log` (10 MB × 5 files).

- `stopwaitsecs=3600`: supervisor waits up to one hour for the running job to finish when it stops a worker. It must be at least as long as your longest job.
- `startsecs=1`, `autorestart=true`: a crashed worker (for example during a database outage) is started again forever, so queues recover on their own. With a longer `startsecs`, repeated quick crashes would end in the `FATAL` state and the queue would stay down until a manual restart.
- Run the command again to apply changed options. After each deploy run `php artisan queue:restart`.

### Octane (RoadRunner)

```bash
sudo php vpsmanager laravel:octane example.com [--path=web] [--nginx|--no-nginx] [--port=8100]
sudo php vpsmanager laravel:octane example.com --path=web --remove
```

Only the RoadRunner server is supported. The application needs `laravel/octane`, `spiral/roadrunner-cli` and `spiral/roadrunner-http` in `composer.lock`, and the `rr` binary, either in its root (`php artisan octane:install --server=roadrunner`, add `/rr` to `.gitignore`) or installed globally in `PATH` (the Docker image has it in `/usr/local/bin/rr`). The command lists what is missing.

**Settings live in the application `.env`**, so each project sets what it needs. Supervisor only runs `artisan octane:start`:

```ini
OCTANE_SERVER=roadrunner
OCTANE_HOST=127.0.0.1
OCTANE_PORT=8100
OCTANE_WORKERS=2
OCTANE_MAX_REQUESTS=500
OCTANE_MAX_EXECUTION_TIME=30
```

- When `OCTANE_PORT` is missing, the command offers the first free port from `8100` (or `--port`) and writes `OCTANE_SERVER`, `OCTANE_HOST` and `OCTANE_PORT` into `.env`. Ports of all applications on the server are read from their `.env` files, so they never collide.
- RoadRunner RPC listens on `OCTANE_PORT - 1999` (for example `6101` for `8101`).
- `OCTANE_HOST` must be `127.0.0.1`, NGINX proxies to it.
- Octane reads `host` and `port` from `.env` directly, but workers, max requests and max execution time only when `config/octane.php` contains them. The command warns when they are missing:

```php
'host' => env('OCTANE_HOST', '127.0.0.1'),
'port' => env('OCTANE_PORT', 8000),
'workers' => env('OCTANE_WORKERS', 'auto'),
'max_requests' => env('OCTANE_MAX_REQUESTS', 500),
'max_execution_time' => env('OCTANE_MAX_EXECUTION_TIME', 30),
```

**NGINX** (`--nginx`, or answer yes): every server section of the application (HTTP, and HTTPS after `hosting:ssl`) serves existing static files directly and proxies everything else to Octane (`try_files $uri @octane;` plus a `location @octane` between `# Octane start/end` comments). A subdomain application gets its own server section (`# Octane host (...)`), because all subdomains otherwise share one regex host. `--remove` restores the PHP-FPM configuration. An invalid configuration is rolled back automatically.

- Run the command again after you change `OCTANE_*` in `.env`, it restarts the server and updates the NGINX port.
- After each deploy run `php artisan octane:reload`.
- If you set up SSL for a subdomain after enabling Octane, run `laravel:octane --remove` and enable it again, so the new HTTPS section is proxied too.
- PHP-FPM pool settings (`php_admin_value[...]` like `memory_limit`, `upload_max_filesize` or `open_basedir`) **do not apply** to Octane and queue workers. They run as PHP CLI processes and use `/etc/php/{ver}/cli/php.ini`.

---

## Updating

```bash
cd /root/vpsmanager-cli
git pull
composer install --no-plugins --no-scripts
```

After an update run `php vpsmanager monitor:install` on servers with the monitor, it brings the new versions of the rules, jails and log formats (see [Monitor](#monitor-protection-against-scanners)).

`src/config.php` is git-ignored, so updates keep your configuration. If a new version adds config keys, run `php vpsmanager install` or `php vpsmanager backup:setup` again. Your current values are offered as defaults. After PHP or NodeJS upgrades, run `php vpsmanager chroot:update` to refresh the binaries inside chroot environments.

---

## Local development with Docker

The repository contains a `Dockerfile` and `docker-compose.yml` that simulate a fresh Ubuntu 24.04 VPS with NGINX, PHP-FPM (8.5 by default, set with the `PHP_VERSION` build arg), MySQL, certbot, cron and SSH. This lets you test the commands safely.

```bash
docker compose up -d --build
docker compose exec vpsmanager bash

# inside the container
vpsmanager install
vpsmanager hosting:create example.test
```

- The image ships a `/usr/local/bin/vpsmanager` wrapper, so the `vpsmanager` command works right away without the `.bashrc` alias that `install` adds on a real server.

- The repository is mounted to **`/root/vpsmanager`**, so your local changes are live inside the container.
- Ports: **`8000` → 80** (HTTP), **`8443` → 443** (HTTPS), **`2200` → 22** (SSH, for testing chroot logins).
- Only `/var/lib/mysql` is stored in a named volume (`mysql`). `/var/www` is not persisted on purpose: linux users in `/etc` are recreated with different UIDs, so persisted hosting data would belong to wrong users.
- `docker/entrypoint.sh` starts ssh, mysql, php-fpm, nginx and cron (there is no systemd in the container). On the first start it runs `composer install` if `vendor/` is missing.
- The container runs with `SYS_ADMIN` and no AppArmor profile, so `mount --bind /proc` for chroot works.
- Real Let's Encrypt certificates cannot be issued for local domains, so `hosting:ssl` will fail locally.

`run.sh.example` shows the same setup with plain `docker build` / `docker run`.

### Serving a local Laravel project from the container

A local project can run inside a test hosting, for example the billing API as `marekgogol.sk`, reachable from the Mac through Valet at `http://marekgogol.sk.vpsmanager.test`.

**1. Mounts and MySQL** — `docker-compose.override.yml` (gitignored, loaded automatically by `docker compose`):

```yaml
services:
  vpsmanager:
    environment:
      # Forward 127.0.0.1:3306 inside the container to MySQL on the Mac (brew)
      MYSQL_FORWARD_HOST: host.docker.internal
    volumes:
      # The project, under the same path as on the Mac
      - /Volumes/SSD/www/root/home/projects/billing/api:/Volumes/SSD/www/root/home/projects/billing/api
      # Local composer path packages, used by symlinks in the project's vendor/ (read only)
      - /Volumes/SSD/www/root/home/packages/admin/dependencies:/Volumes/SSD/www/root/home/packages/admin/dependencies:ro
      - /Volumes/SSD/www/root/home/packages/autoajax:/Volumes/SSD/www/root/home/packages/autoajax:ro
      - /Volumes/SSD/www/root/home/packages/helpers:/Volumes/SSD/www/root/home/packages/helpers:ro
      - /Volumes/SSD/www/root/home/packages/invoices:/Volumes/SSD/www/root/home/packages/invoices:ro
```

- Mount the project and the packages under **the same absolute path as on the Mac**. If `vendor/` symlinks use a different letter case (for example `/volumes/ssd/...`), mount the packages under that path too, linux paths are case sensitive. `vendor/` symlinks of composer path repositories and Laravel's `bootstrap/cache` contain absolute Mac paths.
- With `MYSQL_FORWARD_HOST` set, the entrypoint does not start the local MySQL server and forwards `127.0.0.1:3306` to the Mac instead, so the project's `.env` (`DB_HOST=127.0.0.1`) works without changes. Remove the variable to use MySQL inside the container again.

**2. Hosting inside the container.** Only `/var/lib/mysql` is stored in a volume. The linux user, the PHP-FPM pool, the NGINX host and `/var/www` are not persisted, so **they are gone whenever the container is recreated** (`docker compose up` after a change of the compose files, `--build`, `docker compose down`). Recreate them with:

```bash
docker compose up -d --build
docker compose exec vpsmanager bash -c '
    DOMAIN=marekgogol.sk
    PROJECT=/Volumes/SSD/www/root/home/projects/billing/api
    PACKAGES=/Volumes/SSD/www/root/home/packages
    D=/var/www/$DOMAIN/data

    # 1. Create the hosting on an empty path (removes the old www data of this domain only)
    rm -rf /var/www/$DOMAIN
    vpsmanager hosting:create $DOMAIN --php_version=8.5 -n

    # 2. Point the web directory to the project
    rm -rf $D/web && ln -s $PROJECT $D/web

    # 3. Let NGINX answer for the Valet host too
    sed -i "s/server_name www.$DOMAIN;/server_name www.$DOMAIN $DOMAIN.vpsmanager.test;/" /etc/nginx/sites-available/$DOMAIN

    # 4. Allow PHP to open files of the project and packages (open_basedir)
    sed -i "s#/var/www/$DOMAIN:/tmp#/var/www/$DOMAIN:$PROJECT:$PACKAGES:/tmp#" /etc/php/8.5/fpm/pool.d/*$DOMAIN*

    service nginx restart && service php8.5-fpm restart

    # 5. Start Octane again (settings are kept in the project .env)
    vpsmanager laravel:octane $DOMAIN --path=web --nginx
'
```

> **Never run `hosting:create` while `data/web` points to the project.** It writes the welcome `public/index.php` into `data/web/public`, which would overwrite the project's `index.php` on the Mac. Always create the hosting first, then replace `data/web` with the symlink.

Step 5 is needed only when the project runs under Octane; without it, the project is served by PHP-FPM. Steps 3 and 4 are local test tweaks only; they are not generated by VPS Manager.

**3. Valet on the Mac** (only once, it survives container rebuilds):

```bash
valet proxy marekgogol.sk.vpsmanager http://127.0.0.1:8000
```

Remove it with `valet unproxy marekgogol.sk.vpsmanager`.

**Troubleshooting**

| Symptom | Cause |
|---|---|
| `502 Bad Gateway` from Valet (`nginx/1.2x` of Homebrew) | The container is not running or its NGINX is stopped: `docker compose exec vpsmanager service nginx start` |
| `open_basedir restriction in effect` in `data/logs/error.log` | Step 4 is missing, or the project is mounted under a different path than on the Mac |
| `Failed opening required .../vendor/...` | A composer path package is not mounted (see `readlink vendor/*/*` in the project) |
| `service nginx restart` fails | The container runs without `init: true` (zombie processes); it is set in `docker-compose.yml` |

---

## Tests

```bash
composer install
composer test
```

Unit tests in `tests/Unit` cover the logic which decides about bans without a server: levels of the bans, jails of the shared bans and the plan of `monitor:sync` (`SharedBans`), and addresses never banned (`Fail2ban::isIgnoredIp()`).

## Code style

- PHP code is formatted with **Laravel Pint** using the project's `pint.json`. The rules follow crudadmin conventions: short arrays, single quotes, ordered imports, no unused imports, `! $x` spacing, trailing commas in multiline calls, Laravel style docblocks. `src/Stub` and `src/Resources` are excluded.

  ```bash
  composer lint     # pint --test (check only)
  composer format   # pint (fix)
  ```

- `.editorconfig`: UTF-8, LF, 4 spaces (2 for YAML), final newline.
- Conventions used across the codebase:
  - All comments, docblocks and console messages are in **English**.
  - Every method has a docblock with a short description, `@param` and `@return`, and native return types where possible. No closing `?>` tags.
  - Commands contain only input and questions. Server logic belongs in a `Helpers/*` class that extends `Application` and is reached through `vpsManager()->{helper}()`.
  - Helpers return a `Response` (`success()` / `error()` / `message()`) instead of printing directly or throwing. Commands return `Command::SUCCESS` or `Command::FAILURE`.
  - Shell arguments built from user input are escaped with `escapeshellarg()`.

---

## License

MIT, © Marek Gogol
