package tui

import (
	"fmt"
	"strings"
	"time"

	"github.com/charmbracelet/bubbles/key"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"
)

// LogEntry represents a single activity log entry.
type LogEntry struct {
	Timestamp time.Time
	Level     string // "info", "warn", "error", "success"
	Action    string
	Details   string
}

// LogsModel is the TUI model for the activity log view.
type LogsModel struct {
	entries []LogEntry
	cursor  int
	offset  int
	width   int
	height  int
	filter  string // "" (all), "error", "warn", "info", "success"
}

func NewLogsModel() LogsModel {
	return LogsModel{
		filter: "",
	}
}

// AddEntry appends a log entry.
func (m *LogsModel) AddEntry(level, action, details string) {
	m.entries = append(m.entries, LogEntry{
		Timestamp: time.Now(),
		Level:     level,
		Action:    action,
		Details:   details,
	})
}

func (m LogsModel) Init() tea.Cmd {
	return nil
}

func (m LogsModel) Update(msg tea.Msg) (LogsModel, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height

	case tea.KeyMsg:
		switch {
		case key.Matches(msg, key.NewBinding(key.WithKeys("up", "k"))):
			if m.cursor > 0 {
				m.cursor--
				if m.cursor < m.offset {
					m.offset = m.cursor
				}
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys("down", "j"))):
			max := len(m.filteredEntries()) - 1
			if m.cursor < max {
				m.cursor++
				viewHeight := m.height - 8
				if m.cursor >= m.offset+viewHeight {
					m.offset = m.cursor - viewHeight + 1
				}
			}
		case key.Matches(msg, key.NewBinding(key.WithKeys("f"))):
			switch m.filter {
			case "":
				m.filter = "error"
			case "error":
				m.filter = "warn"
			case "warn":
				m.filter = "info"
			case "info":
				m.filter = "success"
			default:
				m.filter = ""
			}
			m.cursor = 0
			m.offset = 0
		case key.Matches(msg, key.NewBinding(key.WithKeys("c"))):
			m.entries = nil
			m.cursor = 0
			m.offset = 0
		}
	}

	return m, nil
}

func (m LogsModel) View() string {
	var b strings.Builder

	header := lipgloss.NewStyle().Bold(true).Render("Activity Log")
	filterLabel := "all"
	if m.filter != "" {
		filterLabel = m.filter
	}
	b.WriteString(fmt.Sprintf("%s  [filter: %s]  (%d entries)\n\n", header, filterLabel, len(m.filteredEntries())))

	entries := m.filteredEntries()

	if len(entries) == 0 {
		b.WriteString("  No log entries yet.\n")
		b.WriteString("\n  Activity will appear here as you use sync operations.\n")
		b.WriteString("\n↑↓ navigate  F filter  C clear")
		return b.String()
	}

	viewHeight := m.height - 8
	if viewHeight < 5 {
		viewHeight = 20
	}

	end := m.offset + viewHeight
	if end > len(entries) {
		end = len(entries)
	}

	for i := m.offset; i < end; i++ {
		e := entries[i]
		cursor := "  "
		if i == m.cursor {
			cursor = "> "
		}

		levelStyle := lipgloss.NewStyle()
		switch e.Level {
		case "error":
			levelStyle = levelStyle.Foreground(lipgloss.Color(colorError))
		case "warn":
			levelStyle = levelStyle.Foreground(lipgloss.Color(colorWarning))
		case "success":
			levelStyle = levelStyle.Foreground(lipgloss.Color(colorPrimary))
		default:
			levelStyle = levelStyle.Foreground(lipgloss.Color(colorDim))
		}

		ts := e.Timestamp.Format("15:04:05")
		level := levelStyle.Render(fmt.Sprintf("%-7s", e.Level))
		b.WriteString(fmt.Sprintf("%s%s %s %s", cursor, ts, level, e.Action))
		if e.Details != "" {
			b.WriteString(fmt.Sprintf(" — %s", e.Details))
		}
		b.WriteString("\n")
	}

	b.WriteString("\n↑↓ navigate  F filter  C clear")

	return b.String()
}

func (m LogsModel) filteredEntries() []LogEntry {
	if m.filter == "" {
		return m.entries
	}
	var filtered []LogEntry
	for _, e := range m.entries {
		if e.Level == m.filter {
			filtered = append(filtered, e)
		}
	}
	return filtered
}
