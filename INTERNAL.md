# Internal development notes

## Updating the docker image used by the GH action

1. Login in to Docker Hub: `docker login -u archtechx`
1. Shut down containers: `composer docker-down`
1. Build the image: `DOCKER_DEFAULT_PLATFORM=linux/amd64 docker compose build --no-cache`
1. Start containers again, using the amd64 image for the `test` service: `composer docker-up`
1. Verify that tests pass on the new image: `composer test`
1. Tag a new image: `docker tag tenancy-test archtechx/tenancy:latest`
1. Push the image: `docker push archtechx/tenancy:latest`
1. Optional: Rebuild the image again locally for arm64: `composer docker-rebuild`

## Debugging GitHub Actions

The `ci.yml` workflow includes support for [act](https://github.com/nektos/act).

To run all tests using act, run `composer act`. To run only certain tests using act, use `composer act-input "FILTER='some test name'"` or `composer act -- --input "FILTER='some test name'"`.

Helpful note: GHA doesn't mount the project at /var/www/html like the docker compose setup does. This can be observed in act where the inner container's filesystem structure will match the host.

Also, for debugging act you can just add a job that does `sleep 1h` and then `docker ps` + `docker exec -it <id> bash`.
