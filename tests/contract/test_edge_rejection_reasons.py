"""The edge agent and the control plane must share one rejection-reason list."""

import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
CONTROL = ROOT / "core/resources/contracts/edge-rejection-reasons.json"
AGENT = ROOT / "edge-agent/rejection_reasons.json"


class EdgeRejectionReasonsContract(unittest.TestCase):
    def test_both_copies_are_identical(self):
        self.assertEqual(CONTROL.read_bytes(), AGENT.read_bytes())

    def test_reasons_are_unique_lowercase_identifiers(self):
        reasons = json.loads(AGENT.read_text())["reasons"]
        self.assertEqual(len(reasons), len(set(reasons)))
        for reason in reasons:
            self.assertRegex(reason, r"^[a-z][a-z0-9_]{0,63}$")

    def test_agent_constants_match_the_list(self):
        source = (ROOT / "edge-agent/artifact_apply.go").read_text()
        reasons = json.loads(AGENT.read_text())["reasons"]
        for reason in reasons:
            self.assertIn('"' + reason + '"', source)


if __name__ == "__main__":
    unittest.main()
