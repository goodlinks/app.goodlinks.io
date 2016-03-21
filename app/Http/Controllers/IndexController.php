<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;
use App\Model\HistoryItem;
use App\Model\Importer;

use Carbon\Carbon;
use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\User;
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
            $projectData['website_count'] = $this->_getWebsiteCount($projectData);
            $projectData['introduction_count'] = $this->_getIntroductionCount($projectData);
            $projectData['referral_count'] = $this->_getReferralCount($projectData);
            $projectData['placement_count'] = $this->_getPlacementCount($projectData);
            $projectData['link_agreed_count'] = $this->_getPlacementCount($projectData);
            $projectData['conversion_count'] = $projectData['link_agreed_count'] + $projectData['placement_count'] + $projectData['introduction_count'] + $projectData['referral_count'];

            $data = $this->_getProjectStatusData($projectData);
            $projectData['project_status'] = $data['project_status'];
            $projectData['progress_severity'] = $data['progress_severity'];
            $projectData['conversion_completion_percentage'] = $data['conversion_completion_percentage'];
            $projectData['billing_period_completion_percentage'] = $data['billing_period_completion_percentage'];

            $projectData['from_date'] = $this->_getFromDate($projectData);
            $projectData['to_date'] = $projectData['from_date']->copy()->addMonth(1);

        }

        $twig = TwigHelper::twig();
        $monthlyConversionCount = getenv('MONTHLY_CONVERSION_COUNT');

        return $twig->render('index.html.twig', array(
            "title"                     => "Projects",
            "body_class"                => "home",
            "projects"                  => $projects,
            "monthly_conversion_count"  => $monthlyConversionCount,
        ));
    }

    public function team()
    {
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $monthlyPitchCount = getenv('TEAM_MEMBER_MONTHLY_PITCH_COUNT');

        $path = dirname(dirname(dirname(dirname(__FILE__)))) . "/.projects.json";
        $projects = json_decode(file_get_contents($path), true);
        if (! $projects) {
            die("Couldn't find projects json data");
        }

        $fromDate = new Carbon("2016-03-01");
        $toDate = $fromDate->copy()->addMonth();

        $buzzstreamUsers = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'Stage')
            ->where('is_ignored', '=', 0)
            ->where('summary', 'like', "Relationship stage changed to: Pitched")
            ->whereNotNull('buzzstream_owner_id')
            ->groupBy('buzzstream_owner_id')
            ->select(array(
                'buzzstream_owner_id as buzzstream_user_id',
                DB::raw('count(*) as pitch_count'),
            ))
            ->get();

        $users = array();
        foreach ($buzzstreamUsers as $buzzstreamUserData) {
            $buzzstreamUserUrl = "https://api.buzzstream.com/v1/users/" . $buzzstreamUserData['buzzstream_user_id'];
            $buzzstreamUser = new User();
            $buzzstreamUser->load($buzzstreamUserUrl);

            $pitchCount = $this->_getPitchCountByUser($buzzstreamUser, $fromDate, $toDate);

            $today = new Carbon();
            $daysIntoBillingPeriod = $today->diffInDays($fromDate);
            $percentBillingPeriodComplete = ($daysIntoBillingPeriod / 30) * 100;
            $percentPitchProgress = ($pitchCount / $monthlyPitchCount) * 100;
            $percentPitchProgress = ($percentPitchProgress > 100) ? 100 : $percentPitchProgress;

            $diff = $percentPitchProgress - $percentBillingPeriodComplete;
            $status = $percentPitchProgress >= $percentBillingPeriodComplete ? "ahead-schedule" : "behind-schedule";
            $severity = abs($diff) >= 7 ? "lot" : "little";

            $websiteCount = $this->_getWebsiteCountByUser($buzzstreamUser, $fromDate, $toDate);
            $introductionCount = $this->_getIntroductionCountByUser($buzzstreamUser, $fromDate, $toDate);
            $referralCount = $this->_getReferralCountByUser($buzzstreamUser, $fromDate, $toDate);
            $placementCount = $this->_getReferralCountByUser($buzzstreamUser, $fromDate, $toDate);
            $linkAgreedCount = $this->_getReferralCountByUser($buzzstreamUser, $fromDate, $toDate);
            $conversionCount = $this->_getReferralCountByUser($buzzstreamUser, $fromDate, $toDate);
            $conversionRate = ($pitchCount > 0) ? number_format($conversionCount / $pitchCount * 100, 1) : 0;

            $users[] = array(
                'name'                                  => $buzzstreamUser->getName(),
                'email'                                 => $buzzstreamUser->getEmail(),
                'image_url'                             => 'http://www.gravatar.com/avatar/' . md5($buzzstreamUser->getEmail()),
                'pitch_completion_percentage'           => $percentPitchProgress,
                'billing_period_completion_percentage'  => $percentBillingPeriodComplete,
                'progress_status'                       => $status,
                'progress_severity'                     => $severity,
                'pitch_count'                           => $pitchCount,
                'website_count'                         => $websiteCount,
                'introduction_count'                    => $introductionCount,
                'referral_count'                        => $referralCount,
                'placement_count'                       => $placementCount,
                'link_agreed_count'                     => $linkAgreedCount,
                'conversion_count'                      => $conversionCount,
                'conversion_rate'                       => $conversionRate,
            );
        }

        $twig = TwigHelper::twig();

        return $twig->render('team.html.twig', array(
            "title"                 => "Team",
            "body_class"            => "home",
            "users"                 => $users,
            "monthly_pitch_count"   => $monthlyPitchCount,
            'from_date'             => $fromDate,
            'to_date'               => $toDate,
        ));
    }

    protected function _getRelationshipStageCount($projectData, $stage)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $fromDate->copy()->addMonth();

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'Stage')
            ->where('is_ignored', '=', 0)
            ->where('summary', 'like', "Relationship stage changed to: $stage")
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

    protected function _getPitchCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Pitched');
    }

    protected function _getIntroductionCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Introduced');
    }

    protected function _getReferralCount($projectData)
    {
        // I typo'd it initially as "Referrred"
        return $this->_getRelationshipStageCount($projectData, 'Referr%');
    }

    protected function _getPlacementCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Successful Placement');
    }

    protected function _getLinkAgreedCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Link Agreed%');
    }

    protected function _getWebsiteCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $fromDate->copy()->addMonth();

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('is_ignored', '=', 0)
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

    protected function _getProjectStatusData($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $conversionCount = $projectData['conversion_count'];
        $monthlyConversionCount = getenv('MONTHLY_CONVERSION_COUNT');

        $today = new Carbon();
        $daysIntoBillingPeriod = $today->diffInDays($fromDate);
        $percentBillingPeriodComplete = ($daysIntoBillingPeriod / 30) * 100;
        $percentConversionProgress = ($conversionCount / $monthlyConversionCount) * 100;
        $percentConversionProgress = ($percentConversionProgress > 100) ? 100 : $percentConversionProgress;

        $diff = $percentConversionProgress - $percentBillingPeriodComplete;
        $status = $percentConversionProgress >= $percentBillingPeriodComplete ? "ahead-schedule" : "behind-schedule";
        $severity = abs($diff) >= 7 ? "lot" : "little";

        return array(
            'project_status'                        => $status,
            'progress_severity'                     => $severity,
            'conversion_completion_percentage'      => $percentConversionProgress,
            'billing_period_completion_percentage'  => $percentBillingPeriodComplete,
        );
    }

    /*
     * Need to refactor the *ByUser methods to dedup with the other methods based on project data
     * Also need to move into models
     */

    /**
     * @param $buzzStreamUser User
     * @param $stage
     * @param $fromDate Carbon
     * @param $toDate Carbon
     * @return int
     */
    protected function _getRelationshipStageCountByUser($buzzStreamUser, $stage, $fromDate, $toDate)
    {
        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'Stage')
            ->where('is_ignored', '=', 0)
            ->where('summary', 'like', "Relationship stage changed to: $stage")
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->where('history_items.buzzstream_owner_id', '=', $buzzStreamUser->getId())
            ->count();

        return $count;
    }

    protected function _getPitchCountByUser($buzzStreamUser, $fromDate, $toDate)
    {
        return $this->_getRelationshipStageCountByUser($buzzStreamUser, 'Pitched', $fromDate, $toDate);
    }

    protected function _getIntroductionCountByUser($buzzStreamUser, $fromDate, $toDate)
    {
        return $this->_getRelationshipStageCountByUser($buzzStreamUser, 'Introduced', $fromDate, $toDate);
    }

    protected function _getReferralCountByUser($buzzStreamUser, $fromDate, $toDate)
    {
        return $this->_getRelationshipStageCountByUser($buzzStreamUser, 'Referr%', $fromDate, $toDate);
    }

    protected function _getPlacementCountByUser($buzzStreamUser, $fromDate, $toDate)
    {
        return $this->_getRelationshipStageCountByUser($buzzStreamUser, 'Successful Placement', $fromDate, $toDate);
    }

    protected function _getLinkAgreedCountByUser($buzzStreamUser, $fromDate, $toDate)
    {
        return $this->_getRelationshipStageCountByUser($buzzStreamUser, 'Link Agreed%', $fromDate, $toDate);
    }

    /**
     * @param $buzzStreamUser User
     * @param $fromDate Carbon
     * @param $toDate Carbon
     * @return int
     */
    protected function _getWebsiteCountByUser($buzzStreamUser, $fromDate, $toDate)
    {
        $buzzStreamUserId = $buzzStreamUser->getId();

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('is_ignored', '=', 0)
            ->where('history_items.buzzstream_owner_id', '=', $buzzStreamUserId)
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
            ->where('is_ignored', '=', 0)
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
        $linkAgreedCount = $this->_getLinkAgreedCount($projectData);
        $monthlyConversionCount = getenv('MONTHLY_CONVERSION_COUNT');

        $twig = TwigHelper::twig();

        $projectData['pitch_count'] = $this->_getPitchCount($projectData);
        $projectData['conversion_count'] = $this->_getLinkAgreedCount($projectData)
            + $this->_getPlacementCount($projectData) + $this->_getIntroductionCount($projectData)
            + $this->_getReferralCount($projectData);

        $data = $this->_getProjectStatusData($projectData);

        return $twig->render('project.html.twig', array(
            "title"                             => "BuzzStream Feed",
            "body_class"                        => "home",
            "history"                           => $historyItems,
            "project_data"                      => $projectData,
            "website_count"                     => $websiteCount,
            "placement_count"                   => $placementCount,
            "link_agreed_count"                 => $linkAgreedCount,
            "introduction_count"                => $this->_getIntroductionCount($projectData),
            "referral_count"                    => $this->_getReferralCount($projectData),
            "pitch_count"                       => $projectData['pitch_count'],
            "conversion_count"                  => $projectData['conversion_count'],
            "progress_status"                   => $data['project_status'],
            "progress_severity"                 => $data['progress_severity'],
            "conversion_completion_percentage"   => $data['conversion_completion_percentage'],
            "month_completion_percentage"       => $data['billing_period_completion_percentage'],
            "from_date"                         => $fromDate,
            "to_date"                           => $toDate,
            "monthly_conversion_count"          => $monthlyConversionCount,
            "billing_start_date"                => new Carbon($projectData['billing_start_date']),
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

        $offset = isset($_GET['offset']) ? $_GET['offset'] : 0;
        $size = isset($_GET['size']) ? $_GET['size'] : 50;

        list($insertedCount, $results) = Importer::import($offset, $size);

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