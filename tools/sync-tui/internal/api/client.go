package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

const (
	headerAPIKey     = "X-AmpliSync-Key"
	defaultTimeout   = 120 * time.Second
	basePath         = "/wp-json/amplifi-sync/v1"
	maxResponseBytes = 100 * 1024 * 1024 // 100 MB
	maxRetries       = 3
)

// Client communicates with the amplifi.sync WordPress REST API plugin.
type Client struct {
	baseURL       string
	apiKey        string
	httpClient    *http.Client
	AllowInsecure bool // Set to true for development (allows http://)
}

// NewClient creates an API client for the given WordPress site.
// Enforces HTTPS by default. Set AllowInsecure on the returned client for dev use.
func NewClient(siteURL, apiKey string) *Client {
	c := &Client{
		baseURL: siteURL + basePath,
		apiKey:  apiKey,
		httpClient: &http.Client{
			Timeout: defaultTimeout,
		},
	}
	return c
}

// validateBaseURL ensures the base URL uses HTTPS unless AllowInsecure is set.
func (c *Client) validateBaseURL() error {
	if c.AllowInsecure {
		return nil
	}
	if !strings.HasPrefix(c.baseURL, "https://") {
		return fmt.Errorf("HTTPS required: base URL %q does not start with https:// (set AllowInsecure for dev)", c.baseURL)
	}
	return nil
}

// ---------- Status ----------

// GetStatus returns site information and sync plugin status.
func (c *Client) GetStatus(ctx context.Context) (*StatusResponse, error) {
	var resp StatusResponse
	if err := c.get(ctx, "/status", nil, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ---------- Files ----------

// GetFilesManifest returns a recursive file listing for the given directory.
func (c *Client) GetFilesManifest(ctx context.Context, dir string) (*FileManifest, error) {
	params := url.Values{"dir": {dir}}
	var resp FileManifest
	if err := c.get(ctx, "/files/manifest", params, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ReadFile returns the contents of a single file.
func (c *Client) ReadFile(ctx context.Context, path string) (*FileContent, error) {
	params := url.Values{"path": {path}}
	var resp FileContent
	if err := c.get(ctx, "/files/read", params, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// WriteFile creates or overwrites a file on the remote site.
func (c *Client) WriteFile(ctx context.Context, path, content string) error {
	body := map[string]string{
		"path":    path,
		"content": content,
	}
	var resp struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := c.post(ctx, "/files/write", body, &resp); err != nil {
		return err
	}
	if !resp.Success {
		return fmt.Errorf("write failed: %s", resp.Message)
	}
	return nil
}

// DeleteFile removes a file from the remote site.
func (c *Client) DeleteFile(ctx context.Context, path string) error {
	body := map[string]string{"path": path}
	var resp struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := c.post(ctx, "/files/delete", body, &resp); err != nil {
		return err
	}
	if !resp.Success {
		return fmt.Errorf("delete failed: %s", resp.Message)
	}
	return nil
}

// ---------- Database ----------

// GetTables lists all database tables with sizes.
func (c *Client) GetTables(ctx context.Context) (*TablesResponse, error) {
	var resp TablesResponse
	if err := c.get(ctx, "/db/tables", nil, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ExportTable returns paginated rows from a table.
func (c *Client) ExportTable(ctx context.Context, name string, page int) (*TableExport, error) {
	params := url.Values{
		"table": {name},
		"page":  {strconv.Itoa(page)},
	}
	var resp TableExport
	if err := c.get(ctx, "/db/export", params, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ImportTable inserts rows into a table. tokenID and token come from
// TablesResponse.ConfirmationTokens.Import (single-use, get fresh per batch).
// Mode is "truncate" or "append".
func (c *Client) ImportTable(ctx context.Context, name string, rows []map[string]interface{}, tokenID, token, mode string) (*ImportResponse, error) {
	body := map[string]interface{}{
		"table":              name,
		"rows":               rows,
		"token_id":           tokenID,
		"confirmation_token": token,
		"mode":               mode,
	}
	var resp ImportResponse
	if err := c.post(ctx, "/db/import", body, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// DBQuery executes a read-only SQL query and returns the result rows.
func (c *Client) DBQuery(ctx context.Context, sql string) (*DBQueryResponse, error) {
	body := map[string]string{"sql": sql}
	var resp DBQueryResponse
	if err := c.post(ctx, "/db/query", body, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// DBExecute runs a write SQL statement (INSERT/UPDATE/DELETE). The token is
// required for confirmation of destructive operations.
func (c *Client) DBExecute(ctx context.Context, sql, token string) (*DBExecuteResponse, error) {
	body := map[string]string{"sql": sql, "confirmation_token": token}
	var resp DBExecuteResponse
	if err := c.post(ctx, "/db/execute", body, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// DBBackup triggers a full database backup on the remote site.
func (c *Client) DBBackup(ctx context.Context) (*BackupResponse, error) {
	var resp BackupResponse
	if err := c.post(ctx, "/db/backup", nil, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// DBRestore restores a database from the given SQL dump content.
func (c *Client) DBRestore(ctx context.Context, sql string) (*BackupResponse, error) {
	body := map[string]string{"sql": sql}
	var resp BackupResponse
	if err := c.post(ctx, "/db/restore", body, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ---------- Media ----------

// GetMedia returns a paginated list of media library items.
func (c *Client) GetMedia(ctx context.Context, page int) (*MediaList, error) {
	params := url.Values{"page": {strconv.Itoa(page)}}
	var resp MediaList
	if err := c.get(ctx, "/media", params, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ImportMedia sideloads a media file from the given URL into the media library.
func (c *Client) ImportMedia(ctx context.Context, sourceURL, title string) (*MediaItem, error) {
	body := map[string]string{"url": sourceURL, "title": title}
	var resp MediaItem
	if err := c.post(ctx, "/media/import", body, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ---------- Elementor ----------

// ElementorRegenerate triggers Elementor CSS regeneration on the remote site.
func (c *Client) ElementorRegenerate(ctx context.Context) error {
	var resp struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := c.post(ctx, "/elementor/regenerate", nil, &resp); err != nil {
		return err
	}
	if !resp.Success {
		return fmt.Errorf("elementor regenerate failed: %s", resp.Message)
	}
	return nil
}

// ---------- HTTP helpers ----------

func (c *Client) get(ctx context.Context, endpoint string, params url.Values, dest interface{}) error {
	if err := c.validateBaseURL(); err != nil {
		return err
	}

	u := c.baseURL + endpoint
	if params != nil {
		u += "?" + params.Encode()
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return fmt.Errorf("building request: %w", err)
	}
	c.setHeaders(req)

	return c.doRequest(req, dest)
}

func (c *Client) post(ctx context.Context, endpoint string, body interface{}, dest interface{}) error {
	if err := c.validateBaseURL(); err != nil {
		return err
	}

	var bodyData []byte
	if body != nil {
		var err error
		bodyData, err = json.Marshal(body)
		if err != nil {
			return fmt.Errorf("encoding body: %w", err)
		}
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+endpoint, bytes.NewReader(bodyData))
	if err != nil {
		return fmt.Errorf("building request: %w", err)
	}
	c.setHeaders(req)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	return c.doRequest(req, dest)
}

func (c *Client) setHeaders(req *http.Request) {
	req.Header.Set(headerAPIKey, c.apiKey)
	req.Header.Set("Accept", "application/json")
}

// isRetriable determines whether a request should be retried based on the
// error, HTTP status code, and request method.
func isRetriable(err error, statusCode int, method string) bool {
	// Never retry POST requests that got a successful response — the server
	// may have already committed the operation.
	if method == http.MethodPost && statusCode >= 200 && statusCode < 300 {
		return false
	}

	// Retry on connection-level errors.
	if err != nil {
		var netErr net.Error
		if errors.As(err, &netErr) {
			return true // timeout or temporary network error
		}
		// Connection refused, reset, etc.
		errMsg := err.Error()
		if strings.Contains(errMsg, "connection refused") ||
			strings.Contains(errMsg, "connection reset") ||
			strings.Contains(errMsg, "EOF") {
			return true
		}
	}

	// Retry on specific HTTP status codes.
	switch statusCode {
	case 429, 502, 503, 504:
		return true
	}

	// Don't retry other 4xx errors.
	return false
}

func (c *Client) doRequest(req *http.Request, dest interface{}) error {
	var lastErr error

	// Save the body for retries (needed for POST with body).
	var bodyBytes []byte
	if req.Body != nil {
		var err error
		bodyBytes, err = io.ReadAll(req.Body)
		if err != nil {
			return fmt.Errorf("reading request body: %w", err)
		}
		req.Body.Close()
	}

	for attempt := 0; attempt <= maxRetries; attempt++ {
		if attempt > 0 {
			// Exponential backoff: 1s, 2s, 4s with jitter.
			backoff := time.Duration(1<<uint(attempt-1)) * time.Second
			jitter := time.Duration(rand.Int63n(int64(backoff) / 2))
			sleepDur := backoff + jitter

			select {
			case <-req.Context().Done():
				return req.Context().Err()
			case <-time.After(sleepDur):
			}

			// Reconstruct the body reader for retry.
			if bodyBytes != nil {
				req.Body = io.NopCloser(bytes.NewReader(bodyBytes))
			}
		} else if bodyBytes != nil {
			// First attempt: reset the body reader.
			req.Body = io.NopCloser(bytes.NewReader(bodyBytes))
		}

		resp, err := c.httpClient.Do(req)
		if err != nil {
			lastErr = fmt.Errorf("request failed: %w", err)
			if isRetriable(err, 0, req.Method) && attempt < maxRetries {
				continue
			}
			return lastErr
		}

		data, readErr := io.ReadAll(io.LimitReader(resp.Body, maxResponseBytes))
		resp.Body.Close()
		if readErr != nil {
			lastErr = fmt.Errorf("reading response: %w", readErr)
			if attempt < maxRetries {
				continue
			}
			return lastErr
		}

		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			lastErr = fmt.Errorf("HTTP %d: %s", resp.StatusCode, string(data))
			if isRetriable(nil, resp.StatusCode, req.Method) && attempt < maxRetries {
				continue
			}
			return lastErr
		}

		if dest != nil {
			if err := json.Unmarshal(data, dest); err != nil {
				return fmt.Errorf("decoding response: %w", err)
			}
		}
		return nil
	}

	return lastErr
}
