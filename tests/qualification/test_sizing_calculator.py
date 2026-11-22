import importlib.util
from pathlib import Path
import sys
import unittest

SPEC = importlib.util.spec_from_file_location('sizing_calculator', Path(__file__).resolve().parents[2] / 'scripts/sizing-calculator.py')
calculator = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = calculator
SPEC.loader.exec_module(calculator)


class SizingCalculatorTests(unittest.TestCase):
    def test_waf_traffic_needs_far_more_cpu_than_plain_traffic(self):
        plain = calculator.calculate(1000, 0, 100, 50, 0)['edge_vcpu_needed']
        protected = calculator.calculate(1000, 1, 100, 50, 0)['edge_vcpu_needed']
        self.assertGreater(protected, plain * 3)

    def test_one_edge_host_measured_limit_is_the_planning_input(self):
        # A 4 vCPU host measured about 320 requests per second with the WAF on; at 60 % that is about 190.
        result = calculator.calculate(190, 1, 20, 10, 0, utilization=0.6, high_availability=False, host_vcpu=4)
        self.assertEqual(result['edge_hosts'], 1)
        self.assertLessEqual(result['edge_vcpu_needed'], 4)

    def test_high_availability_adds_a_spare_and_never_goes_below_two_hosts(self):
        self.assertEqual(calculator.calculate(10, 0, 1, 10, 0, high_availability=True)['edge_hosts'], 2)
        self.assertEqual(calculator.calculate(10, 0, 1, 10, 0, high_availability=False)['edge_hosts'], 1)

    def test_many_connections_can_dominate_the_request_rate(self):
        low = calculator.calculate(100, 1, 20, 10, 0, connections=0)['edge_vcpu_needed']
        high = calculator.calculate(100, 1, 20, 10, 0, connections=2000)['edge_vcpu_needed']
        self.assertGreater(high, low)

    def test_bandwidth_follows_request_rate_and_size(self):
        self.assertEqual(calculator.calculate(1000, 0, 10, 100, 0)['edge_bandwidth_mbit'], 800.0)

    def test_a_busy_domain_can_outgrow_its_cell_while_the_fleet_has_room(self):
        # 600 requests per second on a WAF domain, shared by the serving hosts, needs more than a default 0.5 CPU cell.
        result = calculator.calculate(2000, 1, 100, 50, 0, busiest_domain_rps=1200)
        self.assertFalse(result['busiest_domain_fits_cell'])
        self.assertGreater(result['busiest_domain_cell_cpus'], 0.5)
        roomy = calculator.calculate(2000, 1, 100, 50, 0, busiest_domain_rps=1200, cell_cpus=4)
        self.assertTrue(roomy['busiest_domain_fits_cell'])
        self.assertEqual(roomy['edge_hosts'], result['edge_hosts'], 'the cell size does not change the host count')

    def test_the_waf_costs_the_busy_domain_more_cell_cpu(self):
        protected = calculator.busiest_domain_cell_cpus(300, waf=True)
        plain = calculator.busiest_domain_cell_cpus(300, waf=False)
        self.assertGreater(protected, plain * 5)

    def test_the_spare_host_does_not_count_for_the_busy_domain(self):
        # Two hosts with high availability: one may be down, so the other carries the whole domain.
        result = calculator.calculate(100, 1, 10, 10, 0, busiest_domain_rps=100)
        self.assertEqual(result['edge_hosts'], 2)
        self.assertAlmostEqual(result['busiest_domain_cell_cpus'], calculator.busiest_domain_cell_cpus(100, waf=True), places=2)

    def test_invalid_busiest_domain_inputs_are_rejected(self):
        with self.assertRaises(ValueError):
            calculator.calculate(100, 1, 10, 10, 0, busiest_domain_rps=200)
        with self.assertRaises(ValueError):
            calculator.calculate(100, 1, 10, 10, 0, cell_cpus=0)

    def test_invalid_shares_are_rejected(self):
        with self.assertRaises(ValueError):
            calculator.calculate(100, 1.5, 10, 10, 0)
        with self.assertRaises(ValueError):
            calculator.calculate(100, 1, 10, 10, 0, utilization=0)


if __name__ == '__main__':
    unittest.main()
