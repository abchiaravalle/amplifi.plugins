package tui

import (
	"context"
	"fmt"
	"strings"

	"github.com/charmbracelet/bubbles/key"
	"github.com/charmbracelet/bubbles/spinner"
	"github.com/charmbracelet/bubbles/textinput"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/sync"
)

type dbTablesMsg struct {
	source *api.TablesResponse
	target *api.TablesResponse
	err    error
}

type dbSyncDoneMsg struct {
	result *sync.DatabaseSyncResult
	err    error
}

type DatabaseModel struct {
	prodClient    *api.Client
	stagingClient *api.Client
	prodURL       string
	stagingURL    string
	tableDiffs    []sync.TableDiff
	cursor        int
	selected      map[int]bool
	loading       bool
	syncing       bool
	dryRun        bool
	spinner       spinner.Model
	width         int
	height        int
	offset        int
	statusMsg     string
	searchInput   textinput.Model
	replaceInput  textinput.Model
	editingURLs   bool
	focusedInput  int
}

func NewDatabaseModel(prod, staging *api.Client, prodURL, stagingURL string) DatabaseModel {
	s := spinner.New()
	s.Spinner = spinner.Dot
	s.Style = lipgloss.NewStyle().Foreground(lipgloss.Color(colorPrimary))

	si := textinput.New()
	si.Placeholder = "https://prod.example.com"
	si.SetValue(prodURL)
	si.Width = 50

	ri := textinput.New()
	ri.Placeholder = "https://staging.example.com"
	ri.SetValue(stagingURL)
	ri.Width = 50

	return DatabaseModel{
		prodClient:    prod,
		stagingClient: staging,
		prodURL:       prodURL,
		stagingURL:    stagingURL,
		selected:      make(map[int]bool),
		spinner:       s,
		dryRun:        true,
		searchInput:   si,
		replaceInput:  ri,
	}
}

func (m DatabaseModel) Init() tea.Cmd {
	return tea.Batch(m.spinner.Tick, m.fetchTables())
}

func (m DatabaseModel) Update(msg tea.Msg) (DatabaseModel, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height

	case spinner.TickMsg:
		var cmd tea.Cmd
		m.spinner, cmd = m.spinner.Update(msg)
		return m, cmd

	case dbTablesMsg:
		m.loading = false
		if msg.err != nil {
			m.statusMsg = fmt.Sprintf("Error: %v", msg.err)
			return m, nil
		}
		ctx := context.Background()
		m.tableDiffs = sync.CompareTables(ctx, msg.source, msg.target)
		m.statusMsg = fmt.Sprintf("%d tables loaded", len(m.tableDiffs))

	case dbSyncDoneMsg:
		m.syncing = false
		if msg.err != nil {
			m.statusMsg = fmt.Sprintf("Sync error: %v", msg.err)
			return m, nil
		}
		r := msg.result
		if m.dryRun {
			m.statusMsg = fmt.Sprintf("[DRY RUN] Would sync %d tables, %d rows", r.TablesProcessed, r.RowsTransferred)
		} else {
			m.statusMsg = fmt.Sprintf("Synced %d tables, %d rows transferred", r.TablesProcessed, r.RowsTransferred)
			m.selected = make(map[int]bool)
			return m, m.fetchTables()
		}

	case tea.KeyMsg:
		if m.editingURLs {
			return m.updateURLInputs(msg)
		}
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
			if m.cursor < len(m.tableDiffs)-1 {
				m.cursor++
				viewHeight := m.height - 14
				if m.cursor >= m.offset+viewHeight {
					m.offset = m.cursor - viewHeight + 1
				}
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys(" "))):
			m.selected[m.cursor] = !m.selected[m.cursor]
		case key.Matches(msg, key.NewBinding(key.WithKeys("a"))):
			for i := range m.tableDiffs {
				m.selected[i] = true
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys("n"))):
			m.selected = make(map[int]bool)
		case key.Matches(msg, key.NewBinding(key.WithKeys("r"))):
			m.loading = true
			return m, tea.Batch(m.spinner.Tick, m.fetchTables())
		case key.Matches(msg, key.NewBinding(key.WithKeys("d"))):
			m.dryRun = !m.dryRun
		case key.Matches(msg, key.NewBinding(key.WithKeys("u"))):
			m.editingURLs = true
			m.focusedInput = 0
			m.searchInput.Focus()
		case key.Matches(msg, key.NewBinding(key.WithKeys("right"))):
			return m, m.syncSelected(m.prodClient, m.stagingClient, m.prodURL, m.stagingURL)
		case key.Matches(msg, key.NewBinding(key.WithKeys("left"))):
			return m, m.syncSelected(m.stagingClient, m.prodClient, m.stagingURL, m.prodURL)
		}
	}

	return m, nil
}

func (m DatabaseModel) updateURLInputs(msg tea.KeyMsg) (DatabaseModel, tea.Cmd) {
	switch msg.String() {
	case "esc":
		m.editingURLs = false
		m.searchInput.Blur()
		m.replaceInput.Blur()
		return m, nil
	case "tab":
		m.focusedInput = (m.focusedInput + 1) % 2
		if m.focusedInput == 0 {
			m.searchInput.Focus()
			m.replaceInput.Blur()
		} else {
			m.searchInput.Blur()
			m.replaceInput.Focus()
		}
		return m, nil
	case "enter":
		m.prodURL = m.searchInput.Value()
		m.stagingURL = m.replaceInput.Value()
		m.editingURLs = false
		m.searchInput.Blur()
		m.replaceInput.Blur()
		m.statusMsg = "URL mappings updated"
		return m, nil
	}

	var cmd tea.Cmd
	if m.focusedInput == 0 {
		m.searchInput, cmd = m.searchInput.Update(msg)
	} else {
		m.replaceInput, cmd = m.replaceInput.Update(msg)
	}
	return m, cmd
}

func (m DatabaseModel) View() string {
	var b strings.Builder

	if m.loading {
		b.WriteString(m.spinner.View() + " Fetching table info...\n")
		return b.String()
	}

	if m.syncing {
		b.WriteString(m.spinner.View() + " Syncing database...\n")
		return b.String()
	}

	header := lipgloss.NewStyle().Bold(true).Render("Database Comparison")
	dryLabel := "LIVE"
	if m.dryRun {
		dryLabel = "DRY RUN"
	}
	b.WriteString(fmt.Sprintf("%s  [%s]\n", header, dryLabel))

	// URL mapping.
	b.WriteString(fmt.Sprintf("  Search:  %s\n", m.prodURL))
	b.WriteString(fmt.Sprintf("  Replace: %s\n", m.stagingURL))

	if m.editingURLs {
		b.WriteString("\n  Edit URL mappings (TAB to switch, ENTER to save, ESC to cancel):\n")
		b.WriteString(fmt.Sprintf("  Search:  %s\n", m.searchInput.View()))
		b.WriteString(fmt.Sprintf("  Replace: %s\n", m.replaceInput.View()))
		return b.String()
	}

	b.WriteString("\n")

	// Table header.
	b.WriteString(fmt.Sprintf("  %-40s %10s %10s %10s\n", "Table", "Source", "Target", "Diff"))
	b.WriteString(fmt.Sprintf("  %s\n", strings.Repeat("─", 74)))

	viewHeight := m.height - 14
	if viewHeight < 5 {
		viewHeight = 20
	}

	end := m.offset + viewHeight
	if end > len(m.tableDiffs) {
		end = len(m.tableDiffs)
	}

	for i := m.offset; i < end; i++ {
		t := m.tableDiffs[i]
		cursor := "  "
		if i == m.cursor {
			cursor = "> "
		}
		sel := " "
		if m.selected[i] {
			sel = "*"
		}

		diffStr := ""
		if t.ExistsSource && t.ExistsTarget {
			if t.RowDiff > 0 {
				diffStr = fmt.Sprintf("+%d", t.RowDiff)
			} else if t.RowDiff < 0 {
				diffStr = fmt.Sprintf("%d", t.RowDiff)
			} else {
				diffStr = "="
			}
		} else if t.ExistsSource {
			diffStr = "new→"
		} else {
			diffStr = "←only"
		}

		srcRows := "-"
		if t.ExistsSource {
			srcRows = fmt.Sprintf("%d", t.SourceRows)
		}
		tgtRows := "-"
		if t.ExistsTarget {
			tgtRows = fmt.Sprintf("%d", t.TargetRows)
		}

		b.WriteString(fmt.Sprintf("%s[%s] %-40s %10s %10s %10s\n", cursor, sel, t.Name, srcRows, tgtRows, diffStr))
	}

	// Status.
	b.WriteString("\n")
	selected := 0
	for _, v := range m.selected {
		if v {
			selected++
		}
	}
	b.WriteString(fmt.Sprintf("Selected: %d  |  %s\n", selected, m.statusMsg))
	b.WriteString("↑↓ navigate  SPACE select  A all  D dry-run toggle  U url mapping  → sync to staging  ← sync to prod")

	return b.String()
}

func (m DatabaseModel) fetchTables() tea.Cmd {
	return func() tea.Msg {
		ctx := context.Background()
		source, err := m.prodClient.GetTables(ctx)
		if err != nil {
			return dbTablesMsg{err: fmt.Errorf("prod: %w", err)}
		}
		target, err := m.stagingClient.GetTables(ctx)
		if err != nil {
			return dbTablesMsg{err: fmt.Errorf("staging: %w", err)}
		}
		return dbTablesMsg{source: source, target: target}
	}
}

func (m *DatabaseModel) syncSelected(source, target *api.Client, sourceURL, targetURL string) tea.Cmd {
	var tables []string
	for i, sel := range m.selected {
		if sel && i < len(m.tableDiffs) {
			tables = append(tables, m.tableDiffs[i].Name)
		}
	}
	if len(tables) == 0 {
		m.statusMsg = "No tables selected"
		return nil
	}
	m.syncing = true
	dryRun := m.dryRun
	return func() tea.Msg {
		ctx := context.Background()
		opts := sync.DatabaseSyncOptions{
			Tables:      tables,
			URLMappings: sync.BuildURLMappings(sourceURL, targetURL),
			Mode:        "truncate",
			DryRun:      dryRun,
		}
		result, err := sync.SyncDatabase(ctx, source, target, opts, nil)
		return dbSyncDoneMsg{result: result, err: err}
	}
}
