<?php

namespace justinholtweb\airwave\migrations;

use craft\db\Migration;

/**
 * Failing-feed alerts: one latch row per feed and incident type.
 *
 * The table definition lives in {@see createAlertsTable()} so `Install` and this migration cannot
 * drift apart — a fresh install and an upgrade end up with the same columns.
 */
class m261009_000000_alerts extends Migration
{
    public const TABLE = '{{%airwave_alerts}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            self::createAlertsTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);

        return true;
    }

    /**
     * The latch. Unique on `(sourceId, incident)`, so there is exactly one row to compare and
     * stamp — the database, not a remembered check, is what makes "one email per incident" true
     * when a queued refresh and cron both run a check in the same minute.
     */
    public static function createAlertsTable(Migration $migration): void
    {
        $migration->createTable(self::TABLE, [
            'id' => $migration->primaryKey(),
            'sourceId' => $migration->integer()->notNull(),
            'incident' => $migration->string(32)->notNull(),
            // `ok` or `open`.
            'state' => $migration->string(8)->notNull()->defaultValue('ok'),
            // Consecutive failed refreshes. Kept here rather than on the feed so a refresh's own
            // bookkeeping stays exactly what it was; reset to 0 by the first success.
            'failures' => $migration->integer()->notNull()->defaultValue(0),
            // Redacted, short: what the last check saw. Never a credential or a feed URL's query.
            'detail' => $migration->text(),
            'openedAt' => $migration->dateTime()->null(),
            'notifiedAt' => $migration->dateTime()->null(),
            'recoveredAt' => $migration->dateTime()->null(),
            'recoveryNotifiedAt' => $migration->dateTime()->null(),
            // Set when a recovery goes out: a reopening before this is held, not sent.
            'quietUntil' => $migration->dateTime()->null(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, self::TABLE, ['sourceId', 'incident'], true);
        $migration->addForeignKey(null, self::TABLE, ['sourceId'], '{{%airwave_sources}}', ['id'], 'CASCADE', 'CASCADE');
    }
}
