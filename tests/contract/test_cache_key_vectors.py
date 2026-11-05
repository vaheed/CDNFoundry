"""The Lua cell runtime must compute the cache-key target exactly as the fixture says.

The PHP purge implementation runs the same fixture in CacheKeyVectorsTest.
"""

import json
import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
FIXTURE = ROOT / "tests/fixtures/cache_key_vectors.json"
MODULE = ROOT / "docker/openresty"


def lua_string(value: str) -> str:
    return '"' + "".join("\\%d" % byte for byte in value.encode()) + '"'


def expand(vector: dict) -> str:
    if "target_repeat" in vector:
        r = vector["target_repeat"]
        return r["prefix"] + r["separator"].join([r["item"]] * r["count"])
    return vector["target"]


class LuaCacheKeyVectors(unittest.TestCase):
    def test_every_vector_matches(self):
        interpreter = next((shutil.which(name) for name in ("luajit", "lua5.1", "lua", "lua5.4") if shutil.which(name)), None)
        if interpreter is None:
            self.skipTest("no Lua interpreter available")
        vectors = json.loads(FIXTURE.read_text())["vectors"]
        lines = ['package.path = %s .. "/?.lua;" .. package.path' % lua_string(str(MODULE)),
                 'local key = require "cache_key"', "local failures = 0"]
        for vector in vectors:
            parameters = "{" + ",".join(lua_string(p) for p in vector.get("parameters", [])) + "}"
            policy = lua_string(vector["policy"]) if "policy" in vector else "nil"
            expected = lua_string(vector["expected"]) if vector["expected"] is not None else "nil"
            reason = lua_string(vector["reason"]) if "reason" in vector else "nil"
            lines.append(
                "do local got, why = key.target(%s, %s, %s); if got ~= %s or (%s ~= nil and why ~= %s) then "
                "failures = failures + 1; print(%s, tostring(got), tostring(why)) end end"
                % (lua_string(expand(vector)), policy, parameters, expected, reason, reason, lua_string(vector["name"]))
            )
        lines.append("os.exit(failures == 0 and 0 or 1)")
        with tempfile.TemporaryDirectory() as directory:
            script = pathlib.Path(directory) / "run.lua"
            script.write_text("\n".join(lines))
            result = subprocess.run([interpreter, str(script)], capture_output=True, text=True, cwd=directory)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
