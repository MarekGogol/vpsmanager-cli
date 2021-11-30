FROM ubuntu:18.04

ENV TZ=Europe/Bratislava
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN apt-get update
RUN apt-get install -y curl
RUN apt-get install -y locales
RUN apt install -y gcc
RUN apt install -y libpng-dev
RUN apt install -y make
RUN apt install -y rsync
RUN apt install -y zip
RUN apt install -y ssh
RUN apt install -y nano
RUN apt install -y software-properties-common
RUN add-apt-repository -y ppa:ondrej/php
RUN apt install -y php8.0-cli php8.0-fpm php8.0-soap php8.0-mysql php8.0-zip php8.0-gd php8.0-mbstring php8.0-curl php8.0-xml php8.0-bcmath php8.0-redis php8.0-common php8.0-imagick
RUN service ssh start
RUN service php8.0-fpm start
# RUN add-apt-repository -y ppa:certbot/certbot && apt install -y python-certbot-nginx
#RUN apt install -y nginx
#RUN apt install -y composer
#RUN apt install -y pngquant
#RUN apt install -y mysql-server
#RUN curl -sL https://deb.nodesource.com/setup_10.x | bash
#RUN apt-get install -y nodejs
#RUN chown -R mysql:mysql /var/lib/mysql /var/run/mysqld && \
#    service mysql start && \
#    mvn -q verify site
WORKDIR /root/vpsmanager

EXPOSE 80 443 21 22 3306

CMD ["/bin/bash"]
