#!/usr/bin/env bash
#
# Build the MonitorMGR agent as a fully STATIC Linux x86_64 binary.
#
# CGO_ENABLED=0 removes the glibc dependency and uses Go's pure resolver, so the
# binary runs on Ubuntu 22.04+, Debian 12+, and any other Linux x86_64 regardless
# of the build host's glibc. Do not drop CGO_ENABLED=0 - a dynamic build ties the
# binary to the build box's glibc and breaks on older distros.
#
#   ./build.sh 1.0.0
#
set -euo pipefail
VER="${1:-dev}"
mkdir -p bin
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath \
    -ldflags "-s -w -X main.version=${VER}" -o bin/monitor-agent ./cmd/agent
file bin/monitor-agent | grep -q 'statically linked' || { echo "!! build is not static"; exit 1; }
echo "built static monitor-agent ${VER}: $(ls -la bin/monitor-agent | awk '{print $5}') bytes"
