# Build once; qualification and publication consume the same image archives.
variable "SOURCE_REVISION" { default = "development" }
variable "RELEASE_VERSION" { default = "development" }
variable "BUILD_DATE" { default = "1970-01-01T00:00:00Z" }

group "default" { targets = ["core", "web", "edge-control", "edge-runtime", "grafana", "pdns", "dnsdist", "prometheus", "alertmanager", "node-exporter", "edge-agent", "edge-gateway", "mmdb-updater", "loki", "vector", "postgres", "caddy"] }
group "application" { targets = ["core", "web", "edge-control"] }
group "edge" { targets = ["edge-runtime"] }
group "dashboards" { targets = ["grafana"] }
group "authoritative-dns" { targets = ["pdns"] }
group "dns-routing" { targets = ["dnsdist"] }
group "monitoring" { targets = ["prometheus", "alertmanager", "node-exporter"] }
group "agents" { targets = ["edge-agent", "edge-gateway", "mmdb-updater"] }
group "logging" { targets = ["loki", "vector"] }
group "storage" { targets = ["postgres", "caddy"] }

target "_common" {
  args = {
    SOURCE_REVISION = SOURCE_REVISION
    RELEASE_VERSION = RELEASE_VERSION
    BUILD_DATE = BUILD_DATE
  }
}

target "core" {
  inherits = ["_common"]
  context = "core"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-core:ci"]
  target = "production"
}

target "web" {
  inherits = ["_common"]
  context = "."
  dockerfile = "docker/nginx/Dockerfile.production"
  tags = ["ghcr.io/vaheed/cdnfoundry-web:ci"]
  target = "web"
  args = { CORE_IMAGE = "core-image" }
  contexts = { core-image = "target:core" }
}

target "edge-control" {
  inherits = ["_common"]
  context = "."
  dockerfile = "docker/nginx/Dockerfile.production"
  tags = ["ghcr.io/vaheed/cdnfoundry-edge-control:ci"]
  target = "edge-control"
  args = { CORE_IMAGE = "core-image" }
  contexts = { core-image = "target:core" }
}

target "edge-runtime" {
  inherits = ["_common"]
  context = "."
  dockerfile = "docker/openresty/Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-edge-runtime:ci"]
}

target "grafana" {
  inherits = ["_common"]
  context = "docker/grafana"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-grafana:ci"]
}

target "pdns" {
  inherits = ["_common"]
  context = "docker/pdns"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-pdns:ci"]
}

target "dnsdist" {
  inherits = ["_common"]
  context = "docker/dnsdist"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-dnsdist:ci"]
}

target "prometheus" {
  inherits = ["_common"]
  context = "docker/prometheus"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-prometheus:ci"]
}

target "alertmanager" {
  inherits = ["_common"]
  context = "docker/alertmanager"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-alertmanager:ci"]
}

target "node-exporter" {
  inherits = ["_common"]
  context = "docker/node-exporter"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-node-exporter:ci"]
}

target "edge-agent" {
  inherits = ["_common"]
  context = "edge-agent"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-edge-agent:ci"]
}

target "edge-gateway" {
  inherits = ["_common"]
  context = "edge-gateway"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-edge-gateway:ci"]
}

target "mmdb-updater" {
  inherits = ["_common"]
  context = "docker/mmdb-updater"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-mmdb-updater:ci"]
}

target "loki" {
  inherits = ["_common"]
  context = "docker/loki"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-loki:ci"]
}

target "vector" {
  inherits = ["_common"]
  context = "docker/vector-runtime"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-vector:ci"]
}

target "postgres" {
  inherits = ["_common"]
  context = "docker/postgres"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-postgres:ci"]
}

target "caddy" {
  inherits = ["_common"]
  context = "docker/caddy"
  dockerfile = "Dockerfile"
  tags = ["ghcr.io/vaheed/cdnfoundry-caddy:ci"]
}
