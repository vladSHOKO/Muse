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

Результаты публикации и CI сохранены документирующим коммитом `d49ca6d`:

```bash
git add docs/REPOSITORY_SETUP.md AGENTS.md
git diff --cached --check
git commit -m "docs: record repository publication and successful CI"
git push origin main
```

Для этого коммита также успешно прошёл [CI № 34765977078](https://github.com/vladSHOKO/Muse/actions/runs/34765977078). Для проверки последующих коммитов откройте [Actions → CI](https://github.com/vladSHOKO/Muse/actions/workflows/ci.yml) и сопоставьте SHA запуска с `git rev-parse HEAD`. Результат одного запуска относится к его SHA, а не ко всем будущим изменениям.

## Завершение настройки GitHub

После успешного CI настроена защита `main`. Фактические параметры проверены через GitHub API:

| Параметр | Итоговое значение |
| --- | --- |
| Pull request перед слиянием | Обязателен |
| Проверки | `PHP 8.2 / PostgreSQL 17`, `PHP 8.4 / PostgreSQL 17` |
| Актуальная ветка перед слиянием | Обязательна (`strict: true`) |
| Защита распространяется на администратора | Да (`enforce_admins: true`) |
| Обязательные approvals | 0: проект ведёт один разработчик |
| Сброс устаревших approvals после изменений | Включён |
| Force push и удаление `main` | Запрещены |
| Способ слияния | Только squash merge |
| Автоматическое удаление слитых веток | Включено |

Настройки GitHub не задаются самим файлом workflow. При первичной публикации был доступен только SSH и публичное чтение API. Для завершения скачан официальный GitHub CLI 2.100.0 из `cli/cli`, SHA-256 архива проверен по digest релиза; временный бинарник размещён вне репозитория. Запущен `gh auth login --hostname github.com --git-protocol ssh --web --skip-ssh-key`. Пользователь подтвердил вход в браузере; CLI сообщил авторизацию как `vladSHOKO`, API подтвердил права администратора Muse. Пароль, токен и одноразовый код в документацию не включаются.

После входа повторная проверка обнаружила уже включённые squash merge, удаление слитых веток и базовое правило защиты. Однако список обязательных CI-проверок был пуст, а `enforce_admins` выключен. Через `PUT /repos/vladSHOKO/Muse/branches/main/protection` записаны параметры из таблицы выше. Настройки слияния уже соответствовали выбранному процессу и были сохранены.

GitHub CLI нужен только для управления репозиторием, приложение от него не зависит. В новой среде его можно установить обычным способом, выполнить `gh auth login` и проверить настройки командами:

```bash
gh api repos/vladSHOKO/Muse/branches/main/protection
gh api repos/vladSHOKO/Muse --jq '{allow_squash_merge, allow_merge_commit, allow_rebase_merge, delete_branch_on_merge}'
```

Точные действия вручную:

1. Settings → General → Pull Requests: включить Allow squash merging и Automatically delete head branches; отключить merge commits и rebase merging.
2. Settings → Branches → Add classic branch protection rule, шаблон ветки `main`.
3. Включить Require a pull request before merging; обязательное число approvals не задавать для одного разработчика.
4. Включить Require status checks to pass before merging и Require branches to be up to date before merging. Выбрать оба названия проверок из таблицы выше.
5. Включить Do not allow bypassing the above settings. Allow force pushes и Allow deletions оставить выключенными.
6. Сохранить правило и проверить, что GitHub показывает `main` как protected. Защиту настраивать после завершения первичной публикации; следующие изменения отправлять через pull request.

## Проверка рабочего процесса после включения защиты

Итоговая запись этого журнала отправляется из ветки `docs/complete-repository-setup` через [pull request № 2](https://github.com/vladSHOKO/Muse/pull/2). Во время выполнения обоих обязательных CI-job GitHub API подтвердил `mergeStateStatus: BLOCKED`: слияние ожидает проверок. Повторное чтение API подтвердило сохранение всех параметров защиты. Так проверяется обычный путь разработки при уже включённой защите:

```bash
git switch -c docs/complete-repository-setup
# Обновить журнал и AGENTS.md
git add docs/REPOSITORY_SETUP.md AGENTS.md CONTRIBUTING.md
git diff --cached --check
git commit -m "docs: complete GitHub repository setup log"
git push -u origin docs/complete-repository-setup
gh pr create --base main --head docs/complete-repository-setup --title "docs: complete GitHub repository setup log" --body-file /tmp/muse-setup-pr.md
gh pr checks --required --watch
gh pr merge --squash --delete-branch
git switch main
git pull --ff-only
```

Содержимое PR — результаты публикации и CI, авторизации и проверки настроек GitHub. Точный запуск CI привязан к коммиту PR и виден на вкладке Checks; факт squash merge и итоговый коммит фиксируются в истории PR и `main`. После слияния запускается отдельный CI для `main`. Отключение защиты и обход администратором не входят в этот процесс.

## Граница текущего этапа

Публикация исходников в GitHub и CI предшествуют production-деплою. Выбор сервера/домена, HTTPS, постоянные хранилища, резервное копирование и восстановление, production-секреты, выпуск проверенной версии, миграции и откат приложения выполняются на следующем этапе. Сам push приложения на сервер не разворачивает.
