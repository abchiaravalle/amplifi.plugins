// Package serializer provides PHP serialize/unserialize in Go with
// serialization-safe string replacement for WordPress database sync.
package serializer

import (
	"errors"
	"fmt"
	"strconv"
	"strings"
)

// ---------------------------------------------------------------------------
// Type definitions
// ---------------------------------------------------------------------------

// PHPValue is the interface satisfied by every PHP type we can represent.
type PHPValue interface {
	// Serialize returns the PHP-serialized representation of the value.
	Serialize() string
	// phpValue is an unexported marker so only this package can implement it.
	phpValue()
}

// PHPString represents a PHP string (s:…).
type PHPString struct {
	Value string
}

// PHPInt represents a PHP integer (i:…).
type PHPInt struct {
	Value int64
}

// PHPFloat represents a PHP float/double (d:…).
type PHPFloat struct {
	Value float64
}

// PHPBool represents a PHP boolean (b:…).
type PHPBool struct {
	Value bool
}

// PHPNull represents PHP null (N;).
type PHPNull struct{}

// PHPArrayEntry is one key/value pair inside a PHPArray.
type PHPArrayEntry struct {
	Key   PHPValue // PHPInt or PHPString only
	Value PHPValue
}

// PHPArray represents a PHP array (a:…).
type PHPArray struct {
	Entries []PHPArrayEntry
}

// PHPObject represents a PHP serialized object (O:…).
type PHPObject struct {
	ClassName string
	Entries   []PHPArrayEntry
}

// ---------------------------------------------------------------------------
// Marker method implementations
// ---------------------------------------------------------------------------

func (PHPString) phpValue() {}
func (PHPInt) phpValue()    {}
func (PHPFloat) phpValue()  {}
func (PHPBool) phpValue()   {}
func (PHPNull) phpValue()   {}
func (PHPArray) phpValue()  {}
func (PHPObject) phpValue() {}

// ---------------------------------------------------------------------------
// Serialize
// ---------------------------------------------------------------------------

// Serialize a PHPString. Byte count uses len() which gives the byte length,
// matching PHP's serialize behaviour for multi-byte strings.
func (v PHPString) Serialize() string {
	return fmt.Sprintf("s:%d:\"%s\";", len(v.Value), v.Value)
}

func (v PHPInt) Serialize() string {
	return fmt.Sprintf("i:%d;", v.Value)
}

func (v PHPFloat) Serialize() string {
	s := strconv.FormatFloat(v.Value, 'f', -1, 64)
	// PHP always includes a decimal point for floats.
	if !strings.Contains(s, ".") {
		s += ".0"
	}
	return fmt.Sprintf("d:%s;", s)
}

func (v PHPBool) Serialize() string {
	if v.Value {
		return "b:1;"
	}
	return "b:0;"
}

func (PHPNull) Serialize() string {
	return "N;"
}

func (v PHPArray) Serialize() string {
	var b strings.Builder
	fmt.Fprintf(&b, "a:%d:{", len(v.Entries))
	for _, e := range v.Entries {
		b.WriteString(e.Key.Serialize())
		b.WriteString(e.Value.Serialize())
	}
	b.WriteByte('}')
	return b.String()
}

func (v PHPObject) Serialize() string {
	var b strings.Builder
	fmt.Fprintf(&b, "O:%d:\"%s\":%d:{", len(v.ClassName), v.ClassName, len(v.Entries))
	for _, e := range v.Entries {
		b.WriteString(e.Key.Serialize())
		b.WriteString(e.Value.Serialize())
	}
	b.WriteByte('}')
	return b.String()
}

// ---------------------------------------------------------------------------
// Parser
// ---------------------------------------------------------------------------

// Unserialize parses a PHP-serialized byte slice and returns a PHPValue tree.
func Unserialize(data []byte) (PHPValue, error) {
	v, _, err := parse(data, 0)
	return v, err
}

// parse is the recursive descent parser. It returns the parsed value and the
// index immediately after the consumed bytes.
func parse(data []byte, pos int) (PHPValue, int, error) {
	if pos >= len(data) {
		return nil, pos, errors.New("unexpected end of input")
	}

	switch data[pos] {
	case 'N':
		return parseNull(data, pos)
	case 'b':
		return parseBool(data, pos)
	case 'i':
		return parseInt(data, pos)
	case 'd':
		return parseFloat(data, pos)
	case 's':
		return parseString(data, pos)
	case 'a':
		return parseArray(data, pos)
	case 'O':
		return parseObject(data, pos)
	default:
		return nil, pos, fmt.Errorf("unexpected type tag %q at position %d", data[pos], pos)
	}
}

// expectByte checks that data[pos] == ch and returns pos+1.
func expectByte(data []byte, pos int, ch byte) (int, error) {
	if pos >= len(data) {
		return pos, fmt.Errorf("expected %q at position %d but got EOF", ch, pos)
	}
	if data[pos] != ch {
		return pos, fmt.Errorf("expected %q at position %d but got %q", ch, pos, data[pos])
	}
	return pos + 1, nil
}

func parseNull(data []byte, pos int) (PHPValue, int, error) {
	p, err := expectByte(data, pos, 'N')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ';')
	if err != nil {
		return nil, pos, err
	}
	return PHPNull{}, p, nil
}

func parseBool(data []byte, pos int) (PHPValue, int, error) {
	// b:0; or b:1;
	p, err := expectByte(data, pos, 'b')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ':')
	if err != nil {
		return nil, pos, err
	}
	if p >= len(data) {
		return nil, pos, errors.New("unexpected end of input in bool")
	}
	val := data[p] == '1'
	p++
	p, err = expectByte(data, p, ';')
	if err != nil {
		return nil, pos, err
	}
	return PHPBool{Value: val}, p, nil
}

func parseInt(data []byte, pos int) (PHPValue, int, error) {
	// i:<number>;
	p, err := expectByte(data, pos, 'i')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ':')
	if err != nil {
		return nil, pos, err
	}
	semi := indexOf(data, ';', p)
	if semi < 0 {
		return nil, pos, errors.New("missing semicolon in int")
	}
	n, err := strconv.ParseInt(string(data[p:semi]), 10, 64)
	if err != nil {
		return nil, pos, fmt.Errorf("invalid int: %w", err)
	}
	return PHPInt{Value: n}, semi + 1, nil
}

func parseFloat(data []byte, pos int) (PHPValue, int, error) {
	// d:<number>;
	p, err := expectByte(data, pos, 'd')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ':')
	if err != nil {
		return nil, pos, err
	}
	semi := indexOf(data, ';', p)
	if semi < 0 {
		return nil, pos, errors.New("missing semicolon in float")
	}
	f, err := strconv.ParseFloat(string(data[p:semi]), 64)
	if err != nil {
		return nil, pos, fmt.Errorf("invalid float: %w", err)
	}
	return PHPFloat{Value: f}, semi + 1, nil
}

func parseString(data []byte, pos int) (PHPValue, int, error) {
	// s:<len>:"<value>";
	p, err := expectByte(data, pos, 's')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ':')
	if err != nil {
		return nil, pos, err
	}
	colon := indexOf(data, ':', p)
	if colon < 0 {
		return nil, pos, errors.New("missing colon in string length")
	}
	length, err := strconv.Atoi(string(data[p:colon]))
	if err != nil {
		return nil, pos, fmt.Errorf("invalid string length: %w", err)
	}
	p = colon + 1
	p, err = expectByte(data, p, '"')
	if err != nil {
		return nil, pos, err
	}
	if p+length > len(data) {
		return nil, pos, errors.New("string length exceeds data")
	}
	val := string(data[p : p+length])
	p += length
	p, err = expectByte(data, p, '"')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ';')
	if err != nil {
		return nil, pos, err
	}
	return PHPString{Value: val}, p, nil
}

func parseArray(data []byte, pos int) (PHPValue, int, error) {
	// a:<count>:{<key><value>...}
	p, err := expectByte(data, pos, 'a')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ':')
	if err != nil {
		return nil, pos, err
	}
	colon := indexOf(data, ':', p)
	if colon < 0 {
		return nil, pos, errors.New("missing colon in array count")
	}
	count, err := strconv.Atoi(string(data[p:colon]))
	if err != nil {
		return nil, pos, fmt.Errorf("invalid array count: %w", err)
	}
	p = colon + 1
	p, err = expectByte(data, p, '{')
	if err != nil {
		return nil, pos, err
	}

	entries := make([]PHPArrayEntry, 0, count)
	for i := 0; i < count; i++ {
		var key, val PHPValue
		key, p, err = parse(data, p)
		if err != nil {
			return nil, pos, fmt.Errorf("array key %d: %w", i, err)
		}
		val, p, err = parse(data, p)
		if err != nil {
			return nil, pos, fmt.Errorf("array value %d: %w", i, err)
		}
		entries = append(entries, PHPArrayEntry{Key: key, Value: val})
	}
	p, err = expectByte(data, p, '}')
	if err != nil {
		return nil, pos, err
	}
	return PHPArray{Entries: entries}, p, nil
}

func parseObject(data []byte, pos int) (PHPValue, int, error) {
	// O:<classNameLen>:"<className>":<propCount>:{<key><value>...}
	p, err := expectByte(data, pos, 'O')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ':')
	if err != nil {
		return nil, pos, err
	}
	colon := indexOf(data, ':', p)
	if colon < 0 {
		return nil, pos, errors.New("missing colon in object class name length")
	}
	nameLen, err := strconv.Atoi(string(data[p:colon]))
	if err != nil {
		return nil, pos, fmt.Errorf("invalid class name length: %w", err)
	}
	p = colon + 1
	p, err = expectByte(data, p, '"')
	if err != nil {
		return nil, pos, err
	}
	if p+nameLen > len(data) {
		return nil, pos, errors.New("class name length exceeds data")
	}
	className := string(data[p : p+nameLen])
	p += nameLen
	p, err = expectByte(data, p, '"')
	if err != nil {
		return nil, pos, err
	}
	p, err = expectByte(data, p, ':')
	if err != nil {
		return nil, pos, err
	}

	// Property count
	colon2 := indexOf(data, ':', p)
	if colon2 < 0 {
		return nil, pos, errors.New("missing colon in object property count")
	}
	propCount, err := strconv.Atoi(string(data[p:colon2]))
	if err != nil {
		return nil, pos, fmt.Errorf("invalid property count: %w", err)
	}
	p = colon2 + 1
	p, err = expectByte(data, p, '{')
	if err != nil {
		return nil, pos, err
	}

	entries := make([]PHPArrayEntry, 0, propCount)
	for i := 0; i < propCount; i++ {
		var key, val PHPValue
		key, p, err = parse(data, p)
		if err != nil {
			return nil, pos, fmt.Errorf("object property key %d: %w", i, err)
		}
		val, p, err = parse(data, p)
		if err != nil {
			return nil, pos, fmt.Errorf("object property value %d: %w", i, err)
		}
		entries = append(entries, PHPArrayEntry{Key: key, Value: val})
	}
	p, err = expectByte(data, p, '}')
	if err != nil {
		return nil, pos, err
	}
	return PHPObject{ClassName: className, Entries: entries}, p, nil
}

// ---------------------------------------------------------------------------
// Walk applies fn to every PHPValue node in depth-first order, replacing
// each node with the returned value.
// ---------------------------------------------------------------------------

// Walk traverses the PHPValue tree depth-first and calls fn on every node.
// fn may return a replacement value (or the same value unchanged).
func Walk(v PHPValue, fn func(PHPValue) PHPValue) PHPValue {
	switch t := v.(type) {
	case PHPArray:
		for i, e := range t.Entries {
			t.Entries[i].Key = Walk(e.Key, fn)
			t.Entries[i].Value = Walk(e.Value, fn)
		}
		return fn(t)
	case PHPObject:
		for i, e := range t.Entries {
			t.Entries[i].Key = Walk(e.Key, fn)
			t.Entries[i].Value = Walk(e.Value, fn)
		}
		return fn(t)
	default:
		return fn(v)
	}
}

// ---------------------------------------------------------------------------
// IsSerialized returns true if data looks like a PHP-serialized value.
// ---------------------------------------------------------------------------

// IsSerialized performs a quick check to determine whether data appears to be
// PHP-serialized. It checks for the leading type tag followed by : or ;.
func IsSerialized(data []byte) bool {
	if len(data) < 2 {
		return false
	}
	switch data[0] {
	case 'a', 'O', 's':
		return data[1] == ':'
	case 'i', 'd', 'b':
		return data[1] == ':'
	case 'N':
		return data[1] == ';'
	}
	return false
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// indexOf returns the index of the first occurrence of ch in data starting
// at offset, or -1 if not found.
func indexOf(data []byte, ch byte, offset int) int {
	for i := offset; i < len(data); i++ {
		if data[i] == ch {
			return i
		}
	}
	return -1
}
