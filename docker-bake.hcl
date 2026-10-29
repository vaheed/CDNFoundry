# Build once; qualification and publication consume the same image archives.
variable "SOURCE_REVISION" { default = "development" }
variable "RELEASE_VERSION" { default = "development" }
variable "BUILD_DATE" { default = "1970-01-01T00:00:00Z" }

group "default" { targets = ["core", "web", "edge-control", "edge-runtime", "edge-agent", "edge-gateway", "mmdb-updater"] }
group "application" { targets = ["core", "web", "edge-control"] }
group "edge" { targets = ["edge-runtime"] }
group "agents" { targets = ["edge-agent", "edge-gateway", "mmdb-updater"] }

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

