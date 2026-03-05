package sync

import (
	"context"
	"encoding/json"
	"fmt"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/abchiaravalle/amplifi.plugins/tools/sync-tui/internal/api"
)

// AttachmentIDMap maps source attachment post IDs to target attachment post IDs.
// Keys and values are string representations of integers (matching JSON number
// values that arrive as interface{} from the DB export).
type AttachmentIDMap map[string]string

// BuildAttachmentIDMap exports wp_posts from both sites (post_type=attachment),
// matches by filename extracted from the guid field, and returns a source→target
// ID mapping. Attachments present only on source (not yet transferred) are
// omitted — callers should transfer those first if needed.
func BuildAttachmentIDMap(ctx context.Context, source, target *api.Client) (AttachmentIDMap, error) {
	srcAttachments, err := fetchAttachments(ctx, source)
	if err != nil {
		return nil, fmt.Errorf("fetch source attachments: %w", err)
	}
	tgtAttachments, err := fetchAttachments(ctx, target)
	if err != nil {
		return nil, fmt.Errorf("fetch target attachments: %w", err)
	}

	// Build target filename → ID map.
	tgtByFilename := make(map[string]string, len(tgtAttachments))
	for id, guid := range tgtAttachments {
		name := filenameFromGUID(guid)
		if name != "" {
			tgtByFilename[name] = id
		}
	}

	m := make(AttachmentIDMap)
	for srcID, guid := range srcAttachments {
		name := filenameFromGUID(guid)
		if tgtID, ok := tgtByFilename[name]; ok {
			m[srcID] = tgtID
		}
	}
	return m, nil
}

// fetchAttachments returns a map of {ID → guid} for all attachment posts on a
// site, paging through wp_posts until exhausted.
func fetchAttachments(ctx context.Context, client *api.Client) (map[string]string, error) {
	result := make(map[string]string)

	for page := 1; ; page++ {
		if err := ctx.Err(); err != nil {
			return nil, err
		}
		export, err := client.ExportTable(ctx, "wp_posts", page)
		if err != nil {
			return nil, err
		}
		for _, row := range export.Rows {
			postType, _ := row["post_type"].(string)
			if postType != "attachment" {
				continue
			}
			id := idString(row["ID"])
			guid, _ := row["guid"].(string)
			if id != "" && guid != "" {
				result[id] = guid
			}
		}
		if page >= export.TotalPages {
			break
		}
	}
	return result, nil
}

// filenameFromGUID extracts the bare filename (without size suffix or extension
// alternate) from a WordPress attachment guid URL.
// e.g. https://example.com/wp-content/uploads/2024/01/my-photo-1200x800.jpg → my-photo.jpg
// Actually we want the original filename so we strip size suffixes.
func filenameFromGUID(guid string) string {
	base := filepath.Base(guid)
	// Remove query string if any.
	if idx := strings.IndexByte(base, '?'); idx >= 0 {
		base = base[:idx]
	}
	// Strip WordPress size suffixes like -300x200, -1024x768, -scaled.
	re := regexp.MustCompile(`-\d+x\d+(\.[a-zA-Z]+)$`)
	if re.MatchString(base) {
		base = re.ReplaceAllString(base, "$1")
	}
	base = strings.TrimSuffix(base, "-scaled")
	return strings.ToLower(base)
}

// idString converts an interface{} value (float64 from JSON, string, etc.) to
// its string representation for use as a map key.
func idString(v interface{}) string {
	switch n := v.(type) {
	case float64:
		return fmt.Sprintf("%.0f", n)
	case string:
		return n
	case json.Number:
		return n.String()
	default:
		if v != nil {
			return fmt.Sprintf("%v", v)
		}
		return ""
	}
}

// RemapAttachmentIDs applies attachment ID remapping to a database row.
// It handles wp_postmeta rows (_thumbnail_id, _elementor_data, gallery shortcodes)
// and wp_posts rows (post_content with [gallery] shortcodes).
// The idMap is source_id → target_id (string keys).
func RemapAttachmentIDs(row map[string]interface{}, idMap AttachmentIDMap) map[string]interface{} {
	if len(idMap) == 0 {
		return row
	}

	// Detect row type by column presence.
	_, hasMeta := row["meta_key"]

	if hasMeta {
		return remapMetaRow(row, idMap)
	}

	// wp_posts: remap gallery shortcodes in post_content.
	if content, ok := row["post_content"].(string); ok && content != "" {
		remapped := remapGalleryShortcode(content, idMap)
		if remapped != content {
			result := make(map[string]interface{}, len(row))
			for k, v := range row {
				result[k] = v
			}
			result["post_content"] = remapped
			return result
		}
	}

	return row
}

// remapMetaRow handles _thumbnail_id, _elementor_data, and gallery-related
// meta values in a wp_postmeta or similar row.
func remapMetaRow(row map[string]interface{}, idMap AttachmentIDMap) map[string]interface{} {
	metaKey, _ := row["meta_key"].(string)
	metaVal, _ := row["meta_value"].(string)
	if metaVal == "" {
		return row
	}

	var newVal string

	switch metaKey {
	case "_thumbnail_id", "_wp_attached_id":
		// Direct integer ID.
		if mapped, ok := idMap[strings.TrimSpace(metaVal)]; ok {
			newVal = mapped
		}

	case "_elementor_data":
		// JSON blob with image IDs scattered throughout.
		newVal = remapElementorData(metaVal, idMap)

	default:
		// Check for gallery shortcode in arbitrary meta values.
		if strings.Contains(metaVal, "[gallery") {
			newVal = remapGalleryShortcode(metaVal, idMap)
		}
	}

	if newVal == "" || newVal == metaVal {
		return row
	}

	result := make(map[string]interface{}, len(row))
	for k, v := range row {
		result[k] = v
	}
	result["meta_value"] = newVal
	return result
}

// remapElementorData parses Elementor's JSON and remaps image IDs.
// Elementor stores image data as: {"id": 123, "url": "https://..."}
// inside various widget settings.
func remapElementorData(data string, idMap AttachmentIDMap) string {
	if data == "" {
		return data
	}

	// Use a regex to find all "id": <number> patterns followed by (within a few
	// chars) a "url" key — this is the Elementor image widget signature.
	// We do a targeted string replace rather than full JSON parse/re-serialize
	// to avoid mangling the already-complex serialized structure.
	idPattern := regexp.MustCompile(`"id"\s*:\s*(\d+)`)

	return idPattern.ReplaceAllStringFunc(data, func(match string) string {
		numMatch := regexp.MustCompile(`\d+`).FindString(match)
		if mapped, ok := idMap[numMatch]; ok {
			return strings.Replace(match, numMatch, mapped, 1)
		}
		return match
	})
}

// remapGalleryShortcode remaps IDs in [gallery ids="1,2,3"] shortcodes.
func remapGalleryShortcode(content string, idMap AttachmentIDMap) string {
	galleryRe := regexp.MustCompile(`\[gallery([^\]]*)\bids="([^"]+)"`)
	return galleryRe.ReplaceAllStringFunc(content, func(match string) string {
		// Extract the ids="..." value and remap each ID.
		idsRe := regexp.MustCompile(`\bids="([^"]+)"`)
		return idsRe.ReplaceAllStringFunc(match, func(idsMatch string) string {
			inner := strings.TrimPrefix(idsMatch, `ids="`)
			inner = strings.TrimSuffix(inner, `"`)
			parts := strings.Split(inner, ",")
			for i, part := range parts {
				part = strings.TrimSpace(part)
				if mapped, ok := idMap[part]; ok {
					parts[i] = mapped
				}
			}
			return `ids="` + strings.Join(parts, ",") + `"`
		})
	})
}
