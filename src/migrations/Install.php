<?php

namespace justinholtweb\airwave\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\Source;

/**
 * Three tables, and only one of them is big.
 *
 * `airwave_sources` and `airwave_channels` are element-backed configuration — a handful of rows a
 * site will ever have, extending `elements` the way Craft's own element tables do.
 *
 * `airwave_items` is the odd one out and deliberately **not** an element. A busy feed republished
 * hourly produces tens of thousands of rows that nobody edits, searches in the CP, relates to,
 * revises or restores from the trash; paying `elements` + `elements_sites` + search index for each
 * would buy nothing and cost a great deal. Items that become entries get elements — real ones,
 * pointed at from `importedElementId`.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%airwave_sources}}', [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string()->notNull(),
            'url' => $this->text()->notNull(),
            // Null means "use the site-wide default", so raising the default raises it everywhere
            // that never asked for something specific.
            'refreshInterval' => $this->integer(),
            'itemLimit' => $this->integer(),
            'filters' => $this->text(),
            'displayOptions' => $this->text(),
            'fetchOptions' => $this->text(),
            'importEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'importConfig' => $this->text(),
            'meta' => $this->text(),
            // Conditional-GET state. A feed that answers 304 costs a header exchange instead of a
            // megabyte, and most feeds answer 304 most of the time.
            'etag' => $this->string(500),
            'lastModifiedHeader' => $this->string(255),
            'lastFetchedAt' => $this->dateTime(),
            'lastSuccessAt' => $this->dateTime(),
            'lastStatus' => $this->string(16)->notNull()->defaultValue(Source::STATUS_NEVER),
            'lastError' => $this->text(),
            // Denormalised so the index can sort and warn without counting rows per feed.
            'itemCount' => $this->integer()->notNull()->defaultValue(0),
            'newItemCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%airwave_sources}}', ['handle'], true);
        $this->createIndex(null, '{{%airwave_sources}}', ['lastStatus'], false);
        $this->createIndex(null, '{{%airwave_sources}}', ['lastFetchedAt'], false);
        $this->addForeignKey(null, '{{%airwave_sources}}', ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);

        $this->createTable('{{%airwave_items}}', [
            'id' => $this->primaryKey(),
            'sourceId' => $this->integer()->notNull(),
            'guid' => $this->text(),
            // A GUID is routinely a permalink with query parameters, far past anything a unique
            // index can cover, and feeds exist whose GUIDs differ only near the end. Hash it.
            'guidHash' => $this->char(40)->notNull(),
            'url' => $this->text(),
            'title' => $this->text(),
            'summary' => $this->text(),
            'content' => $this->mediumText(),
            'author' => $this->string(255),
            'imageUrl' => $this->text(),
            'enclosureUrl' => $this->text(),
            'enclosureType' => $this->string(255),
            'enclosureLength' => $this->bigInteger(),
            'categories' => $this->text(),
            'extra' => $this->text(),
            'publishedAt' => $this->dateTime(),
            'fetchedAt' => $this->dateTime()->notNull(),
            'importedElementId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%airwave_items}}', ['sourceId', 'guidHash'], true);
        $this->createIndex(null, '{{%airwave_items}}', ['sourceId', 'publishedAt'], false);
        $this->createIndex(null, '{{%airwave_items}}', ['publishedAt'], false);
        $this->createIndex(null, '{{%airwave_items}}', ['importedElementId'], false);
        $this->addForeignKey(null, '{{%airwave_items}}', ['sourceId'], '{{%airwave_sources}}', ['id'], 'CASCADE', 'CASCADE');
        // SET NULL, not CASCADE: deleting the entry an item became should not delete the record
        // that the item was seen — otherwise the next refresh imports it all over again.
        $this->addForeignKey(null, '{{%airwave_items}}', ['importedElementId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);

        $this->createTable('{{%airwave_channels}}', [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string()->notNull(),
            // Which site's content this channel broadcasts, and whose base URL its URI hangs off.
            // Named `targetSiteId` because a channel element also has Craft's own `siteId`, and
            // conflating the two is a bug that only shows up on the second site.
            'targetSiteId' => $this->integer()->notNull(),
            'uri' => $this->string(255)->notNull(),
            'format' => $this->string(16)->notNull()->defaultValue(Channel::FORMAT_RSS),
            'feedSource' => $this->string(16)->notNull()->defaultValue(Channel::SOURCE_ELEMENTS),
            'elementType' => $this->string(255),
            'criteria' => $this->text(),
            'sourceIds' => $this->text(),
            'itemLimit' => $this->integer()->notNull()->defaultValue(20),
            'mapping' => $this->text(),
            'feedTitle' => $this->string(255),
            'feedDescription' => $this->text(),
            'feedLink' => $this->string(500),
            'language' => $this->string(16),
            'copyright' => $this->string(255),
            'imageUrl' => $this->string(500),
            'cacheDuration' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%airwave_channels}}', ['handle'], true);
        $this->createIndex(null, '{{%airwave_channels}}', ['targetSiteId', 'uri'], true);
        $this->addForeignKey(null, '{{%airwave_channels}}', ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%airwave_channels}}', ['targetSiteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        // Failing-feed alerts. Defined once, in the migration that added it.
        m261009_000000_alerts::createAlertsTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        // Delete the elements first: the CASCADE goes the other way, so dropping the tables alone
        // would leave rows in `elements` pointing at element types that no longer exist.
        $this->delete(CraftTable::ELEMENTS, ['type' => Source::class]);
        $this->delete(CraftTable::ELEMENTS, ['type' => Channel::class]);
        $this->dropTableIfExists(m261009_000000_alerts::TABLE);
        $this->dropTableIfExists('{{%airwave_items}}');
        $this->dropTableIfExists('{{%airwave_channels}}');
        $this->dropTableIfExists('{{%airwave_sources}}');

        return true;
    }
}
