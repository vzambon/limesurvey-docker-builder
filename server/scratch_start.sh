#!/bin/sh

docker compose down

docker volume rm -f lime_data lime_database
docker network rm -f lime_network

docker compose up -d