package main

import (
	"bytes"
	"net/netip"
	"strings"
	"testing"

	"github.com/oschwald/maxminddb-golang/v2"
)

func TestDualStackRecordsAndDeterminism(t *testing.T) {
	source := `{"created_at":1700000000}
{"network":"1.1.1.0/24","country":"AU","country_name":"Australia","continent":"OC","asn":13335,"organization":"Cloudflare","anycast":true}
{"network":"2606:4700::/32","country":"US","country_name":"United States","continent":"NA","asn":13335,"organization":"Cloudflare","anonymous_proxy":true,"satellite":true,"hostile":true}
`
	var output, second bytes.Buffer
	if err := compile(strings.NewReader(source), &output); err != nil {
		t.Fatal(err)
	}
	if err := compile(strings.NewReader(source), &second); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(output.Bytes(), second.Bytes()) {
		t.Fatal("identical source generated different MMDB")
	}
	db, err := maxminddb.OpenBytes(output.Bytes())
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	for _, address := range []string{"1.1.1.1", "2606:4700:4700::1111"} {
		var result struct {
			ASN          uint32 `maxminddb:"autonomous_system_number"`
			Organization string `maxminddb:"autonomous_system_organization"`
			Country      struct {
				Code string `maxminddb:"iso_code"`
			} `maxminddb:"country"`
			Continent struct {
				Code string `maxminddb:"code"`
			} `maxminddb:"continent"`
			Traits map[string]bool `maxminddb:"traits"`
		}
		if err := db.Lookup(netip.MustParseAddr(address)).Decode(&result); err != nil {
			t.Fatal(err)
		}
		if result.ASN != 13335 || result.Organization != "Cloudflare" {
			t.Fatalf("lost ASN record: %+v", result)
		}
		if address == "1.1.1.1" {
			if result.Country.Code != "AU" || result.Continent.Code != "OC" || !result.Traits["is_anycast"] {
				t.Fatalf("lost IPv4 geography/flags: %+v", result)
			}
		} else if result.Country.Code != "US" || result.Continent.Code != "NA" || !result.Traits["is_hostile"] || !result.Traits["is_satellite_provider"] || !result.Traits["is_anonymous_proxy"] {
			t.Fatalf("lost IPv6 geography/flags: %+v", result)
		}
	}
	if db.Metadata.BuildEpoch != 1700000000 {
		t.Fatal("source timestamp not retained")
	}
}

func TestRejectMalformedOrEmptySource(t *testing.T) {
	for _, source := range []string{"", `{}`, "{\"created_at\":1700000000}\n", "{\"created_at\":1700000000}\n{\"network\":\"bad\"}\n"} {
		var output bytes.Buffer
		if compile(strings.NewReader(source), &output) == nil {
			t.Fatalf("accepted %q", source)
		}
		if output.Len() != 0 {
			t.Fatal("invalid source wrote a partial database")
		}
	}
}

func TestMoreSpecificNetworkReplacesParentRecord(t *testing.T) {
	source := `{"created_at":1700000000}
{"network":"10.0.0.0/8","country":"GB","continent":"EU","asn":64500,"anycast":true}
{"network":"10.1.0.0/16","country":"DE","continent":"EU","asn":64501}
`
	var output bytes.Buffer
	if err := compile(strings.NewReader(source), &output); err != nil {
		t.Fatal(err)
	}
	db, err := maxminddb.OpenBytes(output.Bytes())
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	for _, sample := range []struct {
		address string
		asn     uint32
		country string
		anycast bool
	}{
		{"10.2.1.1", 64500, "GB", true}, {"10.1.1.1", 64501, "DE", false},
	} {
		var result struct {
			ASN     uint32 `maxminddb:"autonomous_system_number"`
			Country struct {
				Code string `maxminddb:"iso_code"`
			} `maxminddb:"country"`
			Traits struct {
				Anycast bool `maxminddb:"is_anycast"`
			} `maxminddb:"traits"`
		}
		if err := db.Lookup(netip.MustParseAddr(sample.address)).Decode(&result); err != nil {
			t.Fatal(err)
		}
		if result.ASN != sample.asn || result.Country.Code != sample.country || result.Traits.Anycast != sample.anycast {
			t.Fatalf("incorrect longest-prefix record for %s: %+v", sample.address, result)
		}
	}
}
