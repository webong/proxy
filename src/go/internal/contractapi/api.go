// Package contractapi records the source contract without depending on compiler
// version-specific export data. It is used only by readiness tooling and tests.
package contractapi

import (
	"bytes"
	"fmt"
	"go/ast"
	"go/format"
	"go/parser"
	"go/token"
	"path/filepath"
	"sort"
	"strings"
)

func Snapshot(root string) (map[string][]string, error) {
	result := map[string][]string{}
	for _, pkg := range []string{"httpprofile", "httpclient", "socketprofile", "tcpdial", "udpdial", "internal/socks5"} {
		files, err := filepath.Glob(filepath.Join(root, pkg, "*.go"))
		if err != nil {
			return nil, err
		}
		for _, path := range files {
			if strings.HasSuffix(path, "_test.go") {
				continue
			}
			set := token.NewFileSet()
			file, err := parser.ParseFile(set, path, nil, 0)
			if err != nil {
				return nil, err
			}
			for _, decl := range file.Decls {
				if pkg == "internal/socks5" {
					if f, ok := decl.(*ast.FuncDecl); ok {
						if f.Recv == nil {
							continue
						}
						typ := f.Recv.List[0].Type
						if star, ok := typ.(*ast.StarExpr); ok {
							typ = star.X
						}
						name, ok := typ.(*ast.Ident)
						if !ok || (name.Name != "Address" && name.Name != "ReplyError") {
							continue
						}
					} else {
						g, ok := decl.(*ast.GenDecl)
						if !ok || g.Tok != token.TYPE {
							continue
						}
						kept := []ast.Spec{}
						for _, s := range g.Specs {
							t := s.(*ast.TypeSpec)
							if t.Name.Name == "Address" || t.Name.Name == "ReplyError" {
								kept = append(kept, s)
							}
						}
						g.Specs = kept
						if len(kept) == 0 {
							continue
						}
					}
				}
				if !ast.FilterDecl(decl, ast.IsExported) {
					continue
				}
				if f, ok := decl.(*ast.FuncDecl); ok {
					if f.Recv != nil {
						typ := f.Recv.List[0].Type
						if star, ok := typ.(*ast.StarExpr); ok {
							typ = star.X
						}
						name, ok := typ.(*ast.Ident)
						if !ok || !ast.IsExported(name.Name) {
							continue
						}
					}
					f.Body = nil
				}
				if g, ok := decl.(*ast.GenDecl); ok && g.Tok == token.IMPORT {
					continue
				}
				if g, ok := decl.(*ast.GenDecl); ok && g.Tok == token.VAR {
					for _, s := range g.Specs {
						v := s.(*ast.ValueSpec)
						for _, name := range v.Names {
							result[pkg] = append(result[pkg], "var "+name.Name)
						}
					}
					continue
				}
				ast.Inspect(decl, func(node ast.Node) bool {
					switch n := node.(type) {
					case *ast.StructType:
						n.Fields.List = publicFields(n.Fields.List)
					}
					return true
				})
				var buffer bytes.Buffer
				if err := format.Node(&buffer, set, decl); err != nil {
					return nil, fmt.Errorf("%s: %w", path, err)
				}
				result[pkg] = append(result[pkg], buffer.String())
			}
		}
		sort.Strings(result[pkg])
		if len(result[pkg]) == 0 {
			return nil, fmt.Errorf("empty public API for %s", pkg)
		}
	}
	return result, nil
}

func publicFields(fields []*ast.Field) []*ast.Field {
	result := []*ast.Field{}
	for _, field := range fields {
		if len(field.Names) == 0 {
			result = append(result, field)
			continue
		}
		names := []*ast.Ident{}
		for _, name := range field.Names {
			if ast.IsExported(name.Name) {
				names = append(names, name)
			}
		}
		if len(names) > 0 {
			field.Names = names
			result = append(result, field)
		}
	}
	return result
}
