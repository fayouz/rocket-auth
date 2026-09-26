# syntax=docker/dockerfile:1.7
# Static documentation site (with /changelog). Build context: the repository root, for CHANGELOG.md:
#   docker build -f docs/Dockerfile .
FROM node:22-alpine AS build
WORKDIR /src/docs
COPY docs/package.json docs/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY docs/ ./
COPY CHANGELOG.md /src/CHANGELOG.md
RUN npm run generate

FROM nginx:1.29-alpine
COPY docs/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /src/docs/.output/public /usr/share/nginx/html
EXPOSE 80
