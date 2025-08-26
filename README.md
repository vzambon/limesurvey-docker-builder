# LimeSurvey Docker Image Builder

This project provides a set of scripts to build a Docker image for a specific version of LimeSurvey from its official GitHub repository.

## Purpose

The main goal is to create a Docker image for any given tag (release) of LimeSurvey. This allows for deploying specific versions of LimeSurvey in a containerized environment.

## Building a new image

To build a new Docker image from a specific LimeSurvey version, you need to use the `make_docker_image.sh` script.

1.  **Find a tag**: Go to the official LimeSurvey tags page on GitHub: [https://github.com/LimeSurvey/LimeSurvey/tags](https://github.com/LimeSurvey/LimeSurvey/tags) and choose the version you want to build.

2.  **Run the build script**: Execute the `make_docker_image.sh` script with the chosen tag.

    ```bash
    ./make_docker_image.sh --tag <your-chosen-tag>
    ```

    For example, to build an image for version `6.14.3+250617`, you would run:

    ```bash
    ./make_docker_image.sh --tag 6.14.3+250617
    ```

The script will:
1.  Clone the LimeSurvey repository if it doesn't exist locally.
2.  Checkout the specified tag.
3.  Build the Docker image with the tag `limesurvey:<tag_without_plus_suffix>`.
