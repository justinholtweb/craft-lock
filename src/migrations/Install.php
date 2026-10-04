<?php

namespace justinholtweb\lock\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Lock's schema.
 *
 * Two rules run through all of it.
 *
 * **User foreign keys are `SET NULL`, never `CASCADE`.** Deleting a person must not delete the
 * evidence that you handled their request lawfully — that record outlives them on purpose, and a
 * cascade would quietly destroy the plugin's own audit trail the first time somebody used the
 * plugin as intended.
 *
 * **Ledgers store JSON, not joins.** The activity log, the run outcomes and the erasure
 * certificates are historical records. Normalising them means an old row changes shape when the
 * schema does, and an audit record that changes retrospectively is not an audit record.
 */
class Install extends Migration
{
    /**
     * Re-runnable. Each table is created only when it is missing, together with its indexes and
     * keys, so an install that is replayed — a test harness, a half-finished first attempt — adds
     * nothing twice. `createIndex()` in particular is not idempotent, and MySQL will happily keep
     * duplicate indexes until a table hits its 64-key ceiling.
     */
    public function safeUp(): bool
    {
        $this->requests();
        $this->requestEvents();
        $this->consents();
        $this->activity();
        $this->processing();
        $this->runs();
        $this->holds();
        $this->erasures();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%lock_erasures}}');
        $this->dropTableIfExists('{{%lock_holds}}');
        $this->dropTableIfExists('{{%lock_runs}}');
        $this->dropTableIfExists('{{%lock_processing}}');
        $this->dropTableIfExists('{{%lock_activity}}');
        $this->dropTableIfExists('{{%lock_consents}}');
        $this->dropTableIfExists('{{%lock_requestevents}}');
        $this->dropTableIfExists('{{%lock_requests}}');

        return true;
    }

    private function requests(): void
    {
        if ($this->db->tableExists('{{%lock_requests}}')) {
            return;
        }

        $this->createTable('{{%lock_requests}}', [
            'id' => $this->primaryKey(),

            // The reference the subject quotes. Unique, and never reused.
            'reference' => $this->string(32)->notNull(),

            'type' => $this->string(20)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('unverified'),
            'source' => $this->string(20)->notNull()->defaultValue('web'),

            'email' => $this->string()->notNull(),
            'name' => $this->string(),
            'userId' => $this->integer(),

            'message' => $this->text(),
            'note' => $this->text(),
            'outcome' => $this->text(),

            'assigneeId' => $this->integer(),

            // Only the hash is stored. A verification token in the clear is a password to
            // somebody else's personal data sitting in the database.
            'tokenHash' => $this->string(64),
            'tokenExpiresAt' => $this->dateTime(),

            'receivedAt' => $this->dateTime()->notNull(),
            'verifiedAt' => $this->dateTime(),
            'dueAt' => $this->dateTime(),
            'extendedAt' => $this->dateTime(),
            'closedAt' => $this->dateTime(),

            'dossierPath' => $this->string(1000),
            'dossierBuiltAt' => $this->dateTime(),

            'context' => $this->json(),
            'remindersSent' => $this->integer()->notNull()->defaultValue(0),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_requests}}', ['reference'], true);
        $this->createIndex(null, '{{%lock_requests}}', ['status', 'dueAt'], false);
        $this->createIndex(null, '{{%lock_requests}}', ['email'], false);
        $this->createIndex(null, '{{%lock_requests}}', ['tokenHash'], false);

        $this->addForeignKey(null, '{{%lock_requests}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%lock_requests}}', ['assigneeId'], Table::USERS, ['id'], 'SET NULL', null);
    }

    private function requestEvents(): void
    {
        if ($this->db->tableExists('{{%lock_requestevents}}')) {
            return;
        }

        $this->createTable('{{%lock_requestevents}}', [
            'id' => $this->primaryKey(),
            'requestId' => $this->integer()->notNull(),
            'type' => $this->string(40)->notNull(),
            'message' => $this->text(),
            'data' => $this->json(),
            'userId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_requestevents}}', ['requestId', 'dateCreated'], false);

        // The one cascade in the schema, and it is the right one: the timeline is part of the
        // request, not a separate record of it.
        $this->addForeignKey(null, '{{%lock_requestevents}}', ['requestId'], '{{%lock_requests}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%lock_requestevents}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
    }

    private function consents(): void
    {
        if ($this->db->tableExists('{{%lock_consents}}')) {
            return;
        }

        $this->createTable('{{%lock_consents}}', [
            'id' => $this->primaryKey(),

            'email' => $this->string()->notNull(),

            // Kept alongside the address so a consent record survives anonymisation of the
            // subject with its identity intact enough to prove the consent happened.
            'emailHash' => $this->string(64)->notNull(),

            'userId' => $this->integer(),
            'purpose' => $this->string(64)->notNull(),
            'state' => $this->string(16)->notNull()->defaultValue('granted'),
            'source' => $this->string(32)->notNull()->defaultValue('form'),
            'policyVersion' => $this->string(64),
            'evidence' => $this->json(),
            'siteId' => $this->integer()->null(),
            'recordedAt' => $this->dateTime()->notNull(),
            'expiresAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_consents}}', ['emailHash', 'purpose', 'recordedAt'], false);
        $this->createIndex(null, '{{%lock_consents}}', ['email'], false);
        $this->createIndex(null, '{{%lock_consents}}', ['purpose', 'state'], false);

        $this->addForeignKey(null, '{{%lock_consents}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
        // SET NULL, not CASCADE. Deleting a site must not delete the proof that people on it
        // consented — Article 7(1) does not lapse because a site was retired.
        $this->addForeignKey(null, '{{%lock_consents}}', ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
    }

    private function activity(): void
    {
        if ($this->db->tableExists('{{%lock_activity}}')) {
            return;
        }

        $this->createTable('{{%lock_activity}}', [
            'id' => $this->primaryKey(),
            'category' => $this->string(24)->notNull(),
            'action' => $this->string(64)->notNull(),
            'summary' => $this->text(),

            // The keyed hash only, never the address. This table is append-only, so an address
            // written here is one no erasure can ever take back out.
            'subjectHash' => $this->string(64),
            'requestId' => $this->integer(),
            'data' => $this->json(),
            'userId' => $this->integer(),
            'ip' => $this->string(45),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_activity}}', ['category', 'dateCreated'], false);
        $this->createIndex(null, '{{%lock_activity}}', ['subjectHash'], false);
        $this->createIndex(null, '{{%lock_activity}}', ['requestId'], false);

        $this->addForeignKey(null, '{{%lock_activity}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);

        // Deliberately *no* foreign key to the request. Deleting a request must not delete the
        // log line that says it was deleted.
    }

    private function processing(): void
    {
        if ($this->db->tableExists('{{%lock_processing}}')) {
            return;
        }

        $this->createTable('{{%lock_processing}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'purpose' => $this->text(),
            'basis' => $this->string(32)->notNull()->defaultValue('legitimate_interests'),
            'balancing' => $this->text(),
            'dataCategories' => $this->json(),
            'subjectCategories' => $this->json(),
            'recipients' => $this->json(),
            'transfers' => $this->text(),
            'retention' => $this->text(),
            'safeguards' => $this->text(),
            'systems' => $this->json(),
            'owner' => $this->string(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'reviewedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_processing}}', ['sortOrder'], false);
    }

    private function runs(): void
    {
        if ($this->db->tableExists('{{%lock_runs}}')) {
            return;
        }

        $this->createTable('{{%lock_runs}}', [
            'id' => $this->primaryKey(),

            // `retention`, `erasure` or `dossier`.
            'type' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),

            'ruleKey' => $this->string(64),

            // Hash only, for the same reason as the activity ledger: the run that erased somebody
            // must not be the row that remembers their address.
            'subjectHash' => $this->string(64),
            'requestId' => $this->integer(),

            'summary' => $this->text(),

            // The plan and the outcome, side by side. Kept apart so "exactly what the preview
            // said" is a comparison anyone can make afterwards rather than a promise.
            'plan' => $this->json(),
            'outcome' => $this->json(),
            'fingerprint' => $this->string(64),

            'erased' => $this->integer()->notNull()->defaultValue(0),
            'anonymised' => $this->integer()->notNull()->defaultValue(0),
            'skipped' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),

            'duration' => $this->decimal(10, 3)->notNull()->defaultValue(0),
            'dryRun' => $this->boolean()->notNull()->defaultValue(false),
            'scheduled' => $this->boolean()->notNull()->defaultValue(false),

            'backupPath' => $this->string(1000),
            'userId' => $this->integer(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_runs}}', ['type', 'dateCreated'], false);
        $this->createIndex(null, '{{%lock_runs}}', ['ruleKey', 'dryRun', 'dateCreated'], false);
        $this->createIndex(null, '{{%lock_runs}}', ['subjectHash'], false);

        $this->addForeignKey(null, '{{%lock_runs}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
    }

    private function holds(): void
    {
        if ($this->db->tableExists('{{%lock_holds}}')) {
            return;
        }

        $this->createTable('{{%lock_holds}}', [
            'id' => $this->primaryKey(),
            'email' => $this->string()->notNull()->defaultValue(''),
            'emailHash' => $this->string(64),
            'userId' => $this->integer(),
            'reason' => $this->text()->notNull(),
            'expiresAt' => $this->dateTime(),
            'createdBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_holds}}', ['emailHash'], false);

        $this->addForeignKey(null, '{{%lock_holds}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%lock_holds}}', ['createdBy'], Table::USERS, ['id'], 'SET NULL', null);
    }

    private function erasures(): void
    {
        if ($this->db->tableExists('{{%lock_erasures}}')) {
            return;
        }

        $this->createTable('{{%lock_erasures}}', [
            'id' => $this->primaryKey(),

            // No plaintext address. This table is what remains *after* an address was erased, so
            // storing it here would make the erasure a lie.
            'emailHash' => $this->string(64)->notNull(),

            'pseudonym' => $this->string(64)->notNull(),
            'mode' => $this->string(16)->notNull(),
            'requestId' => $this->integer(),
            'reference' => $this->string(32),

            // What was done, by source, with counts. No labels that could re-identify.
            'scope' => $this->json(),

            'erased' => $this->integer()->notNull()->defaultValue(0),
            'anonymised' => $this->integer()->notNull()->defaultValue(0),

            // Whether a later re-import of this address should be blocked.
            'suppressed' => $this->boolean()->notNull()->defaultValue(true),

            'completedAt' => $this->dateTime()->notNull(),
            'userId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%lock_erasures}}', ['emailHash'], false);
        $this->createIndex(null, '{{%lock_erasures}}', ['suppressed'], false);

        $this->addForeignKey(null, '{{%lock_erasures}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
    }
}
