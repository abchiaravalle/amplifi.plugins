package config

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/bubbles/textinput"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"
)

// wizardStep enumerates each configuration prompt.
type wizardStep int

const (
	stepName wizardStep = iota
	stepProdURL
	stepProdAPIKey
	stepProdSFTPHost
	stepProdSFTPUser
	stepProdSSHKeyPath
	stepStagingURL
	stepStagingAPIKey
	stepStagingSFTPHost
	stepStagingSFTPUser
	stepStagingSSHKeyPath
	stepBackupDir
	stepConfirm
	stepDone
)

var stepLabels = map[wizardStep]string{
	stepName:              "Site Pair Name",
	stepProdURL:           "Production Site URL",
	stepProdAPIKey:        "Production API Key",
	stepProdSFTPHost:      "Production SFTP Host (optional)",
	stepProdSFTPUser:      "Production SFTP User (optional)",
	stepProdSSHKeyPath:    "Production SSH Key Path (optional)",
	stepStagingURL:        "Staging Site URL",
	stepStagingAPIKey:     "Staging API Key",
	stepStagingSFTPHost:   "Staging SFTP Host (optional)",
	stepStagingSFTPUser:   "Staging SFTP User (optional)",
	stepStagingSSHKeyPath: "Staging SSH Key Path (optional)",
	stepBackupDir:         "Backup Directory",
}

var stepPlaceholders = map[wizardStep]string{
	stepName:              "My Production Site",
	stepProdURL:           "https://example.com",
	stepProdAPIKey:        "your-production-api-key",
	stepProdSFTPHost:      "example.sftp.wpengine.com",
	stepProdSFTPUser:      "example",
	stepProdSSHKeyPath:    "~/.ssh/wpengine_rsa",
	stepStagingURL:        "https://staging.example.com",
	stepStagingAPIKey:     "your-staging-api-key",
	stepStagingSFTPHost:   "staging.sftp.wpengine.com",
	stepStagingSFTPUser:   "staging",
	stepStagingSSHKeyPath: "~/.ssh/wpengine_rsa",
	stepBackupDir:         "~/.amplifi-sync/backups",
}

// WizardMode controls whether this is a first-run setup or adding a new pair.
type WizardMode int

const (
	WizardModeSetup  WizardMode = iota // First run — also asks for BACKUP_DIR.
	WizardModeAddPair                  // Adding a new pair to existing config.
)

// WizardDoneMsg is sent when the wizard completes successfully.
type WizardDoneMsg struct {
	ConfigPath string
	Config     *Config
}

// WizardModel is the Bubbletea model for the interactive config wizard.
type WizardModel struct {
	mode       WizardMode
	step       wizardStep
	input      textinput.Model
	values     map[wizardStep]string
	err        string
	written    bool
	configPath string
	existing   *Config // Existing config to append a pair to (WizardModeAddPair).
}

// NewWizardModel creates a first-run setup wizard.
// configPath is where amplifi-sync.json will be written.
func NewWizardModel(configPath string) WizardModel {
	return newWizard(WizardModeSetup, configPath, nil)
}

// NewAddPairWizardModel creates a wizard that appends a new pair to an
// existing config.
func NewAddPairWizardModel(configPath string, existing *Config) WizardModel {
	return newWizard(WizardModeAddPair, configPath, existing)
}

func newWizard(mode WizardMode, configPath string, existing *Config) WizardModel {
	ti := textinput.New()
	ti.Focus()
	ti.CharLimit = 256
	ti.Width = 60
	ti.Placeholder = stepPlaceholders[stepName]

	return WizardModel{
		mode:       mode,
		step:       stepName,
		input:      ti,
		values:     make(map[wizardStep]string),
		configPath: configPath,
		existing:   existing,
	}
}

func (m WizardModel) Init() tea.Cmd {
	return textinput.Blink
}

func (m WizardModel) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		switch msg.String() {
		case "ctrl+c", "esc":
			return m, tea.Quit

		case "enter":
			if m.step == stepConfirm {
				cfg, err := m.buildConfig()
				if err != nil {
					m.err = fmt.Sprintf("config error: %v", err)
					return m, nil
				}
				if err := SaveConfigFile(m.configPath, cfg); err != nil {
					m.err = fmt.Sprintf("failed to write config: %v", err)
					return m, nil
				}
				m.written = true
				m.step = stepDone
				return m, func() tea.Msg {
					return WizardDoneMsg{ConfigPath: m.configPath, Config: cfg}
				}
			}
			if m.step == stepDone {
				return m, tea.Quit
			}

			// Save current value and advance.
			m.values[m.step] = m.input.Value()
			next := m.step + 1

			// In AddPair mode, skip BACKUP_DIR (keep existing value).
			if m.mode == WizardModeAddPair && next == stepBackupDir {
				next = stepConfirm
			}

			m.step = next
			m.input.SetValue("")
			if ph, ok := stepPlaceholders[m.step]; ok {
				m.input.Placeholder = ph
			}
			return m, nil
		}
	}

	var cmd tea.Cmd
	m.input, cmd = m.input.Update(msg)
	return m, cmd
}

func (m WizardModel) View() string {
	titleStyle := lipgloss.NewStyle().
		Bold(true).
		Foreground(lipgloss.Color("#78ea78")).
		MarginBottom(1)

	labelStyle := lipgloss.NewStyle().
		Foreground(lipgloss.Color("#888888"))

	errStyle := lipgloss.NewStyle().
		Foreground(lipgloss.Color("#ff5555"))

	var b strings.Builder

	title := "amplifi.sync — Setup Wizard"
	if m.mode == WizardModeAddPair {
		title = "amplifi.sync — Add Site Pair"
	}
	b.WriteString(titleStyle.Render(title))
	b.WriteString("\n\n")

	if m.step == stepDone {
		if m.written {
			b.WriteString(lipgloss.NewStyle().Foreground(lipgloss.Color("#78ea78")).Render("Configuration saved."))
			b.WriteString("\n")
		}
		return b.String()
	}

	if m.step == stepConfirm {
		b.WriteString("Review your configuration:\n\n")
		for s := stepName; s <= stepBackupDir; s++ {
			// Skip BACKUP_DIR in AddPair mode.
			if m.mode == WizardModeAddPair && s == stepBackupDir {
				continue
			}
			label := stepLabels[s]
			val := m.values[s]
			if val == "" {
				val = "(empty)"
			}
			// Mask API keys.
			if s == stepProdAPIKey || s == stepStagingAPIKey {
				if len(val) > 8 {
					val = val[:4] + "..." + val[len(val)-4:]
				}
			}
			b.WriteString(fmt.Sprintf("  %s: %s\n", labelStyle.Render(label), val))
		}
		if m.mode == WizardModeAddPair && m.existing != nil {
			b.WriteString(fmt.Sprintf("\n  %s\n",
				labelStyle.Render(fmt.Sprintf("Backup Dir: %s (unchanged)", m.existing.BackupDir))))
		}
		b.WriteString("\nPress Enter to save, Esc to cancel.\n")
		if m.err != "" {
			b.WriteString(errStyle.Render(m.err))
			b.WriteString("\n")
		}
		return b.String()
	}

	// Progress counter.
	lastStep := int(stepBackupDir)
	if m.mode == WizardModeAddPair {
		lastStep = int(stepStagingSSHKeyPath)
	}
	total := lastStep + 1
	current := int(m.step) + 1
	b.WriteString(fmt.Sprintf("  Step %d of %d\n\n", current, total))

	// Section header.
	switch {
	case m.step == stepName:
		b.WriteString(labelStyle.Render("  -- Site Pair --"))
	case m.step <= stepProdSSHKeyPath:
		b.WriteString(labelStyle.Render("  -- Production Environment --"))
	case m.step <= stepStagingSSHKeyPath:
		b.WriteString(labelStyle.Render("  -- Staging Environment --"))
	default:
		b.WriteString(labelStyle.Render("  -- General --"))
	}
	b.WriteString("\n\n")

	b.WriteString(fmt.Sprintf("  %s\n", stepLabels[m.step]))
	b.WriteString(fmt.Sprintf("  %s\n\n", m.input.View()))
	b.WriteString(labelStyle.Render("  Enter to continue, Esc to cancel"))
	b.WriteString("\n")

	return b.String()
}

// buildConfig assembles a Config from the collected wizard values.
func (m WizardModel) buildConfig() (*Config, error) {
	backupDir := m.values[stepBackupDir]
	if backupDir == "" {
		backupDir = "~/.amplifi-sync/backups"
	}

	pair := SitePair{
		Name: m.values[stepName],
		Prod: SiteConfig{
			URL:        m.values[stepProdURL],
			APIKey:     m.values[stepProdAPIKey],
			SFTPHost:   m.values[stepProdSFTPHost],
			SFTPUser:   m.values[stepProdSFTPUser],
			SSHKeyPath: m.values[stepProdSSHKeyPath],
		},
		Staging: SiteConfig{
			URL:        m.values[stepStagingURL],
			APIKey:     m.values[stepStagingAPIKey],
			SFTPHost:   m.values[stepStagingSFTPHost],
			SFTPUser:   m.values[stepStagingSFTPUser],
			SSHKeyPath: m.values[stepStagingSSHKeyPath],
		},
	}
	if pair.Name == "" {
		pair.Name = fmt.Sprintf("Pair %d", 1)
	}

	var cfg *Config
	if m.mode == WizardModeAddPair && m.existing != nil {
		// Clone existing config and append the new pair.
		clone := *m.existing
		clone.Pairs = append(clone.Pairs, pair)
		cfg = &clone
	} else {
		cfg = &Config{
			Version:         ConfigVersion,
			BackupDir:       backupDir,
			BackupRetention: 10,
			Pairs:           []SitePair{pair},
		}
	}

	return cfg, cfg.validate()
}
