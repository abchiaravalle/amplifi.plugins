package remote

import (
	"bytes"
	"context"
	"fmt"
	"log"
	"net"
	"os"
	"path/filepath"
	"time"

	"golang.org/x/crypto/ssh"
	"golang.org/x/crypto/ssh/knownhosts"
)

const (
	sshTimeout        = 15 * time.Second
	commandTimeout    = 5 * time.Minute
	sshKeepalive      = 30 * time.Second
)

// SSHManager manages an SSH connection to a remote host.
type SSHManager struct {
	client *ssh.Client
	host   string
	user   string
}

// Connect establishes an SSH connection using key-based authentication.
// Uses known_hosts for host key verification when available, falling back to
// InsecureIgnoreHostKey with a warning if the file does not exist.
func (m *SSHManager) Connect(host, user, keyPath string) error {
	keyData, err := os.ReadFile(keyPath)
	if err != nil {
		return fmt.Errorf("reading SSH key %s: %w", keyPath, err)
	}

	signer, err := ssh.ParsePrivateKey(keyData)
	if err != nil {
		return fmt.Errorf("parsing SSH key: %w", err)
	}

	hostKeyCallback, err := loadHostKeyCallback()
	if err != nil {
		return fmt.Errorf("loading host key callback: %w", err)
	}

	config := &ssh.ClientConfig{
		User: user,
		Auth: []ssh.AuthMethod{
			ssh.PublicKeys(signer),
		},
		HostKeyCallback: hostKeyCallback,
		Timeout:         sshTimeout,
	}

	addr := host
	if _, _, err := net.SplitHostPort(host); err != nil {
		addr = host + ":22"
	}

	client, err := ssh.Dial("tcp", addr, config)
	if err != nil {
		return fmt.Errorf("SSH dial %s: %w", addr, err)
	}

	// Start SSH keepalive in background.
	go func() {
		ticker := time.NewTicker(sshKeepalive)
		defer ticker.Stop()
		for range ticker.C {
			if client == nil {
				return
			}
			// Send a keepalive request; ignore errors (connection may be closed).
			_, _, err := client.SendRequest("keepalive@openssh.com", true, nil)
			if err != nil {
				return
			}
		}
	}()

	m.client = client
	m.host = host
	m.user = user
	return nil
}

// loadHostKeyCallback returns a host key callback using ~/.ssh/known_hosts
// if available. Falls back to InsecureIgnoreHostKey with a logged warning.
func loadHostKeyCallback() (ssh.HostKeyCallback, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		log.Printf("WARNING: cannot determine home directory, using insecure host key verification: %v", err)
		return ssh.InsecureIgnoreHostKey(), nil
	}

	knownHostsPath := filepath.Join(home, ".ssh", "known_hosts")
	if _, err := os.Stat(knownHostsPath); os.IsNotExist(err) {
		log.Printf("WARNING: %s not found, using insecure host key verification", knownHostsPath)
		return ssh.InsecureIgnoreHostKey(), nil
	}

	callback, err := knownhosts.New(knownHostsPath)
	if err != nil {
		return nil, fmt.Errorf("parsing known_hosts %s: %w", knownHostsPath, err)
	}

	return callback, nil
}

// Client returns the underlying ssh.Client for use by SFTP or other subsystems.
func (m *SSHManager) Client() *ssh.Client {
	return m.client
}

// RunCommand executes a command on the remote host and returns stdout and
// stderr as separate strings. Has a default timeout of 5 minutes.
func (m *SSHManager) RunCommand(cmd string) (stdout, stderr string, err error) {
	if m.client == nil {
		return "", "", fmt.Errorf("SSH not connected")
	}

	ctx, cancel := context.WithTimeout(context.Background(), commandTimeout)
	defer cancel()

	session, err := m.client.NewSession()
	if err != nil {
		return "", "", fmt.Errorf("creating SSH session: %w", err)
	}

	var outBuf, errBuf bytes.Buffer
	session.Stdout = &outBuf
	session.Stderr = &errBuf

	// Run session.Run in a goroutine so we can enforce the timeout.
	done := make(chan error, 1)
	go func() {
		done <- session.Run(cmd)
	}()

	select {
	case <-ctx.Done():
		// Timeout: close the session to force the command to stop.
		session.Close()
		return outBuf.String(), errBuf.String(), fmt.Errorf("command timed out after %s", commandTimeout)
	case runErr := <-done:
		session.Close()
		if runErr != nil {
			return outBuf.String(), errBuf.String(), fmt.Errorf("command failed: %w", runErr)
		}
		return outBuf.String(), errBuf.String(), nil
	}
}

// Close terminates the SSH connection.
func (m *SSHManager) Close() error {
	if m.client == nil {
		return nil
	}
	err := m.client.Close()
	m.client = nil
	return err
}
