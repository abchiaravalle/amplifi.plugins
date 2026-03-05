package remote

import (
	"fmt"
	"io"
	"os"
	"path/filepath"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"
)

const (
	defaultMaxFileSize  = 100 * 1024 * 1024 // 100 MB
	defaultMaxDirEntries = 50000
)

// DirEntry represents a single file or directory in a remote listing.
type DirEntry struct {
	Path    string
	Size    int64
	IsDir   bool
	ModTime string
}

// DirListResult holds the result of a directory listing.
type DirListResult struct {
	Entries   []DirEntry
	Truncated bool // True if the listing was cut short due to the entry limit.
}

// SFTPClient wraps a pkg/sftp client for file operations on a remote host.
type SFTPClient struct {
	client *sftp.Client
}

// Connect creates an SFTP subsystem from an existing SSH connection.
func (s *SFTPClient) Connect(sshClient *ssh.Client) error {
	if sshClient == nil {
		return fmt.Errorf("SSH client is nil")
	}

	client, err := sftp.NewClient(sshClient)
	if err != nil {
		return fmt.Errorf("creating SFTP client: %w", err)
	}

	s.client = client
	return nil
}

// ReadFile reads the contents of a remote file with a size limit.
// maxSize defaults to 100MB if set to 0.
func (s *SFTPClient) ReadFile(path string, maxSize int64) ([]byte, error) {
	if s.client == nil {
		return nil, fmt.Errorf("SFTP not connected")
	}

	if maxSize <= 0 {
		maxSize = defaultMaxFileSize
	}

	// Check file size before reading.
	stat, err := s.client.Stat(path)
	if err != nil {
		return nil, fmt.Errorf("stat remote file %s: %w", path, err)
	}
	if stat.Size() > maxSize {
		return nil, fmt.Errorf("remote file %s is %d bytes, exceeds limit of %d bytes", path, stat.Size(), maxSize)
	}

	f, err := s.client.Open(path)
	if err != nil {
		return nil, fmt.Errorf("opening remote file %s: %w", path, err)
	}
	defer f.Close()

	data, err := io.ReadAll(io.LimitReader(f, maxSize+1))
	if err != nil {
		return nil, fmt.Errorf("reading remote file %s: %w", path, err)
	}

	if int64(len(data)) > maxSize {
		return nil, fmt.Errorf("remote file %s exceeds size limit of %d bytes", path, maxSize)
	}

	return data, nil
}

// WriteFile writes data to a remote file, creating parent directories as
// needed. Cleans up the partial file on error.
func (s *SFTPClient) WriteFile(path string, data []byte) error {
	if s.client == nil {
		return fmt.Errorf("SFTP not connected")
	}

	dir := filepath.Dir(path)
	if err := s.client.MkdirAll(dir); err != nil {
		return fmt.Errorf("creating directory %s: %w", dir, err)
	}

	f, err := s.client.Create(path)
	if err != nil {
		return fmt.Errorf("creating remote file %s: %w", path, err)
	}

	if _, err := f.Write(data); err != nil {
		f.Close()
		// Clean up the partial file.
		_ = s.client.Remove(path)
		return fmt.Errorf("writing remote file %s: %w", path, err)
	}

	if err := f.Close(); err != nil {
		// Attempt cleanup on close error as well.
		_ = s.client.Remove(path)
		return fmt.Errorf("closing remote file %s: %w", path, err)
	}

	return nil
}

// DeleteFile removes a file from the remote host.
func (s *SFTPClient) DeleteFile(path string) error {
	if s.client == nil {
		return fmt.Errorf("SFTP not connected")
	}

	if err := s.client.Remove(path); err != nil {
		return fmt.Errorf("deleting remote file %s: %w", path, err)
	}

	return nil
}

// ListDir returns a recursive listing of all files and directories under the
// given path, up to the entry limit (default 50000).
func (s *SFTPClient) ListDir(path string) (*DirListResult, error) {
	if s.client == nil {
		return nil, fmt.Errorf("SFTP not connected")
	}

	result := &DirListResult{}
	err := s.walkDir(path, result)
	if err != nil {
		return nil, fmt.Errorf("listing directory %s: %w", path, err)
	}

	return result, nil
}

func (s *SFTPClient) walkDir(dir string, result *DirListResult) error {
	if len(result.Entries) >= defaultMaxDirEntries {
		result.Truncated = true
		return nil
	}

	items, err := s.client.ReadDir(dir)
	if err != nil {
		return err
	}

	for _, item := range items {
		if len(result.Entries) >= defaultMaxDirEntries {
			result.Truncated = true
			return nil
		}

		fullPath := filepath.Join(dir, item.Name())
		entry := DirEntry{
			Path:    fullPath,
			Size:    item.Size(),
			IsDir:   item.IsDir(),
			ModTime: item.ModTime().Format("2006-01-02T15:04:05Z"),
		}
		result.Entries = append(result.Entries, entry)

		if item.IsDir() {
			if err := s.walkDir(fullPath, result); err != nil {
				// Skip unreadable subdirectories.
				if !os.IsPermission(err) {
					return err
				}
			}
		}
	}

	return nil
}

// Close terminates the SFTP session.
func (s *SFTPClient) Close() error {
	if s.client == nil {
		return nil
	}
	err := s.client.Close()
	s.client = nil
	return err
}
