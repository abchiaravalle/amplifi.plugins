package sync

import (
	"context"
	"fmt"
	"strings"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
)

// MediaDiffStatus indicates whether a media item exists on one or both sides.
type MediaDiffStatus int

const (
	MediaBothSides MediaDiffStatus = iota
	MediaOnlySource
	MediaOnlyTarget
)

// MediaDiff represents the comparison of a media item.
type MediaDiff struct {
	Status     MediaDiffStatus
	SourceItem *api.MediaItem
	TargetItem *api.MediaItem
}

// MediaSyncResult holds the result of a media sync.
type MediaSyncResult struct {
	Transferred int
	Skipped     int
	Errors      []error
	URLMap      map[string]string // Old URL -> new URL mapping for content updates.
}

// CompareMedia compares media libraries from two environments.
// Matching is based on filename (basename of URL).
func CompareMedia(source, target []api.MediaItem) []MediaDiff {
	targetByFile := make(map[string]*api.MediaItem, len(target))
	for i := range target {
		name := basename(target[i].URL)
		targetByFile[name] = &target[i]
	}

	var diffs []MediaDiff

	sourceByFile := make(map[string]bool)
	for i := range source {
		name := basename(source[i].URL)
		sourceByFile[name] = true

		tgt, exists := targetByFile[name]
		if exists {
			diffs = append(diffs, MediaDiff{
				Status:     MediaBothSides,
				SourceItem: &source[i],
				TargetItem: tgt,
			})
		} else {
			diffs = append(diffs, MediaDiff{
				Status:     MediaOnlySource,
				SourceItem: &source[i],
			})
		}
	}

	for i := range target {
		name := basename(target[i].URL)
		if !sourceByFile[name] {
			diffs = append(diffs, MediaDiff{
				Status:     MediaOnlyTarget,
				TargetItem: &target[i],
			})
		}
	}

	return diffs
}

// SyncMedia transfers media items from source to target that don't exist on the target.
func SyncMedia(ctx context.Context, source, target *api.Client, diffs []MediaDiff, progress func(current, total int, name string)) *MediaSyncResult {
	result := &MediaSyncResult{
		URLMap: make(map[string]string),
	}

	var toTransfer []MediaDiff
	for _, d := range diffs {
		if d.Status == MediaOnlySource {
			toTransfer = append(toTransfer, d)
		}
	}

	for i, diff := range toTransfer {
		if err := ctx.Err(); err != nil {
			result.Errors = append(result.Errors, fmt.Errorf("cancelled: %w", err))
			break
		}

		if progress != nil {
			progress(i+1, len(toTransfer), diff.SourceItem.Title)
		}

		resp, err := target.ImportMedia(ctx, diff.SourceItem.URL, diff.SourceItem.Title)
		if err != nil {
			result.Errors = append(result.Errors, fmt.Errorf("%s: %w", diff.SourceItem.Title, err))
			continue
		}

		result.URLMap[diff.SourceItem.URL] = resp.URL
		result.Transferred++
	}

	return result
}

// FetchAllMedia fetches the complete media library from a site, paginating through all pages.
func FetchAllMedia(ctx context.Context, client *api.Client) ([]api.MediaItem, error) {
	var all []api.MediaItem
	page := 1
	for {
		if err := ctx.Err(); err != nil {
			return nil, err
		}
		resp, err := client.GetMedia(ctx, page)
		if err != nil {
			return nil, err
		}
		all = append(all, resp.Items...)
		if page >= resp.TotalPages {
			break
		}
		page++
	}
	return all, nil
}

func basename(url string) string {
	parts := strings.Split(url, "/")
	if len(parts) == 0 {
		return url
	}
	name := parts[len(parts)-1]
	// Remove query string.
	if idx := strings.Index(name, "?"); idx >= 0 {
		name = name[:idx]
	}
	return name
}
