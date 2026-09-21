<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Attachment;
use App\Entity\ChecklistItem;
use App\Entity\Task;
use App\Entity\TaskBlock;
use App\Entity\TaskShareLink;
use App\Entity\User;
use App\Service\FileStorage;
use App\Service\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApplicationTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $alice;
    private User $bob;
    private int $taskId;
    private int $itemId;
    private int $secondItemId;
    private int $foreignTaskId;
    private int $blockId;
    private int $foreignBlockId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $connection = $this->em()->getConnection();
        $database = $connection->fetchOne('SELECT current_database()');
        if (!str_ends_with($database, '_test')) { throw new \RuntimeException('Refusing to reset a non-test database.'); }
        $connection->executeStatement('TRUNCATE app_user, task_block, task, attachment, task_share_link, pending_file_deletion, checklist_item RESTART IDENTITY CASCADE');
        $uploadDir = self::getContainer()->getParameter('app.upload_dir');
        if (!str_ends_with($uploadDir, '/var/test-uploads')) { throw new \RuntimeException('Unsafe test upload directory.'); }
        (new Filesystem())->remove($uploadDir);
        $this->alice = $this->makeUser('alice@example.com');
        $this->bob = $this->makeUser('bob@example.com');
        $block = new TaskBlock($this->alice);
        $block->setTitle('Работа');
        $foreignBlock = new TaskBlock($this->bob);
        $foreignBlock->setTitle('Секретный блок');
        $task = new Task($this->alice);
        $task->setTitle('Подготовить проект');
        $task->setDescription('Описание проекта');
        $task->setBlock($block);
        $item = new ChecklistItem($task);
        $item->setTitle('Собрать материалы');
        $secondItem = new ChecklistItem($task);
        $secondItem->setTitle('Согласовать макет');
        $foreign = new Task($this->bob);
        $foreign->setTitle('Чужая секретная задача');
        $foreign->setBlock($foreignBlock);
        foreach ([$block, $foreignBlock, $task, $item, $secondItem, $foreign] as $entity) { $this->em()->persist($entity); }
        $this->em()->flush();
        $this->taskId = $task->getId();
        $this->itemId = $item->getId();
        $this->secondItemId = $secondItem->getId();
        $this->foreignTaskId = $foreign->getId();
        $this->blockId = $block->getId();
        $this->foreignBlockId = $foreignBlock->getId();
        $this->em()->clear();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    private function em(): EntityManagerInterface { return self::getContainer()->get(EntityManagerInterface::class); }

    private function makeUser(string $email): User
    {
        $user = new User($email);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'correct-password'));
        $this->em()->persist($user);
        return $user;
    }

    private function makeAttachment(int $taskId, string $original = 'notes.txt'): Attachment
    {
        $name = bin2hex(random_bytes(32));
        $storage = self::getContainer()->get(FileStorage::class);
        (new Filesystem())->mkdir(dirname($storage->path($name)), 0700);
        file_put_contents($storage->path($name), 'Document content');
        $attachment = new Attachment($this->em()->find(Task::class, $taskId), $original, $name, 'text/plain', 16);
        $this->em()->persist($attachment);
        $this->em()->flush();
        return $attachment;
    }

    private function share(int $taskId): array
    {
        return self::getContainer()->get(ShareService::class)->create($this->em()->find(Task::class, $taskId));
    }

    public function testDeadlineCreateEditClearValidationAndViews(): void
    {
        Clock::set(new MockClock('2026-09-13T15:30:00Z'));
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/new?block='.$this->blockId);
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form([
            'task[title]' => 'Задача со сроком', 'task[deadline]' => '2026-09-13T18:30',
        ]));
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.deadline-badge.overdue', '13.09.2026 18:30 МСК');
        $task = $this->em()->getRepository(Task::class)->findOneBy(['title' => 'Задача со сроком']);
        $id = $task->getId();
        self::assertSame('2026-09-13 15:30', $task->getDeadline()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        foreach (['/', '/?block='.$this->blockId] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('[data-task-id="'.$id.'"].task-overdue .overdue');
        }
        $share = $this->share($id);
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertSelectorExists('.deadline-badge.overdue');
        self::assertSelectorNotExists('form');
        $crawler = $this->client->request('GET', '/tasks/'.$id.'/edit');
        self::assertInputValueSame('task[deadline]', '2026-09-13T18:30');
        $form = $crawler->selectButton('Сохранить задачу')->form();
        $values = $form->getPhpValues();
        $values['task']['deadline'] = '2026-02-30T25:61';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        self::assertSame($task->getDeadline()->getTimestamp(), $this->em()->find(Task::class, $id)->getDeadline()->getTimestamp());
        $crawler = $this->client->request('GET', '/tasks/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form(['task[deadline]' => '2026-09-14T09:00']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.deadline-badge', '14.09.2026 09:00 МСК');
        self::assertSelectorNotExists('.overdue');
        $crawler = $this->client->request('GET', '/tasks/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form(['task[deadline]' => '']));
        $this->client->followRedirect();
        self::assertSelectorNotExists('.deadline-badge');
        self::assertNull($this->em()->find(Task::class, $id)->getDeadline());
    }

    public function testCompletedTabAndReopeningWithChecklistReturn(): void
    {
        $task = $this->em()->find(Task::class, $this->taskId);
        $task->setDeadline(new \DateTimeImmutable('2000-01-01T00:00:00Z'));
        $this->em()->flush();
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId);
        $this->client->submit($crawler->filter('.task-card-actions form')->form());
        self::assertResponseRedirects('/?block='.$this->blockId, 303);
        $this->client->followRedirect();
        self::assertSelectorCount(0, '.task-card');
        self::assertSelectorTextContains('.task-status-tabs', 'Завершённые (1)');
        $crawler = $this->client->request('GET', '/?block='.$this->blockId.'&status=completed');
        self::assertSelectorCount(1, '.task-card');
        self::assertSelectorNotExists('.overdue');
        $this->client->submit($crawler->filter('form[action="/checklist/'.$this->itemId.'/complete"]')->form());
        self::assertResponseRedirects('/?block='.$this->blockId.'&status=completed#checklist-'.$this->taskId, 303);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Открыть снова')->form());
        $this->client->followRedirect();
        self::assertSelectorCount(1, '.task-card.task-overdue');
        $this->client->request('GET', '/?block='.$this->blockId.'&status=completed');
        self::assertSelectorCount(0, '.task-card');
        $this->client->request('GET', '/?block='.$this->foreignBlockId.'&status=completed');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/?block='.$this->blockId.'&status=invalid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPrivatePagesRequireLoginAndRegistrationWorks(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Зарегистрироваться →')->form([
            'registration[email]' => 'New@EXAMPLE.com', 'registration[password]' => 'a-long-new-password',
        ]));
        self::assertResponseRedirects('/login', 303);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertNotNull($user);
        self::assertNotSame('a-long-new-password', $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'a-long-new-password'));
    }

    public function testDuplicateEmailAndShortPasswordAreRejected(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Зарегистрироваться →')->form([
            'registration[email]' => 'ALICE@EXAMPLE.COM', 'registration[password]' => 'correct-password',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Эта почта уже зарегистрирована.');
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Зарегистрироваться →')->form([
            'registration[email]' => 'new@example.com', 'registration[password]' => 'short',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(2, $this->em()->getRepository(User::class)->count([]));
    }

    public function testLoginNormalizesEmailAndLogoutWorks(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Войти →')->form(['_username' => 'ALICE@EXAMPLE.COM', '_password' => 'correct-password']));
        self::assertResponseRedirects('http://localhost/');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Мои блоки');
        $this->client->submit($crawler->selectButton('Выйти')->form());
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testWrongPasswordDoesNotAuthenticate(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Войти →')->form(['_username' => 'alice@example.com', '_password' => 'wrong-password']));
        self::assertResponseRedirects('http://localhost/login');
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testOwnershipOnListsAndEveryTaskRoute(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/?block='.$this->blockId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Подготовить проект');
        self::assertSelectorTextNotContains('main', 'Чужая секретная задача');
        self::assertSelectorTextNotContains('main', 'Секретный блок');
        foreach (['' => 'GET', '/edit' => 'GET', '/children/new' => 'GET', '/toggle' => 'POST', '/delete' => 'POST', '/shares' => 'POST', '/attachments' => 'POST'] as $suffix => $method) {
            $this->client->request($method, '/tasks/'.$this->foreignTaskId.$suffix);
            self::assertResponseStatusCodeSame(404);
        }
        foreach (['/edit' => 'GET', '/delete' => 'POST'] as $suffix => $method) {
            $this->client->request($method, '/blocks/'.$this->foreignBlockId.$suffix);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testTaskCreateAndEdit(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/new');
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form([
            'task[title]' => 'Новая задача', 'task[description]' => "Текст\n<script>alert(1)</script>", 'task[block]' => $this->blockId,
        ]));
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Новая задача');
        self::assertSelectorNotExists('main script');
        $task = $this->em()->getRepository(Task::class)->findOneBy(['title' => 'Новая задача']);
        self::assertSame($this->alice->getId(), $task->getOwner()->getId());
        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form(['task[title]' => 'Изменено', 'task[completed]' => true, 'task[block]' => $this->blockId]));
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Изменено');
        self::assertSelectorTextContains('.badge.done', 'Выполнена');
    }

    public function testForgedBlockAndOwnerFieldsCannotChangeOwnership(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId.'/edit');
        $form = $crawler->selectButton('Сохранить задачу')->form();
        $values = $form->getPhpValues();
        $values['task']['block'] = (string) $this->foreignBlockId;
        $values['task']['owner'] = (string) $this->bob->getId();
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        $task = $this->em()->find(Task::class, $this->taskId);
        self::assertSame($this->alice->getId(), $task->getOwner()->getId());
        self::assertSame($this->blockId, $task->getBlock()->getId());
    }

    public function testMutationRequiresCsrf(): void
    {
        $this->client->loginUser($this->alice);
        foreach (['toggle', 'delete', 'shares'] as $action) {
            $this->client->request('POST', '/tasks/'.$this->taskId.'/'.$action);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('GET', '/tasks/'.$this->taskId.'/delete');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/tasks/new', ['task' => ['title' => 'Без токена']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(2, $this->em()->getRepository(Task::class)->count([]));
    }

    public function testBlockCreateRenameAndDeleteCascades(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/blocks/new');
        $this->client->submit($crawler->selectButton('Сохранить блок')->form(['block[title]' => 'Дом']));
        $createdBlock = $this->em()->getRepository(TaskBlock::class)->findOneBy(['title' => 'Дом']);
        self::assertResponseRedirects('/?block='.$createdBlock->getId(), 303);
        $crawler = $this->client->request('GET', '/blocks/'.$this->blockId.'/edit');
        $this->client->submit($crawler->selectButton('Сохранить блок')->form(['block[title]' => 'Проекты']));
        self::assertResponseRedirects('/?block='.$this->blockId, 303);
        $crawler = $this->client->request('GET', '/blocks/'.$this->blockId.'/edit');
        $this->client->submit($crawler->selectButton('Удалить блок')->form());
        self::assertResponseRedirects('/', 303);
        $this->em()->clear();
        self::assertNull($this->em()->find(TaskBlock::class, $this->blockId));
        self::assertNull($this->em()->find(Task::class, $this->taskId));
        self::assertSame(0, $this->em()->getRepository(ChecklistItem::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(Task::class)->count([]));
    }

    public function testShareCreationAndPublicScope(): void
    {
        $rootFile = $this->makeAttachment($this->taskId, 'root.txt');
        $foreignFile = $this->makeAttachment($this->foreignTaskId, 'private.txt');
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
        $this->client->submit($crawler->selectButton('Создать ссылку')->form());
        self::assertResponseIsSuccessful();
        $url = $this->client->getCrawler()->filter('#share-url')->attr('value');
        $token = basename($url);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $link = $this->em()->getRepository(TaskShareLink::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
        self::assertNotNull($link);
        self::assertNotSame($token, $link->getTokenHash());
        $this->client->restart();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Подготовить проект');
        self::assertSelectorTextContains('main', 'Собрать материалы');
        self::assertSelectorTextContains('main', 'Согласовать макет');
        self::assertSelectorTextNotContains('main', 'Работа');
        self::assertSelectorNotExists('form');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        foreach ([$rootFile] as $file) {
            $this->client->request('GET', '/s/'.$token.'/attachments/'.$file->getId());
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
            self::assertStringContainsString('attachment;', $this->client->getResponse()->headers->get('Content-Disposition'));
        }
        $this->client->request('GET', '/s/'.$token.'/attachments/'.$foreignFile->getId());
        self::assertResponseStatusCodeSame(404);
    }



    public function testShareExpiryAlsoClosesDownloads(): void
    {
        $clock = new MockClock('2026-09-12T10:00:00Z');
        Clock::set($clock);
        $file = $this->makeAttachment($this->taskId);
        $share = $this->share($this->taskId);
        $clock->sleep(86399);
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertResponseIsSuccessful();
        $clock->sleep(1);
        foreach (['/s/'.$share['token'], '/s/'.$share['token'].'/attachments/'.$file->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
            self::assertSelectorTextNotContains('main', 'Подготовить проект');
        }
    }

    public function testShareCanBeRevokedOnlyByOwnerAndWithCsrf(): void
    {
        $file = $this->makeAttachment($this->taskId);
        $share = $this->share($this->taskId);
        $this->client->loginUser($this->bob);
        $this->client->request('POST', '/shares/'.$share['link']->getId().'/revoke');
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->alice);
        $this->client->request('POST', '/shares/'.$share['link']->getId().'/revoke');
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
        $this->client->submit($crawler->selectButton('Отозвать')->form());
        self::assertResponseStatusCodeSame(303);
        $this->client->restart();
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/s/'.$share['token'].'/attachments/'.$file->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerDownloadsAndAttachmentDeletion(): void
    {
        $attachment = $this->makeAttachment($this->taskId, 'документ.txt');
        $fileId = $attachment->getId();
        $path = self::getContainer()->get(FileStorage::class)->path($attachment->getStoredName());
        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/attachments/'.$fileId.'/download');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/attachments/'.$fileId.'/delete');
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/attachments/'.$fileId.'/download');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/attachments/'.$fileId.'/delete');
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
        $this->client->submit($crawler->filter('.file-list')->selectButton('Удалить')->form());
        self::assertResponseStatusCodeSame(303);
        self::assertFileDoesNotExist($path);
        self::assertNull($this->em()->find(Attachment::class, $fileId));
    }

    public function testDeletingTaskCascadesChecklistFilesAndShares(): void
    {
        $file = $this->makeAttachment($this->taskId);
        $share = $this->share($this->taskId);
        $path = self::getContainer()->get(FileStorage::class)->path($file->getStoredName());
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
        $this->client->submit($crawler->selectButton('Удалить задачу')->form());
        self::assertResponseRedirects('/?block='.$this->blockId, 303);
        $this->em()->clear();
        self::assertNull($this->em()->find(Task::class, $this->taskId));
        self::assertNotNull($this->em()->find(Task::class, $this->foreignTaskId));
        self::assertSame(0, $this->em()->getRepository(ChecklistItem::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Attachment::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(TaskShareLink::class)->count([]));
        self::assertFileDoesNotExist($path);
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMultipleUploadsAndInvalidTypeAreHandled(): void
    {
        $this->client->loginUser($this->alice);
        $first = tempnam(sys_get_temp_dir(), 'task-upload-');
        $second = tempnam(sys_get_temp_dir(), 'task-upload-');
        file_put_contents($first, 'First text document');
        file_put_contents($second, 'Second text document');
        try {
            $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
            $form = $crawler->selectButton('Прикрепить файлы')->form();
            $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['upload' => ['files' => [new UploadedFile($first, 'first.txt', test: true), new UploadedFile($second, 'second.txt', test: true)]]]);
            self::assertResponseStatusCodeSame(303);
            $this->client->followRedirect();
            self::assertSelectorTextContains('.file-list', 'first.txt');
            self::assertSelectorTextContains('.file-list', 'second.txt');
            self::assertSame(2, $this->em()->getRepository(Attachment::class)->count([]));
            file_put_contents($first, '<?php echo "bad";');
            $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
            $form = $crawler->selectButton('Прикрепить файлы')->form();
            $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['upload' => ['files' => [new UploadedFile($first, 'payload.php', test: true)]]]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(2, $this->em()->getRepository(Attachment::class)->count([]));
        } finally { (new Filesystem())->remove([$first, $second]); }
    }

    public function testDatabaseRejectsDuplicateAndNonNormalizedEmail(): void
    {
        $connection = $this->em()->getConnection();
        foreach (['alice@example.com', 'ALICE@example.com'] as $email) {
            try {
                $connection->executeStatement('INSERT INTO app_user (email, password, created_at) VALUES (?, ?, now())', [$email, 'hash']);
                self::fail('Invalid or duplicate email was accepted.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertContains($exception->getSQLState(), ['23505', '23514']);
                self::assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM app_user'));
            }
        }
    }

    public function testDatabaseRejectsForeignBlockAndOwnerChanges(): void
    {
        foreach ([['block_id', $this->foreignBlockId], ['owner_id', $this->bob->getId()], ['priority', -1], ['priority', 0], ['priority', 6]] as [$column, $value]) {
            try {
                $this->em()->getConnection()->executeStatement('UPDATE task SET '.$column.' = ? WHERE id = ?', [$value, $this->taskId]);
                self::fail('Invalid task mutation was accepted.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        }
    }

    public function testExistingShareShowsLiveChecklistContentAndFiles(): void
    {
        $share = $this->share($this->taskId);
        $task = $this->em()->find(Task::class, $this->taskId);
        $task->setDescription('Обновлённое описание');
        $item = new ChecklistItem($task);
        $item->setTitle('Новый пункт после публикации');
        $item->setCompleted(true);
        $this->em()->persist($item);
        $this->em()->flush();
        $attachment = $this->makeAttachment($this->taskId, 'new.txt');
        $this->em()->clear();
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Обновлённое описание');
        self::assertSelectorTextContains('.checklist-item.is-done', 'Новый пункт после публикации');
        self::assertSelectorNotExists('form');
        $this->client->request('GET', '/s/'.$share['token'].'/attachments/'.$attachment->getId());
        self::assertResponseIsSuccessful();
    }

    public function testUploadBatchIsRejectedWithoutPartialWrites(): void
    {
        $this->client->loginUser($this->alice);
        $path = tempnam(sys_get_temp_dir(), 'task-batch-');
        file_put_contents($path, 'A plain text document');
        try {
            foreach ([['valid.txt', 'fake.jpg'], array_fill(0, 6, 'valid.txt')] as $names) {
                $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
                $form = $crawler->selectButton('Прикрепить файлы')->form();
                $files = array_map(static fn (string $name) => new UploadedFile($path, $name, test: true), $names);
                $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['upload' => ['files' => $files]]);
                self::assertResponseStatusCodeSame(422);
                self::assertSame(0, $this->em()->getRepository(Attachment::class)->count([]));
            }
        } finally { (new Filesystem())->remove($path); }
    }

    public function testExpiredAndMalformedLinksDoNotRevealTask(): void
    {
        foreach (['bad-token', str_repeat('f', 64)] as $token) {
            $this->client->request('GET', '/s/'.$token);
            self::assertResponseStatusCodeSame(404);
            self::assertSelectorTextContains('h1', 'Ссылка недействительна');
            self::assertSelectorTextNotContains('main', 'Подготовить проект');
        }
    }

    public function testDeletedFileIsUnavailableThroughExistingShare(): void
    {
        $attachment = $this->makeAttachment($this->taskId);
        $id = $attachment->getId();
        $share = $this->share($this->taskId);
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
        $this->client->submit($crawler->filter('.file-list')->selectButton('Удалить')->form());
        self::assertResponseStatusCodeSame(303);
        $this->client->restart();
        $this->client->request('GET', '/s/'.$share['token'].'/attachments/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertResponseIsSuccessful();
    }


    public function testOverviewAndBlockDetailShowChecklistInline(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.block-preview');
        self::assertSelectorCount(1, '.task-card');
        $this->client->request('GET', '/?block='.$this->blockId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.block-link[aria-current="page"]', 'Работа');
        self::assertSelectorCount(1, '.task-card');
        self::assertSelectorCount(2, '.checklist-item');
        self::assertSelectorTextContains('.inline-checklist', 'Собрать материалы');
        self::assertSelectorTextContains('.inline-checklist', 'Согласовать макет');
        self::assertSelectorNotExists('.checklist-item a');
        self::assertSelectorTextContains('.checklist-progress', '0/2');
        $this->client->request('GET', '/');
        self::assertSelectorCount(1, '.task-card');
        self::assertSelectorTextContains('.block-preview-heading', 'Работа');
        self::assertSelectorNotExists('.workspace');
    }

    public function testBlockSwitchingFiltersTasksAndCounts(): void
    {
        $owner = $this->em()->find(User::class, $this->alice->getId());
        $otherBlock = new TaskBlock($owner);
        $otherBlock->setTitle('Личные дела');
        $otherTask = new Task($owner);
        $otherTask->setTitle('Задача другого блока');
        $otherTask->setBlock($otherBlock);
        foreach ([$otherBlock, $otherTask] as $entity) { $this->em()->persist($entity); }
        $this->em()->flush();
        $otherBlockId = $otherBlock->getId();
        $this->em()->clear();
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId);
        self::assertSelectorTextNotContains('.block-workspace', 'Задача другого блока');
        self::assertSelectorTextContains('.stats > div:first-child .stat-value', '1');
        $this->client->click($crawler->filter('.block-link[href="/?block='.$otherBlockId.'"]')->link());
        self::assertSelectorCount(1, '.task-card');
        self::assertSelectorTextContains('.task-card', 'Задача другого блока');
        self::assertSelectorTextNotContains('.block-workspace', 'Подготовить проект');
        self::assertSelectorNotExists('.checklist-item');
        $this->client->request('GET', '/?block=none');
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignAndInvalidBlocksAreRejectedInListAndCreation(): void
    {
        $this->client->loginUser($this->alice);
        foreach ([(string) $this->foreignBlockId, 'none', '999999', 'invalid', '0', '9999999999999999999999999'] as $block) {
            foreach (['/?block=', '/tasks/new?block='] as $prefix) {
                $this->client->request('GET', $prefix.$block);
                self::assertResponseStatusCodeSame(404);
            }
        }
        $this->client->request('GET', '/?block[]=1');
        self::assertResponseStatusCodeSame(400);
    }

    public function testNewTaskUsesSelectedBlockAndPriority(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId);
        $crawler = $this->client->click($crawler->selectLink('＋ Новая задача')->link());
        self::assertSelectorExists('select[name="task[block]"] option[value="'.$this->blockId.'"][selected]');
        self::assertSame('', $crawler->selectButton('Сохранить задачу')->form()['task[priority]']->getValue());
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form(['task[title]' => 'Важная задача', 'task[priority]' => '1']));
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.priority-badge', 'Приоритет: 1');
        $task = $this->em()->getRepository(Task::class)->findOneBy(['title' => 'Важная задача']);
        self::assertSame(1, $task->getPriority());
        self::assertSame($this->blockId, $task->getBlock()->getId());
        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form(['task[priority]' => '5']));
        self::assertResponseStatusCodeSame(303);
        $this->em()->clear();
        self::assertSame(5, $this->em()->find(Task::class, $task->getId())->getPriority());
        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        $this->client->submit($crawler->selectButton('Сохранить задачу')->form(['task[priority]' => '']));
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.priority-badge', 'Без приоритета');
        $this->em()->clear();
        self::assertNull($this->em()->find(Task::class, $task->getId())->getPriority());
    }

    public function testPriorityOrdersTasksAndTiesHaveStableOrder(): void
    {
        $owner = $this->em()->find(User::class, $this->alice->getId());
        $block = $this->em()->find(TaskBlock::class, $this->blockId);
        foreach ([['Низкий', 5], ['Высокий старый', 1], ['Высокий новый', 1], ['Средний', 3]] as [$title, $priority]) {
            $task = new Task($owner);
            $task->setTitle($title);
            $task->setPriority($priority);
            $task->setBlock($block);
            $this->em()->persist($task);
            $this->em()->flush();
        }
        $this->em()->clear();
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId);
        $titles = $crawler->filter('.main-task-link .task-summary > strong')->each(static fn ($node) => $node->text());
        self::assertSame(['Высокий новый', 'Высокий старый', 'Средний', 'Низкий', 'Подготовить проект'], $titles);
        self::assertSelectorCount(2, '.checklist-item');
        self::assertSelectorCount(5, '.task-card');
        self::assertSelectorTextContains('.stats > div:first-child .stat-value', '5');
        $this->em()->getConnection()->executeStatement('UPDATE task SET completed = true WHERE block_id = ?', [$this->blockId]);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId.'&status=completed');
        self::assertSame($titles, $crawler->filter('.main-task-link .task-summary > strong')->each(static fn ($node) => $node->text()));
    }

    public function testInvalidPriorityCannotBeSaved(): void
    {
        $this->client->loginUser($this->alice);
        foreach (['-1', '0', '6', '1.5', 'not-a-number', '2147483648'] as $priority) {
            $crawler = $this->client->request('GET', '/tasks/'.$this->taskId.'/edit');
            $form = $crawler->selectButton('Сохранить задачу')->form();
            $values = $form->getPhpValues();
            $values['task']['priority'] = $priority;
            $this->client->request('POST', $form->getUri(), $values);
            self::assertResponseStatusCodeSame(422);
            $this->em()->clear();
            self::assertNull($this->em()->find(Task::class, $this->taskId)->getPriority());
        }
    }



    public function testUserMustCreateBlockBeforeTasks(): void
    {
        $user = $this->makeUser('empty@example.com');
        $this->em()->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.blocks-empty', 'Начните с первого блока');
        self::assertSelectorNotExists('.task-card');
        self::assertSelectorNotExists('.block-preview');
        $this->client->request('GET', '/tasks/new');
        self::assertResponseRedirects('/blocks/new', 303);
        $this->client->request('POST', '/tasks/new', ['task' => ['title' => 'No block']]);
        self::assertResponseRedirects('/blocks/new', 303);
        self::assertSame(2, $this->em()->getRepository(Task::class)->count([]));
    }

    public function testChecklistCanBeAddedInBlockAndTask(): void
    {
        $this->client->loginUser($this->alice);
        foreach (['block', 'task', 'overview'] as $context) {
            $url = match ($context) { 'block' => '/?block='.$this->blockId, 'overview' => '/', default => '/tasks/'.$this->taskId };
            $crawler = $this->client->request('GET', $url);
            self::assertSelectorExists('form.checklist-add[data-checklist-add]');
            $form = $crawler->filter('.checklist-add')->form();
            $values = $form->getPhpValues();
            $values['checklist_'.$this->taskId]['title'] = 'Пункт из '.$context;
            $this->client->request('POST', $form->getUri(), $values);
            self::assertResponseRedirects($url.'#checklist-'.$this->taskId, 303);
            $this->client->followRedirect();
            self::assertStringContainsString('Пункт из '.$context, $this->client->getResponse()->getContent());
            self::assertStringContainsString('Пункт из '.$context, $this->client->getCrawler()->filter('.checklist-items')->text(null, false));
        }
        self::assertSame(5, $this->em()->getRepository(ChecklistItem::class)->count([]));
        self::assertSame(2, $this->em()->getRepository(Task::class)->count([]));
    }

    public function testChecklistItemCanBeEditedInline(): void
    {
        $share = $this->share($this->taskId);
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId);
        self::assertSelectorExists('[data-checklist-edit-start][hidden]');
        $form = $crawler->filter('form[data-checklist-edit]')->first()->form();
        $values = $form->getPhpValues();
        $values['title'] = '  Переписать материалы  ';
        $this->client->request('POST', $form->getUri(), $values, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertSame(['title' => 'Переписать материалы'], json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $this->em()->clear();
        self::assertSame('Переписать материалы', $this->em()->find(ChecklistItem::class, $this->itemId)->getTitle());

        $this->client->request('GET', '/tasks/'.$this->taskId);
        self::assertSelectorTextContains('.checklist-title', 'Переписать материалы');
        $this->client->restart();
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertSelectorTextContains('.checklist-title', 'Переписать материалы');
        self::assertSelectorNotExists('[data-checklist-edit]');
        self::assertSelectorNotExists('[data-checklist-edit-start]');
    }

    public function testChecklistEditValidatesTitleAndKeepsOriginalOnError(): void
    {
        $this->client->loginUser($this->alice);
        foreach (['   ', str_repeat('x', 201)] as $invalidTitle) {
            $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
            $form = $crawler->filter('form[data-checklist-edit]')->first()->form();
            $values = $form->getPhpValues();
            $values['title'] = $invalidTitle;
            $this->client->request('POST', $form->getUri(), $values, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseStatusCodeSame(422);
            $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('error', $result);
            $this->em()->clear();
            self::assertSame('Собрать материалы', $this->em()->find(ChecklistItem::class, $this->itemId)->getTitle());
        }
    }

    public function testChecklistAjaxCompletionIsReversibleAndIdempotent(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId);
        $form = $crawler->filter('.checklist-complete')->first()->form();
        $values = $form->getPhpValues();
        foreach ([true, true, false] as $completed) {
            $values['completed'] = $completed ? '1' : '0';
            $this->client->request('POST', $form->getUri(), $values, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseIsSuccessful();
            $state = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($completed, $state['completed']);
            self::assertSame($completed ? 1 : 0, $state['completedCount']);
            self::assertSame(2, $state['totalCount']);
        }
        $this->em()->clear();
        self::assertFalse($this->em()->find(ChecklistItem::class, $this->itemId)->isCompleted());
        self::assertFalse($this->em()->find(Task::class, $this->taskId)->isCompleted());
        $this->client->request('GET', '/tasks/'.$this->taskId);
        self::assertSelectorTextContains('.checklist-progress', '0/2');
    }

    public function testChecklistWorksWithoutJavascriptAndRejectsInvalidState(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
        $form = $crawler->filter('.checklist-complete')->first()->form();
        $values = $form->getPhpValues();
        $this->client->submit($form);
        self::assertResponseRedirects('/tasks/'.$this->taskId.'#checklist-'.$this->taskId, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorCount(1, '.checklist-item.is-done');
        self::assertSelectorTextContains('.checklist-progress', '1/2');
        $this->client->submit($crawler->filter('.checklist-complete')->first()->form());
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.checklist-item.is-done');
        $values['completed'] = 'invalid';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(400);
    }

    public function testChecklistMutationsRequireOwnerAndCsrf(): void
    {
        $this->client->request('POST', '/checklist/'.$this->itemId.'/complete', ['completed' => '1']);
        self::assertResponseRedirects('/login');
        $this->client->loginUser($this->bob);
        foreach (['/tasks/'.$this->taskId.'/checklist', '/checklist/'.$this->itemId.'/complete', '/checklist/'.$this->itemId.'/edit', '/checklist/'.$this->itemId.'/delete'] as $url) {
            $this->client->request('POST', $url, ['completed' => '1', 'title' => 'Чужое изменение']);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->loginUser($this->alice);
        foreach (['complete', 'edit', 'delete'] as $action) {
            $this->client->request('POST', '/checklist/'.$this->itemId.'/'.$action, ['completed' => '1', 'title' => 'Без токена']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/tasks/'.$this->taskId.'/checklist', ['checklist_'.$this->taskId => ['title' => 'Invalid', 'context' => 'task']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(2, $this->em()->getRepository(ChecklistItem::class)->count([]));
        $this->client->request('GET', '/tasks/'.$this->taskId.'/children/new');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/checklist/'.$this->itemId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testChecklistRejectsEmptyLongAndTaskLikeInput(): void
    {
        $this->client->loginUser($this->alice);
        foreach ([['title' => '   '], ['title' => str_repeat('x', 201)], ['title' => 'Text', 'priority' => '5', 'description' => 'Not allowed', 'task' => $this->foreignTaskId]] as $input) {
            $crawler = $this->client->request('GET', '/tasks/'.$this->taskId);
            $form = $crawler->filter('.checklist-add')->form();
            $values = $form->getPhpValues();
            $values['checklist_'.$this->taskId] = array_merge($values['checklist_'.$this->taskId], $input);
            $this->client->request('POST', $form->getUri(), $values);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(2, $this->em()->getRepository(ChecklistItem::class)->count([]));
        }
    }

    public function testRemovingChecklistItemPreservesTaskFilesAndShare(): void
    {
        $file = $this->makeAttachment($this->taskId);
        $share = $this->share($this->taskId);
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/?block='.$this->blockId);
        $this->client->submit($crawler->filter('.checklist-delete')->first()->form());
        self::assertResponseRedirects('/?block='.$this->blockId.'#checklist-'.$this->taskId, 303);
        $this->em()->clear();
        self::assertNull($this->em()->find(ChecklistItem::class, $this->itemId));
        self::assertNotNull($this->em()->find(ChecklistItem::class, $this->secondItemId));
        self::assertNotNull($this->em()->find(Task::class, $this->taskId));
        self::assertNotNull($this->em()->find(Attachment::class, $file->getId()));
        $this->client->restart();
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.checklist-item');
        self::assertSelectorNotExists('form');
    }

    public function testOverviewLimitsEachBlockAndCompletingPromotesNextTask(): void
    {
        $owner = $this->em()->find(User::class, $this->alice->getId());
        $block = $this->em()->find(TaskBlock::class, $this->blockId);
        foreach ([['Первый', 1, false], ['Второй', 1, false], ['Третий', 3, false], ['Завершённый', 1, true]] as [$title, $priority, $completed]) {
            $task = new Task($owner);
            $task->setBlock($block);
            $task->setTitle($title);
            $task->setPriority($priority);
            $task->setCompleted($completed);
            $this->em()->persist($task);
            $this->em()->flush();
        }
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorCount(1, '.block-preview');
        self::assertSelectorCount(3, '.task-card');
        self::assertSelectorTextContains('.block-preview-heading .count', '4');
        self::assertSelectorTextContains('.block-preview-footer .text-link', 'Все задачи (4)');
        self::assertSame(['Второй', 'Первый', 'Третий'], $crawler->filter('.task-summary > strong')->each(static fn ($node) => $node->text()));
        self::assertSelectorTextNotContains('main', 'Секретный блок');
        $form = $crawler->filter('.task-card-actions form')->first()->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/', 303);
        $crawler = $this->client->followRedirect();
        self::assertSame(['Первый', 'Третий', 'Подготовить проект'], $crawler->filter('.task-summary > strong')->each(static fn ($node) => $node->text()));
        self::assertSelectorTextContains('.block-preview-heading .count', '3');
        self::assertSelectorTextContains('.block-preview-footer .text-link', 'Все задачи (3)');
        self::assertSelectorCount(2, '.checklist-item');
        $this->client->submit($form); // Desired state is safe to resend.
        self::assertResponseRedirects('/', 303);
        $this->client->request('GET', '/?block='.$this->blockId);
        self::assertSelectorCount(3, '.task-card');
        $this->client->request('GET', '/?block='.$this->blockId.'&status=completed');
        self::assertSelectorCount(2, '.task-card');
    }

    public function testOverviewLimitsEachBlockSeparatelyAndKeepsCompleteChecklists(): void
    {
        $owner = $this->em()->find(User::class, $this->alice->getId());
        $ids = [];
        foreach (['Первый блок', 'Второй блок'] as $title) {
            $block = new TaskBlock($owner);
            $block->setTitle($title);
            $this->em()->persist($block);
            for ($priority = 1; $priority <= 4; ++$priority) {
                $task = new Task($owner);
                $task->setBlock($block);
                $task->setTitle($title.' / '.$priority);
                $task->setPriority($priority);
                $this->em()->persist($task);
                for ($i = 0; $i < 4; ++$i) {
                    $item = new ChecklistItem($task);
                    $item->setTitle('Пункт '.$i);
                    $this->em()->persist($item);
                }
            }
            $this->em()->flush();
            $ids[] = $block->getId();
        }
        $empty = new TaskBlock($owner);
        $empty->setTitle('Пустой');
        $this->em()->persist($empty);
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorCount(4, '.block-preview');
        foreach ($ids as $id) {
            $preview = $crawler->filter('[data-block-id="'.$id.'"]');
            self::assertCount(3, $preview->filter('.task-card'));
            self::assertCount(12, $preview->filter('.checklist-item'));
            self::assertSame(['Приоритет: 1', 'Приоритет: 2', 'Приоритет: 3'], $preview->filter('.priority-badge')->each(static fn ($node) => $node->text()));
        }
        $names = $crawler->filter('.checklist-add')->each(static fn ($node) => $node->attr('name'));
        self::assertCount(count($names), array_unique($names));
        self::assertSelectorTextContains('[data-block-id="'.$empty->getId().'"] .preview-empty', 'пока нет задач');
    }

    public function testTaskCannotLoseItsBlock(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId.'/edit');
        $form = $crawler->selectButton('Сохранить задачу')->form();
        $values = $form->getPhpValues();
        $values['task']['block'] = '';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        self::assertSame($this->blockId, $this->em()->find(Task::class, $this->taskId)->getBlock()->getId());
    }

    public function testDeletingBlockRemovesFilesAndRevokesShares(): void
    {
        $file = $this->makeAttachment($this->taskId);
        $path = self::getContainer()->get(FileStorage::class)->path($file->getStoredName());
        $share = $this->share($this->taskId);
        $this->client->loginUser($this->alice);
        $this->client->request('POST', '/blocks/'.$this->blockId.'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertFileExists($path);
        $crawler = $this->client->request('GET', '/blocks/'.$this->blockId.'/edit');
        $this->client->submit($crawler->selectButton('Удалить блок')->form());
        self::assertResponseRedirects('/', 303);
        self::assertFileDoesNotExist($path);
        self::assertSame(0, $this->em()->getRepository(Attachment::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(TaskShareLink::class)->count([]));
        self::assertNotNull($this->em()->find(Task::class, $this->foreignTaskId));
        $this->client->request('GET', '/s/'.$share['token']);
        self::assertResponseStatusCodeSame(404);
    }

}
