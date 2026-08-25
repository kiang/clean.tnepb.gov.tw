#!/bin/bash

BASEDIR=$(dirname "$(dirname "$(readlink -f "$0")")")
DATADIR="$BASEDIR/data"
DAY_OF_WEEK=$(date +%u)

cd "$BASEDIR" || exit 1

# Pull latest scripts
git pull --quiet

# Run vehicle crawler (every execution = every 5 minutes via crontab)
/usr/bin/php "$BASEDIR/scripts/crawler.php"

# Run carline crawler on Tuesday (day 2)
if [ "$DAY_OF_WEEK" -eq 2 ]; then
    /usr/bin/php "$BASEDIR/scripts/carline_crawler.php"
fi

# Push data repo if it has a remote
cd "$DATADIR" || exit 0
if git remote | grep -q .; then
    git pull --quiet --rebase 2>/dev/null
    git push --quiet 2>/dev/null
fi
