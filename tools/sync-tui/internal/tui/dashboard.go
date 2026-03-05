package tui

import (
	"context"
	"fmt"
	"strings"

	"github.com/charmbracelet/bubbles/spinner"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
)

// statusResult carries the result of a status fetch for one environment.
type statusResult struct {
	env    string // "prod" or "staging"
	status *api.StatusResponse
	err    error
}

// DashboardModel is the Bubbletea model for the Dashboard tab.
type DashboardModel struct {
	prodClient    *api.Client
	stagingClient *api.Client

	prodStatus    *api.StatusResponse
	stagingStatus *api.StatusResponse
	prodErr       error
	stagingErr    error

	loading bool
	spinner spinner.Model
}

// NewDashboardModel creates the dashboard with API clients for both sites.
func NewDashboardModel(prod, staging *api.Client) DashboardModel {
	s := spinner.New()
	s.Spinner = spinner.Dot
	s.Style = lipgloss.NewStyle().Foreground(colorPrimary)

	return DashboardModel{
		prodClient:    prod,
		stagingClient: staging,
		loading:       true,
		spinner:       s,
	}
}

func (m DashboardModel) Init() tea.Cmd {
	return tea.Batch(
		m.spinner.Tick,
		m.fetchStatus("prod", m.prodClient),
		m.fetchStatus("staging", m.stagingClient),
	)
}

func (m DashboardModel) Update(msg tea.Msg) (DashboardModel, tea.Cmd) {
	switch msg := msg.(type) {
	case statusResult:
		switch msg.env {
		case "prod":
			m.prodStatus = msg.status
			m.prodErr = msg.err
		case "staging":
			m.stagingStatus = msg.status
			m.stagingErr = msg.err
		}
		// Stop loading when both have responded.
		if (m.prodStatus != nil || m.prodErr != nil) &&
			(m.stagingStatus != nil || m.stagingErr != nil) {
			m.loading = false
		}
		return m, nil

	case tea.KeyMsg:
		if msg.String() == "r" {
			m.loading = true
			m.prodStatus = nil
			m.stagingStatus = nil
			m.prodErr = nil
			m.stagingErr = nil
			return m, tea.Batch(
				m.spinner.Tick,
				m.fetchStatus("prod", m.prodClient),
				m.fetchStatus("staging", m.stagingClient),
			)
		}

	case spinner.TickMsg:
		var cmd tea.Cmd
		m.spinner, cmd = m.spinner.Update(msg)
		return m, cmd
	}

	return m, nil
}

func (m DashboardModel) View() string {
	if m.loading {
		return fmt.Sprintf("  %s Fetching site status...\n", m.spinner.View())
	}

	prodCard := m.renderSiteCard("Production", m.prodStatus, m.prodErr)
	stagingCard := m.renderSiteCard("Staging", m.stagingStatus, m.stagingErr)

	cards := lipgloss.JoinHorizontal(lipgloss.Top, prodCard, "  ", stagingCard)

	help := DimStyle.Render("  R to refresh")

	return cards + "\n\n" + help
}

func (m DashboardModel) renderSiteCard(name string, status *api.StatusResponse, err error) string {
	var b strings.Builder

	title := TitleStyle.Render(name)
	b.WriteString(title)
	b.WriteString("\n")

	if err != nil {
		indicator := ErrorStyle.Render("x disconnected")
		b.WriteString(indicator)
		b.WriteString("\n\n")
		b.WriteString(ErrorStyle.Render(fmt.Sprintf("Error: %v", err)))
		return CardStyle.Render(b.String())
	}

	if status == nil {
		b.WriteString(DimStyle.Render("no data"))
		return CardStyle.Render(b.String())
	}

	indicator := SuccessStyle.Render("* connected")
	b.WriteString(indicator)
	b.WriteString("\n\n")

	rows := []struct {
		label string
		value string
	}{
		{"Site URL", status.SiteURL},
		{"WP Version", status.WPVersion},
		{"PHP Version", status.PHPVersion},
		{"Active Theme", status.ActiveTheme},
		{"Plugins", fmt.Sprintf("%d", status.PluginCount)},
		{"Sync Plugin", status.SyncVersion},
	}

	for _, row := range rows {
		label := LabelStyle.Render(row.label)
		value := ValueStyle.Render(row.value)
		b.WriteString(fmt.Sprintf("%s %s\n", label, value))
	}

	return CardStyle.Render(b.String())
}

func (m DashboardModel) fetchStatus(env string, client *api.Client) tea.Cmd {
	return func() tea.Msg {
		ctx := context.Background()
		status, err := client.GetStatus(ctx)
		return statusResult{
			env:    env,
			status: status,
			err:    err,
		}
	}
}
