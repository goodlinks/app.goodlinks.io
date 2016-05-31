<?php

namespace App\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class HistoryItem
 * @package App\Model
 * @method static \Illuminate\Database\Query\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 */
class HistoryItem extends Model
{
    protected $guarded = [];

    public function getId()
    {
        return $this->id;
    }

    public function getBuzzstreamCreatedAt()
    {
        return $this->buzzstream_created_at;
    }

    public function getBuzzstreamCreatedAtDate()
    {
        $date = new Carbon($this->getBuzzstreamCreatedAt());
        return $date;
    }

    public function getBuzzstreamId()
    {
        return $this->buzzstream_id;
    }

    public function getAvatarUrl()
    {
        return $this->avatar_url;
    }

    public function getWebsiteNames()
    {
        return $this->website_names;
    }

    public function getSummary()
    {
        return $this->summary;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setIsIgnored($value)
    {
        $this->is_ignored = $value;
        return $this;
    }

    public function getBuzzstreamApiUrl()
    {
        return "https://api.buzzstream.com/v1/history/" . $this->getBuzzstreamId();
    }

    /**
     * @param $buzzstreamId
     * @return HistoryItem
     */
    public static function findByBuzzstreamId($buzzstreamId)
    {
        $first = $model = static::where('buzzstream_id', '=', $buzzstreamId)
            ->first();

        if (!is_null($first)) {
            return $first;
        }

        return new static;
    }

    public function isConversion()
    {
        if ($this->getType() != 'Stage') {
            return false;
        }

        if (strpos($this->getSummary(), 'Relationship stage changed') === false) {
            return false;
        }

        $conversionStages = array('Introduced', 'Referred', 'Referrred', 'Link Agreed', 'Successful Placement');
        foreach ($conversionStages as $stage) {
            if (strpos($this->getSummary(), $stage) !== false) {
                return true;
            }
        }

        return false;
    }
}