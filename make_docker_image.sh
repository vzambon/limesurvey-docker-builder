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

echo "Cloning LimeSurvey repository"
if [ ! -d "LimeSurvey" ]; then
    echo "LimeSurvey directory does not exist, cloning repository..."
    git clone git@github.com:LimeSurvey/LimeSurvey.git
else
    echo "LimeSurvey directory already exists, skipping clone."
fi

echo "Checking out tag: $TAG"
git -C LimeSurvey checkout tags/$TAG

TAG="${TAG%%+*}"
echo "Building Docker image with tag: $TAG"
echo "User ID: $USER_ID, Group ID: $GROUP_ID"
docker build \
    --build-arg USER_ID=$USER_ID \
    --build-arg GROUP_ID=$GROUP_ID \
    -t limesurvey:$TAG .

rm -rf LimeSurvey
