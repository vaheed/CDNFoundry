#!/usr/bin/env sh
# Sets the file owners a control node needs before any container starts. Run as root.
. "$(dirname "$0")/common.sh"
prepare_permissions
