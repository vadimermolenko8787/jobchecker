# JobChecker

Локальный агрегатор вакансий: собирает вакансии с dou.ua, djinni.co, justjoin.it, LinkedIn и Indeed, находит новые, оценивает их через локальный Claude Code CLI по соответствию резюме и для лучших генерирует адаптированное резюме и cover letter (на английском).

## Запуск

```bash
php artisan serve
# открыть http://127.0.0.1:8000
```

Требования: PHP 8.3+, MariaDB (база `jobchecker`, доступ в `.env`), Claude Code CLI (путь в `config/jobchecker.php`, переопределяется через `CLAUDE_BIN` в `.env`).

## Как пользоваться

1. На панели загрузить резюме в PDF. Claude извлечёт ключевые слова стека, они подставятся в настройки поиска (можно править).
2. Настроить фильтры: обязательные слова и стоп-слова, локации (для LinkedIn/Indeed), только remote, минимальный score для генерации документов.
3. Нажать «Пуск» для ручного запуска, прогресс виден на панели.
4. Для автозапуска включить расписание, задать cron-выражение и один раз добавить в crontab:

```
* * * * * cd /private/var/www/jobchecker && php artisan schedule:run >> /dev/null 2>&1
```

Результаты: страница «Вакансии» (score, статусы: new, matched, rejected, done), у done-вакансий доступны адаптированное резюме и cover letter (файлы также лежат в `storage/app/private/output/{id}/`). У каждого запуска есть детальная страница с логом пайплайна и полным журналом HTTP-запросов/ответов по каждому источнику (`fetch_logs`).

## Источники

| Источник | Метод | Надёжность |
|---|---|---|
| dou.ua | публичный RSS | стабильно |
| djinni.co | публичный RSS | стабильно (компании анонимны) |
| justjoin.it | внутренний JSON API (заголовок `Version: 2`) | стабильно |
| LinkedIn | гостевой HTML endpoint | хрупко, троттлинг 2 с |
| Indeed | GraphQL API мобильного приложения (ключ из проекта JobSpy) | работает, ключ может смениться, по умолчанию выключен |

Если Indeed перестанет отвечать, актуальный `indeed-api-key` можно взять из репозитория github.com/speedyapply/JobSpy (`app/Services/Sources/IndeedSource.php`).

## CLI

```bash
php artisan jobs:search            # полный цикл вручную
php artisan schedule:list          # проверить расписание
```
