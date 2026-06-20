#!/bin/bash

mkdir -p "/tmp/GitHub/appdata.cleanup.ng/source/appdata.cleanup.ng/usr/local/emhttp/plugins/appdata.cleanup.ng/"

cp /usr/local/emhttp/plugins/appdata.cleanup.ng/* /tmp/GitHub/appdata.cleanup.ng/source/appdata.cleanup.ng/usr/local/emhttp/plugins/appdata.cleanup.ng -R -v -p
find . -maxdepth 9999 -noleaf -type f -name "._*" -exec rm -v "{}" \;

