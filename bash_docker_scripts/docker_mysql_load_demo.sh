#!/bin/bash

docker exec -i ldn_mysql mysql -u ldn_user -pldn_password ldn_inbox < ../database/seeds/demo_data.sql

