package tui

import "github.com/charmbracelet/lipgloss"

// Brand and UI colors.
var (
	colorPrimary = lipgloss.Color("#78ea78") // amplifi green
	colorAccent  = lipgloss.Color("#5bb8f5") // blue accent
	colorDim     = lipgloss.Color("#555555")
	colorError   = lipgloss.Color("#ff5555")
	colorWarning = lipgloss.Color("#ffaa00")
	colorSuccess = lipgloss.Color("#78ea78")
	colorFg      = lipgloss.Color("#dddddd")
	colorBg      = lipgloss.Color("#1a1a2e")
	colorCard    = lipgloss.Color("#222244")
)

// HeaderStyle renders the top bar of the application.
var HeaderStyle = lipgloss.NewStyle().
	Bold(true).
	Foreground(colorPrimary).
	Background(lipgloss.Color("#111122")).
	Padding(0, 2).
	Width(80)

// TabStyle renders an inactive tab label.
var TabStyle = lipgloss.NewStyle().
	Foreground(colorDim).
	Padding(0, 2)

// ActiveTabStyle renders the currently selected tab label.
var ActiveTabStyle = lipgloss.NewStyle().
	Bold(true).
	Foreground(colorPrimary).
	Padding(0, 2).
	Underline(true)

// CardStyle renders a content card (e.g. site status panel).
var CardStyle = lipgloss.NewStyle().
	Border(lipgloss.RoundedBorder()).
	BorderForeground(colorDim).
	Padding(1, 2).
	Width(36)

// StatusBarStyle renders the bottom status bar.
var StatusBarStyle = lipgloss.NewStyle().
	Foreground(colorDim).
	Background(lipgloss.Color("#111122")).
	Padding(0, 2).
	Width(80)

// SuccessStyle renders success messages and indicators.
var SuccessStyle = lipgloss.NewStyle().
	Foreground(colorSuccess).
	Bold(true)

// ErrorStyle renders error messages and indicators.
var ErrorStyle = lipgloss.NewStyle().
	Foreground(colorError).
	Bold(true)

// WarningStyle renders warning messages and indicators.
var WarningStyle = lipgloss.NewStyle().
	Foreground(colorWarning)

// TitleStyle renders section titles within panels.
var TitleStyle = lipgloss.NewStyle().
	Bold(true).
	Foreground(colorPrimary).
	MarginBottom(1)

// DimStyle renders secondary/help text.
var DimStyle = lipgloss.NewStyle().
	Foreground(colorDim)

// LabelStyle renders field labels in key-value displays.
var LabelStyle = lipgloss.NewStyle().
	Foreground(colorAccent).
	Width(16)

// ValueStyle renders field values in key-value displays.
var ValueStyle = lipgloss.NewStyle().
	Foreground(colorFg)
