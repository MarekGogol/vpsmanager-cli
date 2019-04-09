## VPS Hosting CLI manager for your VPS

### Installation

```
cd /root/
git clone https://github.com/MarekGogol/vpsmanager-cli vpsmanager-cli && cd vpsmanager-cli
bash install.sh
```

### Update to the latest version

```
cd /root/vpsmanager-cli
git pull
```

## Commands

### Create new hosting

1. Create new linux user with name `{domain}`
2. Create new domain directory structure `/var/www/{domain}`, `/var/www/{domain}/web`, `/var/www/{domain}/sub`, `/var/www/{domain}/logs`, also sets right and secure permissions.
3. Create custom domain PHP Pool socket
4. Create NGINX host in sites-available and allows it in sites-enabled
5. Create new mysql user/database
```
sudo php vpsmanager hosting:create
```