package sync

import (
	"context"
	"fmt"
	"strings"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/serializer"
)

const importBatchSize = 500

// TableDiff represents the comparison of a table between two environments.
type TableDiff struct {
	Name         string
	SourceRows   int
	TargetRows   int
	RowDiff      int
	ExistsSource bool
	ExistsTarget bool
}

// URLMapping defines a search/replace pair for URL transformation.
type URLMapping struct {
	Search  string
	Replace string
}

// DatabaseSyncOptions configures a database sync operation.
type DatabaseSyncOptions struct {
	Tables            []string          // Tables to sync (empty = all).
	URLMappings       []URLMapping      // URL replacements.
	Mode              string            // "truncate" or "merge".
	DryRun            bool              // If true, just report what would change.
	AttachmentIDMap   AttachmentIDMap   // Optional: remap attachment post IDs after URL replace.
	RemapAttachments  bool              // If true, apply attachment ID remapping.
}

// DatabaseSyncResult holds the result of a database sync.
type DatabaseSyncResult struct {
	TablesProcessed int
	RowsTransferred int
	URLReplacements int
	Errors          []error
	DryRunReport    []string
}

// CompareTables compares table lists from two environments.
func CompareTables(ctx context.Context, source, target *api.TablesResponse) []TableDiff {
	sourceMap := make(map[string]api.TableInfo)
	for _, t := range source.Tables {
		sourceMap[t.Name] = t
	}

	targetMap := make(map[string]api.TableInfo)
	for _, t := range target.Tables {
		targetMap[t.Name] = t
	}

	var diffs []TableDiff

	// Tables in source.
	for name, st := range sourceMap {
		tt, exists := targetMap[name]
		diff := TableDiff{
			Name:         name,
			SourceRows:   st.Rows,
			ExistsSource: true,
			ExistsTarget: exists,
		}
		if exists {
			diff.TargetRows = tt.Rows
			diff.RowDiff = st.Rows - tt.Rows
		}
		diffs = append(diffs, diff)
	}

	// Tables only in target.
	for name, tt := range targetMap {
		if _, exists := sourceMap[name]; !exists {
			diffs = append(diffs, TableDiff{
				Name:         name,
				TargetRows:   tt.Rows,
				ExistsSource: false,
				ExistsTarget: true,
			})
		}
	}

	return diffs
}

// SyncTable exports a table from source and imports it to target with URL
// replacements. Data is processed in batches of importBatchSize rows to keep
// memory bounded.
func SyncTable(ctx context.Context, source, target *api.Client, table string, opts DatabaseSyncOptions) (*DatabaseSyncResult, error) {
	result := &DatabaseSyncResult{}

	// For dry-run we still need to count rows.
	if opts.DryRun {
		var totalRows int
		page := 1
		for {
			if err := ctx.Err(); err != nil {
				return nil, err
			}
			export, err := source.ExportTable(ctx, table, page)
			if err != nil {
				return nil, fmt.Errorf("export page %d: %w", page, err)
			}
			totalRows += len(export.Rows)
			if page >= export.TotalPages {
				break
			}
			page++
		}
		result.RowsTransferred = totalRows
		result.TablesProcessed = 1
		result.DryRunReport = append(result.DryRunReport,
			fmt.Sprintf("Table %s: %d rows would be transferred", table, totalRows),
		)
		return result, nil
	}

	// Stream export-transform-import in batches of importBatchSize.
	page := 1
	totalRows := 0
	batch := make([]map[string]interface{}, 0, importBatchSize)

	for {
		if err := ctx.Err(); err != nil {
			return nil, err
		}

		export, err := source.ExportTable(ctx, table, page)
		if err != nil {
			return nil, fmt.Errorf("export page %d: %w", page, err)
		}

		for _, row := range export.Rows {
			transformed := transformRow(row, opts.URLMappings, opts.AttachmentIDMap)
			batch = append(batch, transformed)

			if len(batch) >= importBatchSize {
				if err := ctx.Err(); err != nil {
					return nil, err
				}
				if err := importBatch(ctx, target, table, batch, opts.Mode); err != nil {
					return nil, fmt.Errorf("import batch at row %d: %w", totalRows+len(batch), err)
				}
				totalRows += len(batch)
				batch = batch[:0]
			}
		}

		if page >= export.TotalPages {
			break
		}
		page++
	}

	// Import remaining rows.
	if len(batch) > 0 {
		if err := ctx.Err(); err != nil {
			return nil, err
		}
		if err := importBatch(ctx, target, table, batch, opts.Mode); err != nil {
			return nil, fmt.Errorf("import final batch: %w", err)
		}
		totalRows += len(batch)
	}

	result.RowsTransferred = totalRows
	result.TablesProcessed = 1
	return result, nil
}

// importBatch gets a fresh confirmation token and imports a batch of rows.
func importBatch(ctx context.Context, target *api.Client, table string, rows []map[string]interface{}, mode string) error {
	tablesResp, err := target.GetTables(ctx)
	if err != nil {
		return fmt.Errorf("get confirmation token: %w", err)
	}

	tok := tablesResp.ConfirmationTokens.Import
	_, err = target.ImportTable(ctx, table, rows, tok.TokenID, tok.Token, mode)
	if err != nil {
		return fmt.Errorf("import table %s: %w", table, err)
	}
	return nil
}

// transformRow applies URL replacements and optional attachment ID remapping
// to all string values in a row, handling PHP serialized data and JSON safely.
func transformRow(row map[string]interface{}, mappings []URLMapping, idMap AttachmentIDMap) map[string]interface{} {
	// Step 1: URL replacements.
	if len(mappings) > 0 {
		result := make(map[string]interface{}, len(row))
		for key, val := range row {
			str, ok := val.(string)
			if !ok || str == "" {
				result[key] = val
				continue
			}
			transformed := str
			for _, m := range mappings {
				transformed = string(serializer.SafeReplace([]byte(transformed), m.Search, m.Replace))
			}
			result[key] = transformed
		}
		row = result
	}

	// Step 2: Attachment ID remapping.
	if len(idMap) > 0 {
		row = RemapAttachmentIDs(row, idMap)
	}

	return row
}

// SyncDatabase syncs multiple tables from source to target.
// If opts.RemapAttachments is true and AttachmentIDMap is not pre-populated,
// it will build the map automatically before syncing.
func SyncDatabase(ctx context.Context, source, target *api.Client, opts DatabaseSyncOptions, progress func(table string, current, total int)) (*DatabaseSyncResult, error) {
	combined := &DatabaseSyncResult{}

	// Build attachment ID map if requested and not already provided.
	if opts.RemapAttachments && len(opts.AttachmentIDMap) == 0 && !opts.DryRun {
		idMap, err := BuildAttachmentIDMap(ctx, source, target)
		if err != nil {
			// Non-fatal: log and continue without remapping.
			combined.Errors = append(combined.Errors, fmt.Errorf("build attachment ID map: %w", err))
		} else {
			opts.AttachmentIDMap = idMap
		}
	}

	tables := opts.Tables
	if len(tables) == 0 {
		// Sync all tables.
		resp, err := source.GetTables(ctx)
		if err != nil {
			return nil, fmt.Errorf("list source tables: %w", err)
		}
		for _, t := range resp.Tables {
			tables = append(tables, t.Name)
		}
	}

	for i, table := range tables {
		if err := ctx.Err(); err != nil {
			return combined, err
		}

		if progress != nil {
			progress(table, i+1, len(tables))
		}

		result, err := SyncTable(ctx, source, target, table, opts)
		if err != nil {
			combined.Errors = append(combined.Errors, fmt.Errorf("%s: %w", table, err))
			continue
		}

		combined.TablesProcessed += result.TablesProcessed
		combined.RowsTransferred += result.RowsTransferred
		combined.DryRunReport = append(combined.DryRunReport, result.DryRunReport...)
	}

	return combined, nil
}

// BuildURLMappings creates standard URL mappings for prod <-> staging sync.
func BuildURLMappings(sourceURL, targetURL string) []URLMapping {
	// Normalize: remove trailing slashes.
	sourceURL = strings.TrimRight(sourceURL, "/")
	targetURL = strings.TrimRight(targetURL, "/")

	return []URLMapping{
		{Search: sourceURL, Replace: targetURL},
		// Also handle protocol-relative URLs.
		{
			Search:  strings.TrimPrefix(strings.TrimPrefix(sourceURL, "https://"), "http://"),
			Replace: strings.TrimPrefix(strings.TrimPrefix(targetURL, "https://"), "http://"),
		},
	}
}
