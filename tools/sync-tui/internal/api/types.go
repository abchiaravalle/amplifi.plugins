package api

// StatusResponse represents the site information returned by the sync plugin's
// status endpoint.
type StatusResponse struct {
	SiteURL       string   `json:"site_url"`
	HomeURL       string   `json:"home_url"`
	WPVersion     string   `json:"wp_version"`
	PHPVersion    string   `json:"php_version"`
	ActiveTheme   string   `json:"active_theme"`
	ChildTheme    *string  `json:"child_theme"`
	ActivePlugins []string `json:"active_plugins"`
	PluginCount   int      `json:"plugin_count"`
	Elementor     bool     `json:"elementor"`
	UploadsDir    string   `json:"uploads_dir"`
	UploadsURL    string   `json:"uploads_url"`
	Multisite     bool     `json:"multisite"`
	DBPrefix      string   `json:"db_prefix"`
	SyncVersion   string   `json:"sync_version"`
}

// FileEntry represents a single file in the manifest.
type FileEntry struct {
	Path     string `json:"path"`
	Size     int64  `json:"size"`
	Modified string `json:"modified"`
	MD5      string `json:"md5"`
	Type     string `json:"type"` // "file" or "dir"
}

// FileManifest is the response for a directory listing.
type FileManifest struct {
	Base      string      `json:"base"`
	Count     int         `json:"count"`
	Truncated bool        `json:"truncated"`
	Files     []FileEntry `json:"files"`
}

// FileContent is the response when reading a single file.
type FileContent struct {
	Path    string `json:"path"`
	Content string `json:"content"` // base64-encoded
	Size    int64  `json:"size"`
	MD5     string `json:"md5"`
}

// TableInfo describes a single database table.
type TableInfo struct {
	Name      string `json:"name"`
	Rows      int    `json:"rows"`
	Size      int64  `json:"size"`
	Engine    string `json:"engine"`
	Collation string `json:"collation"`
}

// ConfirmationTokenInfo holds the token ID and value for a single operation.
// The server issues unique, single-use tokens per operation type.
type ConfirmationTokenInfo struct {
	TokenID string `json:"token_id"`
	Token   string `json:"token"`
}

// TablesResponse is the response for listing database tables.
type TablesResponse struct {
	Prefix string      `json:"prefix"`
	Tables []TableInfo `json:"tables"`
	// ConfirmationTokens provides per-operation tokens required for write ops.
	ConfirmationTokens struct {
		Import  ConfirmationTokenInfo `json:"import"`
		Restore ConfirmationTokenInfo `json:"restore"`
	} `json:"confirmation_tokens"`
}

// TableExport is the paginated response for exporting a table.
type TableExport struct {
	Table      string                   `json:"table"`
	Page       int                      `json:"page"`
	PerPage    int                      `json:"per_page"`
	TotalRows  int                      `json:"total_rows"`
	TotalPages int                      `json:"total_pages"`
	Rows       []map[string]interface{} `json:"rows"`
}

// ImportResponse is the result of a table import operation.
type ImportResponse struct {
	Success  bool   `json:"success"`
	Inserted int    `json:"inserted"`
	Mode     string `json:"mode"`
	Message  string `json:"message,omitempty"`
}

// MediaItem represents a single media library attachment.
type MediaItem struct {
	ID       int    `json:"id"`
	Title    string `json:"title"`
	URL      string `json:"url"`
	Path     string `json:"path"`
	MimeType string `json:"mime_type"`
	FileSize int64  `json:"filesize"`
	Width    int    `json:"width,omitempty"`
	Height   int    `json:"height,omitempty"`
	Date     string `json:"date"`
	Sizes    []string `json:"sizes,omitempty"`
}

// MediaList is the paginated response for listing media.
type MediaList struct {
	Items      []MediaItem `json:"items"`
	Page       int         `json:"page"`
	PerPage    int         `json:"per_page"`
	Total      int         `json:"total"`
	TotalPages int         `json:"total_pages"`
}

// BackupResponse is the result of a database backup request.
type BackupResponse struct {
	Success  bool   `json:"success"`
	Filename string `json:"filename"`
	Size     int64  `json:"size"`
	Tables   int    `json:"tables"`
	Path     string `json:"path"`
	Message  string `json:"message,omitempty"`
}

// DBQueryResponse is the result of a raw SQL query.
type DBQueryResponse struct {
	Success bool                     `json:"success"`
	Rows    []map[string]interface{} `json:"rows,omitempty"`
	Columns []string                 `json:"columns,omitempty"`
	Message string                   `json:"message,omitempty"`
}

// DBExecuteResponse is the result of a raw SQL execute (INSERT/UPDATE/DELETE).
type DBExecuteResponse struct {
	Success      bool   `json:"success"`
	AffectedRows int    `json:"affected_rows"`
	Message      string `json:"message"`
}
