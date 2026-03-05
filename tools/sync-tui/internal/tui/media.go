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

type mediaLoadedMsg struct {
	diffs []sync.MediaDiff
	err   error
}

type mediaSyncDoneMsg struct {
	result *sync.MediaSyncResult
}

type MediaModel struct {
	prodClient    *api.Client
	stagingClient *api.Client
	diffs         []sync.MediaDiff
	cursor        int
	selected      map[int]bool
	loading       bool
	syncing       bool
	spinner       spinner.Model
	width         int
	height        int
	offset        int
	statusMsg     string
	filter        string // "all", "source_only", "target_only"
}

func NewMediaModel(prod, staging *api.Client) MediaModel {
	s := spinner.New()
	s.Spinner = spinner.Dot
	s.Style = lipgloss.NewStyle().Foreground(lipgloss.Color(colorPrimary))
	return MediaModel{
		prodClient:    prod,
		stagingClient: staging,
		selected:      make(map[int]bool),
		spinner:       s,
		filter:        "all",
	}
}

func (m MediaModel) Init() tea.Cmd {
	return tea.Batch(m.spinner.Tick, m.fetchMedia())
}

func (m MediaModel) Update(msg tea.Msg) (MediaModel, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height

	case spinner.TickMsg:
		var cmd tea.Cmd
		m.spinner, cmd = m.spinner.Update(msg)
		return m, cmd

	case mediaLoadedMsg:
		m.loading = false
		if msg.err != nil {
			m.statusMsg = fmt.Sprintf("Error: %v", msg.err)
			return m, nil
		}
		m.diffs = msg.diffs
		sourceOnly := 0
		targetOnly := 0
		both := 0
		for _, d := range m.diffs {
			switch d.Status {
			case sync.MediaOnlySource:
				sourceOnly++
			case sync.MediaOnlyTarget:
				targetOnly++
			case sync.MediaBothSides:
				both++
			}
		}
		m.statusMsg = fmt.Sprintf("%d media items: %d on both, %d prod only, %d staging only",
			len(m.diffs), both, sourceOnly, targetOnly)

	case mediaSyncDoneMsg:
		m.syncing = false
		r := msg.result
		m.statusMsg = fmt.Sprintf("Transferred %d items, %d errors", r.Transferred, len(r.Errors))
		m.selected = make(map[int]bool)
		return m, m.fetchMedia()

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
			max := len(m.filteredDiffs()) - 1
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
			for i := range m.filteredDiffs() {
				m.selected[i] = true
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys("n"))):
			m.selected = make(map[int]bool)
		case key.Matches(msg, key.NewBinding(key.WithKeys("f"))):
			switch m.filter {
			case "all":
				m.filter = "source_only"
			case "source_only":
				m.filter = "target_only"
			default:
				m.filter = "all"
			}
			m.cursor = 0
			m.offset = 0
		case key.Matches(msg, key.NewBinding(key.WithKeys("r"))):
			m.loading = true
			return m, tea.Batch(m.spinner.Tick, m.fetchMedia())
		case key.Matches(msg, key.NewBinding(key.WithKeys("right"))):
			return m, m.syncSelectedMedia(m.prodClient, m.stagingClient)
		case key.Matches(msg, key.NewBinding(key.WithKeys("left"))):
			return m, m.syncSelectedMedia(m.stagingClient, m.prodClient)
		}
	}

	return m, nil
}

func (m MediaModel) View() string {
	var b strings.Builder

	if m.loading {
		b.WriteString(m.spinner.View() + " Loading media libraries...\n")
		return b.String()
	}

	if m.syncing {
		b.WriteString(m.spinner.View() + " Transferring media...\n")
		return b.String()
	}

	header := lipgloss.NewStyle().Bold(true).Render("Media Comparison")
	b.WriteString(fmt.Sprintf("%s  [filter: %s]\n\n", header, m.filter))

	diffs := m.filteredDiffs()
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

		var name, status, size string
		switch d.Status {
		case sync.MediaBothSides:
			name = d.SourceItem.Title
			status = "✓"
			size = formatBytes(d.SourceItem.FileSize)
		case sync.MediaOnlySource:
			name = d.SourceItem.Title
			status = "+ prod"
			size = formatBytes(d.SourceItem.FileSize)
		case sync.MediaOnlyTarget:
			name = d.TargetItem.Title
			status = "- staging"
			size = formatBytes(d.TargetItem.FileSize)
		}

		b.WriteString(fmt.Sprintf("%s[%s] %-6s %-40s %10s\n", cursor, sel, status, truncate(name, 40), size))
	}

	b.WriteString("\n")
	selected := 0
	for _, v := range m.selected {
		if v {
			selected++
		}
	}
	b.WriteString(fmt.Sprintf("Selected: %d  |  %s\n", selected, m.statusMsg))
	b.WriteString("↑↓ navigate  SPACE select  F filter  → transfer to staging  ← transfer to prod  R refresh")

	return b.String()
}

func (m MediaModel) filteredDiffs() []sync.MediaDiff {
	if m.filter == "all" {
		return m.diffs
	}
	var filtered []sync.MediaDiff
	for _, d := range m.diffs {
		switch m.filter {
		case "source_only":
			if d.Status == sync.MediaOnlySource {
				filtered = append(filtered, d)
			}
		case "target_only":
			if d.Status == sync.MediaOnlyTarget {
				filtered = append(filtered, d)
			}
		}
	}
	return filtered
}

func (m MediaModel) fetchMedia() tea.Cmd {
	return func() tea.Msg {
		ctx := context.Background()
		sourceMedia, err := sync.FetchAllMedia(ctx, m.prodClient)
		if err != nil {
			return mediaLoadedMsg{err: fmt.Errorf("prod: %w", err)}
		}
		targetMedia, err := sync.FetchAllMedia(ctx, m.stagingClient)
		if err != nil {
			return mediaLoadedMsg{err: fmt.Errorf("staging: %w", err)}
		}
		diffs := sync.CompareMedia(sourceMedia, targetMedia)
		return mediaLoadedMsg{diffs: diffs}
	}
}

func (m *MediaModel) syncSelectedMedia(source, target *api.Client) tea.Cmd {
	diffs := m.filteredDiffs()
	var toSync []sync.MediaDiff
	for i, sel := range m.selected {
		if sel && i < len(diffs) {
			toSync = append(toSync, diffs[i])
		}
	}
	if len(toSync) == 0 {
		m.statusMsg = "No media selected"
		return nil
	}
	m.syncing = true
	return func() tea.Msg {
		ctx := context.Background()
		result := sync.SyncMedia(ctx, source, target, toSync, nil)
		return mediaSyncDoneMsg{result: result}
	}
}

func formatBytes(b int64) string {
	switch {
	case b >= 1<<30:
		return fmt.Sprintf("%.1f GB", float64(b)/(1<<30))
	case b >= 1<<20:
		return fmt.Sprintf("%.1f MB", float64(b)/(1<<20))
	case b >= 1<<10:
		return fmt.Sprintf("%.1f KB", float64(b)/(1<<10))
	default:
		return fmt.Sprintf("%d B", b)
	}
}

func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	return s[:max-3] + "..."
}
