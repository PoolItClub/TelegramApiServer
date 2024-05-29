#!/usr/bin/env sh

docker-compose-wait \
&& nice -n 20 php server.php -e=sessions/.env.session --docker "$@"