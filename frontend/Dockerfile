# syntax=docker/dockerfile:1.7
FROM node:22-alpine AS build
# git: the rocket-core layer is an npm dependency from GitHub.
RUN apk add --no-cache git
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM node:22-alpine AS prod
WORKDIR /app
ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000
COPY --from=build /app/.output ./.output
# Version of the interface ("git describe --tags", passed by the CI).
ARG APP_VERSION=dev
ENV NUXT_PUBLIC_APP_VERSION=${APP_VERSION}
USER node
EXPOSE 3000
# Runtime configuration: NUXT_PUBLIC_API_BASE (browser -> API), NUXT_API_INTERNAL_BASE (server -> API).
CMD ["node", ".output/server/index.mjs"]
