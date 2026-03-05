package sync

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"time"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
)

// Backup represents a stored backup with metadata.
type Backup struct {
	ID        string    `json:"id"`
	Timestamp time.Time `json:"timestamp"`
	Env       string    `json:"env"`  // "prod" or "staging"
	Type      string    `json:"type"` // "db", "files", or "full"
	Tables    []string  `json:"tables,omitempty"`
	Files     []string  `json:"files,omitempty"`
	SizeBytes int64     `json:"size_bytes"`
	Dir       string    `json:"dir"`
	Warnings  []string  `json:"warnings,omitempty"`
}

// BackupManager handles creating, listing, and restoring backups.
type BackupManager struct {
	BaseDir   string
	Retention int
}

// NewBackupManager creates a backup manager with the given base directory.
// Returns an error if the home directory cannot be resolved when baseDir starts with ~.
func NewBackupManager(baseDir string, retention int) (*BackupManager, error) {
	// Expand ~ to home directory.
	if len(baseDir) > 0 && baseDir[0] == '~' {
		home, err := os.UserHomeDir()
		if err != nil {
			return nil, fmt.Errorf("resolving home directory for backup path: %w", err)
		}
		baseDir = filepath.Join(home, baseDir[1:])
	}
	return &BackupManager{
		BaseDir:   baseDir,
		Retention: retention,
	}, nil
}

// CreateDBBackup exports all specified tables from a site and stores them locally.
// Table data is streamed per-page directly to JSON files to keep memory bounded.
// Returns warnings for non-fatal issues (e.g., SQL dump failure).
func (bm *BackupManager) CreateDBBackup(ctx context.Context, client *api.Client, env string, tables []string) (*Backup, error) {
	timestamp := time.Now()
	id := fmt.Sprintf("%s-%s", env, timestamp.Format("20060102-150405"))
	backupDir := filepath.Join(bm.BaseDir, id)

	if err := os.MkdirAll(backupDir, 0755); err != nil {
		return nil, fmt.Errorf("create backup dir: %w", err)
	}

	var totalSize int64
	var warnings []string

	// If no tables specified, get all.
	if len(tables) == 0 {
		resp, err := client.GetTables(ctx)
		if err != nil {
			return nil, fmt.Errorf("list tables: %w", err)
		}
		for _, t := range resp.Tables {
			tables = append(tables, t.Name)
		}
	}

	// Export each table as JSON, streaming per-page to disk.
	for _, table := range tables {
		if err := ctx.Err(); err != nil {
			return nil, err
		}

		filePath := filepath.Join(backupDir, table+".json")
		f, err := os.Create(filePath)
		if err != nil {
			return nil, fmt.Errorf("create file for %s: %w", table, err)
		}

		// Write opening bracket.
		if _, err := f.Write([]byte("[\n")); err != nil {
			f.Close()
			return nil, fmt.Errorf("write to %s: %w", table, err)
		}

		enc := json.NewEncoder(f)
		enc.SetIndent("", "  ")
		page := 1
		firstRow := true

		for {
			if err := ctx.Err(); err != nil {
				f.Close()
				return nil, err
			}

			export, err := client.ExportTable(ctx, table, page)
			if err != nil {
				f.Close()
				return nil, fmt.Errorf("export %s page %d: %w", table, page, err)
			}

			for _, row := range export.Rows {
				if !firstRow {
					if _, err := f.Write([]byte(",\n")); err != nil {
						f.Close()
						return nil, fmt.Errorf("write separator for %s: %w", table, err)
					}
				}
				firstRow = false
				if err := enc.Encode(row); err != nil {
					f.Close()
					return nil, fmt.Errorf("encode row for %s: %w", table, err)
				}
			}

			if page >= export.TotalPages {
				break
			}
			page++
		}

		// Write closing bracket.
		if _, err := f.Write([]byte("\n]\n")); err != nil {
			f.Close()
			return nil, fmt.Errorf("write closing for %s: %w", table, err)
		}

		stat, statErr := f.Stat()
		if err := f.Close(); err != nil {
			return nil, fmt.Errorf("close %s backup file: %w", table, err)
		}
		if statErr == nil {
			totalSize += stat.Size()
		}
	}

	// Also request a full SQL dump via the API (non-fatal).
	backupResp, err := client.DBBackup(ctx)
	if err != nil {
		warnings = append(warnings, fmt.Sprintf("SQL dump request failed: %v", err))
	} else {
		content, err := client.ReadFile(ctx, backupResp.Path)
		if err != nil {
			warnings = append(warnings, fmt.Sprintf("SQL dump download failed: %v", err))
		} else {
			decoded, err := decodeBase64(content.Content)
			if err != nil {
				warnings = append(warnings, fmt.Sprintf("SQL dump decode failed: %v", err))
			} else {
				sqlPath := filepath.Join(backupDir, "full-dump.sql")
				if err := os.WriteFile(sqlPath, decoded, 0644); err != nil {
					warnings = append(warnings, fmt.Sprintf("SQL dump write failed: %v", err))
				} else {
					totalSize += int64(len(decoded))
				}
			}
		}
	}

	backup := &Backup{
		ID:        id,
		Timestamp: timestamp,
		Env:       env,
		Type:      "db",
		Tables:    tables,
		SizeBytes: totalSize,
		Dir:       backupDir,
		Warnings:  warnings,
	}

	// Save metadata.
	meta, err := json.MarshalIndent(backup, "", "  ")
	if err != nil {
		return nil, fmt.Errorf("marshal backup metadata: %w", err)
	}
	if err := os.WriteFile(filepath.Join(backupDir, "backup.json"), meta, 0644); err != nil {
		return nil, fmt.Errorf("write backup metadata: %w", err)
	}

	// Prune old backups.
	bm.pruneOld()

	return backup, nil
}

// CreateFileBackup stores copies of files that are about to be overwritten.
// Tracks failed files and returns them as warnings. Returns an error if no
// files were successfully backed up.
func (bm *BackupManager) CreateFileBackup(ctx context.Context, client *api.Client, env string, paths []string) (*Backup, error) {
	timestamp := time.Now()
	id := fmt.Sprintf("%s-files-%s", env, timestamp.Format("20060102-150405"))
	backupDir := filepath.Join(bm.BaseDir, id)

	var totalSize int64
	var backedUp []string
	var warnings []string

	for _, path := range paths {
		if err := ctx.Err(); err != nil {
			return nil, err
		}

		content, err := client.ReadFile(ctx, path)
		if err != nil {
			warnings = append(warnings, fmt.Sprintf("read %s: %v", path, err))
			continue // File might not exist on target, that's fine.
		}

		decoded, err := decodeBase64(content.Content)
		if err != nil {
			warnings = append(warnings, fmt.Sprintf("decode %s: %v", path, err))
			continue
		}

		destPath := filepath.Join(backupDir, path)
		if err := os.MkdirAll(filepath.Dir(destPath), 0755); err != nil {
			warnings = append(warnings, fmt.Sprintf("mkdir for %s: %v", path, err))
			continue
		}
		if err := os.WriteFile(destPath, decoded, 0644); err != nil {
			warnings = append(warnings, fmt.Sprintf("write %s: %v", path, err))
			continue
		}

		totalSize += int64(len(decoded))
		backedUp = append(backedUp, path)
	}

	if len(backedUp) == 0 && len(paths) > 0 {
		return nil, fmt.Errorf("no files were successfully backed up out of %d attempted; warnings: %v", len(paths), warnings)
	}

	backup := &Backup{
		ID:        id,
		Timestamp: timestamp,
		Env:       env,
		Type:      "files",
		Files:     backedUp,
		SizeBytes: totalSize,
		Dir:       backupDir,
		Warnings:  warnings,
	}

	meta, err := json.MarshalIndent(backup, "", "  ")
	if err != nil {
		return nil, fmt.Errorf("marshal backup metadata: %w", err)
	}
	if err := os.WriteFile(filepath.Join(backupDir, "backup.json"), meta, 0644); err != nil {
		return nil, fmt.Errorf("write backup metadata: %w", err)
	}

	bm.pruneOld()

	return backup, nil
}

// ListBackups returns all stored backups, newest first.
func (bm *BackupManager) ListBackups() ([]Backup, error) {
	var backups []Backup

	entries, err := os.ReadDir(bm.BaseDir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil
		}
		return nil, err
	}

	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		metaPath := filepath.Join(bm.BaseDir, entry.Name(), "backup.json")
		data, err := os.ReadFile(metaPath)
		if err != nil {
			continue
		}
		var b Backup
		if err := json.Unmarshal(data, &b); err != nil {
			continue
		}
		backups = append(backups, b)
	}

	sort.Slice(backups, func(i, j int) bool {
		return backups[i].Timestamp.After(backups[j].Timestamp)
	})

	return backups, nil
}

// RestoreDB restores a database backup by importing JSON tables.
func (bm *BackupManager) RestoreDB(ctx context.Context, client *api.Client, backupID string) error {
	backupDir := filepath.Join(bm.BaseDir, backupID)
	metaPath := filepath.Join(backupDir, "backup.json")

	data, err := os.ReadFile(metaPath)
	if err != nil {
		return fmt.Errorf("read backup metadata: %w", err)
	}

	var backup Backup
	if err := json.Unmarshal(data, &backup); err != nil {
		return fmt.Errorf("parse backup metadata: %w", err)
	}

	for _, table := range backup.Tables {
		if err := ctx.Err(); err != nil {
			return err
		}

		jsonPath := filepath.Join(backupDir, table+".json")
		rowData, err := os.ReadFile(jsonPath)
		if err != nil {
			return fmt.Errorf("read %s backup: %w", table, err)
		}

		var rows []map[string]interface{}
		if err := json.Unmarshal(rowData, &rows); err != nil {
			return fmt.Errorf("parse %s backup: %w", table, err)
		}

		// Import in batches to avoid memory issues.
		for start := 0; start < len(rows); start += importBatchSize {
			if err := ctx.Err(); err != nil {
				return err
			}

			end := start + importBatchSize
			if end > len(rows) {
				end = len(rows)
			}
			batch := rows[start:end]

			// Get a fresh confirmation token for each batch.
			tablesResp, err := client.GetTables(ctx)
			if err != nil {
				return fmt.Errorf("get token for %s: %w", table, err)
			}

			tok := tablesResp.ConfirmationTokens.Import
			_, err = client.ImportTable(ctx, table, batch, tok.TokenID, tok.Token, "truncate")
			if err != nil {
				return fmt.Errorf("import %s batch at %d: %w", table, start, err)
			}
		}
	}

	return nil
}

func (bm *BackupManager) pruneOld() {
	backups, err := bm.ListBackups()
	if err != nil || len(backups) <= bm.Retention {
		return
	}

	// Remove oldest beyond retention.
	for _, b := range backups[bm.Retention:] {
		os.RemoveAll(b.Dir)
	}
}

func decodeBase64(encoded string) ([]byte, error) {
	return base64.StdEncoding.DecodeString(encoded)
}
