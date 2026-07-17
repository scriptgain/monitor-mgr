// Package metrics collects host resource metrics from the Linux /proc and /sys
// pseudo-filesystems and syscalls, using only the Go standard library so the
// agent stays a clean, dependency-free static build.
package metrics

import (
	"bufio"
	"os"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Disk is one real filesystem's usage.
type Disk struct {
	Device string `json:"device"`
	Mount  string `json:"mount"`
	FSType string `json:"fstype"`
	Total  uint64 `json:"total"`
	Used   uint64 `json:"used"`
}

// Core is one CPU core's utilization percent.
type Core struct {
	Core int     `json:"core"`
	Pct  float64 `json:"pct"`
}

// Snapshot is one point-in-time reading of the host's resources.
type Snapshot struct {
	Hostname     string  `json:"hostname"`
	OS           string  `json:"os"`
	Arch         string  `json:"arch"`
	Uptime       uint64  `json:"uptime_seconds"`
	BootTime     int64   `json:"boot_time"`
	CPUPct       float64 `json:"cpu_pct"`
	CPUCores     int     `json:"cpu_cores"`
	Load1        float64 `json:"load1"`
	Load5        float64 `json:"load5"`
	Load15       float64 `json:"load15"`
	MemTotal     uint64  `json:"mem_total"`
	MemUsed      uint64  `json:"mem_used"`
	SwapTotal    uint64  `json:"swap_total"`
	SwapUsed     uint64  `json:"swap_used"`
	DiskTotal    uint64  `json:"disk_total"`
	DiskUsed     uint64  `json:"disk_used"`
	NetRxPerSec  uint64  `json:"net_rx_bytes_sec"`
	NetTxPerSec  uint64  `json:"net_tx_bytes_sec"`
	Disks        []Disk  `json:"disks"`
	Cores        []Core  `json:"cores"`
}

// Collector holds the inter-sample state (previous network counters) needed to
// compute rates between reporting ticks.
type Collector struct {
	prevNetRx uint64
	prevNetTx uint64
	prevNetAt time.Time
}

// New returns a ready collector.
func New() *Collector { return &Collector{} }

// Collect samples every metric once. CPU utilization is measured over a short
// in-call window (accurate and stateless); network throughput is a rate derived
// from the previous Collect call.
func (c *Collector) Collect() Snapshot {
	host, _ := os.Hostname()
	s := Snapshot{
		Hostname: host,
		OS:       runtime.GOOS,
		Arch:     runtime.GOARCH,
		CPUCores: runtime.NumCPU(),
	}

	s.Uptime = readUptime()
	if s.Uptime > 0 {
		s.BootTime = time.Now().Add(-time.Duration(s.Uptime) * time.Second).Unix()
	}
	s.Load1, s.Load5, s.Load15 = readLoad()
	s.MemTotal, s.MemUsed, s.SwapTotal, s.SwapUsed = readMem()
	s.Disks = readDisks()
	for _, d := range s.Disks {
		s.DiskTotal += d.Total
		s.DiskUsed += d.Used
	}
	s.CPUPct, s.Cores = readCPU()
	s.NetRxPerSec, s.NetTxPerSec = c.netRate()
	return s
}

// readUptime returns seconds since boot from /proc/uptime.
func readUptime() uint64 {
	b, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0
	}
	f := strings.Fields(string(b))
	if len(f) == 0 {
		return 0
	}
	v, _ := strconv.ParseFloat(f[0], 64)
	return uint64(v)
}

// readLoad returns the 1/5/15-minute load averages from /proc/loadavg.
func readLoad() (l1, l5, l15 float64) {
	b, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return
	}
	f := strings.Fields(string(b))
	if len(f) < 3 {
		return
	}
	l1, _ = strconv.ParseFloat(f[0], 64)
	l5, _ = strconv.ParseFloat(f[1], 64)
	l15, _ = strconv.ParseFloat(f[2], 64)
	return
}

// readMem returns memory + swap used/total (bytes) from /proc/meminfo. "Used"
// follows the modern kernel definition: MemTotal - MemAvailable.
func readMem() (memTotal, memUsed, swapTotal, swapUsed uint64) {
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return
	}
	defer f.Close()
	vals := map[string]uint64{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		parts := strings.Fields(sc.Text())
		if len(parts) < 2 {
			continue
		}
		key := strings.TrimSuffix(parts[0], ":")
		kb, _ := strconv.ParseUint(parts[1], 10, 64)
		vals[key] = kb * 1024 // meminfo is in kB
	}
	memTotal = vals["MemTotal"]
	avail, ok := vals["MemAvailable"]
	if !ok {
		avail = vals["MemFree"] + vals["Buffers"] + vals["Cached"]
	}
	if memTotal > avail {
		memUsed = memTotal - avail
	}
	swapTotal = vals["SwapTotal"]
	if swapTotal >= vals["SwapFree"] {
		swapUsed = swapTotal - vals["SwapFree"]
	}
	return
}

// pseudoFS is the set of filesystem types that do not represent real storage.
var pseudoFS = map[string]bool{
	"proc": true, "sysfs": true, "devtmpfs": true, "tmpfs": true, "devpts": true,
	"cgroup": true, "cgroup2": true, "mqueue": true, "hugetlbfs": true, "debugfs": true,
	"tracefs": true, "securityfs": true, "pstore": true, "bpf": true, "configfs": true,
	"fusectl": true, "autofs": true, "binfmt_misc": true, "rpc_pipefs": true, "nsfs": true,
	"efivarfs": true, "ramfs": true, "squashfs": true, "fuse.gvfsd-fuse": true,
	"fuse.portal": true, "overlay": true,
}

// readDisks enumerates real filesystems from /proc/mounts, deduped by backing
// device so bind mounts of one disk are counted once, and returns their usage.
func readDisks() []Disk {
	f, err := os.Open("/proc/mounts")
	if err != nil {
		return nil
	}
	defer f.Close()

	seen := map[string]bool{}
	var disks []Disk
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		parts := strings.Fields(sc.Text())
		if len(parts) < 3 {
			continue
		}
		device, mount, fstype := parts[0], parts[1], parts[2]
		if pseudoFS[fstype] {
			continue
		}
		// Only real block devices; skips snap /dev/loop* and virtual sources.
		if !strings.HasPrefix(device, "/dev/") || strings.HasPrefix(device, "/dev/loop") {
			continue
		}
		if seen[device] { // dedupe: first mount of a device wins (bind mounts once)
			continue
		}
		var st syscall.Statfs_t
		if err := syscall.Statfs(mount, &st); err != nil {
			continue
		}
		bs := uint64(st.Bsize)
		total := st.Blocks * bs
		if total == 0 {
			continue
		}
		seen[device] = true
		disks = append(disks, Disk{
			Device: device,
			Mount:  mount,
			FSType: fstype,
			Total:  total,
			Used:   (st.Blocks - st.Bfree) * bs,
		})
	}
	return disks
}

// cpuTimes returns (total, idle) jiffies for the aggregate and each core, keyed
// by cpu label ("cpu" for aggregate, "cpu0".. per core).
func cpuTimes() map[string][2]uint64 {
	f, err := os.Open("/proc/stat")
	if err != nil {
		return nil
	}
	defer f.Close()
	out := map[string][2]uint64{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 5 || !strings.HasPrefix(fields[0], "cpu") {
			continue
		}
		var total, idle uint64
		for i := 1; i < len(fields); i++ {
			v, err := strconv.ParseUint(fields[i], 10, 64)
			if err != nil {
				continue
			}
			total += v
			if i == 4 || i == 5 { // idle + iowait
				idle += v
			}
		}
		out[fields[0]] = [2]uint64{total, idle}
	}
	return out
}

// readCPU measures overall and per-core utilization over a short sampling window.
func readCPU() (overall float64, cores []Core) {
	a := cpuTimes()
	if a == nil {
		return
	}
	time.Sleep(300 * time.Millisecond)
	b := cpuTimes()

	pct := func(label string) (float64, bool) {
		x, ok1 := a[label]
		y, ok2 := b[label]
		if !ok1 || !ok2 {
			return 0, false
		}
		dTotal := float64(y[0] - x[0])
		dIdle := float64(y[1] - x[1])
		if dTotal <= 0 {
			return 0, true
		}
		p := (dTotal - dIdle) / dTotal * 100
		if p < 0 {
			p = 0
		}
		if p > 100 {
			p = 100
		}
		return p, true
	}

	overall, _ = pct("cpu")
	for i := 0; ; i++ {
		p, ok := pct("cpu" + strconv.Itoa(i))
		if !ok {
			break
		}
		cores = append(cores, Core{Core: i, Pct: round1(p)})
	}
	return round1(overall), cores
}

// netRate returns rx/tx bytes-per-second since the previous call, summed over
// every interface except loopback.
func (c *Collector) netRate() (rx, tx uint64) {
	f, err := os.Open("/proc/net/dev")
	if err != nil {
		return
	}
	defer f.Close()
	var totalRx, totalTx uint64
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		line := sc.Text()
		i := strings.IndexByte(line, ':')
		if i < 0 {
			continue
		}
		iface := strings.TrimSpace(line[:i])
		if iface == "lo" {
			continue
		}
		fields := strings.Fields(line[i+1:])
		if len(fields) < 9 {
			continue
		}
		r, _ := strconv.ParseUint(fields[0], 10, 64)  // rx bytes
		t, _ := strconv.ParseUint(fields[8], 10, 64)  // tx bytes
		totalRx += r
		totalTx += t
	}

	now := time.Now()
	if !c.prevNetAt.IsZero() {
		secs := now.Sub(c.prevNetAt).Seconds()
		if secs > 0 {
			if totalRx >= c.prevNetRx {
				rx = uint64(float64(totalRx-c.prevNetRx) / secs)
			}
			if totalTx >= c.prevNetTx {
				tx = uint64(float64(totalTx-c.prevNetTx) / secs)
			}
		}
	}
	c.prevNetRx, c.prevNetTx, c.prevNetAt = totalRx, totalTx, now
	return
}

func round1(f float64) float64 {
	return float64(int64(f*10+0.5)) / 10
}
