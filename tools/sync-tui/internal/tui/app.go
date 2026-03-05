package tui

import (
	"fmt"
	"log"
	"strings"

	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/config"
	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/sync"
)

// Tab identifies the active view in the TUI.
type Tab int

const (
	TabDashboard Tab = iota
	TabFiles
	TabDatabase
	TabMedia
	TabLogs
	TabSettings
)

var tabNames = []string{
	"Dashboard",
	"Files",
	"Database",
	"Media",
	"Logs",
	"Settings",
}

var tabKeys = []string{"D", "F", "B", "M", "L", "S"}

// pairSwitchedMsg is sent internally when the active site pair changes.
type pairSwitchedMsg struct{ idx int }

// App is the root Bubbletea model that manages tab navigation and delegates
// to the active tab's model.
type App struct {
	cfg        *config.Config
	configPath string

	// Active site pair.
	activePairIdx int
	prodClient    *api.Client
	stagingClient *api.Client

	activeTab Tab
	width     int
	height    int

	// Tab models.
	dashboard DashboardModel
	files     FilesModel
	database  DatabaseModel
	media     MediaModel
	logs      LogsModel
	settings  SettingsModel

	// Track which tabs have been initialized for the current pair.
	initialized map[Tab]bool

	// Pair picker overlay.
	showPairPicker bool
	pickerCursor   int
}

// NewApp creates the root application model.
// configPath is where amplifi-sync.json lives (used by settings "add pair" flow).
func NewApp(cfg *config.Config, configPath string) App {
	backupMgr, err := sync.NewBackupManager(cfg.BackupDir, cfg.BackupRetention)
	if err != nil {
		log.Printf("WARNING: failed to initialize backup manager: %v", err)
		backupMgr = &sync.BackupManager{
			BaseDir:   "/tmp/amplifi-sync-backups",
			Retention: cfg.BackupRetention,
		}
	}

	app := App{
		cfg:           cfg,
		configPath:    configPath,
		activePairIdx: 0,
		activeTab:     TabDashboard,
		initialized:   map[Tab]bool{},
	}

	app.logs = NewLogsModel()
	app.settings = NewSettingsModel(cfg, backupMgr, configPath)
	app.buildClientsAndTabs(backupMgr)

	return app
}

// buildClientsAndTabs (re)creates API clients and tab models for the active pair.
func (a *App) buildClientsAndTabs(backupMgr *sync.BackupManager) {
	pair := a.cfg.ActivePair(a.activePairIdx)
	if pair == nil {
		return
	}

	a.prodClient = api.NewClient(pair.Prod.URL, pair.Prod.APIKey)
	a.stagingClient = api.NewClient(pair.Staging.URL, pair.Staging.APIKey)

	// Allow insecure for localhost dev.
	if strings.HasPrefix(pair.Prod.URL, "http://") {
		a.prodClient.AllowInsecure = true
	}
	if strings.HasPrefix(pair.Staging.URL, "http://") {
		a.stagingClient.AllowInsecure = true
	}

	a.dashboard = NewDashboardModel(a.prodClient, a.stagingClient)
	a.files = NewFilesModel(a.prodClient, a.stagingClient)
	a.database = NewDatabaseModel(a.prodClient, a.stagingClient, pair.Prod.URL, pair.Staging.URL)
	a.media = NewMediaModel(a.prodClient, a.stagingClient)
}

func (a App) Init() tea.Cmd {
	a.initialized[TabDashboard] = true
	return a.dashboard.Init()
}

func (a App) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	// Handle pair switcher overlay first.
	if a.showPairPicker {
		return a.updatePairPicker(msg)
	}

	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		a.width = msg.Width
		a.height = msg.Height

	case pairSwitchedMsg:
		a.activePairIdx = msg.idx
		a.initialized = map[Tab]bool{}
		a.buildClientsAndTabs(nil)
		a.initialized[a.activeTab] = true
		return a, a.initActiveTab()

	case tea.KeyMsg:
		switch msg.String() {
		case "q", "ctrl+c":
			return a, tea.Quit

		case "[":
			// Previous pair.
			if len(a.cfg.Pairs) > 1 {
				next := (a.activePairIdx - 1 + len(a.cfg.Pairs)) % len(a.cfg.Pairs)
				return a, func() tea.Msg { return pairSwitchedMsg{next} }
			}
		case "]":
			// Next pair.
			if len(a.cfg.Pairs) > 1 {
				next := (a.activePairIdx + 1) % len(a.cfg.Pairs)
				return a, func() tea.Msg { return pairSwitchedMsg{next} }
			}
		case "p":
			if len(a.cfg.Pairs) > 1 {
				a.showPairPicker = true
				a.pickerCursor = a.activePairIdx
				return a, nil
			}

		case "d":
			// Don't intercept on Database tab — "d" toggles dry-run there.
			if a.activeTab != TabDashboard && a.activeTab != TabDatabase {
				a.activeTab = TabDashboard
				return a, a.initTabIfNeeded(TabDashboard)
			}
		case "f":
			// Don't intercept on Media tab — "f" cycles filter there.
			if a.activeTab != TabFiles && a.activeTab != TabMedia {
				a.activeTab = TabFiles
				return a, a.initTabIfNeeded(TabFiles)
			}
		case "b":
			if a.activeTab != TabDatabase {
				a.activeTab = TabDatabase
				return a, a.initTabIfNeeded(TabDatabase)
			}
		case "m":
			if a.activeTab != TabMedia {
				a.activeTab = TabMedia
				return a, a.initTabIfNeeded(TabMedia)
			}
		case "l":
			if a.activeTab != TabLogs {
				a.activeTab = TabLogs
				return a, a.initTabIfNeeded(TabLogs)
			}
		case "s":
			if a.activeTab != TabSettings {
				a.activeTab = TabSettings
				return a, a.initTabIfNeeded(TabSettings)
			}
		}
	}

	// Delegate update to the active tab.
	var cmd tea.Cmd
	switch a.activeTab {
	case TabDashboard:
		a.dashboard, cmd = a.dashboard.Update(msg)
	case TabFiles:
		a.files, cmd = a.files.Update(msg)
	case TabDatabase:
		a.database, cmd = a.database.Update(msg)
	case TabMedia:
		a.media, cmd = a.media.Update(msg)
	case TabLogs:
		a.logs, cmd = a.logs.Update(msg)
	case TabSettings:
		a.settings, cmd = a.settings.Update(msg)
	}

	return a, cmd
}

func (a App) updatePairPicker(msg tea.Msg) (tea.Model, tea.Cmd) {
	if msg, ok := msg.(tea.KeyMsg); ok {
		switch msg.String() {
		case "esc", "p":
			a.showPairPicker = false
		case "up", "k":
			if a.pickerCursor > 0 {
				a.pickerCursor--
			}
		case "down", "j":
			if a.pickerCursor < len(a.cfg.Pairs)-1 {
				a.pickerCursor++
			}
		case "enter":
			a.showPairPicker = false
			if a.pickerCursor != a.activePairIdx {
				idx := a.pickerCursor
				return a, func() tea.Msg { return pairSwitchedMsg{idx} }
			}
		}
	}
	return a, nil
}

func (a App) View() string {
	var b strings.Builder

	b.WriteString(a.renderHeader())
	b.WriteString("\n")
	b.WriteString(a.renderTabs())
	b.WriteString("\n\n")

	if a.showPairPicker {
		b.WriteString(a.renderPairPicker())
	} else {
		switch a.activeTab {
		case TabDashboard:
			b.WriteString(a.dashboard.View())
		case TabFiles:
			b.WriteString(a.files.View())
		case TabDatabase:
			b.WriteString(a.database.View())
		case TabMedia:
			b.WriteString(a.media.View())
		case TabLogs:
			b.WriteString(a.logs.View())
		case TabSettings:
			b.WriteString(a.settings.View())
		}
	}

	b.WriteString("\n")
	b.WriteString(a.renderStatusBar())

	return b.String()
}

func (a App) renderPairPicker() string {
	var b strings.Builder
	b.WriteString(lipgloss.NewStyle().Bold(true).Render("Select Site Pair") + "\n\n")
	for i, p := range a.cfg.Pairs {
		cursor := "  "
		if i == a.pickerCursor {
			cursor = "> "
		}
		active := ""
		if i == a.activePairIdx {
			active = " " + SuccessStyle.Render("(active)")
		}
		b.WriteString(fmt.Sprintf("%s[%d] %s  %s%s\n", cursor, i+1, p.Name, p.Prod.URL, active))
	}
	b.WriteString("\n" + DimStyle.Render("↑↓ navigate  Enter select  Esc cancel"))
	return b.String()
}

func (a *App) initTabIfNeeded(tab Tab) tea.Cmd {
	if a.initialized[tab] {
		return nil
	}
	a.initialized[tab] = true
	switch tab {
	case TabDashboard:
		return a.dashboard.Init()
	case TabFiles:
		return a.files.Init()
	case TabDatabase:
		return a.database.Init()
	case TabMedia:
		return a.media.Init()
	case TabLogs:
		return a.logs.Init()
	case TabSettings:
		return a.settings.Init()
	}
	return nil
}

func (a *App) initActiveTab() tea.Cmd {
	return a.initTabIfNeeded(a.activeTab)
}

func (a App) renderHeader() string {
	pair := a.cfg.ActivePair(a.activePairIdx)
	pairLabel := ""
	if pair != nil {
		if len(a.cfg.Pairs) > 1 {
			pairLabel = fmt.Sprintf(" [%d/%d] %s", a.activePairIdx+1, len(a.cfg.Pairs), pair.Name)
		} else {
			pairLabel = fmt.Sprintf(" %s", pair.Name)
		}
	}
	title := " amplifi.sync" + pairLabel + " "
	return HeaderStyle.Width(a.clampWidth()).Render(title)
}

func (a App) renderTabs() string {
	var tabs []string
	for i, name := range tabNames {
		label := fmt.Sprintf("[%s] %s", tabKeys[i], name)
		if Tab(i) == a.activeTab {
			tabs = append(tabs, ActiveTabStyle.Render(label))
		} else {
			tabs = append(tabs, TabStyle.Render(label))
		}
	}
	return lipgloss.JoinHorizontal(lipgloss.Top, tabs...)
}

func (a App) renderStatusBar() string {
	pair := a.cfg.ActivePair(a.activePairIdx)
	var status string
	if pair != nil {
		prod := SuccessStyle.Render("PROD")
		staging := SuccessStyle.Render("STG")
		pairHint := ""
		if len(a.cfg.Pairs) > 1 {
			pairHint = "  [/] switch pair  P picker"
		}
		status = fmt.Sprintf(" %s: %s  |  %s: %s%s  |  Q quit",
			prod, pair.Prod.URL,
			staging, pair.Staging.URL,
			pairHint,
		)
	} else {
		status = " Q to quit"
	}
	return StatusBarStyle.Width(a.clampWidth()).Render(status)
}

func (a App) clampWidth() int {
	if a.width > 0 {
		return a.width
	}
	return 80
}
