#!/usr/bin/env bash
#
# MonitorMGR agent installer. Run on the host you want to monitor:
#
#   curl -fsSL https://MASTER/downloads/agent-install.sh | sudo bash -s -- https://MASTER <enroll-token>
#
# Downloads the static agent from the control plane, enrolls this host, and
# installs a systemd service that reports CPU/memory/disk/load metrics every
# 30 seconds over outbound HTTPS. Linux x86_64. No inbound ports required.
set -euo pipefail

MASTER="${1:?usage: agent-install.sh <master-url> <enroll-token>}"
TOKEN="${2:?usage: agent-install.sh <master-url> <enroll-token>}"
MASTER="${MASTER%/}"
DEST="${MONITOR_DIR:-/opt/monitor-agent}"
BIN="$DEST/monitor-agent"
CFG="/etc/monitor-agent/agent.json"

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)."; exit 1; }
command -v curl >/dev/null || { echo "curl is required."; exit 1; }

echo "==> Downloading agent from ${MASTER}/downloads/monitor-agent"
mkdir -p "$DEST" /etc/monitor-agent
curl -fsSL "${MASTER}/downloads/monitor-agent" -o "$BIN"
chmod +x "$BIN"

echo "==> Enrolling with the control plane"
"$BIN" enroll -master "$MASTER" -token "$TOKEN" -config "$CFG"

echo "==> Installing systemd service"
cat > /etc/systemd/system/monitor-agent.service <<UNIT
[Unit]
Description=MonitorMGR agent
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=${BIN} run -config ${CFG}
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now monitor-agent
echo "==> Done. The agent is enrolled and running (systemctl status monitor-agent)."
