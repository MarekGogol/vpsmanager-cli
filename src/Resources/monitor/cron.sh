#!/bin/sh
# Rotates the access log of the monitor every hour, managed by VPS Manager (monitor:access-log).
exec /usr/sbin/logrotate --state /var/lib/logrotate/vpsmanager-monitor.status /etc/vpsmanager/logrotate-monitor.conf
