bash wamp_setup.sh

# Install composer vendor files
composer install --no-plugins --no-scripts

# Start installation process
php vpsmanager install --vpsmanager_path=`pwd`