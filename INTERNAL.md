# Internal development notes

## Updating the docker image used by the GH action

1. Login in to Docker Hub: `docker login -u archtechx -p`
2. Build the image (probably shut down docker-compose containers first): `docker-compose build --no-cache`
3. Tag a new image: `docker tag tenancy_test archtechx/tenancy:latest`
4. Push the image: `docker push archtechx/tenancy:latest`
