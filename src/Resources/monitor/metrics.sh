#!/bin/bash
#
# Metrics of the server for the monitor (RAM, disk, load, network...), printed as key=value lines for
# monitor:collect. The logic is the bash agent of the monitor (monitor.sh 1.1.0), which posted the values itself.
# The state of the previous run (CPU and network counters) is kept in the file of the first argument.
#
# Managed by VPS Manager, run by monitor:collect.

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

state="${1:-/var/lib/vpsmanager/monitor-metrics.state}"

# Prepare values
function prep ()
{
    echo "$1" | sed -e 's/^ *//g' -e 's/ *$//g' | sed -n '1 p'
}

# Integer values
function int ()
{
    echo ${1/\.*}
}

# Filter numeric
function num ()
{
    case $1 in
        ''|*[!0-9\.]*) echo 0 ;;
        *) echo $1 ;;
    esac
}

# System uptime
uptime=$(prep $(int "$(cat /proc/uptime | awk '{ print $1 }')"))

# Login session count
sessions=$(prep "$(who | wc -l)")

# Process count
processes=$(prep "$(ps axc | wc -l)")

# Process array
processes_array="$(ps axc -o uname:12,pcpu,rss,cmd --sort=-pcpu,-rss --noheaders --width 120)"
processes_array="$(echo "$processes_array" | grep -v " ps$" | sed 's/ \+ / /g' | sed '/^$/d' | tr "\n" ";")"

# File descriptors
file_handles=$(prep $(num "$(cat /proc/sys/fs/file-nr | awk '{ print $1 }')"))
file_handles_limit=$(prep $(num "$(cat /proc/sys/fs/file-nr | awk '{ print $3 }')"))

# OS details
os_kernel=$(prep "$(uname -r)")

if ls /etc/*release > /dev/null 2>&1
then
    os_name=$(prep "$(cat /etc/*release | grep '^PRETTY_NAME=\|^NAME=\|^DISTRIB_ID=' | awk -F\= '{ print $2 }' | tr -d '"' | tac)")
fi

if [ -z "$os_name" ]
then
    if [ -e /etc/redhat-release ]
    then
        os_name=$(prep "$(cat /etc/redhat-release)")
    elif [ -e /etc/debian_version ]
    then
        os_name=$(prep "Debian $(cat /etc/debian_version)")
    fi

    if [ -z "$os_name" ]
    then
        os_name=$(prep "$(uname -s)")
    fi
fi

case $(uname -m) in
x86_64)
    os_arch=$(prep "x64")
    ;;
i*86)
    os_arch=$(prep "x86")
    ;;
*)
    os_arch=$(prep "$(uname -m)")
    ;;
esac

# CPU details
cpu_name=$(prep "$(cat /proc/cpuinfo | grep 'model name' | awk -F\: '{ print $2 }')")
cpu_cores=$(prep "$(($(cat /proc/cpuinfo | grep 'model name' | awk -F\: '{ print $2 }' | sed -e :a -e '$!N;s/\n/\|/;ta' | tr -cd \| | wc -c)+1))")

if [ -z "$cpu_name" ]
then
    cpu_name=$(prep "$(cat /proc/cpuinfo | grep 'vendor_id' | awk -F\: '{ print $2 } END { if (!NR) print "N/A" }')")
    cpu_cores=$(prep "$(($(cat /proc/cpuinfo | grep 'vendor_id' | awk -F\: '{ print $2 }' | sed -e :a -e '$!N;s/\n/\|/;ta' | tr -cd \| | wc -c)+1))")
fi

cpu_freq=$(prep "$(cat /proc/cpuinfo | grep 'cpu MHz' | awk -F\: '{ print $2 }')")

if [ -z "$cpu_freq" ]
then
    cpu_freq=$(prep $(num "$(lscpu | grep 'CPU MHz' | awk -F\: '{ print $2 }' | sed -e 's/^ *//g' -e 's/ *$//g')"))
fi

# RAM usage
ram_total=$(prep $(num "$(cat /proc/meminfo | grep ^MemTotal: | awk '{ print $2 }')"))
ram_free=$(prep $(num "$(cat /proc/meminfo | grep ^MemFree: | awk '{ print $2 }')"))
ram_cached=$(prep $(num "$(cat /proc/meminfo | grep ^Cached: | awk '{ print $2 }')"))
ram_buffers=$(prep $(num "$(cat /proc/meminfo | grep ^Buffers: | awk '{ print $2 }')"))
ram_usage=$((($ram_total-($ram_free+$ram_cached+$ram_buffers))*1024))
ram_total=$(($ram_total*1024))

# Swap usage
swap_total=$(prep $(num "$(cat /proc/meminfo | grep ^SwapTotal: | awk '{ print $2 }')"))
swap_free=$(prep $(num "$(cat /proc/meminfo | grep ^SwapFree: | awk '{ print $2 }')"))
swap_usage=$((($swap_total-$swap_free)*1024))
swap_total=$(($swap_total*1024))

# Disk usage
disk_total=$(prep $(num "$(($(df -l -P -B 1 --type ext4 2> /dev/null | grep '^/' | awk '{ print $2 }' | sed -e :a -e '$!N;s/\n/+/;ta')))"))
disk_usage=$(prep $(num "$(($(df -l -P -B 1 --type ext4 2> /dev/null | grep '^/' | awk '{ print $3 }' | sed -e :a -e '$!N;s/\n/+/;ta')))"))
disk_available=$(prep $(num "$(($(df -l -P -B 1 --type ext4 2> /dev/null | grep '^/' | awk '{ print $4 }' | sed -e :a -e '$!N;s/\n/+/;ta')))"))
disk_array=$(prep "$(df -P -B 1 | grep '^/' | awk '{ print $1" "$2" "$3";" }' | sed -e :a -e '$!N;s/\n/ /;ta' | awk '{ print $0 } END { if (!NR) print "N/A" }')")

# Without ext4 (xfs, btrfs, overlay of a container) the file system of the root
if [ "$disk_total" = "0" ]
then
    disk_total=$(prep $(num "$(df -P -B 1 / | tail -n 1 | awk '{ print $2 }')"))
    disk_usage=$(prep $(num "$(df -P -B 1 / | tail -n 1 | awk '{ print $3 }')"))
    disk_available=$(prep $(num "$(df -P -B 1 / | tail -n 1 | awk '{ print $4 }')"))
fi

# Active connections
if [ -n "$(command -v ss)" ]
then
    connections=$(prep $(num "$(ss -tun | tail -n +2 | wc -l)"))
else
    connections=$(prep $(num "$(netstat -tun | tail -n +3 | wc -l)"))
fi

# Network interface
nic=$(prep "$(ip route get 8.8.8.8 | grep dev | awk -F'dev' '{ print $2 }' | awk '{ print $1 }')")

if [ -z $nic ]
then
    nic=$(prep "$(ip link show | grep 'eth[0-9]' | awk '{ print $2 }' | tr -d ':')")
fi

# IP addresses and network usage
ipv4=$(prep "$(ip addr show $nic | grep 'inet ' | awk '{ print $2 }' | awk -F\/ '{ print $1 }' | grep -v '^127' | awk '{ print $0 } END { if (!NR) print "N/A" }')")
ipv6=$(prep "$(ip addr show $nic | grep 'inet6 ' | awk '{ print $2 }' | awk -F\/ '{ print $1 }' | grep -v '^::' | grep -v '^0000:' | grep -v '^fe80:' | awk '{ print $0 } END { if (!NR) print "N/A" }')")

if [ -d /sys/class/net/$nic/statistics ]
then
    rx=$(prep $(num "$(cat /sys/class/net/$nic/statistics/rx_bytes)"))
    tx=$(prep $(num "$(cat /sys/class/net/$nic/statistics/tx_bytes)"))
else
    rx=$(prep $(num "$(ip -s link show $nic | grep '[0-9]*' | grep -v '[A-Za-z]' | awk '{ print $1 }' | sed -n '1 p')"))
    tx=$(prep $(num "$(ip -s link show $nic | grep '[0-9]*' | grep -v '[A-Za-z]' | awk '{ print $1 }' | sed -n '2 p')"))
fi

# Average system load
load=$(prep "$(cat /proc/loadavg | awk '{ print $1" "$2" "$3 }')")

# Detailed system load calculation
time=$(date +%s)
stat=($(cat /proc/stat | head -n1 | sed 's/[^0-9 ]*//g' | sed 's/^ *//'))
cpu=$((${stat[0]}+${stat[1]}+${stat[2]}+${stat[3]}))
io=$((${stat[3]}+${stat[4]}))
idle=${stat[3]}

mkdir -p "$(dirname "$state")"

if [ -e "$state" ]
then
    data=($(cat "$state"))
    interval=$(($time-${data[0]}))
    cpu_gap=$(($cpu-${data[1]}))
    io_gap=$(($io-${data[2]}))
    idle_gap=$(($idle-${data[3]}))

    if [[ $cpu_gap > "0" ]]
    then
        load_cpu=$(((1000*($cpu_gap-$idle_gap)/$cpu_gap+5)/10))
    fi

    if [[ $io_gap > "0" ]]
    then
        load_io=$(((1000*($io_gap-$idle_gap)/$io_gap+5)/10))
    fi

    if [[ $rx > ${data[4]} ]]
    then
        rx_gap=$(($rx-${data[4]}))
    fi

    if [[ $tx > ${data[5]} ]]
    then
        tx_gap=$(($tx-${data[5]}))
    fi
fi

# System load cache
echo "$time $cpu $io $idle $rx $tx" > "$state"

# Prepare load variables
rx_gap=$(prep $(num "$rx_gap"))
tx_gap=$(prep $(num "$tx_gap"))
load_cpu=$(prep $(num "$load_cpu"))
load_io=$(prep $(num "$load_io"))

# Get network latency
ping_eu=$(prep $(num "$(ping -c 2 -w 2 ftp.fr.debian.org | grep rtt | cut -d'/' -f4 | awk '{ print $3 }')"))
ping_us=$(prep $(num "$(ping -c 2 -w 2 ftp.us.debian.org | grep rtt | cut -d'/' -f4 | awk '{ print $3 }')"))
ping_es=$(prep $(num "$(ping -c 2 -w 2 ftp.es.debian.org | grep rtt | cut -d'/' -f4 | awk '{ print $3 }')"))

# Values for monitor:collect, one per line
echo "uptime=$uptime"
echo "sessions=$sessions"
echo "processes=$processes"
echo "processes_array=$processes_array"
echo "file_handles=$file_handles"
echo "file_handles_limit=$file_handles_limit"
echo "os_kernel=$os_kernel"
echo "os_name=$os_name"
echo "os_arch=$os_arch"
echo "cpu_name=$cpu_name"
echo "cpu_cores=$cpu_cores"
echo "cpu_freq=$cpu_freq"
echo "ram_total=$ram_total"
echo "ram_usage=$ram_usage"
echo "swap_total=$swap_total"
echo "swap_usage=$swap_usage"
echo "disk_array=$disk_array"
echo "disk_total=$disk_total"
echo "disk_usage=$disk_usage"
echo "disk_available=$disk_available"
echo "connections=$connections"
echo "nic=$nic"
echo "ipv4=$ipv4"
echo "ipv6=$ipv6"
echo "rx=$rx"
echo "tx=$tx"
echo "rx_gap=$rx_gap"
echo "tx_gap=$tx_gap"
echo "load=$load"
echo "load_cpu=$load_cpu"
echo "load_io=$load_io"
echo "ping_eu=$ping_eu"
echo "ping_us=$ping_us"
echo "ping_es=$ping_es"
