## VPS Hosting CLI manager for your VPS

### 1. Installation

This command securely first check all your VPS requirements such as PHP, MySQL, Npm, NodeJS, Composer and downloads all required components for proper wamp configuration on your VPS. When everything will be instaled, then will be initialized configuration of VPS Manager CLI.

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

## 2. Commands

### Create new hosting

1. Create new linux user with name `{domain}`
2. Create new domain directory structure `/var/www/{domain}`, `/var/www/{domain}/web`, `/var/www/{domain}/sub`, `/var/www/{domain}/logs`, also sets right and secure permissions.
3. Create custom domain PHP Pool socket
4. Create NGINX host in sites-available and allows it in sites-enabled
5. Create new mysql user/database

```bash
sudo php vpsmanager hosting:create
```

### Set up SSL certificates

This command will automatically generate ssl certificates via certbot and update NGINX hosts for correct settings.

```bash
sudo php vpsmanager hosting:ssl
```