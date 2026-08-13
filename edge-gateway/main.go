package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

const version = "1.0.0"

var logger = slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo}))

type config struct {
	SchemaVersion int      `json:"schema_version"`
	Revision      uint64   `json:"revision"`
	GenerationID  string   `json:"generation_id"`
	Listeners     []string `json:"listeners"`
	Routes        []route  `json:"routes"`
}

type route struct {
	Address  string `json:"address"`
	Hostname string `json:"hostname"`
	HTTP     string `json:"http"`
	HTTPS    string `json:"https"`
}

type routingTable struct {
	revision     uint64
	generationID string
	routes       map[string]string
}

type gateway struct {
	configPath string
	stateDir   string
	metrics    string
	table      atomic.Pointer[routingTable]
	ready      atomic.Bool
	accepted   atomic.Uint64
	rejected   atomic.Uint64
	errors     atomic.Uint64
	active     atomic.Int64
	reloads    atomic.Uint64
	rejects    atomic.Uint64
	mu         sync.Mutex
	listeners  map[string]net.Listener
	listen     func(network, address string) (net.Listener, error)
	slots      chan struct{}
}

func main() {
	if len(os.Args) == 2 && os.Args[1] == "--version" {
		fmt.Println(version)
		return
	}
	g := &gateway{
		configPath: env("GATEWAY_CONFIG_FILE", "/var/lib/cdnfoundry/gateway/gateway.json"),
		stateDir:   env("GATEWAY_STATE_DIR", "/var/lib/cdnfoundry/gateway-state"),
		metrics:    env("GATEWAY_METRICS_ADDRESS", "127.0.0.1:9105"),
		listeners:  map[string]net.Listener{},
		slots:      make(chan struct{}, boundedIntegerEnv("GATEWAY_MAX_CONNECTIONS", 8192, 128, 65536)),
	}
	if err := os.MkdirAll(g.stateDir, 0700); err != nil {
		fatal(err)
	}
	if err := g.loadInitial(); err != nil {
		fatal(err)
	}
	logger.Info("edge gateway started", "event", "service_started", "version", version, "revision_id", g.table.Load().revision)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	go g.serveMetrics(ctx)
	go g.watch(ctx)
	go g.logRejectionSummaries(ctx)
	<-ctx.Done()
	g.close()
	logger.Info("edge gateway stopped", "event", "service_stopped")
}

func (g *gateway) loadInitial() error {
	candidate, candidateErr := os.ReadFile(g.configPath)
	if candidateErr == nil {
		candidateErr = g.activate(candidate)
		if candidateErr == nil {
			return nil
		}
	}
	lastValid, lastValidErr := os.ReadFile(filepath.Join(g.stateDir, "last-valid.json"))
	if lastValidErr == nil {
		lastValidErr = g.activate(lastValid)
		if lastValidErr == nil {
			logger.Warn("gateway candidate unavailable at startup; last valid map activated", "event", "gateway_last_valid_activated", "error", candidateErr.Error())
			return nil
		}
	}
	if errors.Is(candidateErr, os.ErrNotExist) && errors.Is(lastValidErr, os.ErrNotExist) {
		g.table.Store(&routingTable{generationID: "bootstrap-unconfigured", routes: map[string]string{}})
		logger.Warn("edge gateway waiting for its first candidate", "event", "gateway_bootstrap_waiting", "revision_id", 0)
		return nil
	}

	return fmt.Errorf("no valid gateway candidate or last-valid map: candidate: %v; last-valid: %w", candidateErr, lastValidErr)
}

func (g *gateway) watch(ctx context.Context) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	var signature string
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			info, err := os.Stat(g.configPath)
			if err != nil {
				continue
			}
			next := fmt.Sprintf("%d/%d", info.ModTime().UnixNano(), info.Size())
			if next == signature {
				continue
			}
			data, err := os.ReadFile(g.configPath)
			if err == nil {
				err = g.activate(data)
			}
			if err != nil {
				g.rejects.Add(1)
				logger.Error("gateway candidate rejected; last valid map preserved", "event", "gateway_candidate_rejected", "error_code", "candidate_validation_failed", "error", err.Error())
				continue
			}
			signature = next
		}
	}
}

func (g *gateway) activate(data []byte) error {
	var candidate config
	if len(data) > 32<<20 {
		return errors.New("gateway map exceeds 32 MiB")
	}
	if err := json.Unmarshal(data, &candidate); err != nil {
		return err
	}
	table, err := validate(candidate)
	if err != nil {
		return err
	}
	if current := g.table.Load(); current != nil && candidate.Revision < current.revision {
		return errors.New("gateway revision cannot move backwards")
	}
	commitListeners, rollbackListeners, err := g.prepareListeners(candidate.Listeners)
	if err != nil {
		return err
	}
	if err := atomicWrite(filepath.Join(g.stateDir, "last-valid.json"), data); err != nil {
		rollbackListeners()
		return err
	}
	g.table.Store(table)
	commitListeners()
	g.reloads.Add(1)
	g.ready.Store(true)
	logger.Info("gateway listener map activated", "event", "gateway_candidate_activated", "revision_id", candidate.Revision, "listeners", len(candidate.Listeners), "routes", len(candidate.Routes))
	return nil
}

func (g *gateway) logRejectionSummaries(ctx context.Context) {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()
	var previousRejected, previousErrors uint64
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			rejected, failures := g.rejected.Load(), g.errors.Load()
			if rejected > previousRejected || failures > previousErrors {
				logger.Warn("bounded gateway connection failures", "event", "gateway_connection_rejection_summary", "rejected", rejected-previousRejected, "errors", failures-previousErrors)
			}
			previousRejected, previousErrors = rejected, failures
		}
	}
}

func validate(candidate config) (*routingTable, error) {
	if candidate.SchemaVersion != 1 || len(candidate.Listeners) > 64 || len(candidate.Routes) > 100000 || (len(candidate.Listeners) == 0) != (len(candidate.Routes) == 0) {
		return nil, errors.New("invalid gateway map bounds")
	}
	listeners := map[string]bool{}
	for _, raw := range candidate.Listeners {
		host, port, err := net.SplitHostPort(raw)
		if err != nil || net.ParseIP(host) == nil || (port != "80" && port != "443") || listeners[raw] {
			return nil, errors.New("invalid or duplicate gateway listener")
		}
		listeners[raw] = true
	}
	routes := make(map[string]string, len(candidate.Routes)*2)
	for _, item := range candidate.Routes {
		ip := net.ParseIP(item.Address)
		host := canonicalHostname(item.Hostname)
		if ip == nil || host == "" {
			return nil, errors.New("invalid gateway route identity")
		}
		for protocol, target := range map[string]string{"http": item.HTTP, "https": item.HTTPS} {
			if target == "" {
				continue
			}
			targetHost, targetPort, err := net.SplitHostPort(target)
			if err != nil || net.ParseIP(targetHost) == nil && canonicalHostname(targetHost) == "" {
				return nil, errors.New("invalid gateway route target")
			}
			port, _ := strconv.Atoi(targetPort)
			if port < 1 || port > 65535 {
				return nil, errors.New("invalid gateway route target port")
			}
			key := protocol + "|" + ip.String() + "|" + host
			if _, exists := routes[key]; exists {
				return nil, errors.New("duplicate gateway route")
			}
			routes[key] = target
		}
	}
	if candidate.GenerationID == "" {
		candidate.GenerationID = fmt.Sprintf("legacy-%020d", candidate.Revision)
	}
	if len(candidate.GenerationID) < 22 || len(candidate.GenerationID) > 64 {
		return nil, errors.New("invalid gateway generation identity")
	}
	return &routingTable{revision: candidate.Revision, generationID: candidate.GenerationID, routes: routes}, nil
}

func (g *gateway) prepareListeners(desired []string) (func(), func(), error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	wanted := map[string]bool{}
	opened := map[string]net.Listener{}
	for _, address := range desired {
		wanted[address] = true
		if g.listeners[address] != nil {
			continue
		}
		listen := g.listen
		if listen == nil {
			listen = net.Listen
		}
		listener, err := listen("tcp", address)
		if err != nil {
			for _, item := range opened {
				_ = item.Close()
			}
			return nil, nil, fmt.Errorf("bind %s: %w", address, err)
		}
		opened[address] = listener
	}
	commit := func() {
		g.mu.Lock()
		defer g.mu.Unlock()
		for address, listener := range opened {
			g.listeners[address] = listener
			go g.accept(address, listener)
		}
		for address, listener := range g.listeners {
			if !wanted[address] {
				_ = listener.Close()
				delete(g.listeners, address)
			}
		}
	}
	rollback := func() {
		for _, listener := range opened {
			_ = listener.Close()
		}
	}
	return commit, rollback, nil
}

func (g *gateway) accept(address string, listener net.Listener) {
	backoff := 10 * time.Millisecond
	for {
		connection, err := listener.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return
			}
			g.errors.Add(1)
			time.Sleep(backoff)
			if backoff < time.Second {
				backoff *= 2
			}
			continue
		}
		backoff = 10 * time.Millisecond
		if g.slots != nil {
			select {
			case g.slots <- struct{}{}:
			default:
				g.rejected.Add(1)
				_ = connection.Close()
				continue
			}
		}
		g.active.Add(1)
		go func() {
			if g.slots != nil {
				defer func() { <-g.slots }()
			}
			defer g.active.Add(-1)
			defer connection.Close()
			_ = g.handle(address, connection)
		}()
	}
}

func (g *gateway) handle(listener string, client net.Conn) error {
	_ = client.SetDeadline(time.Now().Add(15 * time.Second))
	_, port, _ := net.SplitHostPort(listener)
	var name string
	var prefix []byte
	var err error
	protocol := "https"
	if port == "443" {
		name, prefix, err = readClientHello(client)
	} else {
		protocol = "http"
		name, prefix, err = readHTTP(client)
	}
	if err != nil {
		g.rejected.Add(1)
		return err
	}
	localHost, _, _ := net.SplitHostPort(client.LocalAddr().String())
	ip := net.ParseIP(localHost)
	table := g.table.Load()
	if table == nil || ip == nil {
		g.rejected.Add(1)
		return errors.New("gateway unavailable")
	}
	target := table.routes[protocol+"|"+ip.String()+"|"+name]
	if target == "" {
		g.rejected.Add(1)
		return errors.New("unknown gateway route")
	}
	upstream, err := net.DialTimeout("tcp", target, 3*time.Second)
	if err != nil {
		g.errors.Add(1)
		return err
	}
	defer upstream.Close()
	_ = upstream.SetDeadline(time.Now().Add(5 * time.Minute))
	if _, err := upstream.Write(proxyProtocolHeader(client.RemoteAddr(), client.LocalAddr())); err != nil {
		g.errors.Add(1)
		return err
	}
	if _, err := upstream.Write(prefix); err != nil {
		g.errors.Add(1)
		return err
	}
	g.accepted.Add(1)
	_ = client.SetDeadline(time.Time{})
	_ = upstream.SetDeadline(time.Time{})
	done := make(chan struct{}, 1)
	go func() { _, _ = io.Copy(upstream, client); _ = upstream.(*net.TCPConn).CloseWrite(); done <- struct{}{} }()
	_, _ = io.Copy(client, upstream)
	if tcp, ok := client.(*net.TCPConn); ok {
		_ = tcp.CloseWrite()
	}
	<-done
	return nil
}

func readHTTP(connection net.Conn) (string, []byte, error) {
	reader := bufio.NewReaderSize(connection, 16<<10)
	var buffer bytes.Buffer
	host := ""
	for lines := 0; lines < 102; lines++ {
		line, err := reader.ReadString('\n')
		buffer.WriteString(line)
		if err != nil || buffer.Len() > 16<<10 {
			return "", nil, errors.New("invalid HTTP preface")
		}
		if lines == 0 {
			fields := strings.Fields(strings.TrimSpace(line))
			if len(fields) != 3 || !strings.HasPrefix(fields[2], "HTTP/1.") {
				return "", nil, errors.New("invalid HTTP request line")
			}
			continue
		}
		if line == "\r\n" {
			if host == "" {
				return "", nil, errors.New("missing Host")
			}
			return host, buffer.Bytes(), nil
		}
		name, value, found := strings.Cut(strings.TrimSuffix(strings.TrimSuffix(line, "\n"), "\r"), ":")
		if !found || strings.TrimSpace(name) != name {
			return "", nil, errors.New("malformed HTTP header")
		}
		if strings.EqualFold(name, "host") {
			if host != "" {
				return "", nil, errors.New("duplicate Host")
			}
			value = strings.TrimSpace(value)
			if parsedHost, parsedPort, err := net.SplitHostPort(value); err == nil && parsedPort != "" {
				value = parsedHost
			}
			host = canonicalHostname(value)
			if host == "" {
				return "", nil, errors.New("invalid Host")
			}
		}
	}
	return "", nil, errors.New("too many HTTP headers")
}

func readClientHello(connection net.Conn) (string, []byte, error) {
	header := make([]byte, 5)
	if _, err := io.ReadFull(connection, header); err != nil || header[0] != 22 {
		return "", nil, errors.New("invalid TLS record")
	}
	size := int(binary.BigEndian.Uint16(header[3:5]))
	if size < 4 || size > 64<<10 {
		return "", nil, errors.New("invalid TLS ClientHello size")
	}
	body := make([]byte, size)
	if _, err := io.ReadFull(connection, body); err != nil {
		return "", nil, errors.New("truncated TLS ClientHello")
	}
	name, err := clientHelloSNI(body)
	return name, append(header, body...), err
}

func clientHelloSNI(data []byte) (string, error) {
	if len(data) < 42 || data[0] != 1 {
		return "", errors.New("not a TLS ClientHello")
	}
	p := 4 + 2 + 32
	if p >= len(data) {
		return "", errors.New("truncated TLS ClientHello")
	}
	p += 1 + int(data[p])
	if p+2 > len(data) {
		return "", errors.New("truncated TLS session")
	}
	p += 2 + int(binary.BigEndian.Uint16(data[p:p+2]))
	if p >= len(data) {
		return "", errors.New("truncated TLS ciphers")
	}
	p += 1 + int(data[p])
	if p+2 > len(data) {
		return "", errors.New("missing TLS extensions")
	}
	end := p + 2 + int(binary.BigEndian.Uint16(data[p:p+2]))
	p += 2
	if end > len(data) {
		return "", errors.New("truncated TLS extensions")
	}
	for p+4 <= end {
		kind, size := binary.BigEndian.Uint16(data[p:p+2]), int(binary.BigEndian.Uint16(data[p+2:p+4]))
		p += 4
		if p+size > end {
			return "", errors.New("truncated TLS extension")
		}
		if kind == 0 {
			extension := data[p : p+size]
			if len(extension) < 5 || int(binary.BigEndian.Uint16(extension[:2])) != len(extension)-2 || extension[2] != 0 {
				return "", errors.New("invalid TLS SNI")
			}
			nameSize := int(binary.BigEndian.Uint16(extension[3:5]))
			if nameSize != len(extension)-5 {
				return "", errors.New("invalid TLS SNI length")
			}
			name := canonicalHostname(string(extension[5:]))
			if name == "" {
				return "", errors.New("invalid TLS SNI name")
			}
			return name, nil
		}
		p += size
	}
	return "", errors.New("missing TLS SNI")
}

func canonicalHostname(value string) string {
	if len(value) < 1 || len(value) > 253 || value != strings.TrimSpace(value) || strings.HasSuffix(value, ".") {
		return ""
	}
	value = strings.ToLower(value)
	labels := strings.Split(value, ".")
	for _, label := range labels {
		if len(label) < 1 || len(label) > 63 || label[0] == '-' || label[len(label)-1] == '-' {
			return ""
		}
		for _, character := range label {
			if !(character >= 'a' && character <= 'z' || character >= '0' && character <= '9' || character == '-') {
				return ""
			}
		}
	}
	return value
}

func proxyProtocolHeader(source, destination net.Addr) []byte {
	header := []byte("\r\n\r\n\x00\r\nQUIT\n")
	src, _ := source.(*net.TCPAddr)
	dst, _ := destination.(*net.TCPAddr)
	if src == nil || dst == nil {
		return append(header, 0x20, 0x00, 0x00, 0x00)
	}
	if source4, destination4 := src.IP.To4(), dst.IP.To4(); source4 != nil && destination4 != nil {
		payload := make([]byte, 12)
		copy(payload, source4)
		copy(payload[4:], destination4)
		binary.BigEndian.PutUint16(payload[8:], uint16(src.Port))
		binary.BigEndian.PutUint16(payload[10:], uint16(dst.Port))
		return append(append(header, 0x21, 0x11, 0, 12), payload...)
	}
	payload := make([]byte, 36)
	copy(payload, src.IP.To16())
	copy(payload[16:], dst.IP.To16())
	binary.BigEndian.PutUint16(payload[32:], uint16(src.Port))
	binary.BigEndian.PutUint16(payload[34:], uint16(dst.Port))
	return append(append(header, 0x21, 0x21, 0, 36), payload...)
}

func (g *gateway) serveMetrics(ctx context.Context) {
	server := &http.Server{Addr: g.metrics, ReadHeaderTimeout: 2 * time.Second, IdleTimeout: 10 * time.Second, MaxHeaderBytes: 8 << 10}
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(writer http.ResponseWriter, _ *http.Request) {
		if !g.ready.Load() {
			http.Error(writer, "not ready", http.StatusServiceUnavailable)
			return
		}
		_, _ = io.WriteString(writer, "ok\n")
	})
	mux.HandleFunc("/metrics", func(writer http.ResponseWriter, _ *http.Request) {
		table := g.table.Load()
		revision, routes := uint64(0), 0
		if table != nil {
			revision, routes = table.revision, len(table.routes)
		}
		g.mu.Lock()
		listenerCount := len(g.listeners)
		g.mu.Unlock()
		values := map[string]uint64{
			"cdnfoundry_gateway_ready": boolNumber(g.ready.Load()), "cdnfoundry_gateway_active_revision": revision,
			"cdnfoundry_gateway_routes": uint64(routes), "cdnfoundry_gateway_listeners": uint64(listenerCount),
			"cdnfoundry_gateway_connections_active":         uint64(max(g.active.Load(), 0)),
			"cdnfoundry_gateway_connections_accepted_total": g.accepted.Load(), "cdnfoundry_gateway_connections_rejected_total": g.rejected.Load(),
			"cdnfoundry_gateway_errors_total": g.errors.Load(), "cdnfoundry_gateway_activations_total": g.reloads.Load(),
			"cdnfoundry_gateway_candidate_rejections_total": g.rejects.Load(),
		}
		keys := make([]string, 0, len(values))
		for key := range values {
			keys = append(keys, key)
		}
		sort.Strings(keys)
		for _, key := range keys {
			fmt.Fprintf(writer, "%s %d\n", key, values[key])
		}
		if table != nil {
			fmt.Fprintf(writer, "cdnfoundry_gateway_generation_info{generation_id=%q} 1\n", table.generationID)
		}
	})
	server.Handler = mux
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		fatal(err)
	}
}

func (g *gateway) close() {
	g.ready.Store(false)
	g.mu.Lock()
	defer g.mu.Unlock()
	for _, listener := range g.listeners {
		_ = listener.Close()
	}
}

func atomicWrite(path string, data []byte) error {
	temp := path + ".tmp"
	if err := os.WriteFile(temp, data, 0600); err != nil {
		return err
	}
	return os.Rename(temp, path)
}

func boolNumber(value bool) uint64 {
	if value {
		return 1
	}
	return 0
}

func env(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}
	return fallback
}

func boundedIntegerEnv(name string, fallback, minimum, maximum int) int {
	value, err := strconv.Atoi(os.Getenv(name))
	if err != nil || value < minimum || value > maximum {
		return fallback
	}
	return value
}

func fatal(err error) {
	logger.Error("edge gateway terminated", "event", "service_fatal", "error_code", "startup_failed", "error", err.Error())
	os.Exit(1)
}
