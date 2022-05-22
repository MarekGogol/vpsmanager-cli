echo -e "\e[32m-------- WAMP Installation started! --------\e[0m";

if [ "$EUID" -ne 0 ]
  then echo "Please run this configruation file as root."
  exit
fi

apt-get update
apt-get upgrade

#Set correct timezone
timedatectl set-timezone Europe/Bratislava

# Install required packages
apt install -y zip
apt install -y unzip
apt install -y ssl-cert
apt install -y gcc
apt install -y libpng-dev
apt install -y make
apt install -y software-properties-common
apt install -y fail2ban

# Install locales
locale-gen sk_SK
locale-gen sk_SK.UTF-8
locale-gen cs_CZ
locale-gen cs_CZ.UTF-8
locale-gen de_DE
locale-gen de_DE.UTF-8
locale-gen pl_PL
locale-gen pl_PL.UTF-8
locale-gen ru_UA
locale-gen ru_UA.UTF-8
update-locale

echo " "

add_ppa_if_not_exists() {
  grep -h "^deb.*$1" /etc/apt/sources.list.d/* > /dev/null 2>&1
  if [ $? -ne 0 ]
  then
    echo "Adding ppa:$1"
    add-apt-repository -y ppa:$1
    return 0
  fi

  echo "ppa:$1 already exists"
  return 1
}

# Check if nginx is installed
dpkg -s nginx &> /dev/null
IS_NGINX=$?
if [ $IS_NGINX -eq 0 ]; then
    echo -e "\e[32mNginx is installed\e[0m"
else

    read -p 'Do you want to install Nginx? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        apt install -y nginx
        service nginx start
    fi
fi

# Check if imagemagick is installed
dpkg -s imagemagick &> /dev/null
IS_IMAGEMAGICK=$?
if [ $IS_IMAGEMAGICK -eq 0 ]; then
    echo -e "\e[32mImagemagick is installed\e[0m"
else

    read -p 'Do you want to install Imagemagick? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        apt install imagemagick -y
    fi
fi

dpkg -s php8.0-cli &> /dev/null
PHP80=$?
if [ $PHP80 -eq 0 ]; then
    echo -e "\e[32mPHP 8.0 version installed.\e[0m"
else
    read -p 'Do you want to install PHP 8.0? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        add_ppa_if_not_exists ondrej/php
        apt install -y php8.0-fpm && apt install -y php8.0-cli php8.0-fpm php8.0-soap php8.0-mysql php8.0-zip php8.0-gd php8.0-mbstring php8.0-curl php8.0-xml php8.0-bcmath php8.0-redis php8.0-common php8.0-imagick php8.0-intl
        service php8.0-fpm start
    fi
fi

# Check if mysql is installed
dpkg -s python3-certbot-nginx &> /dev/null
IS_CERTBOT=$?
if [ $IS_CERTBOT -eq 0 ]; then
    echo -e "\e[32mCerbot is installed\e[0m"
else
    read -p 'Do you want to install Cerbot? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        apt install -y certbot python3-certbot-nginx
    fi
fi

# Check if mysql is installed
dpkg -s nodejs &> /dev/null
IS_CERTBOT=$?
if [ $IS_CERTBOT -eq 0 ]; then
    echo -e "\e[32mNodeJs is installed\e[0m"
else
    read -p 'Do you want to install NodeJs & Npm? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        cd ~
        curl -sL https://deb.nodesource.com/setup_lts.x -o nodesource_setup.sh
        bash nodesource_setup.sh
        apt install -y nodejs
        apt install -y npm
    fi
fi

# Check if mysql is installed
dpkg -s mysql-server &> /dev/null
IS_MYSQL=$?
if [ $IS_MYSQL -eq 0 ]; then
    echo -e "\e[32mMySQL is installed\e[0m"
else
    read -p 'Do you want to install MySQL 8.0? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        wget -c https://dev.mysql.com/get/mysql-apt-config_0.8.15-1_all.deb
        dpkg -i mysql-apt-config_0.8.15-1_all.deb
        rm mysql-apt-config_0.8.15-1_all.deb
        apt-get update

        apt install mysql-server
        service mysql start
        mysql_secure_installation
    fi
fi

# Check if composer is installed
dpkg -s composer &> /dev/null
IS_COMPOSER=$?
if [ $IS_COMPOSER -eq 0 ]; then
    echo -e "\e[32mComposer is installed\e[0m"
else
    read -p 'Do you want to install Composer? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
        php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    fi
fi

#Image iptimalization
read -p 'Do you want to install Image optimalization libraries? [Y/n]:' answer
answer=${answer:Y}

if [[ $answer =~ [Yy] ]]; then
    apt-get install jpegoptim
    apt-get install optipng
    apt-get install pngquant
    apt-get install gifsicle
    apt-get install webp
    npm install -g svgo
fi