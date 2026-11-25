"""The pinned WAF engine and rule set commits the control plane compares cells against must be the ones the image builds from."""

import json
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
DOCKERFILE = (ROOT / "docker/openresty/Dockerfile").read_text()
PINS = json.loads((ROOT / "core/resources/contracts/runtime-pins.json").read_text())
RUNTIME = (ROOT / "docker/openresty/runtime.lua").read_text()


class RuntimePins(unittest.TestCase):
    def test_the_pins_equal_the_image_build_arguments(self):
        crs = re.search(r"^ARG OWASP_CRS_COMMIT=([0-9a-f]{40})$", DOCKERFILE, re.M).group(1)
        engine = re.search(r"^ARG MODSECURITY_COMMIT=([0-9a-f]{40})$", DOCKERFILE, re.M).group(1)
        self.assertEqual(PINS, {"crs": crs, "modsecurity": engine})

    def test_the_image_records_the_commits_it_checked_out_and_the_cell_reports_them(self):
        self.assertIn("git -C /tmp/coreruleset rev-parse HEAD", DOCKERFILE)
        self.assertIn("COPY --from=module-builder /tmp/ruleset-versions.json /etc/cdnfoundry/ruleset-versions.json", DOCKERFILE)
        self.assertIn("ruleset_versions = ruleset_versions,", RUNTIME)
        self.assertIn('#value == 40', RUNTIME)


if __name__ == "__main__":
    unittest.main()
