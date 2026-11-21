#!/usr/bin/env python3
"""Rough CDNFoundry hardware calculator.

The coefficients below come from the staging measurements in
docs/operations/staging-qualification-2026-11-15.md, taken on 4 vCPU Intel Xeon
E5-2697 v4 virtual machines serving a small cached object. They are planning
figures, not guarantees. Change them here when you measure your own hardware.
"""

from __future__ import annotations

import argparse
import math

# Requests per second one edge vCPU serves, with the cells, gateway, log shipper and DNS on the same host.
EDGE_RPS_PER_VCPU_WAF_OFF = 275
EDGE_RPS_PER_VCPU_WAF_ON = 80
# Busy connections (sending requests back to back) one edge vCPU holds with a median latency under about 150 ms.
CONNECTIONS_PER_VCPU_WAF_OFF = 125
CONNECTIONS_PER_VCPU_WAF_ON = 25
# DNS: queries per second one vCPU answers with no loss, DNSdist, PowerDNS and the Vector that takes its dnstap stream together
# (12,000 loss-free on about 3.9 cores). Set from
# docs/operations/performance-audit-2026-11-21.md; the earlier 8,000 counted truncated replies as answers.
DNS_QPS_PER_VCPU = 3000
# One domain is served by one cell per edge host, so a busy domain can need more than its cell's CPU while the
# host is idle. Requests per second one CPU of a cell serves on its own (cached 10 KB object, many connections),
# from tests/e2e/edge_cell_benchmark.py in docs/operations/performance-audit-2026-11-21.md.
CELL_RPS_PER_CPU_WAF_OFF = 2500
CELL_RPS_PER_CPU_WAF_ON = 340
DEFAULT_CELL_CPUS = 0.5
# Plan to use at most this share of the measured limit, so a spike does not push the host into queueing.
DEFAULT_UTILIZATION = 0.6
DEFAULT_HOST_VCPU = 8
# Memory per domain: the edge agent holds about 19 KB, every worker of a cell about 35 KB.
AGENT_KB_PER_DOMAIN = 19
CELL_KB_PER_DOMAIN_PER_WORKER = 35
CELL_WORKERS = 2
EDGE_BASE_GB = 3.0
# Telemetry: compressed ClickHouse bytes per request event, and the average load as a share of the peak.
TELEMETRY_BYTES_PER_EVENT = 49
AVERAGE_TO_PEAK = 0.3


def control_host(domains: int) -> str:
    if domains <= 1000:
        return "1 host, 8 vCPU, 16 GB, 100 GB SSD (control plane and telemetry together)"
    return ("control 8 vCPU / 16 GB / 200 GB NVMe, database 4 vCPU / 8 GB / 200 GB NVMe, "
            "telemetry 8 vCPU / 32 GB / 1 TB NVMe, monitoring 4 vCPU / 8 GB (estimate)")


def busiest_domain_cell_cpus(rps: float, waf: bool, utilization: float = DEFAULT_UTILIZATION) -> float:
    """CPUs one cell needs to carry one domain's peak on one edge host, at the planned utilization."""
    return rps / (CELL_RPS_PER_CPU_WAF_ON if waf else CELL_RPS_PER_CPU_WAF_OFF) / utilization


def calculate(peak_rps: float, waf_share: float, domains: int, average_kb: float, dns_qps: float,
              connections: int = 0, utilization: float = DEFAULT_UTILIZATION, high_availability: bool = True,
              host_vcpu: int = DEFAULT_HOST_VCPU, busiest_domain_rps: float = 0, busiest_domain_waf: bool = True,
              cell_cpus: float = DEFAULT_CELL_CPUS) -> dict:
    if not 0 <= waf_share <= 1:
        raise ValueError("waf_share must be between 0 and 1")
    if not 0 < utilization <= 1:
        raise ValueError("utilization must be above 0 and at most 1")
    if busiest_domain_rps < 0 or busiest_domain_rps > peak_rps:
        raise ValueError("busiest_domain_rps must be between 0 and peak_rps")
    if cell_cpus <= 0:
        raise ValueError("cell_cpus must be above 0")
    waf_rps = peak_rps * waf_share
    plain_rps = peak_rps - waf_rps
    dns_vcpu = dns_qps / DNS_QPS_PER_VCPU
    request_vcpu = waf_rps / EDGE_RPS_PER_VCPU_WAF_ON + plain_rps / EDGE_RPS_PER_VCPU_WAF_OFF
    # Blend the connection capacity the same way as the request mix.
    per_vcpu_connections = waf_share * CONNECTIONS_PER_VCPU_WAF_ON + (1 - waf_share) * CONNECTIONS_PER_VCPU_WAF_OFF
    connection_vcpu = connections / per_vcpu_connections if connections else 0
    edge_vcpu = (max(request_vcpu, connection_vcpu) + dns_vcpu) / utilization
    hosts = max(1, math.ceil(edge_vcpu / host_vcpu))
    if high_availability:
        # Lose one host and still carry the load; DNS needs at least two hosts anyway.
        hosts = max(2, hosts + 1)
    # Every edge host carries its share of the busiest domain on one cell. With the spare host out, the others share it.
    serving_hosts = max(1, hosts - 1) if high_availability else hosts
    busiest_cpus = busiest_domain_cell_cpus(busiest_domain_rps / serving_hosts, busiest_domain_waf, utilization)
    memory_gb = EDGE_BASE_GB + domains * (AGENT_KB_PER_DOMAIN + CELL_KB_PER_DOMAIN_PER_WORKER * CELL_WORKERS) / 1024 / 1024
    return {
        "edge_vcpu_needed": round(edge_vcpu, 1),
        "edge_hosts": hosts,
        "edge_host_vcpu": host_vcpu,
        "edge_host_ram_gb": max(8, math.ceil(memory_gb * 1.5 / 4) * 4),
        "edge_bandwidth_mbit": round(peak_rps * average_kb * 8 / 1000, 1),
        "dns_vcpu": round(dns_vcpu, 2),
        "telemetry_gb_per_day": round(peak_rps * AVERAGE_TO_PEAK * 86400 * TELEMETRY_BYTES_PER_EVENT / 1e9, 1),
        "control": control_host(domains),
        "busiest_domain_cell_cpus": round(busiest_cpus, 2),
        "busiest_domain_fits_cell": busiest_cpus <= cell_cpus,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Estimate CDNFoundry hosts from peak traffic.")
    parser.add_argument("--peak-rps", type=float, required=True, help="peak HTTP requests per second across all domains")
    parser.add_argument("--waf-share", type=float, default=1.0, help="share of requests on domains with the WAF on, 0 to 1")
    parser.add_argument("--domains", type=int, default=100, help="number of proxied domains")
    parser.add_argument("--connections", type=int, default=0, help="peak connections actively sending requests (idle keep-alive connections cost far less)")
    parser.add_argument("--average-kb", type=float, default=50, help="average response size in KB")
    parser.add_argument("--dns-qps", type=float, default=0, help="peak DNS queries per second")
    parser.add_argument("--utilization", type=float, default=DEFAULT_UTILIZATION, help="planned share of the measured limit")
    parser.add_argument("--host-vcpu", type=int, default=DEFAULT_HOST_VCPU, help="vCPU of each edge host")
    parser.add_argument("--no-ha", action="store_true", help="do not add a spare host")
    parser.add_argument("--busiest-domain-rps", type=float, default=0, help="peak requests per second of the single busiest domain")
    parser.add_argument("--busiest-domain-waf-off", action="store_true", help="the busiest domain has the WAF off")
    parser.add_argument("--cell-cpus", type=float, default=DEFAULT_CELL_CPUS, help="EDGE_CELL_CPUS of the cells, default 0.5")
    arguments = parser.parse_args()
    result = calculate(arguments.peak_rps, arguments.waf_share, arguments.domains, arguments.average_kb,
                       arguments.dns_qps, arguments.connections, arguments.utilization, not arguments.no_ha,
                       arguments.host_vcpu, arguments.busiest_domain_rps, not arguments.busiest_domain_waf_off,
                       arguments.cell_cpus)
    print(f"Edge CPU needed:   {result['edge_vcpu_needed']} vCPU at {arguments.utilization:.0%} utilization, DNS included")
    print(f"Edge hosts:        {result['edge_hosts']} x {result['edge_host_vcpu']} vCPU, {result['edge_host_ram_gb']} GB RAM each")
    print(f"Edge bandwidth:    {result['edge_bandwidth_mbit']} Mbit/s at peak, per fleet")
    print(f"DNS share of CPU:  {result['dns_vcpu']} vCPU")
    print(f"Telemetry growth:  about {result['telemetry_gb_per_day']} GB a day")
    print(f"Control plane:     {result['control']}")
    if arguments.busiest_domain_rps:
        verdict = "fits" if result["busiest_domain_fits_cell"] else f"does not fit: set EDGE_CELL_CPUS to at least {result['busiest_domain_cell_cpus']}"
        print(f"Busiest domain:    needs {result['busiest_domain_cell_cpus']} CPU on its cell per edge host; "
              f"{arguments.cell_cpus} CPU {verdict}")


if __name__ == "__main__":
    main()
