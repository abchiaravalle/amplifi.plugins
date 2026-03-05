package serializer

import (
	"encoding/json"
	"strings"
)

// SafeReplace performs serialization-safe find/replace on data.
//
// If the input is PHP-serialized it is parsed, every string value is walked
// (including nested serialized strings and JSON), replacements are made, and
// the result is re-serialized with correct byte counts.
//
// If the input is JSON it is decoded, walked, and re-encoded.
//
// Otherwise a plain string replacement is performed.
func SafeReplace(data []byte, search, replace string) []byte {
	if len(data) == 0 || search == "" {
		return data
	}

	// Try PHP-serialized first.
	if IsSerialized(data) {
		val, err := Unserialize(data)
		if err == nil {
			replaced := walkReplace(val, search, replace)
			return []byte(replaced.Serialize())
		}
		// Fall through if parsing fails — could be a false positive.
	}

	// Try JSON.
	if looksLikeJSON(data) {
		result, ok := replaceJSON(data, search, replace)
		if ok {
			return result
		}
	}

	// Plain text fallback.
	return []byte(strings.ReplaceAll(string(data), search, replace))
}

// ---------------------------------------------------------------------------
// PHP-serialized walk+replace
// ---------------------------------------------------------------------------

// walkReplace walks the PHPValue tree, replacing occurrences of search with
// replace inside every string value. If a string value is itself serialized
// or JSON, it recurses into the nested structure first.
func walkReplace(v PHPValue, search, replace string) PHPValue {
	return Walk(v, func(node PHPValue) PHPValue {
		s, ok := node.(PHPString)
		if !ok {
			return node
		}
		s.Value = replaceInString(s.Value, search, replace)
		return s
	})
}

// replaceInString handles a single string value. It checks whether the string
// is itself PHP-serialized or JSON and recurses if so, then does a plain
// replacement on whatever is left.
func replaceInString(s, search, replace string) string {
	b := []byte(s)

	// Nested PHP-serialized string.
	if IsSerialized(b) {
		inner, err := Unserialize(b)
		if err == nil {
			replaced := walkReplace(inner, search, replace)
			return replaced.Serialize()
		}
	}

	// Nested JSON (e.g. Elementor _elementor_data).
	if looksLikeJSON(b) {
		result, ok := replaceJSON(b, search, replace)
		if ok {
			return string(result)
		}
	}

	// Plain replacement.
	return strings.ReplaceAll(s, search, replace)
}

// ---------------------------------------------------------------------------
// JSON walk+replace
// ---------------------------------------------------------------------------

// looksLikeJSON returns true if data starts with [ or { after trimming space.
func looksLikeJSON(data []byte) bool {
	s := strings.TrimSpace(string(data))
	if len(s) == 0 {
		return false
	}
	return s[0] == '{' || s[0] == '['
}

// replaceJSON decodes JSON, walks all string values performing replacement,
// and re-encodes. Returns the result and true on success.
func replaceJSON(data []byte, search, replace string) ([]byte, bool) {
	var v interface{}
	if err := json.Unmarshal(data, &v); err != nil {
		return nil, false
	}
	v = walkJSONValue(v, search, replace)
	out, err := json.Marshal(v)
	if err != nil {
		return nil, false
	}
	return out, true
}

// walkJSONValue recursively walks a decoded JSON value, replacing strings.
func walkJSONValue(v interface{}, search, replace string) interface{} {
	switch t := v.(type) {
	case string:
		// The string itself might be nested serialized/JSON.
		return replaceInString(t, search, replace)
	case map[string]interface{}:
		for k, val := range t {
			t[k] = walkJSONValue(val, search, replace)
		}
		return t
	case []interface{}:
		for i, val := range t {
			t[i] = walkJSONValue(val, search, replace)
		}
		return t
	default:
		// numbers, bools, nil — leave as-is.
		return v
	}
}
