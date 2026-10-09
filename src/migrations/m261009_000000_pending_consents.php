<?php

namespace justinholtweb\lock\migrations;

use craft\db\Migration;

/** Adds the table that holds form consent waiting for its emailed confirmation. */
class m261009_000000_pending_consents extends Migration
{
    public function safeUp(): bool
    {
        // The same method a fresh install runs, so the two cannot drift apart.
        (new Install(['db' => $this->db]))->pendingConsents();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%lock_pendingconsents}}');

        return true;
    }
}
