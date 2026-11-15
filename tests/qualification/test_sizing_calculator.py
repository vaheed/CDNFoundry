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

    def test_invalid_shares_are_rejected(self):
        with self.assertRaises(ValueError):
            calculator.calculate(100, 1.5, 10, 10, 0)
        with self.assertRaises(ValueError):
            calculator.calculate(100, 1, 10, 10, 0, utilization=0)


if __name__ == '__main__':
    unittest.main()
