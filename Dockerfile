FROM ubuntu:18.04

ENV TZ=Europe/Bratislava
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN apt update
RUN apt install -y nano
RUN apt install -y nginx
RUN apt install -y composer
RUN apt install -y software-properties-common
RUN add-apt-repository -y ppa:certbot/certbot && apt install -y python-certbot-nginx
RUN add-apt-repository -y ppa:ondrej/php
RUN apt install -y php7.1-fpm && apt install -y php7.1-cli php7.1-fpm php7.1-json php7.1-pdo php7.1-mysql php7.1-zip php7.1-gd php7.1-mbstring php7.1-curl php7.1-xml php7.1-bcmath php7.1-json
RUN apt install -y php7.2-fpm && apt install -y php7.2-cli php7.2-fpm php7.2-json php7.2-pdo php7.2-mysql php7.2-zip php7.2-gd php7.2-mbstring php7.2-curl php7.2-xml php7.2-bcmath php7.2-json
RUN apt install -y php7.3-fpm && apt install -y php7.3-cli php7.3-fpm php7.3-json php7.3-pdo php7.3-mysql php7.3-zip php7.3-gd php7.3-mbstring php7.3-curl php7.3-xml php7.3-bcmath php7.3-json
#RUN apt install -y mysql-server
#RUN chown -R mysql:mysql /var/lib/mysql /var/run/mysqld && \
#    service mysql start && \
#    mvn -q verify site
WORKDIR /root/vpsmanager

EXPOSE 80 443 21 22 3306

CMD ["/bin/bash"]
