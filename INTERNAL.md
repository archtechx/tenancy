# Internal development notes

## Updating the docker image used by the GH action

1. Login in to Docker Hub: `docker login -u archtechx -p`
1. Build the image (probably shut down docker-compose containers first): `DOCKER_DEFAULT_PLATFORM=linux/amd64 docker-compose build --no-cache`
1. Verify that tests pass on the new image: `composer test`
1. Tag a new image: `docker tag tenancy-test archtechx/tenancy:latest`
1. Push the image: `docker push archtechx/tenancy:latest`
