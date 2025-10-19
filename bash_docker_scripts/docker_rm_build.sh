# Stop containers
sudo docker stop ldn_php ldn_mysql

# Remove them
sudo docker rm ldn_php ldn_mysql

# start
sudo docker compose up --build
