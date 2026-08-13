FROM node:20-alpine AS build

WORKDIR /app

ARG VITE_API_URL
ARG VITE_BRAND_NAME=CampaignsHub
ARG VITE_BRAND_DOMAIN=campaignshub.io
ARG VITE_INFLUENCERS_UGC=false

ENV VITE_API_URL=$VITE_API_URL
ENV VITE_BRAND_NAME=$VITE_BRAND_NAME
ENV VITE_BRAND_DOMAIN=$VITE_BRAND_DOMAIN
ENV VITE_INFLUENCERS_UGC=$VITE_INFLUENCERS_UGC

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

FROM nginx:1.27-alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY infrastructure/nginx/frontend.conf /etc/nginx/conf.d/default.conf

EXPOSE 5173
CMD ["nginx", "-g", "daemon off;"]
