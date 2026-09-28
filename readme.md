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
7. [Chroot environments](#chroot-environments)
8. [MySQL databases](#mysql-databases)
9. [Backups](#backups)
10. [Updating](#updating)
11. [Local development with Docker](#local-development-with-docker)
12. [Code style](#code-style)

---

## Requirements

- **Ubuntu server** (other Debian based systems may work). The tool is built around `apt`, `systemd`/`service`, `useradd` and `/etc/*` paths.
- **root access.** Every command checks `whoami` and refuses to run as any user other than `root`.
- **PHP ^8.4** for running the CLI itself (Composer dependencies: Symfony Console 8, Carbon 3, PHPMailer 7).
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
| Base packages | `zip`, `unzip`, `ssl-cert`, `gcc`, `libpng-dev`, `make`, `software-properties-common`, `fail2ban` (enabled) |
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
│   └── Backup/             # backup:setup, backup:run, backup:test-mail, backup:test-remote
├── Helpers/                # the actual server logic
│   ├── helpers.php         # global functions (vpsManager(), isValidDomain(), createDirectories()...)
│   ├── Hosting.php         # orchestrates create/remove of a whole hosting
│   ├── Server.php          # linux users, groups and the domain directory tree
│   ├── Nginx.php           # NGINX hosts, sites-enabled symlinks, config test/restart
│   ├── PHP.php             # PHP-FPM pools, versions, restart, default CLI version
│   ├── MySQLHelper.php     # databases and users via mysqli
│   ├── Certbot.php         # Let's Encrypt certificates + NGINX HTTPS rewrite
│   ├── Chroot.php          # chroot jail building and removal
│   ├── Backup.php          # backups, retention, rsync, email
│   ├── SSH.php             # sshd config test and restart
│   ├── Stub.php            # tiny template engine for files in src/Stub
│   └── Response.php        # success/error result object with console output
├── Stub/                   # templates: nginx.template.conf, nginx.redirect.conf,
│                           #   php-pool.conf, hello.php, banner.txt
├── Resources/nginx/        # shared NGINX config copied into /etc/nginx
│   ├── nginx.conf
│   ├── conf.d/webp.conf
│   └── vpsmanager/         # general.conf, fastcgi-php.conf, cors-preflight.conf
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

## Updating

```bash
cd /root/vpsmanager-cli
git pull
composer install --no-plugins --no-scripts
```

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

- Mount the project and the packages under **the same absolute path as on the Mac**. `vendor/` symlinks of composer path repositories and Laravel's `bootstrap/cache` contain absolute Mac paths.
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
'
```

> **Never run `hosting:create` while `data/web` points to the project.** It writes the welcome `public/index.php` into `data/web/public`, which would overwrite the project's `index.php` on the Mac. Always create the hosting first, then replace `data/web` with the symlink.

Steps 3 and 4 are local test tweaks only; they are not generated by VPS Manager.

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
