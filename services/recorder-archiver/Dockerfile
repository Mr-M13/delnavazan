FROM php:8.3-cli-alpine
RUN apk add --no-cache postgresql-client rsync openssh-client tzdata \
    && docker-php-ext-install pdo_pgsql pdo_sqlite
WORKDIR /opt/delnavazan-recorder
COPY src ./src
COPY bin ./bin
RUN mkdir -p /srv/delnavazan-recordings/tmp /srv/delnavazan-recordings/ready \
    && addgroup -S delnavazan-recorder \
    && adduser -S -G delnavazan-recorder delnavazan-recorder \
    && chown -R delnavazan-recorder:delnavazan-recorder /srv/delnavazan-recordings \
    && chmod 0700 /srv/delnavazan-recordings /srv/delnavazan-recordings/tmp /srv/delnavazan-recordings/ready
USER delnavazan-recorder
ENTRYPOINT ["php", "/opt/delnavazan-recorder/bin/recorder.php"]
CMD ["work"]
