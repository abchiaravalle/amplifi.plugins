package config

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"
)

const ConfigVersion = 1

// SiteConfig holds connection details for a single WordPress environment.
type SiteConfig struct {
	URL        string `json:"url"`
	APIKey     string `json:"api_key"`
	SFTPHost   string `json:"sftp_host"`
	SFTPUser   string `json:"sftp_user"`
	SSHKeyPath string `json:"ssh_key_path"`
}

// SitePair is a named prod+staging pair.
type SitePair struct {
	Name    string     `json:"name"`
	Prod    SiteConfig `json:"prod"`
	Staging SiteConfig `json:"staging"`
}

// Config holds the full application configuration.
type Config struct {
	Version         int        `json:"version"`
	BackupDir       string     `json:"backup_dir"`
	BackupRetention int        `json:"backup_retention"`
	Pairs           []SitePair `json:"pairs"`

	// Deprecated flat fields kept for backward-compat reading of old .env-based
	// configs. Populated only by LoadLegacyEnv; never written to JSON.
	Prod    SiteConfig `json:"-"`
	Staging SiteConfig `json:"-"`
}

// ActivePair returns the SitePair at the given index, or nil if out of range.
func (c *Config) ActivePair(idx int) *SitePair {
	if idx < 0 || idx >= len(c.Pairs) {
		return nil
	}
	return &c.Pairs[idx]
}

// LoadConfigFile reads an amplifi-sync.json file and returns a validated Config.
func LoadConfigFile(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("reading config: %w", err)
	}

	var cfg Config
	if err := json.Unmarshal(data, &cfg); err != nil {
		return nil, fmt.Errorf("parsing config: %w", err)
	}

	cfg.BackupDir = expandHome(cfg.BackupDir)
	for i := range cfg.Pairs {
		cfg.Pairs[i].Prod.SSHKeyPath = expandHome(cfg.Pairs[i].Prod.SSHKeyPath)
		cfg.Pairs[i].Staging.SSHKeyPath = expandHome(cfg.Pairs[i].Staging.SSHKeyPath)
	}

	if cfg.BackupRetention == 0 {
		cfg.BackupRetention = 10
	}
	if cfg.BackupDir == "" {
		cfg.BackupDir = expandHome("~/.amplifi-sync/backups")
	}

	if len(cfg.Pairs) == 0 {
		return nil, fmt.Errorf("config has no site pairs — run the setup wizard")
	}
	if err := cfg.validate(); err != nil {
		return nil, err
	}
	return &cfg, nil
}

// SaveConfigFile writes the config to a JSON file (mode 0600).
func SaveConfigFile(path string, cfg *Config) error {
	if cfg.Version == 0 {
		cfg.Version = ConfigVersion
	}
	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return fmt.Errorf("encoding config: %w", err)
	}
	if err := os.MkdirAll(dirOf(path), 0700); err != nil {
		return fmt.Errorf("creating config dir: %w", err)
	}
	return os.WriteFile(path, data, 0600)
}

// LoadLegacyEnv builds a single-pair Config from the old PROD_* / STAGING_*
// environment variables (which must already be loaded via godotenv).
// Returns an error if neither PROD_SITE_URL nor STAGING_SITE_URL is set.
func LoadLegacyEnv() (*Config, error) {
	prodURL := envOrDefault("PROD_SITE_URL", "")
	stagingURL := envOrDefault("STAGING_SITE_URL", "")
	if prodURL == "" && stagingURL == "" {
		return nil, fmt.Errorf("no site configuration found")
	}

	pair := SitePair{
		Name: "Default",
		Prod: SiteConfig{
			URL:        prodURL,
			APIKey:     envOrDefault("PROD_API_KEY", ""),
			SFTPHost:   envOrDefault("PROD_SFTP_HOST", ""),
			SFTPUser:   envOrDefault("PROD_SFTP_USER", ""),
			SSHKeyPath: expandHome(envOrDefault("PROD_SSH_KEY_PATH", "")),
		},
		Staging: SiteConfig{
			URL:        stagingURL,
			APIKey:     envOrDefault("STAGING_API_KEY", ""),
			SFTPHost:   envOrDefault("STAGING_SFTP_HOST", ""),
			SFTPUser:   envOrDefault("STAGING_SFTP_USER", ""),
			SSHKeyPath: expandHome(envOrDefault("STAGING_SSH_KEY_PATH", "")),
		},
	}

	cfg := &Config{
		Version:         ConfigVersion,
		Pairs:           []SitePair{pair},
		BackupDir:       expandHome(envOrDefault("BACKUP_DIR", "~/.amplifi-sync/backups")),
		BackupRetention: envIntOrDefault("BACKUP_RETENTION", 10),
	}
	return cfg, cfg.validate()
}

func (c *Config) validate() error {
	for i, p := range c.Pairs {
		if p.Prod.URL == "" {
			return fmt.Errorf("pair %d (%q): prod URL is required", i+1, p.Name)
		}
		if p.Staging.URL == "" {
			return fmt.Errorf("pair %d (%q): staging URL is required", i+1, p.Name)
		}
	}
	return nil
}

func envOrDefault(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func envIntOrDefault(key string, fallback int) int {
	v := os.Getenv(key)
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func expandHome(path string) string {
	if strings.HasPrefix(path, "~/") {
		home, err := os.UserHomeDir()
		if err != nil {
			return path
		}
		return home + path[1:]
	}
	return path
}

func dirOf(path string) string {
	idx := strings.LastIndexAny(path, "/\\")
	if idx < 0 {
		return "."
	}
	return path[:idx]
}
