## CLI Hosting manager for your VPS

This package can fully manage your WAMP configuration on your VPS.
- creates new hosting user with directories and correct permissions
- creates and managing NGINX, PHP configurations and databases for each hosting
- manages SSL lets encrypt configuration and sets nginx properly
- you can setup backups for everything what you need, from webpages, databases, custom directories...
- supports remote server backups and email notifications.

Everything is ready in the box, you just need to configure this features by installation commands. Also if you need use just backups, you can setup just backups.

Requirements
- php 7.1+


## 1. Installation

This command first checks all your VPS requirements such as PHP, MySQL, Npm, NodeJS, Composer and downloads all required components for proper wamp configuration in your VPS. When everything will be instaled, then will be initialized configuration of VPS Manager CLI.

```bash
cd /root/
git clone https://github.com/MarekGogol/vpsmanager-cli vpsmanager-cli && cd vpsmanager-cli
bash install.sh
```

### Update to the latest version

```bash
cd /root/vpsmanager-cli
git pull
```

## 2. Managing hostings

### Create new hosting

1. Create new linux user with name `{domain}`
2. Create new domain directory structure `/var/www/{domain}`, `/var/www/{domain}/web`, `/var/www/{domain}/sub`, `/var/www/{domain}/logs`, also sets right and secure permissions.
3. Create custom domain PHP Pool socket
4. Create NGINX host in sites-available and allows it in sites-enabled
5. Create new mysql user/database

```bash
sudo php vpsmanager hosting:create
```

### Remove hosting

1. Removes linux user of hosting
2. Removes nginx configuration
3. Removes PHP Pool socket configuration
4. Removes user/database
5. Removes web data

```bash
sudo php vpsmanager hosting:remove
```

### Set up SSL certificates

This command will automatically generate ssl certificates via certbot and update NGINX hosts for correct settings.

```bash
sudo php vpsmanager hosting:ssl
```

## 2. Backups

If you need backup all your websites, databases, nginx configurations and many more, you can use backup tool build in VPS Manager.

- Backup custom directories as nginx, mysql, etc... everything what you need.
- Backup databases into separate files
- Backup all websites from */var/www* (or other directory from configuration)
- Automatically removes old backups
    - `Databases:` in 24 hours does not remove any backup. After 24 hours keeps 1 backup per day in last month period. After 1 month backups are deleted.
    - `Custom directories:` in 24 hours does not remove any backup, after 24 hours keep 1 backup per one week.
    - `WWW data:` in 24 hours does not remove any backup, after 24 hours keeps 1 backup from two last mondays.
- Send all local data to remote server
- You can set how many lates backups should be stored on remote server. You don't need store all data, you can choose.
- Also email notifications in case of error

### Backup setup

This command configures backups. Sets everything what you want backup as www directories, custom directories, databases, configures remote backups, generate SSH keys for remote backups and set's all backup directories, also set email configuration for notifications.

```bash
sudo php vpsmanager backup:setup
```

### Run backup

With this command you can simply run backups.

- databases are backed up into separate files
- www directories are backed except of *vendor* and *node_modules* directories
- In case of default setup, backup will be stored into `/var/vpsmanager_backups/local/{www|dirs|databases}/2019-XX-XX-XX-00-00/`.

```bash
sudo php vpsmanager backup:run # backup everything
sudo php vpsmanager backup:run --databases #backup only databases
sudo php vpsmanager backup:run --databases --dirs #backup databases and custom directories
sudo php vpsmanager backup:run --www #backup just web directories
```

You can setup crontab for running this backups daily. Just type `crontab -e` and add following line into code.
```bash
0 4 * * * php /root/vpsmanager/vpsmanager backup:run
```

If you need exclude folders, subdomains, or files from www backup. You can specify ignore list, in root of your domain in `/var/www/example.com/.backups_ignore` file.
```
sub/subdomain1
web/my-exclude-folder
```

### Test email nofitications backup

This command tests SMTP configuration. Test email will be send to your email address.

```bash
sudo php vpsmanager backup:test-mail
```

### Test remote SSH connection for remote backups

This command tests your remote server configuration.

> In case of error, you just may need run ssh connection manually to accept server key.

```bash
sudo php vpsmanager backup:test-remote
```