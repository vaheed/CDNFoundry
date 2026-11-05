package main

import (
	"crypto/rand"
	"encoding/binary"
	"errors"
	"net"
	"net/netip"
	"strings"
	"time"
)

// runDNSProbe asks one public nameserver address for the platform zone's SOA, the way a resolver
// would. The control plane uses the answer to decide whether an IPv6 nameserver address may be
// published: a glue record that nothing answers on would make resolvers time out.
func runDNSProbe(task edgeTask) (map[string]any, string) {
	address, err := netip.ParseAddr(task.Payload.ProbeAddress)
	if err != nil || !address.Is6() || blockedIP(address.String(), nil, nil) {
		return map[string]any{"status": "failed", "failure_reason": "invalid_probe_address"}, "failed"
	}
	zone := strings.TrimSuffix(strings.ToLower(task.Payload.ProbeZone), ".")
	if zone == "" || len(zone) > 253 {
		return map[string]any{"status": "failed", "failure_reason": "invalid_probe_zone"}, "failed"
	}
	if err := probeDNS(net.JoinHostPort(address.String(), "53"), zone); err != nil {
		return map[string]any{"status": "failed", "failure_reason": truncate(err.Error(), 100)}, "failed"
	}
	return map[string]any{"status": "completed"}, "succeeded"
}

func truncate(value string, limit int) string {
	if len(value) > limit {
		return value[:limit]
	}
	return value
}

// probeDNS sends a SOA query over UDP and, if the answer is truncated, over TCP. It succeeds only for
// an authoritative NOERROR answer that echoes the query: anything else means the address is not
// serving the zone.
func probeDNS(endpoint, zone string) error {
	query, id, err := soaQuery(zone)
	if err != nil {
		return err
	}
	response, err := exchangeUDP(endpoint, query)
	if err == nil && len(response) >= 12 && response[2]&0x02 != 0 { // TC: retry over TCP
		response, err = exchangeTCP(endpoint, query)
	}
	if err != nil {
		return errors.New("no_answer")
	}
	return checkSOAResponse(response, id)
}

func soaQuery(zone string) ([]byte, uint16, error) {
	var seed [2]byte
	if _, err := rand.Read(seed[:]); err != nil {
		return nil, 0, err
	}
	id := binary.BigEndian.Uint16(seed[:])
	message := make([]byte, 12, 12+len(zone)+8)
	binary.BigEndian.PutUint16(message[0:], id)
	binary.BigEndian.PutUint16(message[2:], 0x0000) // standard query, no recursion desired
	binary.BigEndian.PutUint16(message[4:], 1)      // one question
	for _, label := range strings.Split(zone, ".") {
		if label == "" || len(label) > 63 {
			return nil, 0, errors.New("invalid_probe_zone")
		}
		message = append(message, byte(len(label)))
		message = append(message, label...)
	}
	message = append(message, 0, 0, 6, 0, 1) // root, type SOA, class IN
	return message, id, nil
}

func checkSOAResponse(response []byte, id uint16) error {
	if len(response) < 12 || binary.BigEndian.Uint16(response[0:]) != id || response[2]&0x80 == 0 {
		return errors.New("invalid_response")
	}
	if response[2]&0x04 == 0 {
		return errors.New("not_authoritative")
	}
	if response[3]&0x0f != 0 {
		return errors.New("rcode_not_noerror")
	}
	if binary.BigEndian.Uint16(response[6:]) == 0 {
		return errors.New("no_soa_answer")
	}
	return nil
}

func exchangeUDP(endpoint string, query []byte) ([]byte, error) {
	connection, err := net.DialTimeout("udp6", endpoint, 3*time.Second)
	if err != nil {
		return nil, err
	}
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(3 * time.Second))
	if _, err = connection.Write(query); err != nil {
		return nil, err
	}
	buffer := make([]byte, 4096)
	n, err := connection.Read(buffer)
	return buffer[:n], err
}

func exchangeTCP(endpoint string, query []byte) ([]byte, error) {
	connection, err := net.DialTimeout("tcp6", endpoint, 3*time.Second)
	if err != nil {
		return nil, err
	}
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(3 * time.Second))
	framed := append([]byte{byte(len(query) >> 8), byte(len(query))}, query...)
	if _, err = connection.Write(framed); err != nil {
		return nil, err
	}
	var length [2]byte
	if _, err = readFull(connection, length[:]); err != nil {
		return nil, err
	}
	size := int(binary.BigEndian.Uint16(length[:]))
	if size < 12 || size > 4096 {
		return nil, errors.New("invalid_response")
	}
	body := make([]byte, size)
	_, err = readFull(connection, body)
	return body, err
}

func readFull(connection net.Conn, buffer []byte) (int, error) {
	read := 0
	for read < len(buffer) {
		n, err := connection.Read(buffer[read:])
		read += n
		if err != nil {
			return read, err
		}
	}
	return read, nil
}
