<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce normalized email, task hierarchy, ownership, upload size and share lifetime in PostgreSQL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT normalized_email CHECK (email = lower(btrim(email)))");
        $this->addSql("ALTER TABLE task ADD CONSTRAINT task_title_not_blank CHECK (length(btrim(title)) > 0)");
        $this->addSql("ALTER TABLE task_block ADD CONSTRAINT block_title_not_blank CHECK (length(btrim(title)) > 0)");
        $this->addSql('ALTER TABLE task ADD CONSTRAINT task_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT child_has_no_block CHECK (parent_id IS NULL OR block_id IS NULL)');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT attachment_size_limit CHECK (size > 0 AND size <= 50000000)');
        $this->addSql("ALTER TABLE task_share_link ADD CONSTRAINT share_lifetime CHECK (expires_at - created_at = interval '24 hours')");
        $this->addSql(<<<'SQL'
CREATE FUNCTION validate_task_links() RETURNS trigger AS $$
DECLARE parent_owner int; parent_parent int; block_owner int;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.owner_id IS DISTINCT FROM OLD.owner_id OR NEW.parent_id IS DISTINCT FROM OLD.parent_id THEN
            RAISE EXCEPTION 'Task owner and parent are immutable' USING ERRCODE = '23514';
        END IF;
    END IF;
    IF NEW.parent_id IS NOT NULL THEN
        SELECT owner_id, parent_id INTO parent_owner, parent_parent FROM task WHERE id = NEW.parent_id FOR KEY SHARE;
        IF NOT FOUND OR parent_owner <> NEW.owner_id OR parent_parent IS NOT NULL THEN
            RAISE EXCEPTION 'Subtask requires a root parent with the same owner' USING ERRCODE = '23514';
        END IF;
    END IF;
    IF NEW.block_id IS NOT NULL THEN
        SELECT owner_id INTO block_owner FROM task_block WHERE id = NEW.block_id FOR KEY SHARE;
        IF NOT FOUND OR block_owner <> NEW.owner_id THEN
            RAISE EXCEPTION 'Task block must have the same owner' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        $this->addSql('CREATE TRIGGER validate_task_links BEFORE INSERT OR UPDATE ON task FOR EACH ROW EXECUTE FUNCTION validate_task_links()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION preserve_block_owner() RETURNS trigger AS $$
BEGIN
    IF NEW.owner_id IS DISTINCT FROM OLD.owner_id THEN
        RAISE EXCEPTION 'Block owner is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        $this->addSql('CREATE TRIGGER preserve_block_owner BEFORE UPDATE ON task_block FOR EACH ROW EXECUTE FUNCTION preserve_block_owner()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER preserve_block_owner ON task_block');
        $this->addSql('DROP FUNCTION preserve_block_owner()');
        $this->addSql('DROP TRIGGER validate_task_links ON task');
        $this->addSql('DROP FUNCTION validate_task_links()');
        $this->addSql('ALTER TABLE task_share_link DROP CONSTRAINT share_lifetime');
        $this->addSql('ALTER TABLE attachment DROP CONSTRAINT attachment_size_limit');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT child_has_no_block');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT task_not_own_parent');
        $this->addSql('ALTER TABLE task_block DROP CONSTRAINT block_title_not_blank');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT task_title_not_blank');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT normalized_email');
    }
}
