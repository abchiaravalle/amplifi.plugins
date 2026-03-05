package tui

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/bubbles/key"
	"github.com/charmbracelet/bubbles/textinput"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/config"
	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/sync"
)

// SettingsModel is the TUI model for the settings view.
type SettingsModel struct {
	cfg           *config.Config
	configPath    string
	backupMgr     *sync.BackupManager
	backups       []sync.Backup
	inputs        []textinput.Model
	labels        []string
	cursor        int
	editing       bool
	activeSection string // "config" or "backups"
	width         int
	height        int
	statusMsg     string
}

func NewSettingsModel(cfg *config.Config, backupMgr *sync.BackupManager, configPath string) SettingsModel {
	labels := []string{
		"Prod URL",
		"Prod API Key",
		"Prod SFTP Host",
		"Prod SFTP User",
		"Staging URL",
		"Staging API Key",
		"Staging SFTP Host",
		"Staging SFTP User",
		"SSH Key Path",
		"Backup Dir",
	}

	inputs := make([]textinput.Model, len(labels))
	values := []string{
		cfg.Prod.URL,
		cfg.Prod.APIKey,
		cfg.Prod.SFTPHost,
		cfg.Prod.SFTPUser,
		cfg.Staging.URL,
		cfg.Staging.APIKey,
		cfg.Staging.SFTPHost,
		cfg.Staging.SFTPUser,
		cfg.Prod.SSHKeyPath,
		cfg.BackupDir,
	}

	for i := range inputs {
		inputs[i] = textinput.New()
		inputs[i].SetValue(values[i])
		inputs[i].Width = 60
		if strings.Contains(strings.ToLower(labels[i]), "key") {
			inputs[i].EchoMode = textinput.EchoPassword
		}
	}

	return SettingsModel{
		cfg:           cfg,
		configPath:    configPath,
		backupMgr:     backupMgr,
		labels:        labels,
		inputs:        inputs,
		activeSection: "config",
	}
}

func (m SettingsModel) Init() tea.Cmd {
	return m.loadBackups()
}

func (m SettingsModel) Update(msg tea.Msg) (SettingsModel, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height

	case backupsLoadedMsg:
		m.backups = msg.backups

	case tea.KeyMsg:
		if m.editing {
			return m.updateEditing(msg)
		}

		switch {
		case key.Matches(msg, key.NewBinding(key.WithKeys("tab"))):
			if m.activeSection == "config" {
				m.activeSection = "backups"
			} else {
				m.activeSection = "config"
			}
			m.cursor = 0

		case key.Matches(msg, key.NewBinding(key.WithKeys("up", "k"))):
			if m.cursor > 0 {
				m.cursor--
			}

		case key.Matches(msg, key.NewBinding(key.WithKeys("down", "j"))):
			maxCursor := len(m.labels) - 1
			if m.activeSection == "backups" {
				maxCursor = len(m.backups) - 1
			}
			if m.cursor < maxCursor {
				m.cursor++
			}

		case key.Matches(msg, key.NewBinding(key.WithKeys("enter"))):
			if m.activeSection == "config" {
				m.editing = true
				m.inputs[m.cursor].Focus()
			}

		case key.Matches(msg, key.NewBinding(key.WithKeys("r"))):
			return m, m.loadBackups()
		}
	}

	return m, nil
}

func (m SettingsModel) updateEditing(msg tea.KeyMsg) (SettingsModel, tea.Cmd) {
	switch msg.String() {
	case "esc", "enter":
		m.editing = false
		m.inputs[m.cursor].Blur()
		m.statusMsg = fmt.Sprintf("Updated %s", m.labels[m.cursor])
		return m, nil
	}

	var cmd tea.Cmd
	m.inputs[m.cursor], cmd = m.inputs[m.cursor].Update(msg)
	return m, cmd
}

func (m SettingsModel) View() string {
	var b strings.Builder

	header := lipgloss.NewStyle().Bold(true).Render("Settings")
	b.WriteString(header + "\n\n")

	// Section tabs.
	configTab := "Config"
	backupsTab := "Backups"
	if m.activeSection == "config" {
		configTab = lipgloss.NewStyle().Bold(true).Underline(true).Render(configTab)
	} else {
		backupsTab = lipgloss.NewStyle().Bold(true).Underline(true).Render(backupsTab)
	}
	b.WriteString(fmt.Sprintf("  %s  |  %s  (TAB to switch)\n\n", configTab, backupsTab))

	if m.activeSection == "config" {
		for i, label := range m.labels {
			cursor := "  "
			if i == m.cursor {
				cursor = "> "
			}

			if m.editing && i == m.cursor {
				b.WriteString(fmt.Sprintf("%s%-18s %s\n", cursor, label+":", m.inputs[i].View()))
			} else {
				val := m.inputs[i].Value()
				if strings.Contains(strings.ToLower(label), "key") && len(val) > 8 {
					val = val[:4] + "..." + val[len(val)-4:]
				}
				b.WriteString(fmt.Sprintf("%s%-18s %s\n", cursor, label+":", val))
			}
		}
	} else {
		if len(m.backups) == 0 {
			b.WriteString("  No backups found.\n")
		} else {
			b.WriteString(fmt.Sprintf("  %-30s %-10s %-8s %10s\n", "ID", "Env", "Type", "Size"))
			b.WriteString(fmt.Sprintf("  %s\n", strings.Repeat("─", 62)))
			for i, bk := range m.backups {
				cursor := "  "
				if i == m.cursor {
					cursor = "> "
				}
				b.WriteString(fmt.Sprintf("%s%-30s %-10s %-8s %10s\n",
					cursor, bk.ID, bk.Env, bk.Type, formatBytes(bk.SizeBytes)))
			}
		}
	}

	b.WriteString("\n")
	if m.statusMsg != "" {
		b.WriteString(m.statusMsg + "\n")
	}
	if m.configPath != "" {
		b.WriteString(fmt.Sprintf("\n  Config: %s\n", m.configPath))
		if len(m.cfg.Pairs) > 1 {
			b.WriteString(fmt.Sprintf("  Site pairs: %d  (use [ ] to switch, P to pick)\n", len(m.cfg.Pairs)))
		}
	}
	b.WriteString("↑↓ navigate  ENTER edit  TAB switch section  R refresh backups")

	return b.String()
}

type backupsLoadedMsg struct {
	backups []sync.Backup
}

func (m SettingsModel) loadBackups() tea.Cmd {
	return func() tea.Msg {
		backups, _ := m.backupMgr.ListBackups()
		return backupsLoadedMsg{backups: backups}
	}
}
