// The page's solver must find exactly the counter a SHA-256 of the same text says: the cell checks that, and nothing else.
import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import { createRequire } from 'node:module';

const { search } = createRequire(import.meta.url)('../../docker/openresty/challenge_solver.js');
const lead = (seed, counter) => { const d = createHash('sha256').update(`${seed}:${counter}`).digest(); let n = 0; for (const b of d) { if (b === 0) { n += 8; continue; } return n + Math.clz32(b) - 24; } return n; };

let checked = 0;
// Seed lengths cross the 55, 64 and 119 byte block boundaries, where padding changes the number of blocks.
for (const length of [1, 20, 52, 53, 54, 55, 56, 57, 63, 64, 65, 100, 110, 117, 118, 119, 120, 128, 129, 200]) {
    for (let round = 0; round < 3; round++) {
        const seed = randomBytes(length).toString('hex').slice(0, length);
        for (const bits of [4, 8, 12]) {
            const found = search(seed, bits, 0, 1, 10_000_000);
            let expected = 0;
            while (lead(seed, expected) < bits) expected++;
            assert.equal(found, expected, `seed length ${length}, ${bits} bits`);
            checked++;
        }
    }
}
// A lane (start and step) only visits its own counters, and counters of every number of digits hash correctly.
const seed = 'k1.801.1700000000.16.aabbccddeeff.' + 'f'.repeat(64);
const lane = search(seed, 10, 3, 7, 1_000_000);
assert.equal((lane - 3) % 7, 0);
assert.ok(lead(seed, lane) >= 10);
for (const counter of [0, 9, 10, 99, 100, 99999, 1234567, 99999999, 2 ** 31 - 1, 2 ** 32 + 5]) {
    const found = search(seed, 0, counter, 1, 1);
    assert.equal(found, counter);
}
// A 16 bit search over a real seed length is quick enough to be done many times a second.
const started = performance.now();
let done = 0;
while (performance.now() - started < 1500) { search(seed, 16, done * 1000, 1, 1000); done++; }
console.log(`solver checked ${checked} searches; ${(done * 1000 / 1.5).toFixed(0)} hashes per second on this host`);
