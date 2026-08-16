#!/usr/bin/env bash
#
# MonitorMGR agent installer. Run on the host you want to monitor:
#
#   curl -fsSL https://MASTER/downloads/agent-install.sh | sudo bash -s -- https://MASTER <enroll-token>
#
# Downloads the agent from the panel, trades the one-time enrollment token for a
# permanent agent key, and installs a systemd service that samples the host and
# pushes metrics outbound over HTTPS. The agent never listens on a port, so the
# monitored host needs no inbound firewall rule. Linux x86_64.
set -euo pipefail

MASTER="${1:?usage: agent-install.sh <master-url> <enroll-token>}"
TOKEN="${2:?usage: agent-install.sh <master-url> <enroll-token>}"
MASTER="${MASTER%/}"
DEST="${MONITOR_AGENT_DIR:-/opt/monitor-agent}"
CFG="/etc/monitor-agent/agent.json"

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)."; exit 1; }
command -v curl >/dev/null || { echo "curl is required."; exit 1; }

echo "==> Downloading the agent from ${MASTER}/downloads"
mkdir -p "$DEST" /etc/monitor-agent
curl -fsSL "${MASTER}/downloads/monitor-agent" -o "$DEST/monitor-agent.new"
chmod +x "$DEST/monitor-agent.new"
mv -f "$DEST/monitor-agent.new" "$DEST/monitor-agent"

echo "==> Enrolling with the panel"
"$DEST/monitor-agent" enroll -master "$MASTER" -token "$TOKEN" -config "$CFG"
chmod 600 "$CFG"

echo "==> Installing systemd service"
cat > /etc/systemd/system/monitor-agent.service <<UNIT
[Unit]
Description=MonitorMGR host agent
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=${DEST}/monitor-agent run -config ${CFG}
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now monitor-agent
echo "==> Done. The agent is enrolled and reporting (systemctl status monitor-agent)."
