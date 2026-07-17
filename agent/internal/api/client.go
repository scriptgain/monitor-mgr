// Package api is the agent's client for the MonitorMGR control plane.
//
// All traffic is agent-initiated outbound HTTPS. Authentication is a per-agent
// bearer API key (issued at enrollment); the /enroll call itself is unauthed and
// uses a one-time token. Endpoints live under /api/agent/v1 on the master.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/thelonelyfrog/monitor/agent/internal/metrics"
)

const apiPrefix = "/api/agent/v1"

// Client talks to one MonitorMGR control plane.
type Client struct {
	baseURL string
	apiKey  string
	hc      *http.Client
}

// New builds a Client. apiKey may be empty for the initial Enroll call.
func New(masterURL, apiKey string) *Client {
	return &Client{
		baseURL: strings.TrimRight(masterURL, "/"),
		apiKey:  apiKey,
		hc:      &http.Client{Timeout: 30 * time.Second},
	}
}

func (c *Client) endpoint(path string) string { return c.baseURL + apiPrefix + path }

// do performs a JSON request. When body is non-nil it is JSON-encoded; when out
// is non-nil a 2xx body is decoded into it. auth toggles the bearer header.
func (c *Client) do(ctx context.Context, method, path string, body, out any, auth bool) (int, error) {
	var rdr io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return 0, fmt.Errorf("encode request: %w", err)
		}
		rdr = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.endpoint(path), rdr)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if auth {
		if c.apiKey == "" {
			return 0, fmt.Errorf("%s %s: missing API key", method, path)
		}
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}

	resp, err := c.hc.Do(req)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		snippet, _ := io.ReadAll(io.LimitReader(resp.Body, 2048))
		return resp.StatusCode, fmt.Errorf("%s %s: %s: %s",
			method, path, resp.Status, strings.TrimSpace(string(snippet)))
	}
	if out != nil && resp.StatusCode != http.StatusNoContent {
		if err := json.NewDecoder(resp.Body).Decode(out); err != nil {
			return resp.StatusCode, fmt.Errorf("decode response: %w", err)
		}
	}
	return resp.StatusCode, nil
}

// EnrollRequest trades a one-time token for a permanent API key.
type EnrollRequest struct {
	Token        string `json:"token"`
	Hostname     string `json:"hostname"`
	OS           string `json:"os"`
	Arch         string `json:"arch"`
	AgentVersion string `json:"agent_version"`
}

// EnrollResponse carries the credentials the agent persists after enrollment.
type EnrollResponse struct {
	HostID string `json:"host_id"`
	APIKey string `json:"api_key"`
}

// Enroll trades a one-time token for permanent credentials.
func (c *Client) Enroll(ctx context.Context, req EnrollRequest) (*EnrollResponse, error) {
	var out EnrollResponse
	if _, err := c.do(ctx, http.MethodPost, "/enroll", req, &out, false); err != nil {
		return nil, err
	}
	c.apiKey = out.APIKey
	return &out, nil
}

// IngestResponse is the master's reply to a metrics report. IntervalSeconds,
// when > 0, is a master-configured sampling cadence the agent should adopt.
type IngestResponse struct {
	IntervalSeconds int `json:"interval_seconds,omitempty"`
}

// ingestBody is the metrics snapshot plus the agent's version.
type ingestBody struct {
	metrics.Snapshot
	AgentVersion string `json:"agent_version"`
}

// Ingest POSTs a metrics snapshot to the master.
func (c *Client) Ingest(ctx context.Context, snap metrics.Snapshot, agentVersion string) (*IngestResponse, error) {
	var out IngestResponse
	body := ingestBody{Snapshot: snap, AgentVersion: agentVersion}
	if _, err := c.do(ctx, http.MethodPost, "/metrics", body, &out, true); err != nil {
		return nil, err
	}
	return &out, nil
}
