#!/bin/sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

USER_ID=$(id -u)
GROUP_ID=$(id -g)

# Parse command line options
OPTIONS=$(getopt -o t: --long tag: -- "$@")
if [ $? -ne 0 ]; then
    echo "Incorrect options provided"
    exit 1
fi
eval set -- "$OPTIONS"

TAG=''

while true; do
    case "$1" in
        -t|--tag) TAG="$2"; shift 2 ;;
        --) shift; break ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

VERSION="${TAG%%+*}"
echo "Building Docker image with tag: $TAG"
echo "User ID: $USER_ID, Group ID: $GROUP_ID"
docker build \
    --build-arg USER_ID=$USER_ID \
    --build-arg GROUP_ID=$GROUP_ID \
    --build-arg LIME_VERSION=$TAG \
    -t limesurvey:$VERSION .