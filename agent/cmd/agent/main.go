// Command monitor-agent is the MonitorMGR host agent.
//
// It runs on any Linux host, samples local resource metrics (CPU, memory, disk,
// load, uptime, network) from /proc and /sys, and reports them to the MonitorMGR
// control plane over outbound HTTPS. No inbound ports are required on the host.
//
// Subcommands:
//
//	monitor-agent version
//	monitor-agent enroll -master URL -token TOKEN
//	monitor-agent run
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"runtime"
	"syscall"
	"time"

	"github.com/thelonelyfrog/monitor/agent/internal/api"
	"github.com/thelonelyfrog/monitor/agent/internal/config"
	"github.com/thelonelyfrog/monitor/agent/internal/metrics"
)

var version = "dev"

func main() {
	if len(os.Args) < 2 {
		os.Args = append(os.Args, "run")
	}
	cmd, args := os.Args[1], os.Args[2:]

	var err error
	switch cmd {
	case "version", "-v", "--version":
		fmt.Printf("monitor-agent %s\n", version)
	case "enroll":
		err = cmdEnroll(args)
	case "run":
		err = cmdRun(args)
	default:
		usage()
		if cmd != "help" && cmd != "-h" && cmd != "--help" {
			err = fmt.Errorf("unknown command %q", cmd)
		}
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprint(os.Stderr, `monitor-agent

usage:
  monitor-agent version
  monitor-agent enroll -master <url> -token <token>
  monitor-agent run
`)
}

func cmdEnroll(args []string) error {
	fs := flag.NewFlagSet("enroll", flag.ExitOnError)
	master := fs.String("master", "", "master control-plane base URL")
	token := fs.String("token", "", "one-time enrollment token")
	cfgPath := fs.String("config", config.DefaultPath(), "agent config path")
	fs.Parse(args)

	if *master == "" || *token == "" {
		return errors.New("both -master and -token are required")
	}
	hostname, _ := os.Hostname()
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	resp, err := api.New(*master, "").Enroll(ctx, api.EnrollRequest{
		Token:        *token,
		Hostname:     hostname,
		OS:           runtime.GOOS,
		Arch:         runtime.GOARCH,
		AgentVersion: version,
	})
	if err != nil {
		return fmt.Errorf("enroll: %w", err)
	}

	cfg := config.Default()
	cfg.MasterURL = *master
	cfg.APIKey = resp.APIKey
	cfg.HostID = resp.HostID
	if err := cfg.Save(*cfgPath); err != nil {
		return err
	}
	fmt.Printf("enrolled as host %s; config saved to %s\n", resp.HostID, *cfgPath)
	return nil
}

func cmdRun(args []string) error {
	fs := flag.NewFlagSet("run", flag.ExitOnError)
	cfgPath := fs.String("config", config.DefaultPath(), "agent config path")
	once := fs.Bool("once", false, "sample once and exit (for testing)")
	fs.Parse(args)

	cfg, err := config.Load(*cfgPath)
	if errors.Is(err, config.ErrNotConfigured) || (err == nil && !cfg.Enrolled()) {
		return fmt.Errorf("not enrolled: run `monitor-agent enroll` first (config: %s)", *cfgPath)
	}
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	client := api.New(cfg.MasterURL, cfg.APIKey)
	collector := metrics.New()
	interval := time.Duration(cfg.Interval)
	changed := false
	fmt.Printf("monitor-agent %s: reporting to %s every %s\n", version, cfg.MasterURL, interval)

	report := func() {
		snap := collector.Collect()
		resp, err := client.Ingest(ctx, snap, version)
		if err != nil {
			fmt.Fprintln(os.Stderr, "ingest:", err)
			return
		}
		fmt.Printf("reported: cpu %.1f%% mem %s/%s disk %s/%s load %.2f\n",
			snap.CPUPct, human(snap.MemUsed), human(snap.MemTotal),
			human(snap.DiskUsed), human(snap.DiskTotal), snap.Load1)
		// Adopt the master-configured cadence if it changed.
		if resp != nil && resp.IntervalSeconds > 0 {
			if d := time.Duration(resp.IntervalSeconds) * time.Second; d != interval {
				interval, changed = d, true
			}
		}
	}

	// Prime the network-rate baseline, then take the first real reading.
	collector.Collect()
	report()
	if *once {
		return nil
	}
	t := time.NewTicker(interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			fmt.Println("shutting down")
			return nil
		case <-t.C:
			report()
			if changed {
				changed = false
				t.Reset(interval)
				fmt.Printf("interval updated to %s\n", interval)
			}
		}
	}
}

func human(b uint64) string {
	const unit = 1024
	if b < unit {
		return fmt.Sprintf("%dB", b)
	}
	div, exp := uint64(unit), 0
	for n := b / unit; n >= unit; n /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f%cB", float64(b)/float64(div), "KMGTPE"[exp])
}
