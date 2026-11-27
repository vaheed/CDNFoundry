// The proof-of-work solver of the challenge page. It finds the smallest counter, from a start and with a step, for which
// SHA-256(seed + ":" + counter) begins with `bits` zero bits. The page runs it in a few Web Workers and falls back to the page's own
// thread. It is plain synchronous JavaScript, so it needs neither a secure context nor crypto.subtle, and the cell's check
// (a SHA-256 of the same text) is the only authority on whether an answer is valid. The page inlines this whole file; keep it free of
// closing script tags and of anything that is not ASCII.
(function () {
    "use strict";
    var K = new Int32Array([
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
        0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
        0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
        0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ]);
    var INITIAL = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
    var w = new Int32Array(64);

    // One SHA-256 compression of the 16 words at block[offset..], updating the 8 words of state in place.
    function compress(state, block, offset) {
        var i, a, b, c, d, e, f, g, h, t1, t2, x, y;
        for (i = 0; i < 16; i++) w[i] = block[offset + i];
        for (i = 16; i < 64; i++) {
            x = w[i - 15]; y = w[i - 2];
            w[i] = (((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10)) + w[i - 7]
                + (((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3)) + w[i - 16] | 0;
        }
        a = state[0]; b = state[1]; c = state[2]; d = state[3]; e = state[4]; f = state[5]; g = state[6]; h = state[7];
        for (i = 0; i < 64; i++) {
            t1 = h + (((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7))) + ((e & f) ^ (~e & g)) + K[i] + w[i] | 0;
            t2 = (((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10))) + ((a & b) ^ (a & c) ^ (b & c)) | 0;
            h = g; g = f; f = e; e = d + t1 | 0; d = c; c = b; b = a; a = t1 + t2 | 0;
        }
        state[0] = state[0] + a | 0; state[1] = state[1] + b | 0; state[2] = state[2] + c | 0; state[3] = state[3] + d | 0;
        state[4] = state[4] + e | 0; state[5] = state[5] + f | 0; state[6] = state[6] + g | 0; state[7] = state[7] + h | 0;
    }

    // The first state word of SHA-256(seed + ":" + counter) for every counter is computed from the state after the seed's whole
    // 64 byte blocks, so only the last one or two blocks are compressed per attempt.
    function search(seed, bits, start, step, attempts) {
        var prefix = seed + ":", length = prefix.length, whole = length >> 6, tail = length - whole * 64;
        var bytes = new Uint8Array(128), words = new Int32Array(32), mid = new Int32Array(INITIAL), state = new Int32Array(8), digits = new Uint8Array(16);
        var i, n, c, size, blocks, total;
        var head = new Int32Array(16), bs = new Uint8Array(64);
        for (i = 0; i < whole; i++) {
            for (n = 0; n < 64; n++) bs[n] = prefix.charCodeAt(i * 64 + n);
            for (n = 0; n < 16; n++) head[n] = (bs[4 * n] << 24) | (bs[4 * n + 1] << 16) | (bs[4 * n + 2] << 8) | bs[4 * n + 3];
            compress(mid, head, 0);
        }
        for (i = 0; i < tail; i++) bytes[i] = prefix.charCodeAt(whole * 64 + i);
        for (c = start; attempts > 0; c += step, attempts--) {
            n = 0; size = c;
            do { digits[n++] = 48 + size % 10; size = Math.floor(size / 10); } while (size > 0);
            for (i = 0; i < n; i++) bytes[tail + i] = digits[n - 1 - i];
            total = tail + n;
            blocks = total + 9 <= 64 ? 1 : 2;
            bytes[total] = 0x80;
            for (i = total + 1; i < blocks * 64 - 4; i++) bytes[i] = 0;
            size = (length + n) * 8;
            bytes[blocks * 64 - 4] = (size >>> 24) & 255; bytes[blocks * 64 - 3] = (size >>> 16) & 255;
            bytes[blocks * 64 - 2] = (size >>> 8) & 255; bytes[blocks * 64 - 1] = size & 255;
            for (i = 0; i < blocks * 16; i++) words[i] = (bytes[4 * i] << 24) | (bytes[4 * i + 1] << 16) | (bytes[4 * i + 2] << 8) | bytes[4 * i + 3];
            state.set(mid);
            compress(state, words, 0);
            if (blocks === 2) compress(state, words, 16);
            if (Math.clz32(state[0]) >= bits) return c;
        }
        return -1;
    }

    var api = { search: search };
    if (typeof self !== "undefined" && typeof self.postMessage === "function" && typeof window === "undefined") {
        // Inside a worker: search the counters of this worker's lane in chunks, so a message can end the search.
        self.onmessage = function (event) {
            var job = event.data, found = -1, c = job.start, chunk = 20000;
            while (found < 0) {
                found = search(job.seed, job.bits, c, job.step, chunk);
                c += chunk * job.step;
            }
            self.postMessage(found);
        };
    }
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    else if (typeof globalThis !== "undefined") globalThis.chkSolver = api;
})();
