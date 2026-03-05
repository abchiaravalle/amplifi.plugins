package remote

import "fmt"

// ConnectionPool manages a shared SSH connection and provides access to SFTP
// and WP-CLI subsystems through it.
type ConnectionPool struct {
	ssh   *SSHManager
	sftp  *SFTPClient
	wpcli *WPCLIRunner
	host  string
}

// NewConnectionPool connects to the remote host via SSH and initializes both
// the SFTP client and WP-CLI runner.
func NewConnectionPool(host, user, keyPath string) (*ConnectionPool, error) {
	sshMgr := &SSHManager{}
	if err := sshMgr.Connect(host, user, keyPath); err != nil {
		return nil, fmt.Errorf("SSH connection to %s: %w", host, err)
	}

	sftpClient := &SFTPClient{}
	if err := sftpClient.Connect(sshMgr.Client()); err != nil {
		sshMgr.Close()
		return nil, fmt.Errorf("SFTP subsystem on %s: %w", host, err)
	}

	wpcli := NewWPCLIRunner(sshMgr)

	return &ConnectionPool{
		ssh:   sshMgr,
		sftp:  sftpClient,
		wpcli: wpcli,
		host:  host,
	}, nil
}

// SFTP returns the SFTP client for file operations.
func (p *ConnectionPool) SFTP() *SFTPClient {
	return p.sftp
}

// WPCLI returns the WP-CLI runner for remote WordPress commands.
func (p *ConnectionPool) WPCLI() *WPCLIRunner {
	return p.wpcli
}

// SSH returns the underlying SSH manager for direct command execution.
func (p *ConnectionPool) SSH() *SSHManager {
	return p.ssh
}

// Host returns the remote hostname this pool is connected to.
func (p *ConnectionPool) Host() string {
	return p.host
}

// Close tears down all subsystems and the SSH connection.
func (p *ConnectionPool) Close() error {
	var firstErr error

	if p.sftp != nil {
		if err := p.sftp.Close(); err != nil && firstErr == nil {
			firstErr = fmt.Errorf("closing SFTP: %w", err)
		}
	}

	if p.ssh != nil {
		if err := p.ssh.Close(); err != nil && firstErr == nil {
			firstErr = fmt.Errorf("closing SSH: %w", err)
		}
	}

	return firstErr
}
