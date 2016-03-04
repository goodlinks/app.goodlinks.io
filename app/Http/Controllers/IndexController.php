<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;
use App\Model\HistoryItem;
use App\Model\HistoryItemProject;
use App\Model\HistoryItemWebsite;

use Carbon\Carbon;
use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\History;
use DB;

class IndexController extends Controller
{
    protected function _getFromDate($projectData)
    {
        if (isset($_GET['from'])) {
            $fromDate = new Carbon($_GET['from']);
            return $fromDate;
        }

        $projectData = $this->_getProjectData($projectData['id']);

        $fromDate = new Carbon($projectData['billing_start_date']);
        $toDate = $fromDate->copy()->addMonth(1);
        $today = new Carbon();

        while ($toDate < $today) {
            $fromDate->addMonth(1);
            $toDate->addMonth(1);
        }

        return $fromDate;
    }

    public function index()
    {
        $path = dirname(dirname(dirname(dirname(__FILE__)))) . "/.projects.json";
        $projects = json_decode(file_get_contents($path), true);
        if (! $projects) {
            die("Couldn't find projects json data");
        }

        foreach ($projects as & $projectData) {
            $projectData['pitch_count'] = $this->_getPitchCount($projectData);
            $projectData['placement_count'] = $this->_getPlacementCount($projectData);
            $projectData['website_count'] = $this->_getWebsiteCount($projectData);

            $data = $this->_getProjectStatusData($projectData);
            $projectData['project_status'] = $data['project_status'];
            $projectData['progress_severity'] = $data['progress_severity'];
            $projectData['pitch_completion_percentage'] = $data['pitch_completion_percentage'];
            $projectData['billing_period_completion_percentage'] = $data['billing_period_completion_percentage'];

            $projectData['from_date'] = $this->_getFromDate($projectData);
            $projectData['to_date'] = $projectData['from_date']->copy()->addMonth(1);

        }

        $twig = TwigHelper::twig();
        $monthlyPitchCount = getenv('MONTHLY_PITCH_COUNT');

        return $twig->render('index.html.twig', array(
            "title"                 => "Projects",
            "body_class"            => "home",
            "projects"              => $projects,
            "monthly_pitch_count"   => $monthlyPitchCount,
        ));
    }

    protected function _getPitchCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $fromDate->copy()->addMonth();

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'Stage')
            ->where('summary', 'like', 'Relationship stage changed to: Pitched')
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->count();

        return $count;
    }

    protected function _getWebsiteCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $fromDate->copy()->addMonth();

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->count(DB::raw('DISTINCT history_item_websites.buzzstream_website_id'));

        return $count;
    }

    protected function _getPlacementCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $fromDate->copy()->addMonth();

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'Stage')
            ->where('summary', 'like', '%Successful%')
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->count();

        return $count;
    }

    protected function _getProjectStatusData($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $pitchCount = $projectData['pitch_count'];
        $monthlyPitchCount = getenv('MONTHLY_PITCH_COUNT');

        $today = new Carbon();
        $daysIntoBillingPeriod = $today->diffInDays($fromDate);
        $percentBillingPeriodComplete = ($daysIntoBillingPeriod / 30) * 100;
        $percentPitchProgress = ($pitchCount / $monthlyPitchCount) * 100;

        $diff = $percentPitchProgress - $percentBillingPeriodComplete;
        $status = $percentPitchProgress >= $percentBillingPeriodComplete ? "ahead-schedule" : "behind-schedule";
        $severity = abs($diff) >= 7 ? "lot" : "little";

        return array(
            'project_status' => $status,
            'progress_severity' => $severity,
            'pitch_completion_percentage'   => $percentPitchProgress,
            'billing_period_completion_percentage' => $percentBillingPeriodComplete,
        );
    }

    public function project($projectId)
    {
        $projectData = $this->_getProjectData($projectId);

        $key = isset($_GET['key']) ? $_GET['key'] : null;
        if (!$key || $key != $projectData['secret']) {
            die("Access denied");
        }

        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $fromDate = $this->_getFromDate($projectData);
        $toDate = $fromDate->copy()->addMonth(1);

        $excludedWebsiteIds = array(
            56013240,
            55896282,
            55438015, // Buzzstream
        );

        $historyItems = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->whereNotIn('history_item_websites.buzzstream_website_id', $excludedWebsiteIds)
            ->where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->orderBy('buzzstream_created_at', 'desc')
            ->limit(2000)
            ->get();

        $websiteCount = $this->_getWebsiteCount($projectData);
        $placementCount = $this->_getPlacementCount($projectData);
        $monthlyPitchCount = getenv('MONTHLY_PITCH_COUNT');

        $twig = TwigHelper::twig();

        $projectData['pitch_count'] = $this->_getPitchCount($projectData);
        $data = $this->_getProjectStatusData($projectData);

        return $twig->render('project.html.twig', array(
            "title"                         => "BuzzStream Feed",
            "body_class"                    => "home",
            "history"                       => $historyItems,
            "project_data"                  => $projectData,
            "website_count"                 => $websiteCount,
            "placement_count"               => $placementCount,
            "pitch_count"                   => $projectData['pitch_count'],
            "progress_status"               => $data['project_status'],
            "progress_severity"             => $data['progress_severity'],
            "pitch_completion_percentage"   => $data['pitch_completion_percentage'],
            "month_completion_percentage"   => $data['billing_period_completion_percentage'],
            "from_date"                     => $fromDate,
            "to_date"                       => $toDate,
            "monthly_pitch_count"           => $monthlyPitchCount,
            "billing_start_date"            => new Carbon($projectData['billing_start_date']),
        ));
    }

    public function import()
    {
        $twig = TwigHelper::twig();
        $offset = isset($_GET['offset']) ? $_GET['offset'] : 0;
        $size = isset($_GET['size']) ? $_GET['size'] : 50;

        return $twig->render('import.html.twig', array(
            "title"             => "Import from BuzzStream",
            "body_class"        => "home",
            "offset"            => $offset,
            "size"              => $size,
        ));
    }

    /**
     * Do an initial import starting at the most recent history item
     * and going back in time
     *
     * @return array
     * @throws \Exception
     */
    public function importBuzzstreamInitial()
    {
        $before = microtime(true);
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $offset = isset($_GET['offset']) ? $_GET['offset'] : 0;
        $size = isset($_GET['size']) ? $_GET['size'] : 50;

        $history = History::getList(null, null, $offset, $size);
        if (empty($history)) {
            return array(
                'success' => true,
                'complete'  => true,
            );
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
                ));
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
            $insertedCount++;
        }

        $newOffset = $offset + $size;
        $nextProcessUrl = "/processFeedInitial?size=$size&offset=$newOffset";

        return array(
            'success'           => true,
            'offset'            => $offset,
            'size'              => $size,
            'next_url'          => $nextProcessUrl,
            'inserted_count'    => $insertedCount,
            'results'           => $results,
            'processing_time'   => number_format(microtime(true) - $before, 2) . "s",
        );
    }

    protected function _getProjectData($projectId)
    {
        $path = dirname(dirname(dirname(dirname(__FILE__)))) . "/.projects.json";
        $projects = json_decode(file_get_contents($path), true);
        if (! $projects) {
            die("Couldn't find projects json data");
        }

        $thisProjectData = array();
        foreach ($projects as $projectData) {
            if ($projectData['id'] == $projectId) {
                $thisProjectData = $projectData;
            }
        }

        if (empty($thisProjectData)) {
            die("Couldn't find project data for: " . $projectId);
        }

        return $thisProjectData;
    }
}