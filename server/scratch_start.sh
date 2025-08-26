#!/bin/sh

docker compose down

docker volume rm -f lime_data_dev lime_database_dev
docker network rm -f lime_network_dev

docker compose up -d