# Build stage: compila TypeScript a dist/
FROM node:22-alpine AS builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm install
COPY tsconfig.json ./
COPY src ./src
RUN npm run build

# Runtime stage (Railway)
FROM node:22-alpine
RUN apk add --no-cache curl
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm install --omit=dev && rm -rf /root/.npm /tmp/*
COPY --from=builder /app/dist ./dist
COPY src/migrations ./src/migrations
RUN mkdir -p /app/data
VOLUME /app/data
ENV NODE_ENV=production
EXPOSE 3000
CMD ["node", "dist/index.js"]
