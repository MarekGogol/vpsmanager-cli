#!/usr/bin/env bash
set -euo pipefail

echo "===> Protecting MySQL/MariaDB from OOM killer"

# Detect service name
SERVICE=""
for s in mysql mysqld mariadb; do
    if systemctl list-unit-files | grep -q "^${s}\.service"; then
        SERVICE="$s"
        break
    fi
done

if [[ -z "$SERVICE" ]]; then
    echo "ERROR: Could not detect mysql/mysqld/mariadb service"
    exit 1
fi

echo "Detected service: $SERVICE"

# Create systemd override directory
OVERRIDE_DIR="/etc/systemd/system/${SERVICE}.service.d"
OVERRIDE_FILE="${OVERRIDE_DIR}/oom-protect.conf"

mkdir -p "$OVERRIDE_DIR"

# Write override (idempotent)
cat > "$OVERRIDE_FILE" <<EOF
[Service]
OOMScoreAdjust=-1000
EOF

echo "Created override: $OVERRIDE_FILE"

# Reload systemd so it sees the change
systemctl daemon-reexec
systemctl daemon-reload

# Apply immediately if MySQL is running
if pgrep -x mysqld >/dev/null 2>&1 || pgrep -x mariadbd >/dev/null 2>&1; then
    echo "Applying adjustment to running process..."

    for pid in $(pgrep -x mysqld || true) $(pgrep -x mariadbd || true); do
        echo -1000 > "/proc/$pid/oom_score_adj"
        echo "Adjusted PID $pid"
    done
else
    echo "MySQL is not currently running — adjustment will apply on next start."
fi

echo "Done."
echo
echo "Verification:"
systemctl show "$SERVICE" | grep OOMScoreAdjust || true