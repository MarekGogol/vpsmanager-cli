## CLI Hosting manager for your VPS

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

- Backups custom directories as nginx, mysql, etc... everything what you need.
- Backups databases into separate files
- Backups all websites from /var/www (or other directory from configuration)
- Automatically removes old backups
    - `Databases:` in 24 hours does not remove any backup. After 24 hours keep 1 backup per day in last month period. After 1 month backups are deleted.
    - `Custom directories:` in 24 hours does not remove any backup, after 24 hours keep 1 backup per one week.
    - `WWW data:` in 24 hours does not remove any backup, after 24 hours keep 1 backup per one week.
- Send all local data to remote server
- You can set how many lates backups should be stored on remote server. You don't need store all data, you can choose.

### Backup setp

This command configure backups.

```bash
sudo php vpsmanager backup:setup
```