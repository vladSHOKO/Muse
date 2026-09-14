# Разработка Muse

Репозиторий: `git@github.com:vladSHOKO/Muse.git`.
Локальный запуск — в [README.md](README.md), правила приложения — в [MVP.md](MVP.md) и [AGENTS.md](AGENTS.md).

Выполненные шаги первичной публикации и результаты проверок записаны в [docs/REPOSITORY_SETUP.md](docs/REPOSITORY_SETUP.md).

## Рабочий процесс

Основная ветка — `main`. Одно изменение выполняется в короткой ветке `feat/<name>`, `fix/<name>` или `chore/<name>` и приходит в `main` через pull request. Постоянная ветка `develop` на текущем этапе не требуется.

```bash
git switch main
git pull --ff-only
git switch -c feat/example
# Изменения и локальные проверки
git add <изменённые-файлы>
git commit -m "feat: краткое описание изменения"
git push -u origin feat/example
```

В pull request укажите проблему, итоговое поведение и проверки. После успешного CI просмотрите diff и выполните squash merge, затем удалите ветку. При работе в одиночку одобрение второго участника не требуется. Миграции добавляйте новыми файлами; пользовательские данные не используйте в тестах. Изменения интерфейса проверяйте в браузере, обновляйте версию assets. Изменения правил отражайте в документации.

## Проверки

GitHub Actions запускает `.github/workflows/ci.yml` для pull request в `main`, push в `main`, вручную и из workflow деплоя. В отдельных jobs проверяются минимальная PHP 8.2, production PHP 8.3 и локальная PHP 8.4, каждая со своей чистой PostgreSQL 17. Производственные секреты для CI не нужны.

Pipeline проверяет Composer и lock-файл, устанавливает зависимости через `composer install`, проверяет реальную PHP-платформу и синтаксис PHP/JS, применяет все миграции, сверяет схему Doctrine и запускает `composer check` (контейнер, Twig, YAML, PHPUnit).

`DATABASE_URL` содержит базовое имя `task_tracker`, Doctrine добавляет `_test`; сервис создаёт `task_tracker_test`. Загрузки тестов находятся в `var/test-uploads`. Node.js нужен в CI только для проверки синтаксиса JS. Браузерные сценарии CI пока не запускает.

Локально после подготовки тестовой БД:

```bash
composer validate --strict
composer check-platform-reqs
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console doctrine:schema:validate --env=test
composer check
node --check public/assets/app.js
node --check public/assets/cat.js
```

Dependabot раз в неделю предлагает обновления Composer и GitHub Actions. Их слияние проходит обычный pull request и CI; автоматического слияния нет.

## Первичная публикация

В Git передаются исходники, миграции, документация, `composer.lock`, тесты и CI. `.env` и `.env.test` содержат настройки разработки; реальные секреты задаются окружением или `.env.local`. Локальные секреты, `.env.local.php`, `vendor`, `var` (включая вложения, дампы и логи) и настройки IDE исключены через `.gitignore`.

До первого push проверьте `git status --short` и `git diff --cached`. Для пустого удалённого репозитория начальный коммит отправляется командой `git push -u origin main`. Если удалённая история уже есть, сначала получите её и согласуйте изменения; не используйте force push для первичной настройки.

Защита `main` настроена после первого успешного CI. При восстановлении настроек используйте GitHub (Settings → Rules → Rulesets либо Branches):

- Pull request перед слиянием; для единственного разработчика — без обязательного одобрения другого человека.
- Обязательные проверки `PHP 8.2 / PostgreSQL 17` и `PHP 8.4 / PostgreSQL 17`; актуальная ветка перед слиянием.
- Запрет force push и удаления `main`.
- Применение правил к администратору: обход защиты выключен.
- Squash merge и автоматическое удаление слитых веток в настройках репозитория.

Доступность защиты для приватного репозитория зависит от тарифа GitHub. Workflow сам не включает защиту ветки. См. [документацию GitHub](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches).

## Выпуск приложения

Для начатого развёртывания на FirstVDS актуальные инструкции и статус находятся в [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Workflow `Deploy production` запускается вручную из main и повторно проверяет коммит перед выпуском. Существующий `CI` остаётся проверкой кода.

Выбран FirstVDS. По решению пользователя первый запуск выполняется по HTTP/IP, подключение домена musetracker.ru и HTTPS — следующий шаг. БД и вложения сохраняются отдельно от версий кода; перед выпуском создаётся согласованная копия.

Откат кода не равен откату БД: миграции должны учитывать совместимость, а необратимые преобразования требуют резервной копии. Секреты деплоя находятся в GitHub environment production, секреты приложения — на сервере. Они не включаются в Git.
