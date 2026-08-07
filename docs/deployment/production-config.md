---
title: Production Fleet Configuration Guide
description: Learn how to configure a production CDNFoundry fleet using a centralized INI-style configuration file for all IPs, domains, and settings.
---

# Production Fleet Configuration Guide

This guide explains how to configure a production CDNFoundry fleet using a centralized configuration file.

## Quick Start with Configuration File

Instead of editing multiple scripts and environment files, you can use a single configuration file to define your entire fleet topology.

### 1. Clone the Repository at a Specific Version

```bash
# Clone a specific release tag (recommended for production)
git clone --branch v1.0.0 --depth 1 https://github.com/vaheed/CDNFoundry.git
cd CDNFoundry

# Or clone a specific branch for testing
git clone --branch dev --depth 1 https://github.com/vaheed/CDNFoundry.git
cd CDNFoundry

# Or clone a specific commit
git clone https://github.com/vaheed/CDNFoundry.git
cd CDNFoundry
git checkout <commit-sha>
```

> **Important**: Always deploy from a pinned release tag or commit SHA in production. Never use mutable tags like `latest`, `main`, or major/minor version tags.

### 2. Create Your Configuration File

Create a file named `fleet-config.ini` (or use the provided template):

```ini
# CDNFoundry Fleet Configuration
# Simple INI-style configuration for production deployment

[control]
hostname = control.ops.example.com
ipv4 = 192.0.2.10
region = global
location = primary

[pop]
# Define each PoP (Point of Presence) node
# Format: name = ipv4,region,location
pop-1 = 198.51.100.20,europe,amsterdam
pop-2 = 198.51.100.30,europe,frankfurt

[fleet]
operator_domain = ops.example.com
platform_domain = example.net
release = v1.0.0
acme_email = operations@example.com
enable_monitoring = true
enable_logs = true
state_dir = /var/lib/cdnfoundry-fleet
output_dir = /var/lib/cdnfoundry-fleet/bundles

[backup]
# Optional: S3-compatible backup configuration
enabled = false
repository = s3:https://object-storage.example/bucket/cdnfoundry-control
region = us-east-1
access_key_id = 
secret_access_key = 
password_file = /etc/cdnfoundry/secrets/restic-password
```

### 3. Generate Fleet State from Configuration

Use the fleet generator CLI to create your fleet state from the configuration file:

```bash
# Initialize fleet state from config
sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  setup \
  --non-interactive

# Or step-by-step:
sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  init

# Add nodes from config
sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  add-node --node control-1

sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  add-node --node pop-1

sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  add-node --node pop-2

# Configure monitoring and backups
sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  configure-monitoring --mode colocated

sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  configure-backups --mode s3

# Validate and render bundles
sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  validate

sudo ./scripts/cdnfoundry-fleet \
  --config fleet-config.ini \
  render
```

### 4. Configuration File Format Reference

#### `[control]` Section

| Key | Description | Required | Example |
| --- | --- | --- | --- |
| `hostname` | Fully qualified domain name for control plane | Yes | `control.ops.example.com` |
| `ipv4` | Public IPv4 address | Yes | `192.0.2.10` |
| `ipv6` | Public IPv6 address (optional) | No | `2001:db8::10` |
| `region` | Geographic region | Yes | `global`, `europe`, `us-east` |
| `location` | Datacenter/location identifier | Yes | `primary`, `ams1`, `fra2` |

#### `[pop]` Section

Define each edge/DNS node as a key-value pair:

```ini
[pop]
pop-1 = 198.51.100.20,europe,amsterdam
pop-2 = 198.51.100.30,europe,frankfurt
pop-3 = 203.0.113.40,us-east,new-york
```

Format: `<name> = <ipv4>,<region>,<location>`

Optional IPv6: `<name> = <ipv4>,<ipv6>,<region>,<location>`

#### `[fleet]` Section

| Key | Description | Required | Default |
| --- | --- | --- | --- |
| `operator_domain` | Operator DNS domain | Yes | - |
| `platform_domain` | CDN platform domain | Yes | - |
| `release` | Exact release tag or commit SHA | Yes | - |
| `acme_email` | ACME/Let's Encrypt contact | Yes | - |
| `enable_monitoring` | Enable Prometheus/Grafana | No | `false` |
| `enable_logs` | Enable centralized logging | No | `false` |
| `state_dir` | Fleet state directory | No | `/var/lib/cdnfoundry-fleet` |
| `output_dir` | Bundle output directory | No | `${state_dir}/bundles` |

#### `[backup]` Section

| Key | Description | Required | Example |
| --- | --- | --- | --- |
| `enabled` | Enable Restic backups | No | `true`/`false` |
| `repository` | S3 repository URL | If enabled | `s3:https://...` |
| `region` | S3 region | No | `us-east-1` |
| `access_key_id` | S3 access key | If enabled | - |
| `secret_access_key` | S3 secret key | If enabled | - |
| `password_file` | Path to Restic password file | If enabled | `/etc/.../restic-password` |

### 5. Using the Configuration with Example Scripts

The example scripts now support reading from a configuration file:

```bash
# Using three-node-production.sh with config
cd /opt/CDNFoundry
sudo env CONFIG_FILE=/path/to/fleet-config.ini \
  ./deploy/production/examples/three-node-production.sh

# The script will read all values from the config file
# instead of requiring environment variables
```

### 6. Validating Your Configuration

Before deploying, validate your configuration:

```bash
# Check configuration syntax
./scripts/cdnfoundry-fleet --config fleet-config.ini doctor

# Validate fleet state
sudo ./scripts/cdnfoundry-fleet \
  --state-dir /var/lib/cdnfoundry-fleet \
  validate

# Preview what will be generated
sudo ./scripts/cdnfoundry-fleet \
  --state-dir /var/lib/cdnfoundry-fleet \
  status
```

### 7. Template Configuration File

A template configuration file is provided at `deploy/production/fleet-config.template`:

```bash
# Copy the template
cp deploy/production/fleet-config.template fleet-config.ini

# Edit with your values
nano fleet-config.ini

# Generate your fleet
sudo ./scripts/cdnfoundry-fleet --config fleet-config.ini setup
```

## Migration from Manual Configuration

If you have existing manual configurations:

1. Export your current settings to a config file
2. Validate the config file produces the same output
3. Switch to config-driven workflow

```bash
# Export current fleet state to config format
sudo ./scripts/cdnfoundry-fleet \
  --state-dir /var/lib/cdnfoundry-fleet \
  export-config > fleet-config.ini
```

## Best Practices

1. **Version Control**: Keep your configuration file in a separate, secure repository
2. **Secrets Management**: Never store secrets in the config file; use `--from-file` or environment variables
3. **Validation**: Always run `validate` before `render`
4. **Backup**: Backup your configuration file along with fleet state
5. **Documentation**: Comment your configuration file with site-specific notes

## Troubleshooting

### Configuration File Not Found

Ensure the path is absolute or relative to your current directory:

```bash
sudo ./scripts/cdnfoundry-fleet --config /absolute/path/to/fleet-config.ini init
```

### Invalid Configuration Format

Check for:

- Missing section headers `[section]`
- Incorrect key-value separators (use `=`)
- Missing required fields
- Invalid IP addresses or hostnames

Run validation:

```bash
./scripts/cdnfoundry-fleet --config fleet-config.ini doctor
```

### Nodes Not Appearing in Rendered Bundles

1. Verify all nodes are defined in the `[pop]` section
2. Run `add-node` for each node after `init`
3. Check that `render` completes without errors
