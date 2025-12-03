# ISPmanager Handler Changer

Скрипт `ispmgr_handler_changer.php` проходит по всем сайтам в ISPmanager и меняет обработчик PHP с исходного режима на целевой, сохраняя текущую версию PHP.

## Требования
- PHP ≥ 7.2 (строгие типы, оператор `??`)
- Расширение `curl`
- Рекомендуется `intl` (для `idn_to_ascii`); если его нет, используется встроенный fallback Punycode

## Переменные окружения
- `ISP_HOST` — адрес панели, например `https://5.8.76.211:1500`
- `ISP_USERNAME` — логин (например, `www-root`)
- `ISP_PASSWORD` — пароль
- `ISP_MODE_SOURCE` — исходный режим, по умолчанию `php_mode_fcgi_apache`
- `ISP_MODE_TARGET` — целевой режим, по умолчанию `php_mode_lsapi`
- `ISP_HANDLER_EXPECTED` — ожидаемый handler, по умолчанию `handler_php`
- `ISP_CGI_VERSION_KEY` — ключ версии PHP для исходного режима, по умолчанию `site_php_cgi_version`
- `ISP_LSAPI_VERSION_KEY` — ключ версии PHP для целевого режима, по умолчанию `site_php_lsapi_version`

## Запуск
```
ISP_HOST="https://<host:port>" \
ISP_USERNAME="www-root" \
ISP_PASSWORD="secret" \
php ispmgr_handler_changer.php [offset] [limit]
```
- `offset` — сколько доменов пропустить в начале (по умолчанию `0`)
- `limit` — сколько доменов обработать (`0` или пусто — все)

Пример (обработать все домены):
```
php ispmgr_handler_changer.php 0 0
```

## Логирование
- В консоль выводятся статусы `[OK]`, `[SKIP]`, `[ERROR]` по каждому домену.
- Подробный лог пишется в файл `php_mode_migration.log` рядом со скриптом.

## Логика
1. Получить список сайтов через `func=webdomain`.
2. Для каждого сайта вызвать `site.edit elid=<punycode домена>`.
3. Если `site_php_mode` != `ISP_MODE_SOURCE` или `site_handler` != `ISP_HANDLER_EXPECTED` — пропустить.
4. Взять текущую версию PHP из `ISP_CGI_VERSION_KEY` и отправить `site.edit` с:
   - `site_php_mode = ISP_MODE_TARGET`
   - `ISP_LSAPI_VERSION_KEY = <та же версия>`
   - `sok = ok`