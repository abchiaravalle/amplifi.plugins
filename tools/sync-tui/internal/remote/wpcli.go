package remote

import (
	"fmt"
	"strings"
)

// SearchReplaceOpts configures a WP-CLI search-replace operation.
type SearchReplaceOpts struct {
	DryRun     bool
	AllTables  bool
	Precise    bool
	SkipThemes bool
}

// WPCLIRunner executes WP-CLI commands over an SSH connection.
// Compatible with WP Engine's restricted SSH environment — does not use
// --allow-root or any flags that require elevated privileges.
type WPCLIRunner struct {
	ssh     *SSHManager
	wpPath  string // Optional: path to WP install if not default
}

// NewWPCLIRunner creates a runner bound to the given SSH manager.
func NewWPCLIRunner(ssh *SSHManager) *WPCLIRunner {
	return &WPCLIRunner{ssh: ssh}
}

// SetWPPath sets a custom path to the WordPress installation.
// WP Engine typically uses /home/wpe-user/sites/sitename/ or similar.
func (r *WPCLIRunner) SetWPPath(path string) {
	r.wpPath = path
}

func (r *WPCLIRunner) buildCmd(args ...string) string {
	parts := []string{"wp"}
	parts = append(parts, args...)
	if r.wpPath != "" {
		parts = append(parts, "--path="+shellQuote(r.wpPath))
	}
	return strings.Join(parts, " ")
}

// DBExport runs `wp db export` and writes the dump to the given remote path.
func (r *WPCLIRunner) DBExport(path string) (string, error) {
	cmd := r.buildCmd("db", "export", shellQuote(path))
	stdout, stderr, err := r.ssh.RunCommand(cmd)
	if err != nil {
		return "", fmt.Errorf("wp db export: %s: %w", strings.TrimSpace(stderr), err)
	}
	return strings.TrimSpace(stdout), nil
}

// DBImport runs `wp db import` against the given SQL dump file.
func (r *WPCLIRunner) DBImport(path string) (string, error) {
	cmd := r.buildCmd("db", "import", shellQuote(path))
	stdout, stderr, err := r.ssh.RunCommand(cmd)
	if err != nil {
		return "", fmt.Errorf("wp db import: %s: %w", strings.TrimSpace(stderr), err)
	}
	return strings.TrimSpace(stdout), nil
}

// SearchReplace runs `wp search-replace` with the given old/new strings.
func (r *WPCLIRunner) SearchReplace(old, new string, opts SearchReplaceOpts) (string, error) {
	args := []string{
		"search-replace",
		shellQuote(old),
		shellQuote(new),
	}

	if opts.DryRun {
		args = append(args, "--dry-run")
	}
	if opts.AllTables {
		args = append(args, "--all-tables")
	}
	if opts.Precise {
		args = append(args, "--precise")
	}
	if opts.SkipThemes {
		args = append(args, "--skip-themes")
	}

	cmd := r.buildCmd(args...)
	stdout, stderr, err := r.ssh.RunCommand(cmd)
	if err != nil {
		return "", fmt.Errorf("wp search-replace: %s: %w", strings.TrimSpace(stderr), err)
	}
	return strings.TrimSpace(stdout), nil
}

// CacheFlush runs `wp cache flush`.
func (r *WPCLIRunner) CacheFlush() (string, error) {
	cmd := r.buildCmd("cache", "flush")
	stdout, stderr, err := r.ssh.RunCommand(cmd)
	if err != nil {
		return "", fmt.Errorf("wp cache flush: %s: %w", strings.TrimSpace(stderr), err)
	}
	return strings.TrimSpace(stdout), nil
}

// ElementorFlushCSS runs `wp elementor flush-css` to regenerate Elementor CSS.
func (r *WPCLIRunner) ElementorFlushCSS() (string, error) {
	cmd := r.buildCmd("elementor", "flush-css")
	stdout, stderr, err := r.ssh.RunCommand(cmd)
	if err != nil {
		return "", fmt.Errorf("wp elementor flush-css: %s: %w", strings.TrimSpace(stderr), err)
	}
	return strings.TrimSpace(stdout), nil
}

// shellQuote wraps a string in single quotes, escaping any embedded single
// quotes for safe use in a shell command.
func shellQuote(s string) string {
	escaped := strings.ReplaceAll(s, "'", "'\"'\"'")
	return "'" + escaped + "'"
}
