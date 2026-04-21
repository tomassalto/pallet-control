FROM node:22-alpine

# Baileys needs these for native crypto
RUN apk add --no-cache python3 make g++

WORKDIR /app

COPY package*.json ./
RUN npm ci --omit=dev

COPY . .

EXPOSE 3001

CMD ["node", "index.js"]
