package sync

import (
	"context"
	"fmt"
	"sort"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
)

// FileStatus indicates the comparison result for a file.
type FileStatus int

const (
	FileIdentical FileStatus = iota
	FileModified
	FileOnlySource
	FileOnlyTarget
)

func (s FileStatus) String() string {
	switch s {
	case FileIdentical:
		return "identical"
	case FileModified:
		return "modified"
	case FileOnlySource:
		return "only_source"
	case FileOnlyTarget:
		return "only_target"
	default:
		return "unknown"
	}
}

func (s FileStatus) Symbol() string {
	switch s {
	case FileIdentical:
		return "✓"
	case FileModified:
		return "⚠"
	case FileOnlySource:
		return "+"
	case FileOnlyTarget:
		return "-"
	default:
		return "?"
	}
}

// FileDiff represents the comparison result for a single file.
type FileDiff struct {
	Path        string
	Status      FileStatus
	SourceEntry *api.FileEntry
	TargetEntry *api.FileEntry
}

// FileDiffResult holds the full comparison between two file manifests.
type FileDiffResult struct {
	Diffs     []FileDiff
	Identical int
	Modified  int
	Added     int
	Removed   int
}

// CompareManifests compares two file manifests and returns the diff.
func CompareManifests(source, target *api.FileManifest) *FileDiffResult {
	result := &FileDiffResult{}

	// Index target files by path.
	targetMap := make(map[string]*api.FileEntry, len(target.Files))
	for i := range target.Files {
		if target.Files[i].Type == "file" {
			targetMap[target.Files[i].Path] = &target.Files[i]
		}
	}

	// Index source files by path.
	sourceMap := make(map[string]*api.FileEntry, len(source.Files))
	for i := range source.Files {
		if source.Files[i].Type == "file" {
			sourceMap[source.Files[i].Path] = &source.Files[i]
		}
	}

	// Compare source files against target.
	for path, src := range sourceMap {
		tgt, exists := targetMap[path]
		if !exists {
			result.Diffs = append(result.Diffs, FileDiff{
				Path:        path,
				Status:      FileOnlySource,
				SourceEntry: src,
			})
			result.Added++
			continue
		}

		if src.MD5 != tgt.MD5 {
			result.Diffs = append(result.Diffs, FileDiff{
				Path:        path,
				Status:      FileModified,
				SourceEntry: src,
				TargetEntry: tgt,
			})
			result.Modified++
		} else {
			result.Diffs = append(result.Diffs, FileDiff{
				Path:        path,
				Status:      FileIdentical,
				SourceEntry: src,
				TargetEntry: tgt,
			})
			result.Identical++
		}
	}

	// Find files only in target.
	for path, tgt := range targetMap {
		if _, exists := sourceMap[path]; !exists {
			result.Diffs = append(result.Diffs, FileDiff{
				Path:        path,
				Status:      FileOnlyTarget,
				TargetEntry: tgt,
			})
			result.Removed++
		}
	}

	// Sort by path for stable output.
	sort.Slice(result.Diffs, func(i, j int) bool {
		return result.Diffs[i].Path < result.Diffs[j].Path
	})

	return result
}

// SyncFile transfers a single file from source to target via REST API.
// It reads the file from source, then writes it to target.
func SyncFile(ctx context.Context, source, target *api.Client, path string) error {
	content, err := source.ReadFile(ctx, path)
	if err != nil {
		return fmt.Errorf("read from source: %w", err)
	}

	err = target.WriteFile(ctx, path, content.Content)
	if err != nil {
		return fmt.Errorf("write to target: %w", err)
	}

	return nil
}

// SyncFiles transfers multiple files from source to target.
// Returns the number of files synced and any errors.
func SyncFiles(ctx context.Context, source, target *api.Client, paths []string, progress func(current int, total int, path string)) (int, []error) {
	var errors []error
	synced := 0

	for i, path := range paths {
		if err := ctx.Err(); err != nil {
			errors = append(errors, fmt.Errorf("cancelled: %w", err))
			break
		}

		if progress != nil {
			progress(i+1, len(paths), path)
		}

		if err := SyncFile(ctx, source, target, path); err != nil {
			errors = append(errors, fmt.Errorf("%s: %w", path, err))
			continue
		}
		synced++
	}

	return synced, errors
}
