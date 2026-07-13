FROM nginx:1.27-alpine
COPY ./default.conf /etc/nginx/conf.d/default.conf

RUN mkdir -p /etc/nginx/ssl /etc/nginx/virtusphere-conf.d

COPY init.sh /init.sh
RUN chmod +x /init.sh

ENTRYPOINT ["/init.sh"]

EXPOSE 8080 8443