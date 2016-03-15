<?php

namespace App\Model;

use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\History;
use DB;

class Importer
{
    public static function import($offset, $size)
    {
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $history = History::getList(null, null, $offset, $size);
        if (empty($history)) {
            return array(0, array());
        }

        $results = array();
        $insertedCount = 0;

        foreach ($history as $buzzstreamHistoryItem) {
            $buzzstreamId = $buzzstreamHistoryItem->getBuzzstreamId();
            $item = HistoryItem::findByBuzzstreamId($buzzstreamId);

            if (! $item->getId()) {
                $item = HistoryItem::create(array(
                    'buzzstream_id'         => $buzzstreamId,
                    'buzzstream_created_at' => $buzzstreamHistoryItem->getCreatedAt(),
                    'type'                  => $buzzstreamHistoryItem->getType(),
                    'summary'               => $buzzstreamHistoryItem->getSummary(),
                    'body'                  => $buzzstreamHistoryItem->getBody(),
                    'avatar_url'            => $buzzstreamHistoryItem->getAvatarUrl(),
                    'website_names'         => $buzzstreamHistoryItem->getWebsiteNamesCsv(),
                    'buzzstream_owner_id'   => $buzzstreamHistoryItem->getBuzzstreamOwnerId(),
                ));
                $insertedCount++;
            }

            $buzzstreamProjectIds = $buzzstreamHistoryItem->getBuzzstreamProjectIds();
            DB::statement("DELETE FROM history_item_projects WHERE history_item_id = {$item->getId()}");

            foreach ($buzzstreamProjectIds as $projectId) {
                HistoryItemProject::create(array(
                    'history_item_id'       => $item->getId(),
                    'buzzstream_project_id' => $projectId,
                ));
            }

            $buzzstreamWebsiteIds = $buzzstreamHistoryItem->getBuzzstreamWebsiteIds();
            DB::statement("DELETE FROM history_item_websites WHERE history_item_id = {$item->getId()}");
            foreach ($buzzstreamWebsiteIds as $websiteId) {
                HistoryItemWebsite::create(array(
                    'history_item_id'       => $item->getId(),
                    'buzzstream_website_id' => $websiteId,
                ));
            }

            $results[] = $item->toArray();
        }

        return array($insertedCount, $results);
    }
}