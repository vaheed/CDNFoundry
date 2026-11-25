"""Matched CRS rule ids travel from the scoring pass to the access log without any matched data, and the pieces agree."""

import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


class MatchedRuleReporting(unittest.TestCase):
    def test_the_image_makes_every_scoring_rule_append_its_id_and_checks_it_did(self) -> None:
        dockerfile = read("docker/openresty/Dockerfile")
        self.assertIn("tx.cdnf_matched=%{tx.cdnf_matched}|%{rule.id}", dockerfile)
        self.assertRegex(dockerfile, r"-gt 20")
        # ModSecurity ends a quoted setvar value at a comma, so the separator must not be one.
        self.assertNotRegex(dockerfile, r"cdnf_matched=%\{tx\.cdnf_matched\},")

    def test_the_scoring_listener_answers_with_ids_only(self) -> None:
        score = read("docker/openresty/waf-score.conf")
        self.assertIn("&rules=%{tx.cdnf_matched}", score)
        redirect = re.search(r"redirect:'([^']*)'", score).group(1)
        self.assertEqual(sorted(re.findall(r"(\w+)=%\{", redirect)), ["rce", "rules", "sqli", "total", "xss"])

    def test_the_cell_keeps_at_most_eight_numeric_rule_ids_and_names_a_rule_besides_the_blocking_evaluation(self) -> None:
        lua = read("docker/openresty/runtime.lua")
        self.assertIn("local MAX_MATCHED_RULES = 8", lua)
        self.assertIn('gmatch("%d+")', lua)
        self.assertIn("id >= 900000 and id <= 999999", lua)
        self.assertIn("ngx.var.cdn_waf_rule_ids = table.concat(scores.rule_ids", lua)
        self.assertIn("primary_rule(request.scores.rule_ids) or 949110", lua)

    def test_every_hop_carries_the_field(self) -> None:
        self.assertIn('"waf_rule_ids":"$cdn_waf_rule_ids"', read("docker/nginx/openresty.conf"))
        self.assertIn('set $cdn_waf_rule_ids "";', read("docker/nginx/edge-runtime.conf"))
        self.assertIn("waf_rule_ids String DEFAULT ''", read("docker/clickhouse/init.sql"))
        migration = read("docker/clickhouse/migrations/2026_11_25_edge_event_waf_rule_ids.sql")
        self.assertIn("ADD COLUMN IF NOT EXISTS waf_rule_ids String DEFAULT ''", migration)
        vector = read("docker/vector/vector.yaml")
        self.assertIn(r"^[0-9]{6}(,[0-9]{6}){0,7}$", vector)
        self.assertIn("keep_matched_waf_rule_ids_and_drop_anything_else", vector)


if __name__ == "__main__":
    unittest.main()
