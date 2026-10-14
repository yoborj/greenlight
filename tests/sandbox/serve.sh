#!/bin/bash
# Serve the sandbox on http://127.0.0.1:${PORT:-8899}
HERE="$(cd "$(dirname "$0")" && pwd)"; DIR="${1:-$HERE/.sandbox}"
cd "$DIR" && PHP_CLI_SERVER_WORKERS=4 exec "${PHP:-php}" -S "127.0.0.1:${PORT:-8899}" -t wordpress router.php
