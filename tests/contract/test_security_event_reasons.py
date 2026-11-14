"""Every reason the Lua runtime can raise must be accepted by the control plane heartbeat."""

import json
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
CONTRACT = json.loads((ROOT / "core/resources/contracts/security-event-reasons.json").read_text())
REASONS = CONTRACT["reasons"]
NON_ESCALATING = CONTRACT["non_escalating"]
LUA = (ROOT / "docker/openresty/runtime.lua").read_text()


class SecurityEventReasonsContract(unittest.TestCase):
    def test_reasons_are_unique_identifiers_that_fit_the_column(self):
        self.assertEqual(len(REASONS), len(set(REASONS)))
        for reason in REASONS:
            self.assertRegex(reason, r"^[a-z][a-z0-9_]{0,47}$")

    def test_every_reason_the_runtime_raises_is_listed(self):
        raised = set(re.findall(r'security_reject\(\d+, "([a-z_]+)"\)', LUA))
        raised |= set(re.findall(r'cdn_security_reason = "([a-z_]+)"', LUA))
        raised |= set(re.findall(r'key_reason == "([a-z_]+)"', LUA))
        raised.discard("")
        self.assertTrue(raised)
        self.assertEqual(sorted(raised - set(REASONS)), [])

    def test_every_listed_reason_appears_in_the_runtime_or_an_edge_config(self):
        sources = LUA + "".join(p.read_text() for p in (ROOT / "docker").rglob("*.conf"))
        for reason in REASONS:
            self.assertIn(reason, sources, reason)

    def test_the_reasons_that_never_escalate_are_listed_reasons_without_duplicates(self):
        self.assertEqual(len(NON_ESCALATING), len(set(NON_ESCALATING)))
        self.assertEqual(sorted(set(NON_ESCALATING) - set(REASONS)), [])

    def test_the_cell_and_the_control_plane_agree_which_reasons_escalate(self):
        block = re.search(r"local ESCALATION_EXEMPT = \{(.*?)\n\}", LUA, re.S)
        self.assertIsNotNone(block)
        in_cell = set(re.findall(r"([a-z_]+) = true", block.group(1)))
        self.assertEqual(sorted(in_cell ^ set(NON_ESCALATING)), [])

    def test_a_client_the_cell_contains_never_counts_toward_escalating_the_domain(self):
        self.assertIn("client_temporarily_blocked", NON_ESCALATING)


if __name__ == "__main__":
    unittest.main()
