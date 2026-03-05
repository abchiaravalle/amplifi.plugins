package tui

import (
	"context"
	"fmt"
	"strings"

	"github.com/charmbracelet/bubbles/key"
	"github.com/charmbracelet/bubbles/spinner"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/sync"
)

// Messages for async operations.
type filesManifestMsg struct {
	source *api.FileManifest
	target *api.FileManifest
	err    error
}

type fileSyncDoneMsg struct {
	synced int
	errors []error
}

// FilesModel is the TUI model for the Files view.
type FilesModel struct {
	prodClient    *api.Client
	stagingClient *api.Client
	diffResult    *sync.FileDiffResult
	cursor        int
	selected      map[int]bool
	loading       bool
	syncing       bool
	spinner       spinner.Model
	width         int
	height        int
	offset        int // scroll offset
	statusMsg     string
	filterChanged bool // show only changed files
}

func NewFilesModel(prod, staging *api.Client) FilesModel {
	s := spinner.New()
	s.Spinner = spinner.Dot
	s.Style = lipgloss.NewStyle().Foreground(lipgloss.Color(colorPrimary))
	return FilesModel{
		prodClient:    prod,
		stagingClient: staging,
		selected:      make(map[int]bool),
		spinner:       s,
		filterChanged: true,
	}
}

func (m FilesModel) Init() tea.Cmd {
	return tea.Batch(m.spinner.Tick, m.fetchManifests())
}

func (m FilesModel) Update(msg tea.Msg) (FilesModel, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height

	case spinner.TickMsg:
		var cmd tea.Cmd
		m.spinner, cmd = m.spinner.Update(msg)
		return m, cmd

	case filesManifestMsg:
		m.loading = false
		if msg.err != nil {
			m.statusMsg = fmt.Sprintf("Error: %v", msg.err)
			return m, nil
		}
		m.diffResult = sync.CompareManifests(msg.source, msg.target)
		m.statusMsg = fmt.Sprintf("%d files compared: %d identical, %d modified, %d added, %d removed",
			len(m.diffResult.Diffs), m.diffResult.Identical, m.diffResult.Modified, m.diffResult.Added, m.diffResult.Removed)

	case fileSyncDoneMsg:
		m.syncing = false
		if len(msg.errors) > 0 {
			m.statusMsg = fmt.Sprintf("Synced %d files with %d errors", msg.synced, len(msg.errors))
		} else {
			m.statusMsg = fmt.Sprintf("Synced %d files successfully", msg.synced)
		}
		m.selected = make(map[int]bool)
		return m, m.fetchManifests()

	case tea.KeyMsg:
		if m.loading || m.syncing {
			return m, nil
		}
		switch {
		case key.Matches(msg, key.NewBinding(key.WithKeys("up", "k"))):
			if m.cursor > 0 {
				m.cursor--
				if m.cursor < m.offset {
					m.offset = m.cursor
				}
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys("down", "j"))):
			max := m.visibleCount() - 1
			if m.cursor < max {
				m.cursor++
				viewHeight := m.height - 10
				if m.cursor >= m.offset+viewHeight {
					m.offset = m.cursor - viewHeight + 1
				}
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys(" "))):
			m.selected[m.cursor] = !m.selected[m.cursor]
		case key.Matches(msg, key.NewBinding(key.WithKeys("a"))):
			// Select all visible.
			for i := 0; i < m.visibleCount(); i++ {
				m.selected[i] = true
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys("n"))):
			// Clear selection.
			m.selected = make(map[int]bool)
		case key.Matches(msg, key.NewBinding(key.WithKeys("f"))):
			m.filterChanged = !m.filterChanged
			m.cursor = 0
			m.offset = 0
		case key.Matches(msg, key.NewBinding(key.WithKeys("r"))):
			m.loading = true
			m.diffResult = nil
			return m, tea.Batch(m.spinner.Tick, m.fetchManifests())
		case key.Matches(msg, key.NewBinding(key.WithKeys("right"))):
			// Sync selected to staging.
			return m, m.syncSelected(m.prodClient, m.stagingClient)
		case key.Matches(msg, key.NewBinding(key.WithKeys("left"))):
			// Sync selected to prod.
			return m, m.syncSelected(m.stagingClient, m.prodClient)
		}
	}

	return m, nil
}

func (m FilesModel) View() string {
	var b strings.Builder

	if m.loading {
		b.WriteString(m.spinner.View() + " Fetching file manifests...\n")
		return b.String()
	}

	if m.syncing {
		b.WriteString(m.spinner.View() + " Syncing files...\n")
		return b.String()
	}

	if m.diffResult == nil {
		b.WriteString("No file data loaded. Press R to refresh.\n")
		return b.String()
	}

	// Header.
	header := lipgloss.NewStyle().Bold(true).Render("Files Comparison")
	filterLabel := "showing changes only"
	if !m.filterChanged {
		filterLabel = "showing all files"
	}
	b.WriteString(fmt.Sprintf("%s  (%s, F to toggle)\n\n", header, filterLabel))

	// File list.
	diffs := m.visibleDiffs()
	viewHeight := m.height - 10
	if viewHeight < 5 {
		viewHeight = 20
	}

	end := m.offset + viewHeight
	if end > len(diffs) {
		end = len(diffs)
	}

	for i := m.offset; i < end; i++ {
		d := diffs[i]
		cursor := "  "
		if i == m.cursor {
			cursor = "> "
		}
		sel := " "
		if m.selected[i] {
			sel = "*"
		}

		symbol := d.Status.Symbol()
		symbolStyle := lipgloss.NewStyle()
		switch d.Status {
		case sync.FileIdentical:
			symbolStyle = symbolStyle.Foreground(lipgloss.Color(colorDim))
		case sync.FileModified:
			symbolStyle = symbolStyle.Foreground(lipgloss.Color(colorWarning))
		case sync.FileOnlySource:
			symbolStyle = symbolStyle.Foreground(lipgloss.Color(colorPrimary))
		case sync.FileOnlyTarget:
			symbolStyle = symbolStyle.Foreground(lipgloss.Color(colorError))
		}

		b.WriteString(fmt.Sprintf("%s[%s] %s %s\n", cursor, sel, symbolStyle.Render(symbol), d.Path))
	}

	// Status bar.
	b.WriteString("\n")
	selected := 0
	for _, v := range m.selected {
		if v {
			selected++
		}
	}
	b.WriteString(fmt.Sprintf("Selected: %d  |  ", selected))
	b.WriteString(m.statusMsg + "\n")
	b.WriteString("↑↓ navigate  SPACE select  A all  N none  → sync to staging  ← sync to prod  R refresh")

	return b.String()
}

func (m FilesModel) visibleDiffs() []sync.FileDiff {
	if m.diffResult == nil {
		return nil
	}
	if !m.filterChanged {
		return m.diffResult.Diffs
	}
	var filtered []sync.FileDiff
	for _, d := range m.diffResult.Diffs {
		if d.Status != sync.FileIdentical {
			filtered = append(filtered, d)
		}
	}
	return filtered
}

func (m FilesModel) visibleCount() int {
	return len(m.visibleDiffs())
}

func (m FilesModel) fetchManifests() tea.Cmd {
	return func() tea.Msg {
		ctx := context.Background()
		source, err := m.prodClient.GetFilesManifest(ctx, "wp-content")
		if err != nil {
			return filesManifestMsg{err: fmt.Errorf("prod: %w", err)}
		}
		target, err := m.stagingClient.GetFilesManifest(ctx, "wp-content")
		if err != nil {
			return filesManifestMsg{err: fmt.Errorf("staging: %w", err)}
		}
		return filesManifestMsg{source: source, target: target}
	}
}

func (m *FilesModel) syncSelected(source, target *api.Client) tea.Cmd {
	diffs := m.visibleDiffs()
	var paths []string
	for i, sel := range m.selected {
		if sel && i < len(diffs) {
			paths = append(paths, diffs[i].Path)
		}
	}
	if len(paths) == 0 {
		m.statusMsg = "No files selected"
		return nil
	}
	m.syncing = true
	return func() tea.Msg {
		ctx := context.Background()
		synced, errs := sync.SyncFiles(ctx, source, target, paths, nil)
		return fileSyncDoneMsg{synced: synced, errors: errs}
	}
}
