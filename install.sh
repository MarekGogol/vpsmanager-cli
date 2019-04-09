WAMP_SETUP_PATH=./.vps-wamp-setup

# Install server requirements
#bash /volumes/ssd/www/root/home/projects/vps_wamp_setup/setup.sh
git clone https://github.com/MarekGogol/vps-wamp-setup $WAMP_SETUP_PATH
echo " "
bash $WAMP_SETUP_PATH/setup.sh
rm -rf $WAMP_SETUP_PATH
echo " "

# Install composer vendor files
composer install --no-plugins --no-scripts

# Start installation process
php vpsmanager install --vpsmanager_path=`pwd`