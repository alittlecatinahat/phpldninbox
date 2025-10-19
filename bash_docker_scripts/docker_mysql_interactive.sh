#!/bin/bash
# connect interactively to mysql in docker container
docker exec -it ldn_mysql mysql -u ldn_user -pldn_password ldn_inbox 
