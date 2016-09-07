<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;
use App\Model\Article;
use App\Model\HistoryItem;
use App\Model\Importer;
use App\Model\HistoryItemProject;

use Carbon\Carbon;
use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\User;
use DB;

class IndexController extends Controller
{
    /**
     * @param $projectData
     * @return Carbon
     */
    protected function _getFromDate($projectData)
    {
        $toDate = $this->_getToDate($projectData);
        $months = $this->_getMonthCount($projectData);
        $fromDate = $toDate->copy()->subMonths($months);

        return $fromDate;
    }

    /**
     * @param $projectData
     * @return Carbon
     */
    protected function _getToDate($projectData)
    {
        if (isset($_GET['to'])) {
            $toDate = new Carbon($_GET['to']);
            return $toDate;
        }

        $billingStartDate = new Carbon($projectData['billing_start_date']);
        $today = new Carbon();
        $toDate = $billingStartDate->copy();

        while ($toDate < $today) {
            $toDate->addMonth();
        }

        return $toDate;
    }

    protected function _getMonthCount($projectData)
    {
        $months = isset($_GET['months']) ? $_GET['months'] : 1;
        $billingStartDate = new Carbon($projectData['billing_start_date']);
        $toDate = $this->_getToDate($projectData);
        $fromDate = $toDate->copy()->subMonths($months);

        while ($fromDate < $billingStartDate) {
            $fromDate->addMonth(1);
            $months--;
        }

        return $months;
    }

    public function index()
    {
        $path = dirname(dirname(dirname(dirname(__FILE__)))) . "/.projects.json";
        $projects = json_decode(file_get_contents($path), true);
        if (! $projects) {
            die("Couldn't find projects json data");
        }

        foreach ($projects as & $projectData) {
            $projectData['email_count'] = $this->_getEmailCount($projectData);
            $projectData['tweet_count'] = $this->_getTweetCount($projectData);
            $projectData['comment_count'] = $this->_getCommentCount($projectData);
            $projectData['communication_count'] = $projectData['email_count'] + $projectData['tweet_count'] + $projectData['comment_count'];

            $projectData['website_count'] = $this->_getWebsiteCount($projectData);
            $projectData['introduction_count'] = $this->_getIntroductionCount($projectData);
            $projectData['referral_count'] = $this->_getReferralCount($projectData);
            $projectData['warm_count'] = $this->_getWarmCount($projectData);
            $projectData['placement_count'] = $this->_getPlacementCount($projectData);
            $projectData['link_agreed_count'] = $this->_getLinkAgreedCount($projectData);
            $projectData['conversion_count'] = $projectData['link_agreed_count'] + $projectData['placement_count'] + $projectData['introduction_count'] + $projectData['referral_count'];

            $projectData['introduction_items'] = $this->_getIntroductionItems($projectData);
            $projectData['referral_items'] = $this->_getReferralItems($projectData);
            $projectData['warm_items'] = $this->_getWarmItems($projectData);
            $projectData['placement_items'] = $this->_getPlacementItems($projectData);
            $projectData['link_agreed_items'] = $this->_getLinkAgreedItems($projectData);

            $data = $this->_getProjectStatusData($projectData);
            $projectData['project_status'] = $data['project_status'];
            $projectData['progress_severity'] = $data['progress_severity'];
            $projectData['conversion_completion_percentage'] = $data['conversion_completion_percentage'];
            $projectData['billing_period_completion_percentage'] = $data['billing_period_completion_percentage'];
            $projectData['communication_count_behind'] = $data['communication_count_behind'];

            $projectData['from_date'] = $this->_getFromDate($projectData);
            $projectData['to_date'] = $this->_getToDate($projectData);
            $projectData['months'] = $this->_getMonthCount($projectData);
            $projectData['monthly_conversion_count'] = $projectData['monthly_conversion_count'] * $projectData['months'];

            $projectData['paid_content'] = $this->_getPaidContentData($projectData);
        }

        $twig = TwigHelper::twig();

        $adminIPs = array_map('trim', explode(',', env('ADMIN_IPS')));
        $isAdmin = isset($_SERVER['REMOTE_ADDR']) ? (in_array($_SERVER['REMOTE_ADDR'], $adminIPs)) : false;

        return $twig->render('index.html.twig', array(
            "title"                     => "Projects",
            "body_class"                => "home",
            "projects"                  => $projects,
            "is_admin"                  => $isAdmin,
        ));
    }

    protected function _getPaidContentData($projectData)
    {
        if (! isset($projectData['monthly_word_count'])) {
            return array();
        }

        $fromDate = $this->_getFromDate($projectData);
        $toDate = $this->_getToDate($projectData);

        $articles = Article::where('billed_at', '>=', $fromDate->format('Y-m-d'))
            ->where('billed_at', '<=', $toDate->format('Y-m-d'))
            ->where('buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->get();

        $monthlyWordCount = $projectData['monthly_word_count'];
        $wordCount = $articleCount = 0;
        foreach ($articles as $article) {
            /** @var Article $article */
            $wordCount += $article->getWordCount();
            $articleCount++;
        }

        $completionPercentage = ($wordCount / $monthlyWordCount) * 100;
        $completionPercentage = ($completionPercentage > 100) ? 100 : $completionPercentage;
        $percentBillingPeriodComplete = $projectData['billing_period_completion_percentage'];

        $diff = $completionPercentage - $percentBillingPeriodComplete;
        $status = $completionPercentage >= $percentBillingPeriodComplete ? "ahead-schedule" : "behind-schedule";
        $severity = abs($diff) > 10 ? "lot" : "little";

        return array(
            'status'                        => $status,
            'severity'                      => $severity,
            'word_count'                    => $wordCount,
            'article_count'                 => $articleCount,
            'completion_percentage'         => $completionPercentage,
            'articles'                      => $articles,
        );
    }

    public function team()
    {
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $path = dirname(dirname(dirname(dirname(__FILE__)))) . "/.projects.json";
        $projects = json_decode(file_get_contents($path), true);
        if (! $projects) {
            die("Couldn't find projects json data");
        }

        $today = new Carbon();
        $numDays = isset($_GET['days']) ? $_GET['days'] : 10;
        $fromDate = new Carbon(isset($_GET['from']) ? $_GET['from'] : $today->subDays(10));
        $toDate = $fromDate->copy()->addDays($numDays);

        $buzzstreamUsers = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where(DB::raw('date_format(buzzstream_created_at, "%Y-%m-%d")'), '<=', $toDate->format('Y-m-d'))
            ->whereIn('type', array('EMail', 'Tweet', 'Blog Comment', 'Call'))
            ->where('is_ignored', '=', 0)
            ->groupBy('buzzstream_owner_id', 'the_day')
            ->groupBy('the_day')
            ->select(array(
                'buzzstream_owner_id as buzzstream_user_id',
                DB::raw('count(*) as outreach_count'),
                DB::raw('date_format(buzzstream_created_at, "%Y-%m-%d") as the_day')
            ))
            ->get();

        $outreachCountByUser = array();

        /** @var HistoryItem $user */
        foreach ($buzzstreamUsers as $user) {
            if (! isset($outreachCountByUser[$user->buzzstream_user_id])) {
                $name = env('USER_' . $user->buzzstream_user_id . '_NAME');
                $color = env('USER_' . $user->buzzstream_user_id . '_COLOR');

                $outreachCountByUser[$user->buzzstream_user_id] = array(
                    'name'  => $name ? $name : "Outreacher #{$user->buzzstream_user_id}",
                    'color' => $color ? $color : "gray",
                );
            }

            $outreachCountByUser[$user->buzzstream_user_id]['data'][$user->the_day] = $user->outreach_count;
        }

        $theDay = $fromDate->copy();
        $days = array();

        for ($i = 0; $i <= $numDays; $i++) {
            $days[] = $theDay->format("Y-m-d");
            foreach ($buzzstreamUsers as $user) {
                if (! isset($outreachCountByUser[$user->buzzstream_user_id]['data'][$theDay->format("Y-m-d")])) {
                    $outreachCountByUser[$user->buzzstream_user_id]['data'][$theDay->format("Y-m-d")] = 0;
                }
            }
            ksort($outreachCountByUser[$user->buzzstream_user_id]['data']);
            $theDay->addDay();
        }

        $twig = TwigHelper::twig();

        /*
        $outreachCountByUser = array(
            array(
                'name' => 'Kalen',
                'color' => 'red',
                'data' => array(
                    '2016-08-01' => 52,
                    '2016-08-02' => 53,
                ),
            ),
            array(
                'name' => 'Steve',
                'color' => 'blue',
                'data' => array(
                    '2016-08-01' => 22,
                    '2016-08-02' => 33,
                ),
            ),
        );

        */
        return $twig->render('team.html.twig', array(
            "title"                     => "Team",
            "body_class"                => "home",
            'from_date'                 => $fromDate,
            'to_date'                   => $toDate,
            'outreach_count_by_user'    => $outreachCountByUser,
            'days'                      => array_values($days),
        ));
    }

    protected function _getRelationshipStageCount($projectData, $stage)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $this->_getToDate($projectData);

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

    protected function _getItemsByRelationshipStage($projectData, $stage)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $this->_getToDate($projectData);

        $collection = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
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
            ->get();

        return $collection;
    }

    protected function _getEmailCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $this->_getToDate($projectData);

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'EMail')
            ->where('is_ignored', '=', 0)
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

    protected function _getTweetCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $this->_getToDate($projectData);

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'Tweet')
            ->where('is_ignored', '=', 0)
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

    protected function _getCommentCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $this->_getToDate($projectData);

        $count = HistoryItem::where('buzzstream_created_at', '>=', $fromDate->format('Y-m-d'))
            ->where('buzzstream_created_at', '<=', $toDate->format('Y-m-d'))
            ->where('type', '=', 'Blog Comment')
            ->where('is_ignored', '=', 0)
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

    protected function _getIntroductionCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Introduced');
    }

    protected function _getReferralCount($projectData)
    {
        // I typo'd it initially as "Referrred"
        return $this->_getRelationshipStageCount($projectData, 'Referr%');
    }

    protected function _getWarmCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Warm');
    }

    protected function _getPlacementCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Successful Placement');
    }

    protected function _getLinkAgreedCount($projectData)
    {
        return $this->_getRelationshipStageCount($projectData, 'Link Agreed%');
    }

    protected function _getIntroductionItems($projectData)
    {
        return $this->_getItemsByRelationshipStage($projectData, 'Introduced');
    }

    protected function _getReferralItems($projectData)
    {
        // I typo'd it initially as "Referrred"
        return $this->_getItemsByRelationshipStage($projectData, 'Referr%');
    }

    protected function _getWarmItems($projectData)
    {
        return $this->_getItemsByRelationshipStage($projectData, 'Warm');
    }

    protected function _getPlacementItems($projectData)
    {
        return $this->_getItemsByRelationshipStage($projectData, 'Successful Placement');
    }

    protected function _getLinkAgreedItems($projectData)
    {
        return $this->_getItemsByRelationshipStage($projectData, 'Link Agreed%');
    }

    protected function _getWebsiteCount($projectData)
    {
        $fromDate = $this->_getFromDate($projectData);
        $toDate = $this->_getToDate($projectData);

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
        $months = $this->_getMonthCount($projectData);
        $fromDate = $this->_getFromDate($projectData);
        $communicationCount = $projectData['email_count'] + $projectData['tweet_count'] + $projectData['comment_count'];
        $monthlyCommunicationCount = $projectData['monthly_communication_count'] * $months;

        $today = new Carbon();
        $daysIntoBillingPeriod = $today->diffInDays($fromDate);
        $percentBillingPeriodComplete = ($daysIntoBillingPeriod / (30 * $months)) * 100;
        $percentBillingPeriodComplete = ($percentBillingPeriodComplete > 100) ? 100 : $percentBillingPeriodComplete;

        $percentConversionProgress = ($communicationCount / $monthlyCommunicationCount) * 100;
        $percentConversionProgress = ($percentConversionProgress > 100) ? 100 : $percentConversionProgress;

        $diff = $percentConversionProgress - $percentBillingPeriodComplete;
        $status = $percentConversionProgress >= $percentBillingPeriodComplete ? "ahead-schedule" : "behind-schedule";
        $severity = abs($diff) > 10 ? "lot" : "little";

        $amountBehind = (int)((($percentBillingPeriodComplete - $percentConversionProgress) / 100) * ($monthlyCommunicationCount));
        echo "<!-- Behind: $amountBehind (Communicaiton count:$communicationCount) -->";
        //echo "monthly: $monthlyCommunicationCount<br>behind: $amountBehind <br>count: $communicationCount <br>bill complete: $percentBillingPeriodComplete<br>conversion progress: $percentConversionProgress<br><br>";

        return array(
            'project_status'                        => $status,
            'communication_count_behind'            => $amountBehind,
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
        $toDate = $this->_getToDate($projectData);

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
            ->where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->orderBy('buzzstream_created_at', 'desc')
            ->limit(2000);

        if (isset($_GET['type'])) {
            $type = $_GET['type'];
            $historyItems->where('history_items.type', '=', $type);
        }

        $historyItems = $historyItems->get();

        $websiteCount = $this->_getWebsiteCount($projectData);
        $placementCount = $this->_getPlacementCount($projectData);
        $linkAgreedCount = $this->_getLinkAgreedCount($projectData);
        $monthlyConversionCount = $projectData['monthly_conversion_count'];

        $projectData['referral_items'] = $this->_getReferralItems($projectData);
        $projectData['warm_items'] = $this->_getWarmItems($projectData);
        $projectData['placement_items'] = $this->_getPlacementItems($projectData);
        $projectData['link_agreed_items'] = $this->_getLinkAgreedItems($projectData);

        $twig = TwigHelper::twig();

        $projectData['email_count'] = $this->_getEmailCount($projectData);
        $projectData['tweet_count'] = $this->_getTweetCount($projectData);
        $projectData['comment_count'] = $this->_getCommentCount($projectData);
        $projectData['communication_count'] = $projectData['email_count'] + $projectData['tweet_count'] + $projectData['comment_count'];

        $projectData['conversion_count'] = $this->_getLinkAgreedCount($projectData)
            + $this->_getPlacementCount($projectData) + $this->_getIntroductionCount($projectData)
            + $this->_getReferralCount($projectData);

        $data = $this->_getProjectStatusData($projectData);
        $projectData['billing_period_completion_percentage'] = $data['billing_period_completion_percentage'];

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
            "warm_count"                        => $this->_getWarmCount($projectData),
            "email_count"                       => $projectData['email_count'],
            "tweet_count"                       => $projectData['tweet_count'],
            "comment_count"                     => $projectData['comment_count'],
            "communication_count"               => $projectData['communication_count'],
            "conversion_count"                  => $projectData['conversion_count'],
            'introduction_items'                => $this->_getIntroductionItems($projectData),
            'referral_items'                    => $this->_getReferralItems($projectData),
            'warm_items'                        => $this->_getWarmItems($projectData),
            'placement_items'                   => $this->_getPlacementItems($projectData),
            'link_agreed_items'                 => $this->_getLinkAgreedItems($projectData),
            "progress_status"                   => $data['project_status'],
            "progress_severity"                 => $data['progress_severity'],
            "conversion_completion_percentage"  => $data['conversion_completion_percentage'],
            "billing_period_completion_percentage"       => $data['billing_period_completion_percentage'],
            "from_date"                         => $fromDate,
            "to_date"                           => $toDate,
            "monthly_conversion_count"          => $monthlyConversionCount,
            "monthly_communication_count"       => $projectData['monthly_communication_count'],
            "billing_start_date"                => new Carbon($projectData['billing_start_date']),
            "paid_content"                      => $this->_getPaidContentData($projectData),
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

    public function ignoreHistoryItem($buzzstreamId)
    {
        $historyItem = HistoryItem::findByBuzzstreamId($buzzstreamId);
        $historyItem->setIsIgnored(true)->save();

        return array(
            'success'       => true,
            'buzzstream_id' => $buzzstreamId,
        );
    }

    public function flagHistoryItem($buzzstreamId)
    {
        try {
            return $this->_flagHistoryItem($buzzstreamId);
        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }

    protected function _flagHistoryItem($buzzstreamId)
    {
        $historyItem = HistoryItem::findByBuzzstreamId($buzzstreamId);
        $projectId = isset($_GET['project_id']) ? $_GET['project_id'] : null;
        if (! $projectId) {
            throw new \Exception("Missing project ID");
        }

        $projectData = $this->_getProjectData($projectId);

        $goodlinkerEmail = isset($projectData['goodlinker_email']) ? $projectData['goodlinker_email'] : null;
        if (! $goodlinkerEmail) {
            throw new \Exception("No email found for project: $projectId");
        }

        $headers = "";
        $headers .= 'From: Goodlinks QA <qa@goodlinks.io>' . "\r\n";
        $headers .= 'Bcc: kalen@goodlinks.io' . "\r\n";

        $subject = substr($historyItem->getSummary(), 0, 100) . "(#$buzzstreamId)";

        $body = "We found a problem with this communication. Please review it, reply back and confirm what the issue was so that we're on the same page going forward.";
        $body .= "\r\n\r\nMore info on handling flagged communications: http://docs.goodlinks.io/article/59-handling-flagged-communication";
        $body .= "\r\n\r\n------------";
        $body .= "\r\n\r\nWebsite(s): " . $historyItem->getWebsiteNames();
        $body .= "\r\n\r\n" . $historyItem->getBody();

        mail($goodlinkerEmail, "Communication Flagged: $subject", $body, $headers);

        return array(
            'success'       => true,
            'buzzstream_id' => $buzzstreamId,
        );
    }
}