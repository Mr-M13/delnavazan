FROM php:8.3-cli-alpine
RUN apk add --no-cache tzdata && addgroup -S delnavazan-playback && adduser -S -G delnavazan-playback delnavazan-playback
WORKDIR /opt/delnavazan-playback
COPY src ./src
COPY bin ./bin
RUN mkdir -p /run/delnavazan-playback /var/log/delnavazan && chown -R delnavazan-playback:delnavazan-playback /run/delnavazan-playback /var/log/delnavazan
USER delnavazan-playback
EXPOSE 8092
ENTRYPOINT ["sh", "-c", "exec php -S ${DZN_PLAYBACK_BIND:-127.0.0.1:8092} /opt/delnavazan-playback/bin/router.php"]
