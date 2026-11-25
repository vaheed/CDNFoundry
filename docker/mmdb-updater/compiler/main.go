// mmdb-compiler preserves IPFire records in the MMDB layout consumed by the edge and PowerDNS.
package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"os"

	"github.com/maxmind/mmdbwriter"
	"github.com/maxmind/mmdbwriter/mmdbtype"
)

type record struct {
	Network        string `json:"network"`
	Country        string `json:"country"`
	CountryName    string `json:"country_name"`
	Continent      string `json:"continent"`
	ASN            uint32 `json:"asn"`
	Organization   string `json:"organization"`
	Anycast        bool   `json:"anycast"`
	Satellite      bool   `json:"satellite"`
	AnonymousProxy bool   `json:"anonymous_proxy"`
	Hostile        bool   `json:"hostile"`
}

func compile(input io.Reader, output io.Writer) error {
	scanner := bufio.NewScanner(input)
	scanner.Buffer(make([]byte, 4096), 16384)
	if !scanner.Scan() {
		return fmt.Errorf("missing source metadata")
	}
	var metadata struct {
		CreatedAt int64 `json:"created_at"`
	}
	if err := json.Unmarshal(scanner.Bytes(), &metadata); err != nil || metadata.CreatedAt <= 0 {
		return fmt.Errorf("invalid source timestamp")
	}
	tree, err := mmdbwriter.New(mmdbwriter.Options{
		DatabaseType: "CDNFoundry-IPFire", IPVersion: 6, RecordSize: 28,
		BuildEpoch: metadata.CreatedAt, DisableIPv4Aliasing: true, Languages: []string{"en"},
		IncludeReservedNetworks: true,
		Description:             map[string]string{"en": "IPFire Location https://www.ipfire.org/location/; CC BY-SA 4.0 https://creativecommons.org/licenses/by-sa/4.0/; converted to MMDB by CDNFoundry"},
	})
	if err != nil {
		return err
	}
	count := 0
	for scanner.Scan() {
		count++
		if count > 4000000 {
			return fmt.Errorf("network count exceeds 4000000")
		}
		var row record
		if err := json.Unmarshal(scanner.Bytes(), &row); err != nil {
			return fmt.Errorf("record %d: %w", count, err)
		}
		_, network, err := net.ParseCIDR(row.Network)
		if err != nil {
			return fmt.Errorf("record %d: %w", count, err)
		}
		data := mmdbtype.Map{
			"autonomous_system_number":       mmdbtype.Uint32(row.ASN),
			"autonomous_system_organization": mmdbtype.String(row.Organization),
			"traits": mmdbtype.Map{
				"is_anycast":            mmdbtype.Bool(row.Anycast),
				"is_satellite_provider": mmdbtype.Bool(row.Satellite),
				"is_anonymous_proxy":    mmdbtype.Bool(row.AnonymousProxy),
				"is_hostile":            mmdbtype.Bool(row.Hostile),
			},
		}
		if row.Country != "" {
			data["country"] = mmdbtype.Map{"iso_code": mmdbtype.String(row.Country),
				"names": mmdbtype.Map{"en": mmdbtype.String(row.CountryName)}}
		}
		if row.Continent != "" {
			data["continent"] = mmdbtype.Map{"code": mmdbtype.String(row.Continent)}
		}
		if err := tree.Insert(network, data); err != nil {
			return fmt.Errorf("record %d: %w", count, err)
		}
	}
	if err := scanner.Err(); err != nil {
		return err
	}
	if count == 0 {
		return fmt.Errorf("no networks to compile")
	}
	_, err = tree.WriteTo(output)
	return err
}

func main() {
	if len(os.Args) != 2 {
		fmt.Fprintln(os.Stderr, "usage: mmdb-compiler OUTPUT < records.jsonl")
		os.Exit(2)
	}
	file, err := os.Create(os.Args[1])
	if err == nil {
		err = compile(os.Stdin, file)
	}
	if file != nil {
		if closeErr := file.Close(); err == nil {
			err = closeErr
		}
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
