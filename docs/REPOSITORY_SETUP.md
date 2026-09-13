# Публикация Muse и настройка разработки

Дата: 13 сентября 2026 года. Репозиторий: `git@github.com:vladSHOKO/Muse.git`.
Постоянные правила работы и команды для новых изменений: [CONTRIBUTING.md](../CONTRIBUTING.md).

## Подготовка

1. Проверено локальное состояние: история Git отсутствовала. Выполнены `git init -b main` и `git remote add origin git@github.com:vladSHOKO/Muse.git`.
2. Через `git ls-remote origin` проверены SSH-доступ и отсутствие удалённых веток и тегов. Повторная проверка перед публикацией также не обнаружила истории.
3. Проверены файлы для публикации и `.gitignore`. В репозиторий входят код, тесты, миграции, документация, Composer lock-файл и настройки разработки. `.env.local`, `.env.local.php`, `var` с БД-дампами, вложениями и логами, `vendor`, IDE и локальные каталоги агентов исключены.
4. Добавлен `.github/workflows/ci.yml`: pull request в `main`, push в `main`, ручной запуск; PHP 8.2/8.4, отдельная PostgreSQL 17 для каждого job, установка по lock-файлу, проверка платформы и синтаксиса PHP/JS, миграции, схема Doctrine, Symfony lint и PHPUnit. Доступ workflow — `contents: read`; production-секреты не используются.
5. Добавлены шаблон pull request и еженедельные обновления Composer/GitHub Actions через Dependabot. Процесс коротких веток, CI и squash merge описан в CONTRIBUTING.
6. Локально прошли `composer validate --strict`, `composer check-platform-reqs`, проверка YAML в `.github`, синтаксиса PHP и обоих JS-файлов, миграций и схемы тестовой БД, `composer check`: **51 тест, 453 утверждения** на PHP 8.4.1. В тесте обзора исправлено устаревшее ожидание общего количества задач: проверяются активные счётчики до и после завершения задачи. Рабочая БД не очищалась.

## Первичная публикация

Пользователь поручил выполнить публикацию и сохранить весь процесс в закоммиченном документе. Последовательность:

```bash
git add .
git diff --cached --check
git diff --cached --stat
git commit -m "chore: initialize Muse with GitHub Actions CI"
git push -u origin main
```

Первый коммит [`b342cf96ce54559b11e6c95fbbd6656ebfbee408`](https://github.com/vladSHOKO/Muse/commit/b342cf96ce54559b11e6c95fbbd6656ebfbee408) создан и отправлен в `main`. GitHub принял новую ветку, локальная `main` отслеживает `origin/main`. Опубликованы 96 файлов, включая этот журнал. Репозиторий имеет публичную видимость; настройка видимости не менялась.

Перед коммитом `git diff --cached --check` обнаружил один пробел в конце строки теста; пробел удалён, проверка пройдена. Дополнительная проверка подготовленных файлов на распространённые форматы приватных ключей и GitHub/AWS-токенов совпадений не обнаружила.

## Результат CI

Первый push автоматически запустил [CI № 34764730038](https://github.com/vladSHOKO/Muse/actions/runs/34764730038) для коммита `b342cf9`. Через GitHub API подтверждены `status: completed`, `conclusion: success`.

| Проверка | Результат |
| --- | --- |
| PHP 8.2 / PostgreSQL 17 | Успешно |
| PHP 8.4 / PostgreSQL 17 | Успешно |

Оба job установили зависимости по lock-файлу, применили миграции к чистой тестовой БД, проверили схему и выполнили предусмотренные workflow проверки. Также автоматически запустились задания Dependabot. Их обновления рассматриваются отдельными pull request и в эту первичную публикацию не включаются.

Результаты публикации и CI сохраняются следующим документирующим коммитом:

```bash
git add docs/REPOSITORY_SETUP.md AGENTS.md
git diff --cached --check
git commit -m "docs: record repository publication and successful CI"
git push origin main
```

Этот push также запускает CI. Для проверки последующих коммитов откройте [Actions → CI](https://github.com/vladSHOKO/Muse/actions/workflows/ci.yml) и сопоставьте SHA запуска с `git rev-parse HEAD`. Результат одного запуска относится к его SHA, а не ко всем будущим изменениям.

## Завершение настройки GitHub

После успешного CI нужно включить обязательные проверки `PHP 8.2 / PostgreSQL 17` и `PHP 8.4 / PostgreSQL 17`, pull request перед слиянием, актуальность ветки, запрет force push и удаления `main`; оставить squash merge и включить удаление слитых веток. Для единственного разработчика обязательное одобрение другого участника не нужно. Настройки GitHub не задаются самим файлом workflow.

Чтение CI через публичный GitHub API работает. Для изменения настроек доступ отсутствует: `gh` не установлен, `GH_TOKEN`/`GITHUB_TOKEN` и сохранённая авторизация `gh` не обнаружены. SSH позволяет отправлять Git-коммиты, но не заменяет авторизацию API. Защита ветки и настройки слияния в рамках этой публикации пока не изменены.

Пользователю предложено установить GitHub CLI и выполнить `gh auth login`, не передавая токен в чат, либо применить настройки вручную. После авторизации настройку следует завершить и обновить этот журнал отдельным коммитом.

Точные действия вручную:

1. Settings → General → Pull Requests: включить Allow squash merging и Automatically delete head branches; отключить merge commits и rebase merging.
2. Settings → Branches → Add classic branch protection rule, шаблон ветки `main`.
3. Включить Require a pull request before merging; обязательное число approvals не задавать для одного разработчика.
4. Включить Require status checks to pass before merging и Require branches to be up to date before merging. Выбрать оба названия проверок из таблицы выше.
5. Включить Do not allow bypassing the above settings. Allow force pushes и Allow deletions оставить выключенными.
6. Сохранить правило и проверить, что GitHub показывает `main` как protected. Защиту настраивать после завершения первичной публикации; следующие изменения отправлять через pull request.

## Граница текущего этапа

Публикация исходников в GitHub и CI предшествуют production-деплою. Выбор сервера/домена, HTTPS, постоянные хранилища, резервное копирование и восстановление, production-секреты, выпуск проверенной версии, миграции и откат приложения выполняются на следующем этапе. Сам push приложения на сервер не разворачивает.
