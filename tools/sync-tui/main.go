package main

import (
	"fmt"
	"os"
	"path/filepath"

	tea "github.com/charmbracelet/bubbletea"
	"github.com/joho/godotenv"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/config"
	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/tui"
)

func main() {
	cfg, configPath, err := loadConfig()
	if err != nil {
		fmt.Fprintf(os.Stderr, "configuration error: %v\n", err)
		os.Exit(1)
	}

	app := tui.NewApp(cfg, configPath)
	p := tea.NewProgram(app, tea.WithAltScreen())
	if _, err := p.Run(); err != nil {
		fmt.Fprintf(os.Stderr, "application error: %v\n", err)
		os.Exit(1)
	}
}

// loadConfig tries, in order:
//  1. amplifi-sync.json in CWD or next to binary.
//  2. Legacy .env file (backward compat).
//  3. Interactive wizard to create a new amplifi-sync.json.
func loadConfig() (*config.Config, string, error) {
	// 1. Try JSON config.
	configPath := findConfigFile()
	if configPath != "" {
		cfg, err := config.LoadConfigFile(configPath)
		if err != nil {
			return nil, "", fmt.Errorf("loading %s: %w", configPath, err)
		}
		return cfg, configPath, nil
	}

	// 2. Try legacy .env.
	if envPath := findEnvFile(); envPath != "" {
		if err := godotenv.Load(envPath); err == nil {
			if cfg, err := config.LoadLegacyEnv(); err == nil {
				fmt.Fprintf(os.Stderr, "Note: loaded legacy .env — consider running the wizard to migrate to amplifi-sync.json\n")
				return cfg, "", nil
			}
		}
	}

	// 3. Launch wizard to create config.
	defaultPath := defaultConfigPath()
	cfg, err := runWizard(defaultPath, nil)
	if err != nil {
		return nil, "", err
	}
	return cfg, defaultPath, nil
}

// runWizard launches the first-run setup wizard and returns the resulting config.
func runWizard(configPath string, existing *config.Config) (*config.Config, error) {
	var model tea.Model
	if existing != nil {
		model = config.NewAddPairWizardModel(configPath, existing)
	} else {
		model = config.NewWizardModel(configPath)
	}

	p := tea.NewProgram(model, tea.WithAltScreen())
	finalModel, err := p.Run()
	if err != nil {
		return nil, fmt.Errorf("wizard error: %w", err)
	}

	// The wizard sends a WizardDoneMsg when it writes the file; reload from disk.
	_ = finalModel
	cfg, err := config.LoadConfigFile(configPath)
	if err != nil {
		return nil, fmt.Errorf("loading saved config: %w", err)
	}
	return cfg, nil
}

// findConfigFile searches for amplifi-sync.json in CWD, next to the binary,
// and in the default ~/.amplifi-sync/ directory.
func findConfigFile() string {
	candidates := []string{"amplifi-sync.json"}

	exe, err := os.Executable()
	if err == nil {
		candidates = append(candidates, filepath.Join(filepath.Dir(exe), "amplifi-sync.json"))
	}

	// Also check the default config directory.
	candidates = append(candidates, defaultConfigPath())

	for _, c := range candidates {
		if _, err := os.Stat(c); err == nil {
			return c
		}
	}
	return ""
}

// findEnvFile searches for .env in CWD and next to the binary.
func findEnvFile() string {
	candidates := []string{".env"}

	exe, err := os.Executable()
	if err == nil {
		candidates = append(candidates, filepath.Join(filepath.Dir(exe), ".env"))
	}

	for _, c := range candidates {
		if _, err := os.Stat(c); err == nil {
			return c
		}
	}
	return ""
}

// defaultConfigPath returns the default location for a new amplifi-sync.json.
func defaultConfigPath() string {
	home, err := os.UserHomeDir()
	if err != nil {
		return "amplifi-sync.json"
	}
	return filepath.Join(home, ".amplifi-sync", "amplifi-sync.json")
}
