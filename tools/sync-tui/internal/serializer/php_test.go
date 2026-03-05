package serializer

import (
	"encoding/json"
	"strconv"
	"strings"
	"testing"
)

// ---------------------------------------------------------------------------
// 1. Simple string serialization round-trip
// ---------------------------------------------------------------------------

func TestSimpleStringRoundTrip(t *testing.T) {
	input := `s:5:"hello";`
	v, err := Unserialize([]byte(input))
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	s, ok := v.(PHPString)
	if !ok {
		t.Fatalf("expected PHPString, got %T", v)
	}
	if s.Value != "hello" {
		t.Fatalf("expected %q, got %q", "hello", s.Value)
	}
	if out := s.Serialize(); out != input {
		t.Fatalf("Serialize: expected %q, got %q", input, out)
	}
}

func TestSimpleStringReplace(t *testing.T) {
	input := []byte(`s:5:"hello";`)
	got := SafeReplace(input, "hello", "world")
	want := `s:5:"world";`
	if string(got) != want {
		t.Fatalf("expected %q, got %q", want, string(got))
	}
}

// ---------------------------------------------------------------------------
// 2. String with length change
// ---------------------------------------------------------------------------

func TestStringLengthChange(t *testing.T) {
	input := []byte(`s:5:"hello";`)
	got := SafeReplace(input, "hello", "hello world")
	want := `s:11:"hello world";`
	if string(got) != want {
		t.Fatalf("expected %q, got %q", want, string(got))
	}
}

func TestStringShorter(t *testing.T) {
	input := []byte(`s:11:"hello world";`)
	got := SafeReplace(input, "hello world", "hi")
	want := `s:2:"hi";`
	if string(got) != want {
		t.Fatalf("expected %q, got %q", want, string(got))
	}
}

// ---------------------------------------------------------------------------
// 3. Nested array with mixed types
// ---------------------------------------------------------------------------

func TestNestedArrayMixedTypes(t *testing.T) {
	// a:3:{i:0;s:3:"foo";i:1;i:42;i:2;b:1;}
	input := `a:3:{i:0;s:3:"foo";i:1;i:42;i:2;b:1;}`
	v, err := Unserialize([]byte(input))
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	arr, ok := v.(PHPArray)
	if !ok {
		t.Fatalf("expected PHPArray, got %T", v)
	}
	if len(arr.Entries) != 3 {
		t.Fatalf("expected 3 entries, got %d", len(arr.Entries))
	}

	// Check types
	if _, ok := arr.Entries[0].Value.(PHPString); !ok {
		t.Errorf("entry 0 value: expected PHPString, got %T", arr.Entries[0].Value)
	}
	if _, ok := arr.Entries[1].Value.(PHPInt); !ok {
		t.Errorf("entry 1 value: expected PHPInt, got %T", arr.Entries[1].Value)
	}
	if _, ok := arr.Entries[2].Value.(PHPBool); !ok {
		t.Errorf("entry 2 value: expected PHPBool, got %T", arr.Entries[2].Value)
	}

	// Round-trip
	if out := v.Serialize(); out != input {
		t.Fatalf("Serialize: expected %q, got %q", input, out)
	}
}

func TestArrayReplace(t *testing.T) {
	input := []byte(`a:2:{i:0;s:3:"foo";i:1;s:3:"bar";}`)
	got := SafeReplace(input, "foo", "baz")
	want := `a:2:{i:0;s:3:"baz";i:1;s:3:"bar";}`
	if string(got) != want {
		t.Fatalf("expected %q, got %q", want, string(got))
	}
}

// ---------------------------------------------------------------------------
// 4. Object serialization
// ---------------------------------------------------------------------------

func TestObjectRoundTrip(t *testing.T) {
	input := `O:7:"MyClass":2:{s:4:"name";s:5:"Alice";s:3:"age";i:30;}`
	v, err := Unserialize([]byte(input))
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	obj, ok := v.(PHPObject)
	if !ok {
		t.Fatalf("expected PHPObject, got %T", v)
	}
	if obj.ClassName != "MyClass" {
		t.Fatalf("expected class %q, got %q", "MyClass", obj.ClassName)
	}
	if len(obj.Entries) != 2 {
		t.Fatalf("expected 2 entries, got %d", len(obj.Entries))
	}
	if out := v.Serialize(); out != input {
		t.Fatalf("Serialize: expected %q, got %q", input, out)
	}
}

func TestObjectReplace(t *testing.T) {
	input := []byte(`O:7:"MyClass":1:{s:3:"url";s:24:"https://prod.example.com";}`)
	got := SafeReplace(input, "prod.example.com", "staging.example.com")
	want := `O:7:"MyClass":1:{s:3:"url";s:27:"https://staging.example.com";}`
	if string(got) != want {
		t.Fatalf("expected %q, got %q", want, string(got))
	}
}

// ---------------------------------------------------------------------------
// 5. Nested serialized data (serialized string inside serialized array)
// ---------------------------------------------------------------------------

func TestNestedSerializedData(t *testing.T) {
	// Inner: s:16:"https://old.test"
	// Outer: a:1:{s:4:"data";s:22:"s:16:"https://old.test";";}
	inner := PHPString{Value: "https://old.test"}
	innerSer := inner.Serialize() // s:16:"https://old.test";

	outer := PHPArray{Entries: []PHPArrayEntry{
		{Key: PHPString{Value: "data"}, Value: PHPString{Value: innerSer}},
	}}
	input := []byte(outer.Serialize())

	got := SafeReplace(input, "old.test", "new.test")
	// The inner serialized string should be updated with correct byte count.
	gotStr := string(got)

	// Parse outer to inspect.
	v, err := Unserialize(got)
	if err != nil {
		t.Fatalf("Unserialize result: %v", err)
	}
	arr := v.(PHPArray)
	dataVal := arr.Entries[0].Value.(PHPString).Value

	// dataVal should itself be valid serialized data.
	innerVal, err := Unserialize([]byte(dataVal))
	if err != nil {
		t.Fatalf("Unserialize inner: %v (data=%q)", err, dataVal)
	}
	innerStr := innerVal.(PHPString).Value
	if innerStr != "https://new.test" {
		t.Fatalf("expected inner string %q, got %q", "https://new.test", innerStr)
	}
	// The outer serialized byte count for the "data" value must have been
	// recalculated to match the new inner serialized length.
	if !strings.Contains(gotStr, `s:`+strconv.Itoa(len(dataVal))+`:"`) {
		t.Fatalf("outer byte count not updated correctly: %s", gotStr)
	}
}

// ---------------------------------------------------------------------------
// 6. JSON inside serialized data (Elementor-style)
// ---------------------------------------------------------------------------

func TestJSONInsideSerialized(t *testing.T) {
	// Simulate Elementor _elementor_data: a serialized string containing JSON.
	jsonData := `[{"id":"abc","settings":{"url":"https://prod.site.com/page"}}]`
	outer := PHPArray{Entries: []PHPArrayEntry{
		{Key: PHPString{Value: "_elementor_data"}, Value: PHPString{Value: jsonData}},
	}}
	input := []byte(outer.Serialize())

	got := SafeReplace(input, "prod.site.com", "staging.site.com")

	// Parse outer.
	v, err := Unserialize(got)
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	arr := v.(PHPArray)
	resultJSON := arr.Entries[0].Value.(PHPString).Value

	// Verify JSON is valid and contains the replacement.
	var parsed []map[string]interface{}
	if err := json.Unmarshal([]byte(resultJSON), &parsed); err != nil {
		t.Fatalf("JSON unmarshal: %v (data=%q)", err, resultJSON)
	}
	settings := parsed[0]["settings"].(map[string]interface{})
	url := settings["url"].(string)
	if url != "https://staging.site.com/page" {
		t.Fatalf("expected %q, got %q", "https://staging.site.com/page", url)
	}

	// Verify outer byte count is correct (re-parse should succeed).
	if _, err := Unserialize(got); err != nil {
		t.Fatalf("result not valid serialized data: %v", err)
	}
}

// ---------------------------------------------------------------------------
// 7. URL replacement in serialized data
// ---------------------------------------------------------------------------

func TestURLReplacement(t *testing.T) {
	input := []byte(`a:2:{s:8:"site_url";s:24:"https://prod.example.com";s:8:"home_url";s:24:"https://prod.example.com";}`)
	got := SafeReplace(input, "https://prod.example.com", "https://staging.example.com")

	v, err := Unserialize(got)
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	arr := v.(PHPArray)
	for _, e := range arr.Entries {
		val := e.Value.(PHPString).Value
		if val != "https://staging.example.com" {
			t.Fatalf("expected %q, got %q", "https://staging.example.com", val)
		}
	}
	// byte count: "https://staging.example.com" is 27 bytes
	want := `a:2:{s:8:"site_url";s:27:"https://staging.example.com";s:8:"home_url";s:27:"https://staging.example.com";}`
	if string(got) != want {
		t.Fatalf("expected:\n%s\ngot:\n%s", want, string(got))
	}
}

// ---------------------------------------------------------------------------
// 8. No-op: input with no matches returns unchanged
// ---------------------------------------------------------------------------

func TestNoOp(t *testing.T) {
	input := []byte(`a:1:{s:3:"foo";s:3:"bar";}`)
	got := SafeReplace(input, "notfound", "replacement")
	if string(got) != string(input) {
		t.Fatalf("expected unchanged output, got %q", string(got))
	}
}

func TestNoOpPlain(t *testing.T) {
	input := []byte("just some plain text")
	got := SafeReplace(input, "notfound", "replacement")
	if string(got) != string(input) {
		t.Fatalf("expected unchanged output, got %q", string(got))
	}
}

// ---------------------------------------------------------------------------
// 9. Plain text (non-serialized) replacement
// ---------------------------------------------------------------------------

func TestPlainTextReplace(t *testing.T) {
	input := []byte("Hello from https://prod.example.com and goodbye")
	got := SafeReplace(input, "https://prod.example.com", "https://staging.example.com")
	want := "Hello from https://staging.example.com and goodbye"
	if string(got) != want {
		t.Fatalf("expected %q, got %q", want, string(got))
	}
}

// ---------------------------------------------------------------------------
// 10. Edge cases
// ---------------------------------------------------------------------------

func TestEmptyString(t *testing.T) {
	input := []byte(`s:0:"";`)
	v, err := Unserialize(input)
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	s := v.(PHPString)
	if s.Value != "" {
		t.Fatalf("expected empty string, got %q", s.Value)
	}
	if out := s.Serialize(); out != string(input) {
		t.Fatalf("Serialize: expected %q, got %q", string(input), out)
	}
}

func TestEmptyInput(t *testing.T) {
	got := SafeReplace(nil, "a", "b")
	if got != nil {
		t.Fatalf("expected nil, got %q", string(got))
	}
	got = SafeReplace([]byte{}, "a", "b")
	if len(got) != 0 {
		t.Fatalf("expected empty, got %q", string(got))
	}
}

func TestEmptySearch(t *testing.T) {
	input := []byte("hello")
	got := SafeReplace(input, "", "world")
	if string(got) != "hello" {
		t.Fatalf("expected unchanged, got %q", string(got))
	}
}

func TestSpecialCharacters(t *testing.T) {
	// Backslashes and quotes inside strings.
	val := `C:\Users\test "quoted"`
	s := PHPString{Value: val}
	ser := s.Serialize()

	v, err := Unserialize([]byte(ser))
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	if v.(PHPString).Value != val {
		t.Fatalf("expected %q, got %q", val, v.(PHPString).Value)
	}
}

func TestUTF8(t *testing.T) {
	// PHP serialize uses byte counts, not character counts.
	// "cafe" with accent on the e = "caf\xc3\xa9" = 5 bytes
	val := "caf\u00e9"
	s := PHPString{Value: val}
	ser := s.Serialize()
	want := `s:5:"caf` + "\u00e9" + `";`
	if ser != want {
		t.Fatalf("expected %q, got %q", want, ser)
	}

	v, err := Unserialize([]byte(ser))
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	if v.(PHPString).Value != val {
		t.Fatalf("expected %q, got %q", val, v.(PHPString).Value)
	}
}

func TestUTF8Replace(t *testing.T) {
	input := []byte(`s:5:"` + "caf\u00e9" + `";`)
	got := SafeReplace(input, "caf\u00e9", "cafe")
	want := `s:4:"cafe";`
	if string(got) != want {
		t.Fatalf("expected %q, got %q", want, string(got))
	}
}

// ---------------------------------------------------------------------------
// Additional type round-trips
// ---------------------------------------------------------------------------

func TestNullRoundTrip(t *testing.T) {
	input := `N;`
	v, err := Unserialize([]byte(input))
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	if _, ok := v.(PHPNull); !ok {
		t.Fatalf("expected PHPNull, got %T", v)
	}
	if out := v.Serialize(); out != input {
		t.Fatalf("Serialize: expected %q, got %q", input, out)
	}
}

func TestBoolRoundTrip(t *testing.T) {
	for _, tc := range []struct {
		input string
		want  bool
	}{
		{"b:1;", true},
		{"b:0;", false},
	} {
		v, err := Unserialize([]byte(tc.input))
		if err != nil {
			t.Fatalf("Unserialize %q: %v", tc.input, err)
		}
		b := v.(PHPBool)
		if b.Value != tc.want {
			t.Fatalf("expected %v, got %v", tc.want, b.Value)
		}
		if out := v.Serialize(); out != tc.input {
			t.Fatalf("Serialize: expected %q, got %q", tc.input, out)
		}
	}
}

func TestIntRoundTrip(t *testing.T) {
	for _, tc := range []struct {
		input string
		want  int64
	}{
		{"i:0;", 0},
		{"i:42;", 42},
		{"i:-7;", -7},
	} {
		v, err := Unserialize([]byte(tc.input))
		if err != nil {
			t.Fatalf("Unserialize %q: %v", tc.input, err)
		}
		n := v.(PHPInt)
		if n.Value != tc.want {
			t.Fatalf("expected %d, got %d", tc.want, n.Value)
		}
		if out := v.Serialize(); out != tc.input {
			t.Fatalf("Serialize: expected %q, got %q", tc.input, out)
		}
	}
}

func TestFloatRoundTrip(t *testing.T) {
	for _, tc := range []struct {
		input string
		want  float64
	}{
		{"d:3.14;", 3.14},
		{"d:0.0;", 0.0},
		{"d:-1.5;", -1.5},
	} {
		v, err := Unserialize([]byte(tc.input))
		if err != nil {
			t.Fatalf("Unserialize %q: %v", tc.input, err)
		}
		f := v.(PHPFloat)
		if f.Value != tc.want {
			t.Fatalf("expected %f, got %f", tc.want, f.Value)
		}
		if out := v.Serialize(); out != tc.input {
			t.Fatalf("Serialize: expected %q, got %q", tc.input, out)
		}
	}
}

func TestIsSerializedPositive(t *testing.T) {
	cases := []string{
		`s:3:"foo";`,
		`i:42;`,
		`b:1;`,
		`d:3.14;`,
		`N;`,
		`a:0:{}`,
		`O:3:"Foo":0:{}`,
	}
	for _, c := range cases {
		if !IsSerialized([]byte(c)) {
			t.Errorf("IsSerialized(%q) = false, want true", c)
		}
	}
}

func TestIsSerializedNegative(t *testing.T) {
	cases := []string{
		`hello`,
		`{"json":true}`,
		`<html>`,
		``,
		`x`,
	}
	for _, c := range cases {
		if IsSerialized([]byte(c)) {
			t.Errorf("IsSerialized(%q) = true, want false", c)
		}
	}
}

// ---------------------------------------------------------------------------
// Walk test
// ---------------------------------------------------------------------------

func TestWalkReplacesAllStrings(t *testing.T) {
	input := `a:2:{s:3:"key";s:24:"https://prod.example.com";s:5:"other";a:1:{i:0;s:24:"https://prod.example.com";}}`
	if _, err := Unserialize([]byte(input)); err != nil {
		t.Fatalf("Unserialize: %v", err)
	}

	got := SafeReplace([]byte(input), "prod.example.com", "staging.example.com")
	gotStr := string(got)

	if strings.Contains(gotStr, "prod.example.com") {
		t.Fatalf("replacement missed an occurrence: %s", gotStr)
	}
	if !strings.Contains(gotStr, "staging.example.com") {
		t.Fatalf("replacement not found: %s", gotStr)
	}

	// Should still be valid serialized data.
	if _, err := Unserialize(got); err != nil {
		t.Fatalf("result not valid: %v", err)
	}
}

// ---------------------------------------------------------------------------
// JSON standalone replacement
// ---------------------------------------------------------------------------

func TestJSONReplace(t *testing.T) {
	input := []byte(`{"url":"https://prod.example.com","name":"My Site"}`)
	got := SafeReplace(input, "prod.example.com", "staging.example.com")

	var parsed map[string]interface{}
	if err := json.Unmarshal(got, &parsed); err != nil {
		t.Fatalf("JSON unmarshal: %v", err)
	}
	if parsed["url"] != "https://staging.example.com" {
		t.Fatalf("expected %q, got %q", "https://staging.example.com", parsed["url"])
	}
	if parsed["name"] != "My Site" {
		t.Fatalf("expected %q, got %q", "My Site", parsed["name"])
	}
}

// ---------------------------------------------------------------------------
// Complex WordPress-realistic scenario
// ---------------------------------------------------------------------------

func TestWordPressOptionSerialized(t *testing.T) {
	// Simulates a typical wp_options row with nested serialized data and URLs.
	inner := PHPArray{Entries: []PHPArrayEntry{
		{Key: PHPString{Value: "stylesheet_url"}, Value: PHPString{Value: "https://prod.example.com/wp-content/themes/theme/style.css"}},
		{Key: PHPString{Value: "template_url"}, Value: PHPString{Value: "https://prod.example.com/wp-content/themes/theme"}},
	}}
	outer := PHPArray{Entries: []PHPArrayEntry{
		{Key: PHPInt{Value: 0}, Value: PHPString{Value: inner.Serialize()}},
		{Key: PHPInt{Value: 1}, Value: PHPString{Value: "https://prod.example.com"}},
		{Key: PHPInt{Value: 2}, Value: PHPInt{Value: 42}},
		{Key: PHPInt{Value: 3}, Value: PHPNull{}},
	}}

	input := []byte(outer.Serialize())
	got := SafeReplace(input, "https://prod.example.com", "https://staging.example.com")

	// Verify result is valid serialized data.
	v, err := Unserialize(got)
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}

	// No "prod" should remain.
	if strings.Contains(string(got), "prod.example.com") {
		t.Fatalf("prod.example.com still present in output")
	}

	// Check nested.
	arr := v.(PHPArray)
	innerSer := arr.Entries[0].Value.(PHPString).Value
	innerV, err := Unserialize([]byte(innerSer))
	if err != nil {
		t.Fatalf("Unserialize inner: %v", err)
	}
	innerArr := innerV.(PHPArray)
	for _, e := range innerArr.Entries {
		val := e.Value.(PHPString).Value
		if strings.Contains(val, "prod.example.com") {
			t.Fatalf("inner still contains prod: %q", val)
		}
		if !strings.Contains(val, "staging.example.com") {
			t.Fatalf("inner missing staging: %q", val)
		}
	}
}

// ---------------------------------------------------------------------------
// Deeply nested serialization (3 levels)
// ---------------------------------------------------------------------------

func TestTripleNestedSerialized(t *testing.T) {
	level3 := PHPString{Value: "https://old.site.com/path"}
	level2 := PHPArray{Entries: []PHPArrayEntry{
		{Key: PHPString{Value: "url"}, Value: PHPString{Value: level3.Serialize()}},
	}}
	level1 := PHPArray{Entries: []PHPArrayEntry{
		{Key: PHPInt{Value: 0}, Value: PHPString{Value: level2.Serialize()}},
	}}

	input := []byte(level1.Serialize())
	got := SafeReplace(input, "old.site.com", "new.site.com")

	// Verify all levels.
	if strings.Contains(string(got), "old.site.com") {
		t.Fatalf("old.site.com still present")
	}

	// Re-parse and drill down.
	v1, _ := Unserialize(got)
	s2 := v1.(PHPArray).Entries[0].Value.(PHPString).Value
	v2, err := Unserialize([]byte(s2))
	if err != nil {
		t.Fatalf("level 2 parse: %v (data=%q)", err, s2)
	}
	s3 := v2.(PHPArray).Entries[0].Value.(PHPString).Value
	v3, err := Unserialize([]byte(s3))
	if err != nil {
		t.Fatalf("level 3 parse: %v (data=%q)", err, s3)
	}
	if v3.(PHPString).Value != "https://new.site.com/path" {
		t.Fatalf("expected %q, got %q", "https://new.site.com/path", v3.(PHPString).Value)
	}
}

// ---------------------------------------------------------------------------
// Associative array with string keys
// ---------------------------------------------------------------------------

func TestAssocArrayStringKeys(t *testing.T) {
	input := `a:2:{s:4:"name";s:3:"Bob";s:5:"email";s:15:"bob@example.com";}`
	v, err := Unserialize([]byte(input))
	if err != nil {
		t.Fatalf("Unserialize: %v", err)
	}
	arr := v.(PHPArray)
	if len(arr.Entries) != 2 {
		t.Fatalf("expected 2 entries, got %d", len(arr.Entries))
	}
	if arr.Entries[0].Key.(PHPString).Value != "name" {
		t.Fatalf("expected key %q, got %q", "name", arr.Entries[0].Key.(PHPString).Value)
	}
	if out := v.Serialize(); out != input {
		t.Fatalf("round-trip failed:\nexpected: %s\ngot:      %s", input, out)
	}
}

