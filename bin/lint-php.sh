#!/usr/bin/env bash
set -euo pipefail

find . \
	-name vendor -prune -o \
	-name external -prune -o \
	-name build -prune -o \
	-name .git -prune -o \
	-name '*.php' -print0 \
	| xargs -0 -n1 php -l
