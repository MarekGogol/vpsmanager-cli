echo -e "\e[32m-------- WAMP Installation started! --------\e[0m";

if [ "$EUID" -ne 0 ]
  then echo "Please run this configruation file as root."
  exit
fi

apt-get update

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

# Check if php-cli is installed
dpkg -s php7.1-cli &> /dev/null
PHP71=$?
if [ $PHP71 -eq 0 ]; then
    echo -e "\e[32mPHP 7.1 version installed.\e[0m"
else
    read -p 'Do you want to install PHP 7.1? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        apt install -y software-properties-common
        add_ppa_if_not_exists ondrej/php
        apt install -y php7.1-fpm && apt install -y php7.1-cli php7.1-fpm php7.1-json php7.1-pdo php7.1-mysql php7.1-zip php7.1-gd php7.1-mbstring php7.1-curl php7.1-xml php7.1-bcmath php7.1-json
        service php7.1-fpm start
    fi
fi

dpkg -s php7.2-cli &> /dev/null
PHP72=$?
if [ $PHP72 -eq 0 ]; then
    echo -e "\e[32mPHP 7.2 version installed.\e[0m"
else
    read -p 'Do you want to install PHP 7.2? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        apt install -y software-properties-common
        add_ppa_if_not_exists ondrej/php
        apt install -y php7.2-fpm && apt install -y php7.2-cli php7.2-fpm php7.2-json php7.2-pdo php7.2-mysql php7.2-zip php7.2-gd php7.2-mbstring php7.2-curl php7.2-xml php7.2-bcmath php7.2-json
        service php7.2-fpm start
    fi
fi

dpkg -s php7.3-cli &> /dev/null
PHP73=$?
if [ $PHP73 -eq 0 ]; then
    echo -e "\e[32mPHP 7.3 version installed.\e[0m"
else
    read -p 'Do you want to install PHP 7.3? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        apt install -y software-properties-common
        add_ppa_if_not_exists ondrej/php
        apt install -y php7.3-fpm && apt install -y php7.3-cli php7.3-fpm php7.3-json php7.3-pdo php7.3-mysql php7.3-zip php7.3-gd php7.3-mbstring php7.3-curl php7.3-xml php7.3-bcmath php7.3-json
        service php7.3-fpm start
    fi
fi

# Check if mysql is installed
dpkg -s python-certbot-nginx &> /dev/null
IS_CERTBOT=$?
if [ $IS_CERTBOT -eq 0 ]; then
    echo -e "\e[32mCerbot is installed\e[0m"
else
    read -p 'Do you want to install Cerbot? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
        add_ppa_if_not_exists certbot/certbot
        apt install -y python-certbot-nginx
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
        curl -sL https://deb.nodesource.com/setup_10.x -o nodesource_setup.sh
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
    read -p 'Do you want to install MySQL? [Y/n]:' answer
    answer=${answer:Y}

    if [[ $answer =~ [Yy] ]]; then
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
        apt install -y composer
    fi
fi