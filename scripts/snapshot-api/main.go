// Command snapshot-api writes the Go source API baseline after explicit review.
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"github.com/webong/proxy/internal/contractapi"
	"os"
)

func main() {
	write := flag.Bool("write", false, "replace baseline after contract review")
	flag.Parse()
	api, err := contractapi.Snapshot(".")
	if err != nil {
		panic(err)
	}
	data, err := json.MarshalIndent(api, "", "  ")
	if err != nil {
		panic(err)
	}
	data = append(data, '\n')
	if *write {
		if err = os.WriteFile("spec/api/go-v0.3.json", data, 0644); err != nil {
			panic(err)
		}
	} else {
		fmt.Print(string(data))
	}
}
