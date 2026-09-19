"""Fetch pinned fixture images with bounded retries before starting runtime tests."""
from __future__ import annotations

import re
import subprocess
import time


def ensure_image(reference: str) -> None:
    if not re.fullmatch(r'[^\s]+@sha256:[0-9a-f]{64}', reference):
        raise ValueError('Fixture images must use an immutable digest')
    local = subprocess.run(['docker', 'image', 'inspect', reference],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
    if local.returncode == 0:
        return
    error = ''
    for attempt in range(4):
        try:
            result = subprocess.run(['docker', 'pull', reference],
                                    capture_output=True, text=True, timeout=120)
            if result.returncode == 0:
                return
            error = result.stderr[-2000:]
        except subprocess.TimeoutExpired:
            error = 'Docker pull exceeded its 120-second deadline'
        if attempt < 3:
            time.sleep(2 ** (attempt + 1))
    raise RuntimeError(f'Could not fetch pinned fixture image after four attempts: {reference}: {error}')
